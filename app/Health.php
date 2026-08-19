<?php

declare(strict_types=1);

namespace TEB;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * What /healthz answers.
 *
 * THE JOB
 * -------
 * A news aggregator does not fail loudly. It fails by going quiet: a cron job
 * that stopped running, a publisher that started returning 403, a data/
 * directory that lost its write permission after a host migration. The site
 * keeps serving — it just serves yesterday, then last week, and nobody notices
 * until a reader does. This report is the instrument that makes that visible
 * before the client sees it: last ingest run and how long ago, article and
 * source counts, per-desk counts for both a week and a day, every failing or
 * parked feed with the error it actually returned, the database driver and how
 * big it has grown, and the PHP version with anything missing from the build.
 *
 * TWO RULES
 * ---------
 * 1. It never throws. Every block is guarded, and a block that cannot be read
 *    reports its own error instead of taking down the one page whose job is to
 *    tell you what is broken.
 * 2. It writes nothing to the database and takes no lock. In particular it
 *    does NOT try to take the ingest lock to find out whether a run is in
 *    progress: a non-blocking flock() would succeed most of the time, and in
 *    succeeding it would hold the lock for the instant an ingest needed it,
 *    silently skipping that run. The lock FILE is described — exists, when it
 *    was last touched — and that is enough to tell a stuck run from a
 *    finished one.
 *    The one thing this report can cause to be written is Paths' own
 *    mod_rewrite cache, because reading site.rewrite asks Paths whether the
 *    rewrite works and that answer is memoised in data/. It is stated here
 *    rather than hidden, and it is the same file every page render writes.
 *
 * SEVERITY
 * --------
 *   down      the site cannot do its job: database unreadable, no articles at
 *             all, a required extension missing, PHP too old, data/ read-only.
 *   degraded  it is working but something needs attention: ingest has not run,
 *             no new articles, feeds parked, an optional extension missing.
 *   ok        neither.
 *
 * statusCode() maps that to 503 for "down" and 200 for the other two, so an
 * uptime monitor pages on a site that is broken and not on a feed that is
 * merely slow.
 */
final class Health
{
    /** The floor the build targets. Shared hosting runs 8.1–8.3. */
    public const MIN_PHP = '8.0.0';

    /** Without these the application cannot run at all. */
    private const REQUIRED_EXTENSIONS = ['pdo', 'mbstring', 'json', 'libxml', 'SimpleXML'];

    /** Without these it runs, but worse. */
    private const RECOMMENDED_EXTENSIONS = ['curl', 'dom', 'zlib'];

    /** Newer than this and the ingest is considered to be running normally. */
    private const INGEST_STALE_MULTIPLIER = 3;

    /** Below this floor an "ingest is late" warning would fire on a healthy site. */
    private const INGEST_STALE_FLOOR_MINUTES = 45;

    /** No article newer than this many minutes means the desks have gone quiet. */
    private const QUIET_MINUTES = 240;

    /** A run older than this is not "late", it is stopped. */
    private const INGEST_DEAD_MINUTES = 1440;

    /**
     * Show at most this many failing feeds. failing_count reports what
     * Db::health() returned, and that query has a LIMIT of its own — with a
     * registry of a few dozen feeds the two can never disagree, but the number
     * is "how many are failing, up to fifty", not an unbounded total.
     */
    private const MAX_FAILING_LISTED = 50;

