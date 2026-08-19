<?php

declare(strict_types=1);

namespace TEB;

use PDO;

/**
 * Feed ingestion — the only thing in this codebase that touches the network.
 *
 * Contract:
 *   Ingest::run(PDO $p, array $cfg, ?array $only = null): array
 *       ['feeds_ok','feeds_failed','inserted','skipped','errors' => []]
 *   Ingest::lock(string $dataDir)            resource, or null when another run holds it
 *
 * Rules this file is held to:
 *   - One bad feed NEVER fails the run. Its error is recorded, its fail_count goes up,
 *     and after PARK_AFTER consecutive failures the source is parked and that is logged.
 *   - Every run writes an ingest_runs row with honest counts, including a run that
 *     fetched nothing.
 *   - The batch is bounded: a shared host must never be asked to fetch 35 feeds inside
 *     one page view.
 *   - No brand or domain literal. The User-Agent is built from config, which is also
 *     where the contact URL publishers see comes from.
 */
final class Ingest
{
    /** Consecutive failures before a feed is parked (SPEC §4). */
    public const PARK_AFTER = 8;

    public const DEFAULT_TIMEOUT       = 12;
    public const DEFAULT_BATCH         = 14;
    public const DEFAULT_RETENTION     = 30;
    public const DEFAULT_MAX_ITEMS     = 60;
    /** A feed fetched more recently than this is skipped, so a refresh loop cannot hammer publishers. */
    public const DEFAULT_MIN_INTERVAL_MINUTES = 5;

    /** How long a parked feed is left alone before one retry. Matches the registry's own backoff. */
    public const DEFAULT_PARKED_RETRY_MINUTES = 360;

    /** Hard ceiling on one response body. Feeds run 3 KB – 900 KB; 6 MB is a runaway. */
    public const MAX_BYTES = 6291456;

    /** Prune runs at most this often, tracked in data/prune.state. */
    private const PRUNE_EVERY_MS = 3600000;

    /** @var resource|null Held for the lifetime of the process once lock() succeeds. */
    private static $lockHandle = null;

    /** @var string|null Path of the lock file this process holds. */
    private static $lockPath = null;

    /**
     * Fetch, parse and store one bounded batch of feeds.
     *
     * @param array<string,mixed>          $cfg
     * @param array<int,string|array>|null $only  slugs (or whole feed rows) to restrict this run to
     * @return array{feeds_attempted:int,feeds_ok:int,feeds_failed:int,inserted:int,skipped:int,parked:array<int,string>,errors:array<int,string>,duration_ms:int,locked_out:bool}
     */
    public static function run(PDO $p, array $cfg, ?array $only = null): array
    {
        $startedAt = self::nowMs();
        $res       = [
            'feeds_attempted' => 0,
            'feeds_ok'        => 0,
            'feeds_failed'    => 0,
            'inserted'        => 0,
            'skipped'         => 0,
            'parked'          => [],
            'errors'          => [],
            'duration_ms'     => 0,
            'locked_out'      => false,
            'disabled'        => false,
        ];

        $ingestCfg = is_array($cfg['ingest'] ?? null) ? $cfg['ingest'] : [];
        $runMode   = (string) ($ingestCfg['run_mode'] ?? 'cron');
        $dataDir   = self::dataDir($cfg);

        if (!($ingestCfg['enabled'] ?? true)) {
            $res['disabled']     = true;
            $res['errors'][]     = 'ingest is disabled in config.php (ingest.enabled = false)';
            $res['duration_ms']  = self::nowMs() - $startedAt;
            self::recordRun($p, $startedAt, $res, $runMode, 'disabled in config');

            return $res;
        }

        // Two concurrent page views must not both ingest. If this process already holds the
        // lock (cron/ingest.php takes it before calling), keep it and do not release it here.
        $ownsLock = false;
        if (self::$lockHandle === null) {
            $handle = self::lock($dataDir);
            if ($handle === null) {
                $res['locked_out'] = true;
                $res['errors'][]   = 'another ingest run is already in progress';
                $res['duration_ms'] = self::nowMs() - $startedAt;

                return $res;   // nothing ran, so nothing is recorded
            }
            $ownsLock = true;
        }

        try {
            $feeds = self::feedList($cfg, $only);
            if ($feeds === []) {
                $res['errors'][]    = 'no feeds are configured (app/Feeds.php returned nothing)';
                $res['duration_ms'] = self::nowMs() - $startedAt;
                self::recordRun($p, $startedAt, $res, $runMode, 'no feeds');

                return $res;
            }

            try {
                Db::upsertSources($p, $feeds);
            } catch (\Throwable $e) {
                $res['errors'][] = 'sources: ' . self::short($e->getMessage());
            }

            $state = self::sourceState($p);
            $due   = self::selectDue($feeds, $state, $startedAt, $cfg, $only !== null);

            $maxItems = max(1, (int) ($ingestCfg['max_items_per_feed'] ?? self::DEFAULT_MAX_ITEMS));

            foreach ($due as $feed) {
                $slug = (string) $feed['slug'];
                $res['feeds_attempted']++;

                try {
                    $outcome = self::ingestOne($p, $feed, $cfg, $maxItems);
                } catch (\Throwable $e) {
                    // Nothing a single feed can do is allowed to end the run.
                    $outcome = ['ok' => false, 'error' => self::short(get_class($e) . ': ' . $e->getMessage()), 'inserted' => 0, 'skipped' => 0];
                }

                if ($outcome['ok']) {
                    $res['feeds_ok']++;
                    $res['inserted'] += $outcome['inserted'];
                    $res['skipped']  += $outcome['skipped'];
                } else {
                    $res['feeds_failed']++;
                    $res['errors'][] = $slug . ': ' . $outcome['error'];

                    $fails = (int) ($state[$slug]['fail_count'] ?? 0) + 1;
                    if ($fails >= self::PARK_AFTER) {
                        $res['parked'][] = $slug;
                        $res['errors'][] = $slug . ': parked after ' . $fails . ' consecutive failures — it will be skipped until it succeeds again';
                    }
                }

                try {
                    Db::recordFeedResult($p, $slug, (bool) $outcome['ok'], (string) $outcome['error'], self::nowMs(), self::PARK_AFTER);
                } catch (\Throwable $e) {
                    $res['errors'][] = $slug . ': could not record result: ' . self::short($e->getMessage());
                }
            }

            self::maybePrune($p, $cfg, $dataDir, $res);
        } catch (\Throwable $e) {
            $res['errors'][] = 'run: ' . self::short(get_class($e) . ': ' . $e->getMessage());
        } finally {
            if ($ownsLock) {
                self::unlock();
            }
        }

        $res['duration_ms'] = self::nowMs() - $startedAt;
        self::recordRun($p, $startedAt, $res, $runMode, '');

        return $res;
    }

