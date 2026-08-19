<?php

declare(strict_types=1);

/**
 * Feed ingestion entry point.
 *
 * cPanel cron (every 10 minutes is the tuned cadence — cPanel's own "Twice per hour"
 * preset is fine too):
 *
 *     0,10,20,30,40,50 * * * *  /usr/local/bin/php /home/USER/public_html/cron/ingest.php >/dev/null 2>&1
 *
 * or from a shell:
 *
 *     php /home/USER/public_html/cron/ingest.php
 *     php cron/ingest.php --only=bbc-world,npr-news
 *     php cron/ingest.php --quiet
 *
 * There is deliberately NO `#!` line: PHP strips a shebang only under the CLI SAPI. Served
 * by Apache the line would be printed as page content AND would push `declare(strict_types=1)`
 * off the first statement, which is a fatal error — so the documented URL trigger below would
 * answer HTTP 500 instead of running or refusing. Always invoke it as `php cron/ingest.php`.
 *
 * There is no web server in a cron job, so nothing here reads $_SERVER, no output is
 * HTML, and no path is absolute-from-the-web-root: everything hangs off __DIR__.
 *
 * If this file is ever requested over HTTP it refuses unless ingest.token is set in
 * config.php and matches ?token= — an open ingest URL is a free denial-of-service
 * against the publishers we depend on.
 */

$cliMode = (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');
$root    = dirname(__DIR__);

/** STDOUT/STDERR only exist under the CLI SAPI, so all output goes through these two. */
$say = static function (string $text) use ($cliMode): void {
    if ($cliMode && defined('STDOUT')) {
        fwrite(STDOUT, $text);

        return;
    }
    echo $text;
};
$cry = static function (string $text) use ($cliMode): void {
    if ($cliMode && defined('STDERR')) {
        fwrite(STDERR, $text);

        return;
    }
    echo $text;
};
$argvIn  = $cliMode && isset($argv) && is_array($argv) ? array_slice($argv, 1) : [];

// ---------------------------------------------------------------- options

$opt = [
    'only'    => null,
    'quiet'   => false,
    'json'    => false,
    'help'    => false,
    'timeout' => 0,
    'batch'   => 0,
];
foreach ($argvIn as $arg) {
    if ($arg === '--quiet' || $arg === '-q') {
        $opt['quiet'] = true;
    } elseif ($arg === '--json') {
        $opt['json'] = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        $opt['help'] = true;
    } elseif (strpos($arg, '--only=') === 0) {
        $list = array_filter(array_map('trim', explode(',', substr($arg, 7))));
        $opt['only'] = $list === [] ? null : array_values($list);
    } elseif (strpos($arg, '--timeout=') === 0) {
        $opt['timeout'] = (int) substr($arg, 10);
    } elseif (strpos($arg, '--batch=') === 0) {
        $opt['batch'] = (int) substr($arg, 8);
    }
}

if ($opt['help']) {
    $say(<<<TXT
Usage: php cron/ingest.php [options]

  --only=a,b     fetch only these source slugs (ignores the parked list and the
                 minimum interval — this is a human asking for them now)
  --batch=N      override ingest.batch for this run
  --timeout=N    override ingest.timeout_seconds for this run
  --quiet        print nothing unless something went wrong
  --json         print the result as one line of JSON
  --help         this text

Exit codes: 0 ok (or another run already in progress) · 1 every feed failed
            2 the site could not be loaded (config or app/ missing)

TXT
    );
    exit(0);
}

// ---------------------------------------------------------------- bootstrap

$fail = static function (string $msg, int $code) use ($cliMode, $cry): void {
    if (!$cliMode && !headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8', true, 500);
    }
    $cry('ingest: ' . $msg . PHP_EOL);
    exit($code);
};

if (is_file($root . '/app/bootstrap.php')) {
    require_once $root . '/app/bootstrap.php';
} else {
    // A partial checkout (or a build run before the integrator's bootstrap lands) must
    // still be able to ingest: require what is there, by name, in dependency order.
    foreach (['Config', 'Paths', 'Db', 'Feeds', 'Xml', 'Ingest'] as $class) {
        $file = $root . '/app/' . $class . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
}

foreach (['TEB\\Db', 'TEB\\Xml', 'TEB\\Ingest'] as $needed) {
    if (!class_exists($needed)) {
        $fail('missing class ' . $needed . ' — is app/ complete? (looked in ' . $root . '/app)', 2);
    }
}

// ---------------------------------------------------------------- config

$cfg = [];
if (class_exists('TEB\\Config')) {
    try {
        $cfg = TEB\Config::load($root);
    } catch (Throwable $e) {
        $fail('config could not be loaded: ' . $e->getMessage(), 2);
    }
} elseif (is_file($root . '/config.php')) {
    $loaded = require $root . '/config.php';
    $cfg    = is_array($loaded) ? $loaded : [];
} else {
    $fail('config.php not found in ' . $root, 2);
}
if (!isset($cfg['root'])) {
    $cfg['root'] = $root;
}

$siteName = trim((string) ($cfg['site']['name'] ?? ''));
$tz       = trim((string) ($cfg['site']['timezone'] ?? 'UTC'));
if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
    date_default_timezone_set($tz);
}

// ---------------------------------------------------------------- web guard

if (!$cliMode) {
    $token    = (string) ($cfg['ingest']['token'] ?? '');
    $supplied = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
    if ($token === '' || !hash_equals($token, $supplied)) {
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8', true, 403);
        }
        $say("Forbidden. Ingestion runs from cron; set ingest.token in config.php to allow a URL trigger.\n");
        exit(0);
    }
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
    }
}

