<?php
declare(strict_types=1);

/**
 * Test runner.
 *
 *   php tests/run.php                         run every tests/test_*.php
 *   php tests/run.php tests/test_paths.php    run one file (or several)
 *   php tests/run.php --filter=subdirectory   run only tests whose name matches
 *   php tests/run.php --list                  list what would run
 *   php tests/run.php --inline                run in this process (no isolation)
 *   php tests/run.php --timeout=30            per-FILE seconds (default 120)
 *
 * Each test file returns  ['test name' => callable, ...]  and a test passes if
 * its callable returns without throwing. See tests/lib.php.
 *
 * By default every FILE runs in its own PHP process. That isolation is
 * deliberate: seven agents write into this suite at once, and one file with a
 * parse error, a fatal, an exhausted memory limit or a static that leaks into
 * the next file must not be able to take the whole suite down with it. A file
 * that dies is reported as a failure, and the run continues.
 *
 * Exit code is 0 only when every test passed.
 */

require_once __DIR__ . '/lib.php';

const TEB_TRAILER = '##TEB-RESULT ';

// ---------------------------------------------------------------- arguments --

$argvIn   = $argv ?? [];
$isChild  = false;
$childFile = '';
$filter   = '';
$inline   = false;
$list     = false;
$timeout  = 120;
$files    = [];

for ($i = 1; $i < count($argvIn); $i++) {
    $a = $argvIn[$i];
    if ($a === '--child') {
        $isChild   = true;
        $childFile = $argvIn[++$i] ?? '';
    } elseif (strpos($a, '--filter=') === 0) {
        $filter = substr($a, 9);
    } elseif ($a === '--inline' || $a === '--no-isolate') {
        $inline = true;
    } elseif ($a === '--list') {
        $list = true;
    } elseif (strpos($a, '--timeout=') === 0) {
        $timeout = max(5, (int) substr($a, 10));
    } elseif ($a === '--help' || $a === '-h') {
        echo "usage: php tests/run.php [file...] [--filter=substr] [--inline] [--list] [--timeout=secs]\n";
        exit(0);
    } elseif ($a !== '' && $a[0] !== '-') {
        $files[] = $a;
    }
}

$dir     = __DIR__;
$useTty  = function_exists('posix_isatty') && @posix_isatty(STDOUT);
$C       = static function (string $code, string $s) use ($useTty): string {
    return $useTty ? "\033[" . $code . 'm' . $s . "\033[0m" : $s;
};

// ------------------------------------------------------------- child branch --

if ($isChild) {
    exit(teb_run_file($childFile, $filter, $C, true));
}

// ------------------------------------------------------------------ discover --

if (!$files) {
    $files = glob($dir . '/test_*.php') ?: [];
} else {
    $files = array_map(static function (string $f) use ($dir): string {
        if (is_file($f)) {
            return (string) (realpath($f) ?: $f);
        }
        $try = $dir . '/' . ltrim($f, '/');
        if (!is_file($try) && substr($try, -4) !== '.php') {
            $try .= '.php';
        }
        if (!is_file($try) && strpos(basename($try), 'test_') !== 0) {
            $try = $dir . '/test_' . basename($try);
        }

        return (string) (realpath($try) ?: $f);
    }, $files);
}
sort($files);
$files = array_values(array_filter($files, 'is_file'));

if ($list) {
    foreach ($files as $f) {
        echo teb_rel($f), "\n";
    }
    exit(0);
}

if (!$files) {
    echo $C('33', "no test files found in " . teb_rel($dir) . " (expected tests/test_*.php)\n");
    echo "PASS 0 / FAIL 0\n";
    exit(0);
}

// ----------------------------------------------------------------- run them --

$started   = microtime(true);
$pass      = 0;
$fail      = 0;
$crashed   = [];
$failedIds = [];

echo $C('1', "Test suite") . "  ("
    . count($files) . " file" . (count($files) === 1 ? '' : 's')
    . ", PHP " . PHP_VERSION . ($inline ? ', inline' : '') . ")\n";

