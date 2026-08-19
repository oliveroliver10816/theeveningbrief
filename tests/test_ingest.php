<?php

declare(strict_types=1);

/**
 * app/Ingest.php — fetching, the failure ledger, and the run lock.
 *
 * Everything here runs against a real HTTP server (php -S on 127.0.0.1, serving copies of
 * the fixtures) and a real SQLite database in a temp directory. Nothing reaches the public
 * internet, so the suite is deterministic and works offline, but the code under test still
 * goes through cURL, redirects, gzip, the parser and the writer exactly as it does in
 * production.
 */

require_once __DIR__ . '/lib.php';
teb_require_app('Xml', 'Db', 'Ingest');

use TEB\Db;
use TEB\Ingest;

/**
 * Start (once) a local web server over a directory of feed fixtures.
 *
 * @return array{base:string,dir:string}
 */
function ti_server(): array
{
    static $server = null;
    if ($server !== null) {
        return $server;
    }

    $dir = teb_tmp_dir('teb-feeds');
    $fx  = __DIR__ . '/fixtures/';
    copy($fx . 'media-thumbnail.xml', $dir . '/good1.xml');
    copy($fx . 'rss2-media-content.xml', $dir . '/good2.xml');
    copy($fx . 'rdf.xml', $dir . '/good3.xml');
    copy($fx . 'malformed.xml', $dir . '/junk.xml');
    file_put_contents($dir . '/empty.xml', '');
    file_put_contents($dir . '/page.html', '<!doctype html><html><body><h1>Not a feed</h1></body></html>');

    $server = ['base' => ti_serve_dir($dir), 'dir' => $dir];

    return $server;
}

/**
 * Start a `php -S` document root and return its base URL (with a trailing slash).
 *
 * Each call gets its own process and its own port, so the fixture server and the server
 * running cron/ingest.php are genuinely separate: php -S handles one request at a time,
 * and a script that curled its OWN server would deadlock.
 */
function ti_serve_dir(string $dir): string
{
    // Ask the OS for a free port, then hand it straight to php -S.
    $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        teb_fail('could not open a probe socket to find a free port: ' . $errstr);
    }
    $name = (string) stream_socket_get_name($probe, false);
    $port = (int) substr($name, (int) strrpos($name, ':') + 1);
    fclose($probe);

    $cmd   = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($dir);
    $pipes = [];
    $proc  = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
    if (!is_resource($proc)) {
        teb_fail('could not start php -S on port ' . $port);
    }

    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $sock = @fsockopen('127.0.0.1', $port, $eno, $estr, 0.2);
        if (is_resource($sock)) {
            fclose($sock);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    if (!$ready) {
        proc_terminate($proc);
        teb_fail('php -S never came up on 127.0.0.1:' . $port);
    }

    register_shutdown_function(static function () use ($proc, $pipes): void {
        foreach ($pipes as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
        proc_terminate($proc, 9);
        proc_close($proc);
    });

    return 'http://127.0.0.1:' . $port . '/';
}

/**
 * One plain GET, with the status line and the body kept apart.
 *
 * @return array{status:int,body:string,content_type:string}
 */
function ti_http(string $url): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,   // a redirect here would itself be the bug
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_FAILONERROR    => false,
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type   = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err    = curl_error($ch);
    curl_close($ch);

    if (!is_string($body)) {
        teb_fail('request to ' . $url . ' failed: ' . $err);
    }

    return ['status' => $status, 'body' => (string) $body, 'content_type' => $type];
}

/** A config pointing at the local server, with its own SQLite file. */
function ti_cfg(array $overrides = []): array
{
    $srv  = ti_server();
    $root = teb_tmp_dir('teb-run');

    $cfg = [
        'root' => $root,
        'site' => [
            'name'       => 'Probe Paper',
            'short_name' => 'PP',
            'domain'     => 'probe.example',
            'locale'     => 'en-US',
        ],
        'db' => ['driver' => 'sqlite', 'sqlite_path' => $root . '/news.sqlite'],
        'ingest' => [
            'enabled'              => true,
            'timeout_seconds'      => 8,
            'batch'                => 20,
            'retention_days'       => 30,
            'run_mode'             => 'test',
            'min_interval_minutes' => 0,
        ],
        'feeds' => [
            ['slug' => 'good-one', 'name' => 'Good One', 'feed' => $srv['base'] . 'good1.xml', 'section' => 'world', 'tier' => 1, 'weight' => 1.2],
            ['slug' => 'good-two', 'name' => 'Good Two', 'feed' => $srv['base'] . 'good2.xml', 'section' => 'us', 'tier' => 1, 'weight' => 1.0],
            ['slug' => 'good-three', 'name' => 'Good Three', 'feed' => $srv['base'] . 'good3.xml', 'section' => 'international', 'tier' => 2, 'weight' => 1.0],
            ['slug' => 'gone', 'name' => 'Gone', 'feed' => $srv['base'] . 'does-not-exist.xml', 'section' => 'us', 'tier' => 3, 'weight' => 0.5],
            ['slug' => 'refused', 'name' => 'Refused', 'feed' => 'http://127.0.0.1:1/feed.xml', 'section' => 'us', 'tier' => 3, 'weight' => 0.5],
        ],
    ];

    foreach ($overrides as $k => $v) {
        $cfg[$k] = is_array($v) && isset($cfg[$k]) && is_array($cfg[$k]) ? array_merge($cfg[$k], $v) : $v;
    }

    return $cfg;
}