// ---------------------------------------------------------------- overrides

if ($opt['timeout'] > 0) {
    $cfg['ingest']['timeout_seconds'] = $opt['timeout'];
}
if ($opt['batch'] > 0) {
    $cfg['ingest']['batch'] = $opt['batch'];
}
$cfg['ingest']['run_mode'] = $cliMode ? 'cli' : 'token';

if ($cliMode) {
    @set_time_limit(0);
    @ini_set('memory_limit', '256M');
}
ignore_user_abort(true);

// ---------------------------------------------------------------- run

$t0 = microtime(true);

try {
    $pdo = TEB\Db::connect($cfg);
    TEB\Db::migrate($pdo);
} catch (Throwable $e) {
    $fail('database unavailable: ' . $e->getMessage(), 2);
}

$dataDir = TEB\Ingest::dataDir($cfg);
$lock    = TEB\Ingest::lock($dataDir);
if ($lock === null) {
    if (!$opt['quiet']) {
        $say('ingest: another run is already in progress — nothing to do.' . PHP_EOL);
    }
    exit(0);
}

try {
    $res = TEB\Ingest::run($pdo, $cfg, $opt['only']);
} catch (Throwable $e) {
    TEB\Ingest::unlock();
    $fail('run failed: ' . get_class($e) . ': ' . $e->getMessage(), 1);
    exit(1);
}
TEB\Ingest::unlock();

// ---------------------------------------------------------------- report

$attempted = (int) ($res['feeds_attempted'] ?? 0);
$ok        = (int) ($res['feeds_ok'] ?? 0);
$failed    = (int) ($res['feeds_failed'] ?? 0);
$inserted  = (int) ($res['inserted'] ?? 0);
$skipped   = (int) ($res['skipped'] ?? 0);
$errors    = is_array($res['errors'] ?? null) ? $res['errors'] : [];
$parked    = is_array($res['parked'] ?? null) ? $res['parked'] : [];
// Nothing attempted and something to say about it (no feeds, a dead database) is just as
// much a failed run as every feed 403ing. Ingestion switched off on purpose is not.
$disabled  = (bool) ($res['disabled'] ?? false);
$totalFail = !$disabled && (($attempted > 0 && $ok === 0) || ($attempted === 0 && $errors !== []));
$seconds   = number_format(microtime(true) - $t0, 1);

if ($opt['json']) {
    $say((string) json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit($totalFail ? 1 : 0);
}

$lines = [];
if ($siteName !== '') {
    $lines[] = $siteName . ' — feed ingest';
}
$lines[] = date('Y-m-d H:i:s T') . '  ·  mode ' . ($cliMode ? 'cli' : 'url') . '  ·  ' . TEB\Db::driver($pdo);
$lines[] = '';
$lines[] = sprintf('  feeds    %d attempted · %d ok · %d failed', $attempted, $ok, $failed);
$lines[] = sprintf('  stories  %d new · %d already seen', $inserted, $skipped);
$lines[] = sprintf('  time     %ss', $seconds);

if ($parked !== []) {
    $lines[] = '';
    $lines[] = '  parked (' . TEB\Ingest::PARK_AFTER . ' failures in a row, skipped until they recover):';
    foreach ($parked as $slug) {
        $lines[] = '    · ' . $slug;
    }
}
if ($errors !== []) {
    $lines[] = '';
    $lines[] = '  problems:';
    foreach (array_slice($errors, 0, 30) as $err) {
        $lines[] = '    ! ' . $err;
    }
    if (count($errors) > 30) {
        $lines[] = '    … and ' . (count($errors) - 30) . ' more';
    }
}
if ($totalFail && $attempted > 0) {
    $lines[] = '';
    $lines[] = '  EVERY FEED FAILED — check outbound HTTPS from this host, then retry one feed:';
    $lines[] = '    php ' . $root . '/cron/ingest.php --only=' . (string) ($opt['only'][0] ?? 'a-source-slug');
} elseif ($totalFail) {
    $lines[] = '';
    $lines[] = '  NOTHING WAS FETCHED — see the problem above. The site keeps serving the';
    $lines[] = '  stories it already has, so this is not visible to readers yet.';
}
$lines[] = '';

$report = implode(PHP_EOL, $lines);

if (!$opt['quiet'] || $totalFail || $errors !== []) {
    $totalFail ? $cry($report) : $say($report);
}

exit($totalFail ? 1 : 0);