    /**
     * The whole picture, in one call and one pass over the database.
     *
     * @param  array<string,mixed> $cfg
     * @return array<string,mixed>
     */
    public static function report(PDO $p, array $cfg = []): array
    {
        $now = self::nowMs();

        $problems = [];
        $warnings = [];

        $php = self::phpBlock($problems, $warnings);
        $db  = self::databaseBlock($p, $cfg, $problems);

        $core = self::coreBlock($p, $problems);

        $articles = self::articlesBlock($core, $cfg, $now, $problems, $warnings);
        $sources  = self::sourcesBlock($core, $warnings);
        $sections = self::sectionsBlock($p, $core, $now);
        $ingest   = self::ingestBlock($core, $cfg, $now, $warnings, $problems);

        $status = $problems !== [] ? 'down' : ($warnings !== [] ? 'degraded' : 'ok');

        return [
            'ok'               => $problems === [],
            'status'           => $status,
            'generated_at'     => $now,
            'generated_at_iso' => self::iso($now, $cfg),
            'site'             => [
                'name'     => self::oneLine(self::conf($cfg, 'site.name', '')),
                'timezone' => self::oneLine(self::conf($cfg, 'site.timezone', 'UTC')),
                'locale'   => self::oneLine(self::conf($cfg, 'site.locale', '')),
                'base'     => class_exists(Paths::class) ? Paths::base() : '',
                'rewrite'  => class_exists(Paths::class) ? Paths::hasRewrite() : null,
            ],
            'php'      => $php,
            'database' => $db,
            'articles' => $articles,
            'sections' => $sections,
            'sources'  => $sources,
            'ingest'   => $ingest,
            'problems' => array_values($problems),
            'warnings' => array_values($warnings),
        ];
    }

    /** 503 when the site cannot do its job, 200 when it can. */
    public static function statusCode(array $report): int
    {
        return ($report['status'] ?? 'ok') === 'down' ? 503 : 200;
    }

    /**
     * The report as JSON for the /healthz route. Pretty-printed because a human
     * reads this in a browser far more often than a machine parses it.
     */
    public static function json(array $report): string
    {
        $json = json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return $json === false ? '{"ok":false,"status":"down","problems":["health report could not be encoded"]}' : $json;
    }

    // =====================================================================
    //  blocks
    // =====================================================================

    /**
     * The one read of the database. Db::health() is the single source of truth
     * for these numbers, so /healthz and any other caller cannot drift apart.
     *
     * @param array<int,string> $problems
     * @return array<string,mixed>
     */
    private static function coreBlock(PDO $p, array &$problems): array
    {
        try {
            $core = Db::health($p);

            return is_array($core) ? $core : [];
        } catch (Throwable $e) {
            $problems[] = 'The database could not be read: ' . self::oneLine($e->getMessage());

            return [];
        }
    }

    /**
     * @param array<int,string> $problems
     * @param array<int,string> $warnings
     * @return array<string,mixed>
     */
    private static function phpBlock(array &$problems, array &$warnings): array
    {
        $missing     = [];
        $missingSoft = [];

        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }
        foreach (self::RECOMMENDED_EXTENSIONS as $ext) {
            if (!extension_loaded($ext)) {
                $missingSoft[] = $ext;
            }
        }

        // The PDO driver in use is only knowable from the connection, so it is
        // checked here against what is compiled in rather than against config.
        $pdoDrivers = PDO::getAvailableDrivers();
        if (!in_array('sqlite', $pdoDrivers, true) && !in_array('mysql', $pdoDrivers, true)) {
            $missing[] = 'pdo_sqlite or pdo_mysql';
        }

        $versionOk = version_compare(PHP_VERSION, self::MIN_PHP, '>=');
        $curl      = extension_loaded('curl');
        $openssl   = extension_loaded('openssl');
        $urlFopen  = self::iniBool('allow_url_fopen');
        $maxExec   = (int) ini_get('max_execution_time');

        if (!$versionOk) {
            $problems[] = 'PHP ' . PHP_VERSION . ' is older than the ' . self::MIN_PHP . ' this build needs.';
        }
        if ($missing !== []) {
            $problems[] = 'PHP is missing required extensions: ' . implode(', ', $missing) . '.';
        }
        if ($missingSoft !== []) {
            $warnings[] = 'PHP is missing recommended extensions: ' . implode(', ', $missingSoft) . '.';
        }
        if (!$curl && !$openssl) {
            // Every feed in the registry is https. Without cURL the fetcher falls
            // back to streams, and streams cannot open TLS without openssl.
            $problems[] = 'Neither cURL nor OpenSSL is available, so no feed can be fetched over https.';
        }
        if (!$curl && !$urlFopen) {
            $problems[] = 'cURL is missing and allow_url_fopen is off, so feeds cannot be fetched at all.';
        }
        if ($maxExec > 0 && $maxExec < 30) {
            $warnings[] = 'max_execution_time is ' . $maxExec . 's, which is short for a full ingest run.';
        }