function ti_db(array $cfg): PDO
{
    $pdo = Db::connect($cfg);
    Db::migrate($pdo);

    return $pdo;
}

/** Run a snippet in a separate PHP process, returning [stdout, exitCode]. */
function ti_php(string $code): array
{
    $file = teb_tmp_dir('teb-proc') . '/snippet.php';
    file_put_contents($file, "<?php\n" . $code);
    $out  = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);

    return [implode("\n", $out), $code];
}

return [

    // ------------------------------------------------------------ user agent

    'the User-Agent identifies the site from config and carries a contact URL' => function (): void {
        $ua = Ingest::userAgent([
            'site' => ['name' => 'The Daily Something', 'short_name' => 'TDS', 'domain' => 'daily.example'],
        ]);

        assertContains('TDSBot/1.0', $ua, 'the site names itself');
        assertContains('+https://daily.example/about', $ua, 'and says where to complain');
        assertContains('compatible;', $ua, 'standard bot UA shape');

        // Change the config, the UA changes: nothing about the brand is compiled in.
        $other = Ingest::userAgent(['site' => ['short_name' => 'ZZQ', 'domain' => 'other.example']]);
        assertContains('ZZQBot', $other);
        assertNotContains('TDS', $other);

        // No config at all still produces a valid, brand-free UA.
        $bare = Ingest::userAgent([]);
        assertNotSame('', $bare);
        assertNotContains('+https://', $bare, 'no invented contact URL');
        assertNotContains('  ', $bare);
    },

    'the User-Agent survives a hostile config value' => function (): void {
        $ua = Ingest::userAgent([
            'site' => ['short_name' => "Bad\r\nInjected: yes", 'domain' => "evil.example\r\nX-Bad: 1"],
        ]);
        assertNotContains("\r", $ua, 'no CR can reach a request header');
        assertNotContains("\n", $ua, 'no LF either');
        assertContains('BadInjectedyesBot', $ua);
    },

    // ---------------------------------------------------------------- fetch

    'fetch: a real 200 over HTTP, with the body intact' => function (): void {
        $srv = ti_server();
        $r   = Ingest::fetch($srv['base'] . 'good1.xml', ti_cfg());

        assertTrue($r['ok'], 'error was: ' . $r['error']);
        assertSame(200, $r['status']);
        assertSame('', $r['error']);
        assertGreaterThan(1000, $r['bytes']);
        assertContains('<rss', $r['body']);
        assertContains('Sacked Ukrainian defence minister', $r['body']);
    },

    'fetch: 404, an empty body and a refused connection each fail cleanly' => function (): void {
        $srv = ti_server();
        $cfg = ti_cfg();

        $missing = Ingest::fetch($srv['base'] . 'does-not-exist.xml', $cfg);
        assertFalse($missing['ok']);
        assertSame(404, $missing['status']);
        assertContains('HTTP 404', $missing['error']);

        $empty = Ingest::fetch($srv['base'] . 'empty.xml', $cfg);
        assertFalse($empty['ok']);
        assertSame(200, $empty['status'], 'the server did answer');
        assertContains('empty response', $empty['error']);

        $refused = Ingest::fetch('http://127.0.0.1:1/feed.xml', $cfg);
        assertFalse($refused['ok']);
        assertNotSame('', $refused['error']);
        assertSame('', $refused['body']);
    },

    'fetch: anything that is not http(s) is refused before a request is made' => function (): void {
        foreach (['', '   ', 'file:///etc/passwd', 'ftp://example.org/f.xml', 'javascript:alert(1)', '/local/path'] as $bad) {
            $r = Ingest::fetch($bad, ti_cfg());
            assertFalse($r['ok'], 'accepted: ' . $bad);
            assertSame('not an http(s) url', $r['error'], 'for: ' . $bad);
            assertSame(0, $r['status']);
        }
    },

    // ----------------------------------------------------------------- lock

    'lock: a second process cannot take a lock this one holds' => function (): void {
        $dir  = teb_tmp_dir('teb-lock');
        $app  = teb_root() . '/app/Ingest.php';
        $snippet = 'require ' . var_export($app, true) . ';'
            . '$h = TEB\Ingest::lock(' . var_export($dir, true) . ');'
            . 'echo $h === null ? "BUSY" : "LOCKED";';

        [$before] = ti_php($snippet);
        assertSame('LOCKED', $before, 'nothing holds it yet');

        $handle = Ingest::lock($dir);
        assertTrue(is_resource($handle), 'we take the lock');
        assertTrue(Ingest::holdsLock());
        assertFileExists($dir . '/ingest.lock');

        [$during] = ti_php($snippet);
        assertSame('BUSY', $during, 'a concurrent run is locked out — this is what stops a double ingest');

        assertSame($handle, Ingest::lock($dir), 'asking twice in one process returns the same handle');

        Ingest::unlock();
        assertFalse(Ingest::holdsLock());

        [$after] = ti_php($snippet);
        assertSame('LOCKED', $after, 'released cleanly');
    },

    'lock: an unwritable directory yields null, not a fatal' => function (): void {
        $r = Ingest::lock('/proc/definitely-not-writable/teb');
        assertNull($r);
        Ingest::unlock();
    },

    // ------------------------------------------------------------------ run

    'run: three good feeds and two broken ones — the broken ones do not stop it' => function (): void {
        $cfg = ti_cfg();
        $pdo = ti_db($cfg);

        $res = Ingest::run($pdo, $cfg);

        assertSame(5, $res['feeds_attempted']);
        assertSame(3, $res['feeds_ok']);
        assertSame(2, $res['feeds_failed']);
        assertSame(11, $res['inserted'], '3 + 4 + 4 items across the three good fixtures');
        assertSame(0, $res['skipped']);
        assertCount(2, $res['errors'], 'one error per broken feed: ' . json_encode($res['errors']));
        assertFalse($res['locked_out']);
        assertGreaterThan(0, $res['duration_ms']);

        $n = (int) $pdo->query('SELECT COUNT(*) FROM articles')->fetchColumn();
        assertSame($res['inserted'], $n, 'the count it reports is the count in the table');

        // Sections and source names ride along from the registry, not from the feed.
        $row = $pdo->query("SELECT * FROM articles WHERE source_slug = 'good-one' LIMIT 1")->fetch();
        assertSame('world', $row['section']);
        assertSame('Good One', $row['source_name']);
        assertNotSame('', $row['title']);
        assertNotSame('', $row['url']);
        assertGreaterThan(1000000000000, (int) $row['published_at'], 'ms epoch');
    },

    'run: the ingest_runs row is written with honest counts' => function (): void {
        $cfg = ti_cfg();
        $pdo = ti_db($cfg);
        $res = Ingest::run($pdo, $cfg);

        $run = $pdo->query('SELECT * FROM ingest_runs ORDER BY id DESC LIMIT 1')->fetch();
        assertTrue(is_array($run), 'a run row exists');
        // Absolute numbers first: comparing the row only against what run() just returned
        // would still pass if BOTH were wrong.
        assertSame(3, (int) $run['feeds_ok'], 'three fixtures really did parse');
        assertSame(2, (int) $run['feeds_failed'], 'the 404 and the refused connection');
        assertSame(11, (int) $run['inserted']);
        assertSame(0, (int) $run['skipped']);
        assertSame($res['feeds_ok'], (int) $run['feeds_ok']);
        assertSame($res['feeds_failed'], (int) $run['feeds_failed']);
        assertSame($res['inserted'], (int) $run['inserted']);
        assertSame($res['skipped'], (int) $run['skipped']);
        assertSame('test', $run['run_mode']);
        assertGreaterThan(0, (int) $run['started_at']);
        assertGreaterThanOrEqual((int) $run['started_at'], (int) $run['finished_at']);
        assertContains('gone', (string) $run['errors'], 'the failing slug is named in the log');
    },

    'run: the second pass inserts nothing and reports the duplicates' => function (): void {
        $cfg = ti_cfg();
        $pdo = ti_db($cfg);

        $first  = Ingest::run($pdo, $cfg);
        $second = Ingest::run($pdo, $cfg);

        assertGreaterThan(0, $first['inserted']);
        assertSame(0, $second['inserted'], 'the same feed twice is not news twice');
        assertSame($first['inserted'], $second['skipped'], 'every item is recognised as already seen');
        assertSame(3, $second['feeds_ok']);
        assertSame(
            $first['inserted'],
            (int) $pdo->query('SELECT COUNT(*) FROM articles')->fetchColumn()
        );
    },

    'run: a feed fetched a moment ago is skipped by the minimum interval' => function (): void {
        $cfg = ti_cfg(['ingest' => ['min_interval_minutes' => 30]]);
        $pdo = ti_db($cfg);

        $first  = Ingest::run($pdo, $cfg);
        $second = Ingest::run($pdo, $cfg);

        assertSame(5, $first['feeds_attempted']);
        assertSame(0, $second['feeds_attempted'], 'nothing is due yet');
        assertSame([], $second['errors']);
    },

    'run: the batch size is a hard bound on one pass' => function (): void {
        $cfg = ti_cfg(['ingest' => ['batch' => 2]]);
        $pdo = ti_db($cfg);

        $res = Ingest::run($pdo, $cfg);
        assertSame(2, $res['feeds_attempted'], 'a shared host is never asked to fetch five feeds at once');

        // The next pass picks up where it left off: least-recently-fetched first.
        $next = Ingest::run($pdo, $cfg);
        assertSame(2, $next['feeds_attempted']);
        $fetched = (int) $pdo->query('SELECT COUNT(*) FROM sources WHERE last_fetch_at > 0')->fetchColumn();
        assertSame(4, $fetched, 'four distinct feeds have been tried across two passes');
    },

    // --------------------------------------------------------------- parking

    'a feed is parked after eight consecutive failures, and the parking is logged' => function (): void {
        $cfg = ti_cfg();
        $pdo = ti_db($cfg);

        Db::upsertSources($pdo, $cfg['feeds']);
        // Seven failures already on the record; this run is the eighth.
        $pdo->prepare('UPDATE sources SET fail_count = 7 WHERE slug = ?')->execute(['gone']);

        $res = Ingest::run($pdo, $cfg);

        assertContains('gone', $res['parked'], 'it is reported as parked');
        $logged = implode(' | ', $res['errors']);
        assertContains('parked after 8 consecutive failures', $logged, 'and the reason is written down');

        $source = Db::sourceBySlug($pdo, 'gone');
        assertSame(8, (int) $source['fail_count']);
        assertSame(0, (int) $source['active'], 'parked means inactive');

        // Parked feeds are not tried again on the next pass.
        $next = Ingest::run($pdo, $cfg);
        assertSame(4, $next['feeds_attempted'], 'five feeds, one parked');
        assertNotContains('gone: ', implode(' | ', $next['errors']));
    },

    'a parked feed is retried after the backoff, and one success revives it' => function (): void {
        $cfg = ti_cfg();
        $pdo = ti_db($cfg);

        Db::upsertSources($pdo, $cfg['feeds']);
        // Parked seven hours ago: past the six-hour retry window.
        $sevenHoursAgo = (int) round(microtime(true) * 1000) - (7 * 3600 * 1000);
        $pdo->prepare('UPDATE sources SET fail_count = 9, active = 0, last_fetch_at = ? WHERE slug = ?')
            ->execute([$sevenHoursAgo, 'good-one']);

        $res = Ingest::run($pdo, $cfg);

        assertSame(5, $res['feeds_attempted'], 'the parked feed gets one retry, or parking is permanent');

        $source = Db::sourceBySlug($pdo, 'good-one');
        assertSame(1, (int) $source['active'], 'one success brings it back');
        assertSame(0, (int) $source['fail_count']);
        assertGreaterThan(0, (int) $pdo->query("SELECT COUNT(*) FROM articles WHERE source_slug = 'good-one'")->fetchColumn());
    },

    'a feed list supplied in config is never overridden by the built-in registry' => function (): void {
        // Feeds::due() answers about the 35-feed production roster. If it were consulted
        // for a config that carries its own list, a test — or a caller with a custom
        // registry — would silently fetch the wrong sites.
        teb_require_app('Feeds');
        assertTrue(class_exists('TEB\\Feeds'), 'the registry is loaded for this test');
        assertGreaterThan(20, count(TEB\Feeds::all()), 'and it really does hold the full roster');

        $cfg = ti_cfg();
        $pdo = ti_db($cfg);

        $res = Ingest::run($pdo, $cfg);

        assertSame(5, $res['feeds_attempted'], 'exactly the five feeds from config');
        $slugs = array_column(Db::sources($pdo), 'slug');
        sort($slugs);
        assertSame(['gone', 'good-one', 'good-three', 'good-two', 'refused'], $slugs);
    },

    'one success revives a feed that was failing' => function (): void {
        $cfg = ti_cfg();
        $pdo = ti_db($cfg);

        Db::upsertSources($pdo, $cfg['feeds']);
        $pdo->prepare('UPDATE sources SET fail_count = 5 WHERE slug = ?')->execute(['good-one']);

        Ingest::run($pdo, $cfg);

        $source = Db::sourceBySlug($pdo, 'good-one');
        assertSame(0, (int) $source['fail_count'], 'the counter resets');
        assertSame(1, (int) $source['active']);
        assertSame('', (string) $source['last_error']);
        assertGreaterThan(0, (int) $source['last_ok_at']);
    },

    'an HTTP 200 that is not a feed counts as a failure, not as a silent success' => function (): void {
        $srv = ti_server();
        $cfg = ti_cfg();
        $cfg['feeds'] = [
            ['slug' => 'html-page', 'name' => 'HTML Page', 'feed' => $srv['base'] . 'page.html', 'section' => 'us', 'tier' => 3],
            ['slug' => 'junk-xml', 'name' => 'Junk XML', 'feed' => $srv['base'] . 'junk.xml', 'section' => 'us', 'tier' => 3],
        ];
        $pdo = ti_db($cfg);

        $res = Ingest::run($pdo, $cfg);

        assertSame(0, $res['feeds_ok']);
        assertSame(2, $res['feeds_failed']);
        assertSame(0, $res['inserted']);
        assertContains('no items parsed', implode(' | ', $res['errors']));
        assertSame(1, (int) Db::sourceBySlug($pdo, 'html-page')['fail_count']);
        assertSame(1, (int) Db::sourceBySlug($pdo, 'junk-xml')['fail_count']);
    },

    // ------------------------------------------------------- switches and locks

    'ingest.enabled = false stops the run before any request' => function (): void {
        $cfg = ti_cfg(['ingest' => ['enabled' => false]]);
        $pdo = ti_db($cfg);

        $res = Ingest::run($pdo, $cfg);

        assertTrue($res['disabled']);
        assertSame(0, $res['feeds_attempted']);
        assertSame(0, $res['inserted']);
        assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM articles')->fetchColumn());

        $run = $pdo->query('SELECT * FROM ingest_runs ORDER BY id DESC LIMIT 1')->fetch();
        assertSame('disabled in config', (string) $run['notes'], 'the skipped run is still on the record');
    },

    'a run that is locked out by another process changes nothing' => function (): void {
        $cfg  = ti_cfg();
        $pdo  = ti_db($cfg);
        $dir  = Ingest::dataDir($cfg);
        @mkdir($dir, 0777, true);

        // A second process holds the lock for a moment.
        $app     = teb_root() . '/app/Ingest.php';
        $snippet = 'require ' . var_export($app, true) . ';'
            . '$h = TEB\Ingest::lock(' . var_export($dir, true) . ');'
            . 'if ($h === null) { echo "BUSY\n"; exit(1); }'
            . 'echo "LOCKED\n"; @ob_flush(); flush(); usleep(2500000);';

        $file = teb_tmp_dir('teb-holder') . '/hold.php';
        file_put_contents($file, "<?php\n" . $snippet);
        $pipes = [];
        $proc  = proc_open(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']],
            $pipes
        );
        assertTrue(is_resource($proc), 'could not start the lock holder');

        try {
            $line = fgets($pipes[1]);
            assertSame("LOCKED\n", $line, 'the other process took the lock');

            $res = Ingest::run($pdo, $cfg);

            assertTrue($res['locked_out'], 'we backed off');
            assertSame(0, $res['feeds_attempted']);
            assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM articles')->fetchColumn());
            assertSame(
                0,
                (int) $pdo->query('SELECT COUNT(*) FROM ingest_runs')->fetchColumn(),
                'a run that never ran is not logged as a run'
            );
        } finally {
            foreach ($pipes as $p) {
                if (is_resource($p)) {
                    fclose($p);
                }
            }
            proc_terminate($proc, 9);
            proc_close($proc);
        }
    },

    // --------------------------------------------------------------- helpers

    'rowsFor maps feed items onto article rows and drops the unusable ones' => function (): void {
        $feed  = ['slug' => 'src', 'name' => 'Source Name', 'section' => 'us'];
        $items = [
            ['guid' => 'g1', 'url' => 'https://example.org/a', 'title' => 'A', 'summary' => 'S', 'image_url' => 'https://example.org/a.jpg', 'published_at' => 1787130959000, 'author' => 'Writer'],
            ['guid' => 'g2', 'url' => '', 'title' => 'No url', 'summary' => '', 'image_url' => '', 'published_at' => null, 'author' => ''],
            ['guid' => 'g3', 'url' => 'https://example.org/c', 'title' => '', 'summary' => '', 'image_url' => '', 'published_at' => null, 'author' => ''],
            ['guid' => 'g4', 'url' => 'https://example.org/d', 'title' => 'D', 'summary' => '', 'image_url' => '', 'published_at' => null, 'author' => ''],
        ];

        $rows = Ingest::rowsFor($feed, $items, 1700000000000, ['id' => 7]);

        assertCount(2, $rows, 'the item with no url and the one with no title are dropped');
        assertSame(7, $rows[0]['source_id']);
        assertSame('src', $rows[0]['source_slug']);
        assertSame('Source Name', $rows[0]['source_name']);
        assertSame('us', $rows[0]['section']);
        assertSame('g1', $rows[0]['guid']);
        assertSame(1787130959000, $rows[0]['published_at']);
        assertSame(1700000000000, $rows[0]['fetched_at']);
        assertSame(0, $rows[1]['published_at'], 'unknown date is 0 here; Db turns it into the fetch time');

        $capped = Ingest::rowsFor($feed, array_fill(0, 50, $items[0]), 1700000000000, [], 5);
        assertCount(5, $capped, 'max items per feed is honoured');
    },

    'dataDir is derived from config and is always absolute' => function (): void {
        $root = '/var/www/example';

        assertSame($root . '/data', Ingest::dataDir(['root' => $root]));
        assertSame($root . '/data', Ingest::dataDir(['root' => $root, 'db' => ['sqlite_path' => 'data/news.sqlite']]));
        assertSame($root . '/store', Ingest::dataDir(['root' => $root, 'db' => ['sqlite_path' => 'store/news.sqlite']]));
        assertSame('/srv/shared', Ingest::dataDir(['root' => $root, 'db' => ['sqlite_path' => '/srv/shared/news.sqlite']]));
        assertSame('/srv/writable', Ingest::dataDir(['root' => $root, 'paths' => ['data' => '/srv/writable']]));
        assertMatches('#^/#', Ingest::dataDir([]), 'always absolute, even with no config at all');
    },

    'feedList narrows to --only, and an explicit run ignores parking' => function (): void {
        $cfg = ti_cfg();

        assertCount(5, Ingest::feedList($cfg));
        assertCount(2, Ingest::feedList($cfg, ['good-one', 'good-two']));
        assertSame('good-two', Ingest::feedList($cfg, ['good-two'])[0]['slug']);
        assertCount(0, Ingest::feedList($cfg, ['no-such-slug']));

        // A parked feed is skipped normally but fetched when a human asks for it by name.
        // (A real parked feed has always been fetched — that is how it failed eight times.)
        $now   = 1787138940000;
        $state = ['good-one' => ['active' => 0, 'fail_count' => 9, 'last_fetch_at' => $now - 60000, 'last_ok_at' => 0]];
        $auto  = Ingest::selectDue($cfg['feeds'], $state, $now, $cfg, false);
        $slugs = array_column($auto, 'slug');
        assertNotContains('good-one', $slugs, 'parked feeds are left alone');

        $forced = Ingest::selectDue(Ingest::feedList($cfg, ['good-one']), $state, $now, $cfg, true);
        assertCount(1, $forced);
        assertSame('good-one', $forced[0]['slug']);
    },

    'an explicit --only list is never silently sliced down to ingest.batch' => function (): void {
        // The bounded batch protects a shared host from an UNATTENDED pass. A human typing
        // --only=a,b,c,... is attended, and reading "3 attempted" after naming eight feeds
        // means believing five sources were refreshed when they were never touched.
        $feeds = [];
        for ($i = 1; $i <= 8; $i++) {
            $feeds[] = ['slug' => 'named-' . $i, 'name' => 'Named ' . $i, 'feed' => 'https://example.org/' . $i . '.xml', 'section' => 'us'];
        }
        $cfg   = ti_cfg(['ingest' => ['batch' => 3]]);
        $state = [];
        $now   = 1787138940000;

        $explicit = Ingest::selectDue($feeds, $state, $now, $cfg, true);
        assertCount(8, $explicit, 'every feed the operator named is attempted');
        assertSame(
            ['named-1', 'named-2', 'named-3', 'named-4', 'named-5', 'named-6', 'named-7', 'named-8'],
            array_column($explicit, 'slug')
        );

        // The unattended path still obeys the bound — that half must not regress.
        $auto = Ingest::selectDue($feeds, $state, $now, $cfg, false);
        assertCount(3, $auto, 'an automatic pass is still capped at ingest.batch');

        // And the absolute ceiling still exists, so a 500-feed config cannot be run in one pass.
        $many = [];
        for ($i = 0; $i < 500; $i++) {
            $many[] = ['slug' => 'm' . $i, 'name' => 'M', 'feed' => 'https://example.org/m' . $i . '.xml', 'section' => 'us'];
        }
        assertCount(200, Ingest::selectDue($many, [], $now, $cfg, true), 'capped at the hard ceiling');
    },

    'a feed with no slug, no URL, or a duplicate slug never reaches the fetcher' => function (): void {
        $cfg = ti_cfg();
        $cfg['feeds'] = [
            ['slug' => 'ok', 'name' => 'Ok', 'feed' => 'https://example.org/a.xml', 'section' => 'us'],
            ['slug' => '', 'name' => 'No slug', 'feed' => 'https://example.org/b.xml'],
            ['slug' => 'no-url', 'name' => 'No url', 'feed' => ''],
            ['slug' => 'bad-scheme', 'name' => 'Bad scheme', 'feed' => 'file:///etc/passwd'],
            ['slug' => 'ok', 'name' => 'Duplicate slug', 'feed' => 'https://example.org/c.xml'],
            ['slug' => 'dup-url', 'name' => 'Duplicate url', 'feed' => 'https://example.org/a.xml'],
        ];

        $list = Ingest::feedList($cfg);
        assertCount(1, $list, 'only the one usable row survives');
        assertSame('ok', $list[0]['slug']);
        assertSame('https://example.org/a.xml', $list[0]['feed']);
    },

    // ------------------------------------------------------------ cron entry

    'cron/ingest.php boots from any directory, with no web server and no data/ of its own' => function (): void {
        // Copy the shipped tree somewhere else entirely — that is what "unzip it anywhere
        // and it works" means, and it keeps this test off the real data/ and off the
        // publishers' servers (--only names a feed the registry does not have).
        $root = teb_tmp_dir('teb-cron');
        mkdir($root . '/app', 0777, true);
        mkdir($root . '/cron', 0777, true);
        copy(teb_root() . '/config.php', $root . '/config.php');
        foreach (glob(teb_root() . '/app/*.php') ?: [] as $file) {
            copy($file, $root . '/app/' . basename($file));
        }
        copy(teb_root() . '/cron/ingest.php', $root . '/cron/ingest.php');

        $php    = escapeshellarg(PHP_BINARY);
        $script = escapeshellarg($root . '/cron/ingest.php');

        $out  = [];
        $code = 0;
        exec($php . ' ' . $script . ' --only=no-such-feed-in-the-registry 2>&1', $out, $code);
        $text = implode("\n", $out);

        assertContains('feeds', $text, 'it printed a summary: ' . $text);
        assertContains('stories', $text);
        assertContains('0 attempted', $text, 'and it fetched nothing, because nothing matched');
        assertSame(1, $code, 'a run that could fetch nothing exits non-zero');
        assertFileExists($root . '/data', 'the writable data directory is created on demand');
        assertTrue(
            glob($root . '/data/*.sqlite') !== [],
            'and the database is created on first run, with no configuration'
        );
        $help = [];
        $helpCode = 0;
        exec($php . ' ' . $script . ' --help 2>&1', $help, $helpCode);
        assertSame(0, $helpCode);
        assertContains('--only=', implode("\n", $help));
        assertContains('Exit codes', implode("\n", $help));
    },

    /**
     * The token guard, exercised over a REAL web SAPI rather than grepped for.
     *
     * Grepping the source for `hash_equals` and `403` cannot see the failure this
     * replaced: the file used to open with a `#!/usr/bin/env php` line. PHP strips a
     * shebang only under the CLI SAPI, so served by Apache the line was printed as page
     * content AND pushed declare(strict_types=1) off the first statement — a fatal. Every
     * request to the documented URL trigger answered HTTP 500 with an empty body, and the
     * source-text assertions still passed.
     */
    'cron/ingest.php over HTTP: refused without the token, and it really runs with it' => function (): void {
        $feeds = ti_server();
        $root  = teb_tmp_dir('teb-web-cron');
        mkdir($root . '/app', 0777, true);
        mkdir($root . '/cron', 0777, true);
        foreach (glob(teb_root() . '/app/*.php') ?: [] as $file) {
            copy($file, $root . '/app/' . basename($file));
        }
        copy(teb_root() . '/cron/ingest.php', $root . '/cron/ingest.php');

        // Our own config: a token, and two feeds served by the local fixture server, so
        // this test never touches a publisher.
        $token = 'tok-' . bin2hex(random_bytes(8));
        $conf  = "<?php return " . var_export([
            'site'   => ['name' => 'Probe Paper', 'short_name' => 'PP', 'domain' => 'probe.example', 'timezone' => 'UTC'],
            'db'     => ['driver' => 'sqlite', 'sqlite_path' => 'data/news.sqlite'],
            'ingest' => [
                'enabled'              => true,
                'token'                => $token,
                'timeout_seconds'      => 8,
                'batch'                => 5,
                'min_interval_minutes' => 0,
            ],
            'feeds'  => [
                ['slug' => 'web-one', 'name' => 'Web One', 'feed' => $feeds['base'] . 'good1.xml', 'section' => 'world', 'tier' => 1],
                ['slug' => 'web-two', 'name' => 'Web Two', 'feed' => $feeds['base'] . 'good2.xml', 'section' => 'us', 'tier' => 1],
            ],
        ], true) . ";\n";
        file_put_contents($root . '/config.php', $conf);

        $srv = ti_serve_dir($root);
        $url = $srv . 'cron/ingest.php';

        $anon = ti_http($url);
        assertSame(403, $anon['status'], 'an untokened request must be refused, not served: ' . $anon['body']);
        assertContains('Forbidden', $anon['body']);
        // The two ways this file has actually broken under a web SAPI.
        assertNotContains('#!', $anon['body'], 'a shebang line leaked into the page');
        assertNotContains('Fatal error', $anon['body']);
        assertNotContains('strict_types', $anon['body']);
        assertFalse(is_file($root . '/data/news.sqlite'), 'a refused request must not even open the database');

        $wrong = ti_http($url . '?token=' . urlencode($token) . 'x');
        assertSame(403, $wrong['status'], 'a near-miss token is still refused');
        assertContains('Forbidden', $wrong['body']);

        $ok = ti_http($url . '?token=' . urlencode($token));
        assertSame(200, $ok['status'], 'the real token must actually work: ' . $ok['body']);
        assertContains('text/plain', strtolower($ok['content_type']), 'the report is plain text, not HTML');
        assertContains('feeds', $ok['body'], 'it printed a report: ' . $ok['body']);
        assertContains('2 attempted', $ok['body']);
        assertContains('2 ok', $ok['body']);
        assertNotContains('Fatal error', $ok['body']);
        assertNotContains('Warning', $ok['body']);
        assertNotContains('Deprecated', $ok['body']);

        // …and it really wrote news, in "token" mode, from the web SAPI.
        assertFileExists($root . '/data/news.sqlite');
        $pdo = new PDO('sqlite:' . $root . '/data/news.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        assertSame(7, (int) $pdo->query('SELECT COUNT(*) FROM articles')->fetchColumn(), '3 + 4 items from the two fixtures');
        $run = $pdo->query('SELECT * FROM ingest_runs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        assertSame('token', (string) $run['run_mode'], 'a URL-triggered run is logged as such, not as cron');
        assertSame(2, (int) $run['feeds_ok']);
    },

    'every file in this module is a plain PHP file with strict types as its first statement' => function (): void {
        foreach (['app/Xml.php', 'app/Ingest.php', 'cron/ingest.php'] as $rel) {
            $src = (string) file_get_contents(teb_root() . '/' . $rel);

            assertSame('<?php', substr($src, 0, 5), $rel . ' must open with <?php and nothing before it');
            assertNotSame('#', $src[0], $rel . ' carries a shebang — PHP only strips one under the CLI SAPI');
            assertMatches(
                '/\A<\?php\s+declare\(strict_types\s*=\s*1\);/',
                $src,
                $rel . ': declare(strict_types=1) must be the very first statement, or a web SAPI fatals'
            );
            assertNotMatches('#\bheader\(\s*[\'"]Location#i', $src, $rel . ' must never redirect');
        }

        // The guard itself is still the constant-time comparison it claims to be.
        $cron = (string) file_get_contents(teb_root() . '/cron/ingest.php');
        assertContains('hash_equals', $cron, 'the token comparison is constant time');
        assertNotContains('$_SERVER', (string) preg_replace('#^\s*(\*|//|/\*).*$#m', '', $cron));
    },
];