foreach ($files as $file) {
    echo "\n" . $C('1;36', teb_rel($file)) . "\n";

    if ($inline || !is_string(PHP_BINARY) || PHP_BINARY === '') {
        $rc     = teb_run_file($file, $filter, $C, false, $pass, $fail, $failedIds);
        if ($rc === 2) {
            $crashed[] = teb_rel($file);
        }
        continue;
    }

    $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=-1 '
        . escapeshellarg(__FILE__) . ' --child ' . escapeshellarg($file)
        . ($filter !== '' ? ' ' . escapeshellarg('--filter=' . $filter) : '');

    [$out, $err, $code, $timedOut] = teb_exec($cmd, $timeout);

    $lines   = preg_split("/\r\n|\n|\r/", rtrim($out, "\n"));
    $trailer = null;
    foreach ($lines as $line) {
        if (strpos($line, TEB_TRAILER) === 0) {
            $trailer = $line;
            continue;
        }
        if ($line !== '' || $trailer === null) {
            echo $line, "\n";
        }
    }

    if ($timedOut) {
        echo '  ' . $C('1;31', 'FAIL') . '  ' . teb_rel($file) . ' — the FILE exceeded the ' . $timeout . "s budget and was killed\n";
        $fail++;
        $failedIds[] = teb_rel($file) . ' (timeout)';
        $crashed[]   = teb_rel($file);
        continue;
    }

    if ($trailer === null) {
        // No trailer means the process died before it could report: a parse
        // error, a fatal outside a test, an exit() in the file, or a segfault.
        echo '  ' . $C('1;31', 'FAIL') . '  ' . teb_rel($file) . " — the file did not run to completion (exit code $code)\n";
        $detail = trim($err);
        if ($detail === '') {
            $detail = 'no stderr output — the process was killed, called exit(), or ran out of memory';
        }
        foreach (array_slice(preg_split("/\r\n|\n|\r/", $detail) ?: [], 0, 12) as $d) {
            if (trim($d) !== '') {
                echo '        ' . $d . "\n";
            }
        }
        $fail++;
        $failedIds[] = teb_rel($file) . ' (did not run)';
        $crashed[]   = teb_rel($file);
        continue;
    }

    if (trim($err) !== '') {
        foreach (preg_split("/\r\n|\n|\r/", trim($err)) ?: [] as $d) {
            if (trim($d) !== '') {
                echo '  ' . $C('33', 'stderr') . '  ' . $d . "\n";
            }
        }
    }

    $stats = [];
    parse_str(str_replace(' ', '&', trim(substr($trailer, strlen(TEB_TRAILER)))), $stats);
    $pass += (int) ($stats['pass'] ?? 0);
    $fail += (int) ($stats['fail'] ?? 0);
    if (!empty($stats['failed'])) {
        foreach (explode('|', rawurldecode((string) $stats['failed'])) as $id) {
            if ($id !== '') {
                $failedIds[] = $id;
            }
        }
    }
}

// ------------------------------------------------------------------ summary --

$elapsed = (microtime(true) - $started) * 1000;

echo "\n" . str_repeat('-', 66) . "\n";
if ($failedIds) {
    echo $C('1;31', 'Failed:') . "\n";
    foreach ($failedIds as $id) {
        echo '  · ' . $id . "\n";
    }
}
if ($crashed) {
    echo $C('1;31', 'Files that could not be run: ') . implode(', ', array_unique($crashed)) . "\n";
}

$summary = 'PASS ' . $pass . ' / FAIL ' . $fail;
echo ($fail === 0 ? $C('1;32', $summary) : $C('1;31', $summary))
    . '   (' . count($files) . ' files, ' . number_format($elapsed, 0) . " ms)\n";

exit($fail === 0 ? 0 : 1);

// ------------------------------------------------------------------ helpers --

/**
 * Run every test in one file. In child mode it prints the machine-readable
 * trailer and returns an exit code; inline it updates the counters by reference.
 *
 * @return int 0 = all passed, 1 = some failed, 2 = the file itself is broken
 */