    /**
     * Exclusive, non-blocking run lock.
     *
     * Returns the open handle (keep it — closing it releases the lock) or null when another
     * process holds it. There is no `: ?resource` return type because PHP has no resource
     * type declaration; the docblock is the contract.
     *
     * @return resource|null
     */
    public static function lock(string $dataDir)
    {
        if (self::$lockHandle !== null) {
            return self::$lockHandle;   // this process already holds it
        }

        $dataDir = rtrim($dataDir, '/\\');
        if ($dataDir === '') {
            $dataDir = dirname(__DIR__) . '/data';
        }
        if (!is_dir($dataDir) && !@mkdir($dataDir, 0775, true) && !is_dir($dataDir)) {
            return null;
        }

        $path   = $dataDir . '/ingest.lock';
        $handle = @fopen($path, 'c+');
        if (!is_resource($handle)) {
            return null;
        }
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);

            return null;
        }

        @ftruncate($handle, 0);
        @fwrite($handle, (string) getmypid() . ' ' . gmdate('c') . "\n");
        @fflush($handle);

        self::$lockHandle = $handle;
        self::$lockPath   = $path;

        return $handle;
    }

    /** Release the lock taken by lock(). Safe to call when nothing is held. */
    public static function unlock(): void
    {
        if (self::$lockHandle === null) {
            return;
        }
        @flock(self::$lockHandle, LOCK_UN);
        @fclose(self::$lockHandle);
        self::$lockHandle = null;
        self::$lockPath   = null;
    }

    /** True when this process is holding the ingest lock. */
    public static function holdsLock(): bool
    {
        return self::$lockHandle !== null;
    }

    /**
     * The User-Agent publishers see. Identifies the site and carries a contact URL, both
     * read from config — nothing about the brand is compiled in here.
     */
    public static function userAgent(array $cfg): string
    {
        $site = is_array($cfg['site'] ?? null) ? $cfg['site'] : [];

        $name = trim((string) ($site['short_name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($site['name'] ?? ''));
        }
        $token = preg_replace('/[^A-Za-z0-9]+/', '', $name);
        $token = is_string($token) ? $token : '';
        if ($token === '') {
            $token = 'NewsAggregator';
        }
        $token = substr($token, 0, 40);

        $domain = trim((string) ($site['domain'] ?? ''));
        $domain = (string) preg_replace('#^https?://#i', '', $domain);
        $domain = trim(rtrim($domain, '/'));
        $domain = (string) preg_replace('/[^A-Za-z0-9.\-]/', '', $domain);

        $contact = $domain !== '' ? '; +https://' . $domain . '/about' : '';

        return 'Mozilla/5.0 (compatible; ' . $token . 'Bot/1.0' . $contact . '; feed aggregator) PHP/'
            . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    /**
     * One HTTP GET. Never throws.
     *
     * @return array{ok:bool,status:int,body:string,error:string,bytes:int,ms:int,final_url:string}
     */
    public static function fetch(string $url, array $cfg): array
    {
        $t0  = microtime(true);
        $out = ['ok' => false, 'status' => 0, 'body' => '', 'error' => '', 'bytes' => 0, 'ms' => 0, 'final_url' => $url];

        $url = trim($url);
        if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
            $out['error'] = 'not an http(s) url';

            return $out;
        }

        $ingestCfg = is_array($cfg['ingest'] ?? null) ? $cfg['ingest'] : [];
        $timeout   = (int) ($ingestCfg['timeout_seconds'] ?? self::DEFAULT_TIMEOUT);
        $timeout   = max(3, min(60, $timeout));
        $locale    = (string) ($cfg['site']['locale'] ?? 'en-US');
        $lang      = str_replace('_', '-', $locale);

        $headers = [
            'Accept: application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.9, */*;q=0.5',
            'Accept-Language: ' . $lang . ',en;q=0.7',
            'Cache-Control: no-cache',
        ];

        if (function_exists('curl_init')) {
            $body = '';
            $ch   = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => max(3, (int) ceil($timeout / 2)),
                CURLOPT_USERAGENT      => self::userAgent($cfg),
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_ENCODING       => '',          // gzip/deflate/br, whatever this build supports
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADER         => false,
                CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$body): int {
                    $body .= $chunk;
                    if (strlen($body) > self::MAX_BYTES) {
                        return -1;                      // abort: this is not a feed any more
                    }

                    return strlen($chunk);
                },
            ]);
            if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
                // A redirect must never take us to file:// or gopher://.
                @curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
                @curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            }

            $ok            = curl_exec($ch);
            $errNo         = curl_errno($ch);
            $errMsg        = curl_error($ch);
            $out['status'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $final         = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);

            $out['final_url'] = $final !== '' ? $final : $url;
            $out['body']      = $body;
            $out['bytes']     = strlen($body);

            if ($ok === false && $errNo !== 0) {
                $out['error'] = ($errNo === CURLE_WRITE_ERROR && $out['bytes'] > self::MAX_BYTES)
                    ? 'response larger than ' . self::MAX_BYTES . ' bytes'
                    : 'curl(' . $errNo . '): ' . $errMsg;

                return $out;
            }
        } else {
            // A host without ext/curl still has to work.
            $context = stream_context_create(['http' => [
                'method'          => 'GET',
                'timeout'         => $timeout,
                'follow_location' => 1,
                'max_redirects'   => 5,
                'ignore_errors'   => true,
                'user_agent'      => self::userAgent($cfg),
                'header'          => implode("\r\n", $headers),
            ]]);
            $body = @file_get_contents($url, false, $context, 0, self::MAX_BYTES);
            $code = 0;
            foreach (($http_response_header ?? []) as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m) === 1) {
                    $code = (int) $m[1];
                }
            }
            $out['status'] = $code;
            $out['body']   = is_string($body) ? $body : '';
            $out['bytes']  = strlen($out['body']);
            if (!is_string($body)) {
                $out['error'] = 'request failed (no curl extension; stream fallback)';

                return $out;
            }
        }

        $out['ms'] = (int) round((microtime(true) - $t0) * 1000);

        if ($out['status'] < 200 || $out['status'] >= 300) {
            $out['error'] = 'HTTP ' . ($out['status'] === 0 ? 'no response' : (string) $out['status']);

            return $out;
        }
        if (trim($out['body']) === '') {
            $out['error'] = 'empty response body';

            return $out;
        }

        $out['ok'] = true;

        return $out;
    }

    /**
     * Parsed feed items to article rows for Db::insertArticles().
     *
     * @param array<string,mixed>       $feed    one row from Feeds::all()
     * @param array<int,array>          $items   Xml::parseFeed()['items']
     * @param array<string,mixed>       $source  the sources row, when we have it (for source_id)
     * @return array<int,array<string,mixed>>
     */
    public static function rowsFor(array $feed, array $items, int $nowMs, array $source = [], int $maxItems = self::DEFAULT_MAX_ITEMS): array
    {
        $rows = [];
        foreach ($items as $i) {
            if (count($rows) >= $maxItems) {
                break;
            }
            $url   = trim((string) ($i['url'] ?? ''));
            $title = trim((string) ($i['title'] ?? ''));
            if ($url === '' || $title === '') {
                continue;
            }

            $published = $i['published_at'] ?? null;

            $rows[] = [
                'source_id'    => (int) ($source['id'] ?? 0),
                'source_slug'  => (string) ($feed['slug'] ?? ''),
                'source_name'  => (string) ($feed['name'] ?? ($source['name'] ?? '')),
                'section'      => (string) ($feed['section'] ?? ''),
                'guid'         => (string) ($i['guid'] ?? $url),
                'url'          => $url,
                'title'        => $title,
                'summary'      => (string) ($i['summary'] ?? ''),
                'image_url'    => (string) ($i['image_url'] ?? ''),
                'author'       => (string) ($i['author'] ?? ''),
                // null means the feed did not say; Db falls back to the fetch time.
                'published_at' => is_int($published) ? $published : 0,
                'fetched_at'   => $nowMs,
            ];
        }

        return $rows;
    }

    /**
     * The feeds this run may consider: the registry, optionally narrowed by $only.
     *
     * @param array<int,string|array>|null $only
     * @return array<int,array<string,mixed>>
     */
    public static function feedList(array $cfg, ?array $only = null): array
    {
        $feeds = [];

        if (is_array($cfg['feeds'] ?? null) && $cfg['feeds'] !== []) {
            $feeds = $cfg['feeds'];                       // an explicit registry beats the built-in one (tests use this)
        } elseif (class_exists(Feeds::class, false) || is_file(__DIR__ . '/Feeds.php')) {
            if (!class_exists(Feeds::class, false)) {
                require_once __DIR__ . '/Feeds.php';
            }
            try {
                $all = Feeds::all();
                if (is_array($all)) {
                    $feeds = $all;
                }
            } catch (\Throwable $e) {
                $feeds = [];
            }
        }

        $feeds = self::normaliseFeeds($feeds);

        if ($only === null || $only === []) {
            return $feeds;
        }

        // $only may be slugs, or whole feed rows for a feed that is not in the registry.
        $wanted  = [];
        $extra   = [];
        foreach ($only as $o) {
            if (is_string($o)) {
                $wanted[strtolower(trim($o))] = true;
            } elseif (is_array($o) && isset($o['slug'])) {
                $extra[] = $o;
                $wanted[strtolower(trim((string) $o['slug']))] = true;
            }
        }

        $picked = [];
        foreach ($feeds as $f) {
            if (isset($wanted[strtolower((string) $f['slug'])])) {
                $picked[(string) $f['slug']] = $f;
            }
        }
        foreach (self::normaliseFeeds($extra) as $f) {
            if (!isset($picked[(string) $f['slug']])) {
                $picked[(string) $f['slug']] = $f;
            }
        }

        return array_values($picked);
    }

    /**
     * Which feeds to fetch this run, bounded by ingest.batch.
     *
     * @param array<int,array<string,mixed>>   $feeds
     * @param array<string,array<string,mixed>> $state  sources rows keyed by slug
     * @return array<int,array<string,mixed>>
     */
    public static function selectDue(array $feeds, array $state, int $nowMs, array $cfg, bool $explicit = false): array
    {
        $ingestCfg = is_array($cfg['ingest'] ?? null) ? $cfg['ingest'] : [];
        $batch     = (int) ($ingestCfg['batch'] ?? self::DEFAULT_BATCH);
        $batch     = max(1, min(200, $batch));
        $minGapMs  = max(0, (int) ($ingestCfg['min_interval_minutes'] ?? self::DEFAULT_MIN_INTERVAL_MINUTES)) * 60000;

        // An explicit --only run is a human asking for these feeds now: no parking, no
        // interval — and no silent slice either. `--only=a,b,...,z` with more slugs than
        // ingest.batch used to fetch the first `batch` of them and say nothing, so the
        // operator read "14 attempted" as "the 20 I named are refreshed". The bounded-batch
        // rule in SPEC §4 exists to protect a shared host from an UNATTENDED pass; a named
        // list is attended. The absolute ceiling still applies.
        if ($explicit) {
            return array_slice($feeds, 0, max($batch, min(200, count($feeds))));
        }

        // The registry knows about tiers and cadences this class does not. Use it — unless
        // the caller passed its own feed list in config, in which case Feeds::due() would
        // answer about a completely different set of feeds.
        $ownRegistry  = is_array($cfg['feeds'] ?? null) && $cfg['feeds'] !== [];
        $fromRegistry = null;
        if (!$ownRegistry && class_exists(Feeds::class, false) && method_exists(Feeds::class, 'due')) {
            try {
                $candidate = Feeds::due($nowMs, $state);
                if (is_array($candidate)) {
                    $fromRegistry = self::normaliseFeeds($candidate);
                }
            } catch (\Throwable $e) {
                $fromRegistry = null;
            }
        }

        $coldStart = true;
        foreach ($state as $s) {
            if ((int) ($s['last_ok_at'] ?? 0) > 0) {
                $coldStart = false;
                break;
            }
        }

        // Honour "nothing is due" — unless nothing has ever succeeded, in which case a
        // first upload would otherwise show an empty site for ever.
        $usedRegistry = false;
        if (is_array($fromRegistry) && ($fromRegistry !== [] || !$coldStart)) {
            $feeds        = $fromRegistry;
            $usedRegistry = true;
        }

        $parkedRetryMs = max(0, (int) ($ingestCfg['parked_retry_minutes'] ?? self::DEFAULT_PARKED_RETRY_MINUTES)) * 60000;

        $eligible = [];
        foreach ($feeds as $f) {
            $slug      = (string) $f['slug'];
            $s         = $state[$slug] ?? null;
            $lastFetch = (int) ($s['last_fetch_at'] ?? 0);

            // Parked. Retry occasionally anyway: one success is the only thing that can
            // revive a source, so never trying again would make parking permanent.
            // When the registry chose this list it has already applied its own backoff.
            if (!$usedRegistry && $s !== null && (int) ($s['active'] ?? 1) === 0) {
                if ($lastFetch > 0 && $parkedRetryMs > 0 && ($nowMs - $lastFetch) < $parkedRetryMs) {
                    continue;
                }
            }
            if ($lastFetch > 0 && $minGapMs > 0 && ($nowMs - $lastFetch) < $minGapMs) {
                continue;                                  // fetched a moment ago
            }

            $eligible[] = ['feed' => $f, 'last' => $lastFetch, 'tier' => (int) ($f['tier'] ?? 3)];
        }

        // Never fetched first, then least recently fetched, then by tier. Stable and cheap.
        usort($eligible, static function (array $a, array $b): int {
            if ($a['last'] !== $b['last']) {
                return $a['last'] <=> $b['last'];
            }
            if ($a['tier'] !== $b['tier']) {
                return $a['tier'] <=> $b['tier'];
            }

            return strcmp((string) $a['feed']['slug'], (string) $b['feed']['slug']);
        });

        $out = [];
        foreach (array_slice($eligible, 0, $batch) as $e) {
            $out[] = $e['feed'];
        }

        return $out;
    }

    /** Absolute path of the writable data directory. */
    public static function dataDir(array $cfg): string
    {
        $root = (string) ($cfg['root'] ?? dirname(__DIR__));

        $dir = trim((string) ($cfg['paths']['data'] ?? ''));
        if ($dir === '') {
            $sqlite = trim((string) ($cfg['db']['sqlite_path'] ?? ''));
            $dir    = $sqlite !== '' ? dirname($sqlite) : 'data';
        }
        if ($dir === '' || $dir === '.') {
            $dir = 'data';
        }
        if (!preg_match('#^([A-Za-z]:[\\\\/]|/)#', $dir)) {
            $dir = rtrim($root, '/\\') . '/' . ltrim($dir, '/\\');
        }

        return rtrim($dir, '/\\');
    }

    // --------------------------------------------------------------- internals

    /**
     * @return array{ok:bool,error:string,inserted:int,skipped:int}
     */
    private static function ingestOne(PDO $p, array $feed, array $cfg, int $maxItems): array
    {
        $result = ['ok' => false, 'error' => '', 'inserted' => 0, 'skipped' => 0];

        $http = self::fetch((string) $feed['feed'], $cfg);
        if (!$http['ok']) {
            $result['error'] = $http['error'] !== '' ? $http['error'] : 'fetch failed';

            return $result;
        }

        $parsed = Xml::parseFeed($http['body']);
        if ($parsed['items'] === []) {
            $result['error'] = 'no items parsed from ' . $http['bytes'] . ' bytes (HTTP ' . $http['status'] . ')';

            return $result;
        }

        $source = [];
        try {
            $source = Db::sourceBySlug($p, (string) $feed['slug']) ?? [];
        } catch (\Throwable $e) {
            $source = [];
        }

        $rows = self::rowsFor($feed, $parsed['items'], self::nowMs(), $source, $maxItems);
        if ($rows === []) {
            $result['error'] = 'parsed ' . count($parsed['items']) . ' items but none were usable';

            return $result;
        }

        $written = Db::insertArticles($p, $rows);

        $result['ok']       = true;
        $result['inserted'] = (int) ($written['inserted'] ?? 0);
        $result['skipped']  = (int) ($written['skipped'] ?? 0);

        return $result;
    }

    /** @return array<string,array<string,mixed>> sources rows keyed by slug */
    private static function sourceState(PDO $p): array
    {
        try {
            $rows = Db::sources($p);
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $slug = (string) ($r['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            // Compatibility shim: the sources column is last_fetch_at, and Feeds::due()
            // reads last_fetched_at / fetched_at. Publishing both spellings means the
            // registry's tier cadence and its parked-retry backoff actually apply
            // instead of silently reading 0 and declaring every feed overdue.
            $r['last_fetched_at'] = (int) ($r['last_fetch_at'] ?? 0);
            $r['fetched_at']      = (int) ($r['last_fetch_at'] ?? 0);
            $out[$slug]           = $r;
        }

        return $out;
    }

    /**
     * Normalise registry rows: every feed needs a slug and an http(s) feed URL, and
     * duplicates by slug or by URL are collapsed.
     *
     * @param array<int,mixed> $feeds
     * @return array<int,array<string,mixed>>
     */
    private static function normaliseFeeds(array $feeds): array
    {
        $out   = [];
        $seen  = [];
        $urls  = [];
        foreach ($feeds as $f) {
            if (!is_array($f)) {
                continue;
            }
            $slug = strtolower(trim((string) ($f['slug'] ?? '')));
            $url  = trim((string) ($f['feed'] ?? $f['feed_url'] ?? ''));
            if ($slug === '' || $url === '' || preg_match('#^https?://#i', $url) !== 1) {
                continue;
            }
            if (isset($seen[$slug]) || isset($urls[$url])) {
                continue;
            }
            $seen[$slug] = true;
            $urls[$url]  = true;

            $f['slug'] = $slug;
            $f['feed'] = $url;
            $out[]     = $f;
        }

        return $out;
    }

    private static function maybePrune(PDO $p, array $cfg, string $dataDir, array &$res): void
    {
        $days = (int) ($cfg['ingest']['retention_days'] ?? self::DEFAULT_RETENTION);
        if ($days <= 0) {
            return;
        }

        $stamp = $dataDir . '/prune.state';
        $last  = 0;
        if (is_file($stamp)) {
            $last = (int) trim((string) @file_get_contents($stamp));
        }
        $now = self::nowMs();
        if ($last > 0 && ($now - $last) < self::PRUNE_EVERY_MS) {
            return;
        }

        try {
            Db::pruneOld($p, $days, $now);
            @file_put_contents($stamp, (string) $now, LOCK_EX);
        } catch (\Throwable $e) {
            $res['errors'][] = 'prune: ' . self::short($e->getMessage());
        }
    }

    private static function recordRun(PDO $p, int $startedAt, array &$res, string $runMode, string $notes): void
    {
        try {
            Db::recordIngestRun($p, [
                'started_at'   => $startedAt,
                'finished_at'  => self::nowMs(),
                'run_mode'     => $runMode,
                'feeds_ok'     => (int) $res['feeds_ok'],
                'feeds_failed' => (int) $res['feeds_failed'],
                'inserted'     => (int) $res['inserted'],
                'skipped'      => (int) $res['skipped'],
                'errors'       => $res['errors'],
                'notes'        => $notes,
            ]);
        } catch (\Throwable $e) {
            // The run happened; failing to write its log entry must not undo it.
            $res['errors'][] = 'could not write ingest_runs row: ' . self::short($e->getMessage());
        }
    }

    private static function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    private static function short(string $s): string
    {
        $s = trim((string) preg_replace('/\s+/', ' ', $s));

        return strlen($s) > 200 ? substr($s, 0, 197) . '...' : $s;
    }
}