        return [
            'version'                => PHP_VERSION,
            'version_ok'             => $versionOk,
            'minimum'                => self::MIN_PHP,
            'sapi'                   => PHP_SAPI,
            'extensions_missing'     => $missing,
            'extensions_recommended_missing' => $missingSoft,
            'pdo_drivers'            => array_values($pdoDrivers),
            'memory_limit'           => (string) ini_get('memory_limit'),
            'max_execution_time'     => $maxExec,
            'allow_url_fopen'        => $urlFopen,
        ];
    }

    /**
     * Driver, size on disk, and whether the directory it lives in can still be
     * written to. Size matters on shared hosting, where a quota is a real
     * ceiling and a 30-day archive of 34 feeds is the thing that fills it.
     *
     * @param array<int,string> $problems
     * @return array<string,mixed>
     */
    private static function databaseBlock(PDO $p, array $cfg, array &$problems): array
    {
        $out = [
            'driver'     => 'unknown',
            'size_bytes' => 0,
            'size_human' => '0 B',
            'files'      => [],
            'data_dir'   => '',
            'writable'   => false,
            'error'      => null,
        ];

        try {
            $out['driver'] = (string) $p->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable $e) {
            $out['error'] = self::oneLine($e->getMessage());
        }

        $dataDir = self::dataDir($cfg);

        if ($out['driver'] === 'sqlite') {
            $files = self::sqliteFiles($p, $cfg);
            // The directory that actually holds the database is the one whose
            // write permission decides whether anything can be stored — which
            // is not necessarily the configured data/ when a host has moved it.
            if ($files !== []) {
                $dataDir = dirname($files[0]);
            }
            foreach ($files as $file) {
                if (is_file($file)) {
                    $bytes               = (int) @filesize($file);
                    $out['files'][$file] = $bytes;
                    $out['size_bytes']  += $bytes;
                }
            }
        } elseif ($out['driver'] === 'mysql') {
            try {
                $st = $p->query(
                    'SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes '
                    . 'FROM information_schema.tables WHERE table_schema = DATABASE()'
                );
                $out['size_bytes'] = $st === false ? 0 : (int) $st->fetchColumn();
            } catch (Throwable $e) {
                $out['error'] = self::oneLine($e->getMessage());
            }
            $out['host'] = self::oneLine(self::conf($cfg, 'db.host', ''));
            $out['name'] = self::oneLine(self::conf($cfg, 'db.name', ''));
        }

        $out['data_dir'] = $dataDir;
        $out['writable'] = is_dir($dataDir) && is_writable($dataDir);
        if (!$out['writable']) {
            $problems[] = 'The data directory is not writable, so nothing can be stored: ' . $dataDir;
        }

        $out['size_human'] = self::bytes((int) $out['size_bytes']);

        return $out;
    }

    /**
     * @param  array<string,mixed> $core
     * @param  array<int,string>   $problems
     * @param  array<int,string>   $warnings
     * @return array<string,mixed>
     */
    private static function articlesBlock(
        array $core,
        array $cfg,
        int $now,
        array &$problems,
        array &$warnings
    ): array {
        $total  = (int) ($core['articles'] ?? 0);
        $newest = $core['newest_article_at'] ?? null;
        $newest = ($newest === null || (int) $newest <= 0) ? null : (int) $newest;
        $oldest = $core['oldest_article_at'] ?? null;
        $oldest = ($oldest === null || (int) $oldest <= 0) ? null : (int) $oldest;

        $ageMinutes = $newest === null ? null : (int) floor(($now - $newest) / 60000);

        if ($total === 0) {
            $problems[] = 'There are no articles in the database, so every page is empty.';
        } elseif ($ageMinutes !== null && $ageMinutes > self::QUIET_MINUTES) {
            $warnings[] = 'The newest article is ' . self::duration($ageMinutes)
                . ' old — the site is showing stale news.';
        }

        return [
            'total'              => $total,
            'last_24h'           => (int) ($core['articles_24h'] ?? 0),
            'newest_at'          => $newest,
            'newest_at_iso'      => $newest === null ? null : self::iso($newest, $cfg),
            'newest_age_minutes' => $ageMinutes,
            'oldest_at'          => $oldest,
            'oldest_at_iso'      => $oldest === null ? null : self::iso($oldest, $cfg),
            'retention_days'     => max(1, (int) self::conf($cfg, 'ingest.retention_days', 30)),
        ];
    }

    /**
     * Per-desk counts over a week and over a day. Every desk the registry knows
     * about appears, including the ones sitting at zero — a desk that has gone
     * quiet is invisible in a report that only lists what it found.
     *
     * @param  array<string,mixed> $core
     * @return array<string,array<string,int>>
     */
    private static function sectionsBlock(PDO $p, array $core, int $now): array
    {
        $week = is_array($core['sections'] ?? null) ? $core['sections'] : [];

        $day = [];
        try {
            $day = Db::sectionCounts($p, $now - 86400000);
        } catch (Throwable $e) {
            $day = [];
        }

        $slugs = array_keys($week + $day);
        if (class_exists(Feeds::class)) {
            $slugs = array_merge(array_keys(Feeds::sections()), $slugs);
        }

        $out  = [];
        $seen = [];
        foreach ($slugs as $slug) {
            $slug = (string) $slug;
            if ($slug === '' || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $out[$slug]  = [
                'last_7_days' => (int) ($week[$slug] ?? 0),
                'last_24h'    => (int) ($day[$slug] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Feeds, and the ones that are not working. A parked feed is one the
     * ingester has given up retrying at full speed after repeated failures; it
     * is the single most useful line in this whole report, because it is where
     * a section quietly loses its supply.
     *
     * @param  array<string,mixed> $core
     * @param  array<int,string>   $warnings
     * @return array<string,mixed>
     */
    private static function sourcesBlock(array $core, array &$warnings): array
    {
        $known  = (int) ($core['sources'] ?? 0);
        $active = (int) ($core['sources_active'] ?? 0);

        $failingRaw = is_array($core['sources_failing'] ?? null) ? $core['sources_failing'] : [];
        $failing    = [];
        $parked     = 0;

        foreach ($failingRaw as $f) {
            if (!is_array($f)) {
                continue;
            }
            $isParked = !empty($f['parked']);
            if ($isParked) {
                $parked++;
            }
            if (count($failing) < self::MAX_FAILING_LISTED) {
                $failing[] = [
                    'slug'          => (string) ($f['slug'] ?? ''),
                    'name'          => (string) ($f['name'] ?? ''),
                    'fail_count'    => (int) ($f['fail_count'] ?? 0),
                    'parked'        => $isParked,
                    'last_error'    => self::oneLine((string) ($f['last_error'] ?? '')),
                    'last_fetch_at' => (int) ($f['last_fetch_at'] ?? 0),
                ];
            }
        }

        $registry = class_exists(Feeds::class) ? count(Feeds::all()) : 0;

        if ($parked > 0) {
            $names = [];
            foreach ($failing as $f) {
                if ($f['parked']) {
                    $names[] = $f['slug'];
                }
            }
            $warnings[] = $parked . ' feed' . ($parked === 1 ? ' is' : 's are')
                . ' parked after repeated failures: ' . implode(', ', array_slice($names, 0, 12)) . '.';
        }
        if ($registry > 0 && $known < $registry) {
            $warnings[] = ($registry - $known) . ' feed(s) in the registry have never been fetched.';
        }
        if ($active > 0 && count($failingRaw) > 0 && count($failingRaw) >= (int) ceil($active / 2)) {
            $warnings[] = 'Half or more of the active feeds are currently failing.';
        }

        return [
            'registry'      => $registry,
            'known'         => $known,
            'active'        => $active,
            'parked'        => $parked,
            'failing_count' => count($failingRaw),
            'failing'       => $failing,
        ];
    }

    /**
     * The last run, how late it is, and the lock file — described, never taken.
     *
     * @param  array<string,mixed> $core
     * @param  array<int,string>   $warnings
     * @param  array<int,string>   $problems
     * @return array<string,mixed>
     */
    private static function ingestBlock(
        array $core,
        array $cfg,
        int $now,
        array &$warnings,
        array &$problems
    ): array {
        $enabled   = (bool) self::conf($cfg, 'ingest.enabled', true);
        $staleMins = max(1, (int) self::conf($cfg, 'ingest.stale_after_minutes', 20));

        $last = is_array($core['last_ingest'] ?? null) ? $core['last_ingest'] : null;

        $ageMinutes = null;
        if ($last !== null) {
            $finished = (int) ($last['finished_at'] ?? 0);
            if ($finished <= 0) {
                $finished = (int) ($last['started_at'] ?? 0);
            }
            if ($finished > 0) {
                $ageMinutes = (int) floor(($now - $finished) / 60000);
            }
            $last['started_at_iso']  = ((int) ($last['started_at'] ?? 0)) > 0
                ? self::iso((int) $last['started_at'], $cfg) : null;
            $last['finished_at_iso'] = ((int) ($last['finished_at'] ?? 0)) > 0
                ? self::iso((int) $last['finished_at'], $cfg) : null;
        }

        $lateAfter = max(self::INGEST_STALE_FLOOR_MINUTES, $staleMins * self::INGEST_STALE_MULTIPLIER);
        $late      = $ageMinutes !== null && $ageMinutes > $lateAfter;

        if (!$enabled) {
            $warnings[] = 'Ingestion is switched off in the configuration, so no new stories will arrive.';
        } elseif ($last === null) {
            $warnings[] = 'No ingest run has ever been recorded — check the cron job.';
        } elseif ($ageMinutes !== null && $ageMinutes > self::INGEST_DEAD_MINUTES) {
            $problems[] = 'The last ingest run finished ' . self::duration($ageMinutes)
                . ' ago — the cron job has stopped.';
        } elseif ($late) {
            $warnings[] = 'The last ingest run finished ' . self::duration((int) $ageMinutes)
                . ' ago, later than the ' . self::duration($lateAfter) . ' expected.';
        }

        // Ingest writes "<pid> <iso8601>" into the lock file when it takes it,
        // so reading the first line — which takes no lock — says who is holding
        // it and since when.
        $lockPath   = self::dataDir($cfg) . '/ingest.lock';
        $lockExists = is_file($lockPath);
        $lockAge    = $lockExists ? max(0, time() - (int) @filemtime($lockPath)) : null;
        $lockNote   = null;
        if ($lockExists) {
            $head     = (string) @file_get_contents($lockPath, false, null, 0, 120);
            $lockNote = self::oneLine($head);
            if ($lockNote === '') {
                $lockNote = null;
            }
        }

        return [
            'enabled'              => $enabled,
            'auto_on_empty'        => (bool) self::conf($cfg, 'ingest.auto_on_empty', true),
            'stale_after_minutes'  => $staleMins,
            'late_after_minutes'   => $lateAfter,
            'late'                 => $late,
            'age_minutes'          => $ageMinutes,
            'last_run'             => $last,
            // The lock file is only described. Taking it, even for an instant,
            // would make a concurrent ingest skip its run.
            'lock_file_exists'     => $lockExists,
            'lock_file_age_seconds' => $lockAge,
            'lock_file_note'       => $lockNote,
        ];
    }

    // =====================================================================
    //  helpers
    // =====================================================================

    /**
     * Every file SQLite keeps for this database. The -wal file is regularly
     * larger than the database itself and counts against the same quota, so a
     * size report that ignored it would understate the real footprint.
     *
     * @return array<int,string>
     */
    private static function sqliteFiles(PDO $p, array $cfg): array
    {
        $path = '';

        try {
            $st = $p->query('PRAGMA database_list');
            if ($st !== false) {
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if ((string) ($row['name'] ?? '') === 'main' && (string) ($row['file'] ?? '') !== '') {
                        $path = (string) $row['file'];
                        break;
                    }
                }
            }
        } catch (Throwable $e) {
            $path = '';
        }

        if ($path === '') {
            $rel  = (string) self::conf($cfg, 'db.sqlite_path', 'data/news.sqlite');
            $path = self::isAbsolute($rel) ? $rel : self::rootDir() . '/' . ltrim($rel, '/\\');
        }

        return [$path, $path . '-wal', $path . '-shm'];
    }

    private static function dataDir(array $cfg): string
    {
        if (class_exists(Paths::class)) {
            $dir = Paths::dataDir();
            if ($dir !== '') {
                return $dir;
            }
        }
        $rel = (string) self::conf($cfg, 'db.sqlite_path', 'data/news.sqlite');
        $dir = self::isAbsolute($rel) ? dirname($rel) : self::rootDir() . '/' . trim(dirname($rel), '/\\.');

        return rtrim($dir, '/\\');
    }

    private static function rootDir(): string
    {
        if (class_exists(Config::class) && Config::rootDir() !== '') {
            return rtrim(Config::rootDir(), '/\\');
        }

        return dirname(__DIR__);
    }

    private static function isAbsolute(string $path): bool
    {
        return $path !== ''
            && ($path[0] === '/' || $path[0] === '\\' || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1);
    }

    private static function iniBool(string $key): bool
    {
        $v = strtolower(trim((string) ini_get($key)));

        return $v === '1' || $v === 'on' || $v === 'true' || $v === 'yes';
    }

    /** '1.4 MB' — for a human reading the page, alongside the exact byte count. */
    private static function bytes(int $n): string
    {
        if ($n < 1024) {
            return $n . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $n / 1024;
        $i     = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, $value < 10 ? 1 : 0) . ' ' . $units[$i];
    }

    /** '3 minutes' / '2 hours' / '4 days' — plain words, no abbreviations. */
    private static function duration(int $minutes): string
    {
        $minutes = max(0, $minutes);
        if ($minutes < 60) {
            return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
        }
        $hours = (int) floor($minutes / 60);
        if ($hours < 48) {
            return $hours . ' hour' . ($hours === 1 ? '' : 's');
        }
        $days = (int) floor($hours / 24);

        return $days . ' day' . ($days === 1 ? '' : 's');
    }

    private static function iso(int $ms, array $cfg): string
    {
        try {
            $tzName = (string) self::conf($cfg, 'site.timezone', 'UTC');
            $tz     = new DateTimeZone($tzName !== '' ? $tzName : 'UTC');

            return (new DateTimeImmutable('@' . intdiv(max(0, $ms), 1000)))
                ->setTimezone($tz)
                ->format(DateTimeInterface::ATOM);
        } catch (Throwable $e) {
            return (new DateTimeImmutable('@' . intdiv(max(0, $ms), 1000)))
                ->format(DateTimeInterface::ATOM);
        }
    }

    private static function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    private static function oneLine($v): string
    {
        if (!is_string($v)) {
            $v = is_scalar($v) ? (string) $v : '';
        }
        $v = preg_replace('/\s+/u', ' ', $v);

        return trim(is_string($v) ? $v : '');
    }

    /**
     * @param  array<string,mixed> $cfg
     * @param  mixed               $default
     * @return mixed
     */
    private static function conf(array $cfg, string $dotPath, $default = null)
    {
        $node  = $cfg;
        $found = true;
        foreach (explode('.', $dotPath) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                $found = false;
                break;
            }
            $node = $node[$key];
        }
        if ($found) {
            return $node;
        }
        if (class_exists(Config::class)) {
            return Config::get($dotPath, $default);
        }

        return $default;
    }
}