function teb_run_file(
    string $file,
    string $filter,
    callable $C,
    bool $childMode,
    int &$pass = 0,
    int &$fail = 0,
    array &$failedIds = []
): int {
    $rel      = teb_rel($file);
    $localOk  = 0;
    $localNo  = 0;
    $failed   = [];
    $reported = false;

    if (!is_file($file)) {
        echo '  ' . $C('1;31', 'FAIL') . '  ' . $rel . " — file not found\n";
        $localNo++;
        $failed[] = $rel . ' (missing)';
        teb_finish($childMode, $localOk, $localNo, $failed, $pass, $fail, $failedIds, $reported);

        return 2;
    }

    // A fatal, an exit() or an exhausted memory_limit inside a test would
    // otherwise kill the child before it could report anything at all. This
    // hook makes sure the tests that already passed still count, and that the
    // one that killed the process is named.
    $current = null;
    register_shutdown_function(static function () use (&$current, &$localOk, &$localNo, &$failed, &$reported, $childMode, $C): void {
        if ($reported) {
            return;
        }
        $e = error_get_last();
        $fatal = $e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
        if ($current !== null) {
            echo '  ' . $C('1;31', 'FAIL') . '  ' . $current . "\n";
            echo '        ' . ($fatal
                ? 'fatal: ' . $e['message'] . "\n        at " . teb_rel($e['file']) . ':' . $e['line']
                : 'the test ended the PHP process (exit/die) instead of returning') . "\n";
            $localNo++;
            $failed[] = $current;
        } elseif ($fatal) {
            echo '  ' . $C('1;31', 'FAIL') . '  fatal outside a test: ' . $e['message'] . "\n";
            echo '        at ' . teb_rel($e['file']) . ':' . $e['line'] . "\n";
            $localNo++;
            $failed[] = teb_rel($e['file']) . ' (fatal outside a test)';
        }
        if ($childMode) {
            echo TEB_TRAILER . 'pass=' . $localOk . ' fail=' . $localNo
                . ' failed=' . rawurlencode(implode('|', $failed)) . "\n";
        }
    });

    $warnings = [];
    set_error_handler(static function (int $no, string $msg, string $f = '', int $ln = 0) use (&$warnings): bool {
        if (!(error_reporting() & $no)) {
            return true; // an @-suppressed call: the caller decided it is fine
        }
        $warnings[] = teb_errname($no) . ': ' . $msg . ' (' . teb_rel($f) . ':' . $ln . ')';

        return true;
    });

    try {
        $tests = require $file;
    } catch (\Throwable $e) {
        restore_error_handler();
        echo '  ' . $C('1;31', 'FAIL') . '  ' . $rel . " — the file threw while loading\n";
        echo '        ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        echo '        at ' . teb_rel($e->getFile()) . ':' . $e->getLine() . "\n";
        $localNo++;
        $failed[] = $rel . ' (load failed)';
        teb_finish($childMode, $localOk, $localNo, $failed, $pass, $fail, $failedIds, $reported);

        return 2;
    }
    restore_error_handler();

    if (!is_array($tests)) {
        echo '  ' . $C('1;31', 'FAIL') . '  ' . $rel . ' — must return an array of ["name" => callable], got '
            . gettype($tests) . "\n";
        $localNo++;
        $failed[] = $rel . ' (bad return)';
        teb_finish($childMode, $localOk, $localNo, $failed, $pass, $fail, $failedIds, $reported);

        return 2;
    }

    if (!$tests) {
        echo '  ' . $C('33', 'none') . '  ' . $rel . " returned no tests\n";
    }

    foreach ($tests as $name => $fn) {
        $label = (string) $name;
        if ($filter !== '' && stripos($label, $filter) === false && stripos($rel, $filter) === false) {
            continue;
        }
        if (!is_callable($fn)) {
            echo '  ' . $C('1;31', 'FAIL') . '  ' . $label . " — not callable (" . gettype($fn) . ")\n";
            $localNo++;
            $failed[] = $rel . ' › ' . $label;
            continue;
        }

        $current  = $rel . ' › ' . $label;
        $warnings = [];
        set_error_handler(static function (int $no, string $msg, string $f = '', int $ln = 0) use (&$warnings): bool {
            if (!(error_reporting() & $no)) {
                return true;
            }
            $warnings[] = teb_errname($no) . ': ' . $msg . ' (' . teb_rel($f) . ':' . $ln . ')';

            return true;
        });

        $t0 = microtime(true);
        try {
            $fn();
            $ms = (microtime(true) - $t0) * 1000;
            restore_error_handler();
            echo '  ' . $C('1;32', 'PASS') . '  ' . teb_pad($label) . teb_ms($ms) . "\n";
            $localOk++;
        } catch (\Throwable $e) {
            $ms = (microtime(true) - $t0) * 1000;
            restore_error_handler();
            echo '  ' . $C('1;31', 'FAIL') . '  ' . teb_pad($label) . teb_ms($ms) . "\n";
            teb_print_throwable($e, $C);
            $localNo++;
            $failed[] = $rel . ' › ' . $label;
        }
        $current = null;

        foreach ($warnings as $w) {
            echo '        ' . $C('33', 'warn: ') . $w . "\n";
        }
    }

    teb_finish($childMode, $localOk, $localNo, $failed, $pass, $fail, $failedIds, $reported);

    return $localNo === 0 ? 0 : 1;
}

function teb_finish(bool $childMode, int $ok, int $no, array $failed, int &$pass, int &$fail, array &$failedIds, bool &$reported = false): void
{
    $reported = true;
    if ($childMode) {
        echo TEB_TRAILER . 'pass=' . $ok . ' fail=' . $no
            . ' failed=' . rawurlencode(implode('|', $failed)) . "\n";

        return;
    }
    $pass     += $ok;
    $fail     += $no;
    $failedIds = array_merge($failedIds, $failed);
}

/** Print an assertion failure (expected vs actual) or any other throwable. */
function teb_print_throwable(\Throwable $e, callable $C): void
{
    $isAssertion = $e instanceof \AssertionFailure;

    if (!$isAssertion) {
        echo '        ' . $C('1;33', get_class($e)) . ': ' . $e->getMessage() . "\n";
    } else {
        echo '        ' . $e->getMessage() . "\n";
    }

    if ($isAssertion && $e->hasValues) {
        echo '        expected: ' . teb_dump($e->expected) . "\n";
        echo '        actual:   ' . teb_dump($e->actual) . "\n";
    }

    // The first frame that is not the assertion library itself is the line the
    // test author cares about.
    $where = teb_rel($e->getFile()) . ':' . $e->getLine();
    foreach ($e->getTrace() as $frame) {
        $f = $frame['file'] ?? '';
        if ($f !== '' && basename($f) !== 'lib.php' && basename($f) !== 'run.php') {
            $where = teb_rel($f) . ':' . ($frame['line'] ?? 0);
            break;
        }
    }
    echo '        at ' . $where . "\n";

    if ($p = $e->getPrevious()) {
        echo '        caused by ' . get_class($p) . ': ' . $p->getMessage() . "\n";
    }
}

/** Run a command with a wall-clock budget. @return array{0:string,1:string,2:int,3:bool} */
function teb_exec(string $cmd, int $timeoutSeconds): array
{
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $desc, $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        return ['', 'could not start: ' . $cmd, 127, false];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $out      = '';
    $err      = '';
    $deadline = microtime(true) + $timeoutSeconds;
    $timedOut = false;

    while (true) {
        $read   = array_filter([$pipes[1], $pipes[2]], 'is_resource');
        $write  = $except = null;
        if ($read) {
            @stream_select($read, $write, $except, 0, 200000);
            foreach ($read as $r) {
                $chunk = stream_get_contents($r);
                if ($chunk !== false && $chunk !== '') {
                    $r === $pipes[1] ? $out .= $chunk : $err .= $chunk;
                }
            }
        }
        $status = proc_get_status($proc);
        if (!$status['running']) {
            foreach ([1, 2] as $i) {
                if (is_resource($pipes[$i])) {
                    $chunk = stream_get_contents($pipes[$i]);
                    if ($chunk !== false && $chunk !== '') {
                        $i === 1 ? $out .= $chunk : $err .= $chunk;
                    }
                }
            }
            break;
        }
        if (microtime(true) > $deadline) {
            $timedOut = true;
            proc_terminate($proc, 9);
            usleep(100000);
            break;
        }
    }

    foreach ($pipes as $p) {
        if (is_resource($p)) {
            fclose($p);
        }
    }
    $code = proc_close($proc);

    return [$out, $err, $timedOut ? 124 : (int) $code, $timedOut];
}

function teb_rel(string $path): string
{
    $root = dirname(__DIR__) . '/';
    return strpos($path, $root) === 0 ? substr($path, strlen($root)) : $path;
}

function teb_pad(string $s, int $width = 56): string
{
    $len = function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);

    return $len >= $width ? $s . '  ' : $s . str_repeat(' ', $width - $len);
}

function teb_ms(float $ms): string
{
    return str_pad(number_format($ms, $ms < 10 ? 1 : 0) . ' ms', 9, ' ', STR_PAD_LEFT);
}

function teb_errname(int $no): string
{
    $map = [
        E_WARNING           => 'Warning',
        E_NOTICE            => 'Notice',
        E_DEPRECATED        => 'Deprecated',
        E_USER_WARNING      => 'User warning',
        E_USER_NOTICE       => 'User notice',
        E_USER_DEPRECATED   => 'User deprecated',
        E_RECOVERABLE_ERROR => 'Recoverable error',
        E_STRICT            => 'Strict',
    ];

    return $map[$no] ?? ('Error(' . $no . ')');
}
