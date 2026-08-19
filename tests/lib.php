<?php
declare(strict_types=1);

/**
 * Shared test assertions.
 *
 * Plain PHP, no PHPUnit, no Composer. Every test file under tests/ is:
 *
 *     <?php
 *     declare(strict_types=1);
 *     require_once __DIR__ . '/lib.php';
 *     return [
 *         'my first check' => function (): void { assertSame(2, 1 + 1); },
 *         'my second check' => function (): void { assertTrue(is_array([])); },
 *     ];
 *
 * A test PASSES when its callable returns without throwing. It FAILS when any
 * assertion throws AssertionFailure — or when anything else throws, which is
 * reported as an ERROR with the exception class and the file:line it came from.
 *
 * Run everything:            php tests/run.php
 * Run one file:              php tests/run.php tests/test_paths.php
 * Run tests whose name matches: php tests/run.php --filter=subdirectory
 *
 * This file is loaded by every agent's test file, so it must stay dependency
 * free and safe to include twice.
 */

if (!class_exists('AssertionFailure', false)) {
    /**
     * Thrown by every assertion in this file. Carries the expected and the
     * actual value so run.php can print them on their own lines.
     */
    class AssertionFailure extends \Exception
    {
        /** @var mixed */
        public $expected;
        /** @var mixed */
        public $actual;
        public bool $hasValues = false;

        /**
         * @param mixed $expected
         * @param mixed $actual
         */
        public static function values(string $message, $expected, $actual): self
        {
            $e = new self($message);
            $e->expected  = $expected;
            $e->actual    = $actual;
            $e->hasValues = true;

            return $e;
        }
    }
}

if (!function_exists('teb_dump')) {
    /**
     * Compact, readable, single-line-ish rendering of any value, for assertion
     * messages. Strings are quoted and escaped so a trailing space or a stray
     * \r is visible instead of invisible — that matters here, because one of
     * the things we assert is that a CR/LF injected host is rejected.
     *
     * @param mixed $v
     */
    function teb_dump($v, int $maxLen = 600): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v) || is_float($v)) {
            return var_export($v, true);
        }
        if (is_string($v)) {
            $s = addcslashes($v, "\0..\37\\\"\177");
            if (strlen($s) > $maxLen) {
                $s = substr($s, 0, $maxLen) . '…(' . strlen($v) . ' bytes)';
            }

            return '"' . $s . '"';
        }
        if (is_array($v)) {
            $json = @json_encode(
                $v,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
            );
            if (!is_string($json)) {
                $json = '[array of ' . count($v) . ']';
            }
            if (strlen($json) > $maxLen) {
                $json = substr($json, 0, $maxLen) . '…(' . count($v) . ' items)';
            }

            return $json;
        }
        if (is_object($v)) {
            if ($v instanceof \Throwable) {
                return get_class($v) . '(' . $v->getMessage() . ')';
            }
            if ($v instanceof \Closure) {
                return 'Closure';
            }

            return get_class($v) . ' ' . teb_dump(get_object_vars($v), $maxLen);
        }
        if (is_resource($v)) {
            return 'resource(' . get_resource_type($v) . ')';
        }

        return gettype($v);
    }
}

if (!function_exists('teb_fail')) {
    /**
     * Fail the current test outright. Also the single choke point every other
     * assertion goes through.
     *
     * @param mixed $expected
     * @param mixed $actual
     */
    function teb_fail(string $what, string $userMessage = '', $expected = null, $actual = null, bool $withValues = false): void
    {
        $message = $userMessage !== '' ? $userMessage . ' — ' . $what : $what;
        if ($withValues) {
            throw AssertionFailure::values($message, $expected, $actual);
        }
        throw new AssertionFailure($message);
    }
}

if (!function_exists('assertTrue')) {
    /** @param mixed $condition */
    function assertTrue($condition, string $message = ''): void
    {
        if ($condition !== true) {
            teb_fail('expected true', $message, true, $condition, true);
        }
    }
}

if (!function_exists('assertFalse')) {
    /** @param mixed $condition */
    function assertFalse($condition, string $message = ''): void
    {
        if ($condition !== false) {
            teb_fail('expected false', $message, false, $condition, true);
        }
    }
}

if (!function_exists('assertTruthy')) {
    /** Loose truth — for values like a non-empty string or a positive count. @param mixed $v */
    function assertTruthy($v, string $message = ''): void
    {
        if (!$v) {
            teb_fail('expected a truthy value', $message, 'truthy', $v, true);
        }
    }
}

if (!function_exists('assertSame')) {
    /**
     * Identity (===). This is the assertion to reach for by default: it catches
     * '' vs null and '0' vs 0, both of which have bitten path code before.
     *
     * @param mixed $expected
     * @param mixed $actual
     */
    function assertSame($expected, $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $what = 'values are not identical';
            if (is_string($expected) && is_string($actual) && strtolower($expected) === strtolower($actual)) {
                $what .= ' (differ only in case)';
            } elseif (is_string($expected) && is_string($actual) && trim($expected) === trim($actual)) {
                $what .= ' (differ only in surrounding whitespace)';
            } elseif (gettype($expected) !== gettype($actual)) {
                $what .= ' (' . gettype($expected) . ' vs ' . gettype($actual) . ')';
            }
            teb_fail($what, $message, $expected, $actual, true);
        }
    }
}

if (!function_exists('assertNotSame')) {
    /** @param mixed $unexpected @param mixed $actual */
    function assertNotSame($unexpected, $actual, string $message = ''): void
    {
        if ($unexpected === $actual) {
            teb_fail('values are identical but should not be', $message, 'anything but ' . teb_dump($unexpected), $actual, true);
        }
    }
}

if (!function_exists('assertEquals')) {
    /**
     * Loose equality (==) for scalars; for arrays, a deep comparison that is
     * insensitive to key ORDER but sensitive to keys and values. Use assertSame
     * unless you have a reason not to.
     *
     * @param mixed $expected
     * @param mixed $actual
     */
    function assertEquals($expected, $actual, string $message = ''): void
    {
        $ok = (is_array($expected) && is_array($actual))
            ? teb_array_equals($expected, $actual)
            : $expected == $actual;

        if (!$ok) {
            teb_fail('values are not equal', $message, $expected, $actual, true);
        }
    }
}

if (!function_exists('teb_array_equals')) {
    /** Deep, key-order-insensitive array comparison used by assertEquals. */
    function teb_array_equals(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $k => $v) {
            if (!array_key_exists($k, $b)) {
                return false;
            }
            if (is_array($v) && is_array($b[$k])) {
                if (!teb_array_equals($v, $b[$k])) {
                    return false;
                }
            } elseif ($v != $b[$k]) {
                return false;
            } elseif (gettype($v) !== gettype($b[$k]) && (is_array($v) || is_array($b[$k]))) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('assertContains')) {
    /**
     * Substring when $haystack is a string, membership (strict) when it is an
     * array. Both spellings are used constantly across these tests: rendered
     * HTML is a string, composed models are arrays.
     *
     * @param mixed $needle
     * @param string|array $haystack
     */
    function assertContains($needle, $haystack, string $message = ''): void
    {
        if (is_string($haystack)) {
            if (!is_string($needle)) {
                teb_fail('needle must be a string when the haystack is a string', $message, 'string', gettype($needle), true);
            }
            if ($needle === '' || strpos($haystack, $needle) === false) {
                $hint = $haystack;
                if (is_string($needle) && $needle !== '') {
                    $hint = teb_near_miss($needle, $haystack);
                }
                teb_fail('substring not found in the haystack', $message, $needle, $hint, true);
            }

            return;
        }
        if (is_array($haystack)) {
            if (!in_array($needle, $haystack, true)) {
                teb_fail('value not present in the array', $message, $needle, $haystack, true);
            }

            return;
        }
        teb_fail('haystack must be a string or an array, got ' . gettype($haystack), $message);
    }
}

if (!function_exists('assertNotContains')) {
    /**
     * @param mixed $needle
     * @param string|array $haystack
     */
    function assertNotContains($needle, $haystack, string $message = ''): void
    {
        if (is_string($haystack)) {
            if (!is_string($needle)) {
                teb_fail('needle must be a string when the haystack is a string', $message, 'string', gettype($needle), true);
            }
            $at = $needle === '' ? false : strpos($haystack, $needle);
            if ($at !== false) {
                $from = max(0, $at - 60);
                teb_fail(
                    'substring IS present at byte ' . $at . ' but must not be',
                    $message,
                    'absence of ' . teb_dump($needle),
                    substr($haystack, $from, strlen($needle) + 120),
                    true
                );
            }

            return;
        }
        if (is_array($haystack)) {
            if (in_array($needle, $haystack, true)) {
                teb_fail('value IS present in the array but must not be', $message, 'absence of ' . teb_dump($needle), $haystack, true);
            }

            return;
        }
        teb_fail('haystack must be a string or an array, got ' . gettype($haystack), $message);
    }
}

if (!function_exists('teb_near_miss')) {
    /**
     * When a substring assertion fails, showing the whole 40 KB of HTML helps
     * nobody. Show the closest thing to the needle that IS in the haystack.
     */
    function teb_near_miss(string $needle, string $haystack): string
    {
        $probe = substr($needle, 0, max(4, (int) floor(strlen($needle) / 2)));
        $at    = $probe === '' ? false : stripos($haystack, $probe);
        if ($at === false) {
            return strlen($haystack) > 400 ? substr($haystack, 0, 400) . '…' : $haystack;
        }
        $from = max(0, $at - 60);

        return '…' . substr($haystack, $from, strlen($needle) + 160) . '…';
    }
}

if (!function_exists('assertNull')) {
    /** @param mixed $v */
    function assertNull($v, string $message = ''): void
    {
        if ($v !== null) {
            teb_fail('expected null', $message, null, $v, true);
        }
    }
}

if (!function_exists('assertNotNull')) {
    /** @param mixed $v */
    function assertNotNull($v, string $message = ''): void
    {
        if ($v === null) {
            teb_fail('expected anything but null', $message, 'not null', null, true);
        }
    }
}

if (!function_exists('assertCount')) {
    /** @param mixed $countable */
    function assertCount(int $expected, $countable, string $message = ''): void
    {
        if (!is_array($countable) && !($countable instanceof \Countable)) {
            teb_fail('value is not countable (' . gettype($countable) . ')', $message, 'array|Countable', $countable, true);
        }
        /** @var array|\Countable $countable */
        $actual = count($countable);
        if ($actual !== $expected) {
            teb_fail('wrong number of items: ' . $actual . ' instead of ' . $expected, $message, $expected, $countable, true);
        }
    }
}

if (!function_exists('assertThrows')) {
    /**
     * Assert that $fn throws.
     *
     * $expect is optional and may be either an exception CLASS NAME
     * (\InvalidArgumentException::class) or a case-insensitive SUBSTRING of the
     * thrown message ('unsupported driver'). Passing nothing accepts any
     * Throwable.
     *
     * Returns the caught Throwable so a test can assert more about it.
     */
    function assertThrows(callable $fn, string $expect = '', string $message = ''): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($expect === '') {
                return $e;
            }
            $looksLikeClass = (strpos($expect, '\\') !== false) || class_exists($expect) || interface_exists($expect);
            if ($looksLikeClass) {
                if (!($e instanceof $expect)) {
                    teb_fail('threw the wrong exception class', $message, $expect, get_class($e) . ': ' . $e->getMessage(), true);
                }

                return $e;
            }
            if (stripos($e->getMessage(), $expect) === false) {
                teb_fail('threw, but the message does not mention the expected text', $message, $expect, get_class($e) . ': ' . $e->getMessage(), true);
            }

            return $e;
        }
        teb_fail('the callable returned normally; it was expected to throw', $message, $expect === '' ? 'any Throwable' : $expect, 'no exception', true);
        // teb_fail always throws; this is only here for static analysers.
        throw new AssertionFailure('unreachable');
    }
}

if (!function_exists('assertMatches')) {
    function assertMatches(string $pattern, string $subject, string $message = ''): void
    {
        $r = @preg_match($pattern, $subject);
        if ($r === false) {
            teb_fail('invalid regular expression: ' . $pattern, $message);
        }
        if ($r !== 1) {
            teb_fail('subject does not match the pattern', $message, $pattern, $subject, true);
        }
    }
}

if (!function_exists('assertNotMatches')) {
    function assertNotMatches(string $pattern, string $subject, string $message = ''): void
    {
        $r = @preg_match($pattern, $subject);
        if ($r === false) {
            teb_fail('invalid regular expression: ' . $pattern, $message);
        }
        if ($r === 1) {
            teb_fail('subject matches the pattern but must not', $message, 'no match for ' . $pattern, $subject, true);
        }
    }
}

if (!function_exists('assertArrayHasKey')) {
    /** @param string|int $key */
    function assertArrayHasKey($key, array $array, string $message = ''): void
    {
        if (!array_key_exists($key, $array)) {
            teb_fail('array is missing the key ' . teb_dump($key), $message, $key, array_keys($array), true);
        }
    }
}

if (!function_exists('assertArrayNotHasKey')) {
    /** @param string|int $key */
    function assertArrayNotHasKey($key, array $array, string $message = ''): void
    {
        if (array_key_exists($key, $array)) {
            teb_fail('array has the key ' . teb_dump($key) . ' but must not', $message, 'no ' . teb_dump($key), array_keys($array), true);
        }
    }
}

if (!function_exists('assertGreaterThan')) {
    /** @param mixed $bound @param mixed $actual */
    function assertGreaterThan($bound, $actual, string $message = ''): void
    {
        if (!($actual > $bound)) {
            teb_fail('value is not greater than the bound', $message, '> ' . teb_dump($bound), $actual, true);
        }
    }
}

if (!function_exists('assertGreaterThanOrEqual')) {
    /** @param mixed $bound @param mixed $actual */
    function assertGreaterThanOrEqual($bound, $actual, string $message = ''): void
    {
        if (!($actual >= $bound)) {
            teb_fail('value is not greater than or equal to the bound', $message, '>= ' . teb_dump($bound), $actual, true);
        }
    }
}

if (!function_exists('assertLessThan')) {
    /** @param mixed $bound @param mixed $actual */
    function assertLessThan($bound, $actual, string $message = ''): void
    {
        if (!($actual < $bound)) {
            teb_fail('value is not less than the bound', $message, '< ' . teb_dump($bound), $actual, true);
        }
    }
}

if (!function_exists('assertLessThanOrEqual')) {
    /** @param mixed $bound @param mixed $actual */
    function assertLessThanOrEqual($bound, $actual, string $message = ''): void
    {
        if (!($actual <= $bound)) {
            teb_fail('value is not less than or equal to the bound', $message, '<= ' . teb_dump($bound), $actual, true);
        }
    }
}

if (!function_exists('assertFileExists')) {
    function assertFileExists(string $path, string $message = ''): void
    {
        if (!file_exists($path)) {
            teb_fail('file does not exist', $message, $path, 'missing', true);
        }
    }
}

if (!function_exists('teb_root')) {
    /** Absolute path of the project root (the directory holding config.php). */
    function teb_root(): string
    {
        return dirname(__DIR__);
    }
}

if (!function_exists('teb_require_app')) {
    /**
     * require_once one or more classes from app/, by class name or file name:
     *     teb_require_app('Config', 'Paths');
     *
     * Throws a clear, actionable message when the file another agent owns has
     * not landed yet, instead of a bare "Class not found" fatal.
     */
    function teb_require_app(string ...$names): void
    {
        foreach ($names as $name) {
            $file = substr($name, -4) === '.php' ? $name : $name . '.php';
            $path = teb_root() . '/app/' . $file;
            if (!is_file($path)) {
                throw new \RuntimeException(
                    'app/' . $file . ' does not exist yet (owned by another agent) — path checked: ' . $path
                );
            }
            require_once $path;
        }
    }
}

if (!function_exists('teb_tmp_dir')) {
    /**
     * Create an empty scratch directory that is deleted when the process ends.
     * Use it for anything that writes to disk (SQLite files, caches, locks) so
     * tests never touch the real data/ directory.
     */
    function teb_tmp_dir(string $prefix = 'teb'): string
    {
        $base = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(6));
        if (!@mkdir($base, 0777, true) && !is_dir($base)) {
            throw new \RuntimeException('could not create temp dir: ' . $base);
        }
        $GLOBALS['__teb_tmp_dirs'][] = $base;
        if (empty($GLOBALS['__teb_tmp_shutdown'])) {
            $GLOBALS['__teb_tmp_shutdown'] = true;
            register_shutdown_function(static function (): void {
                foreach (($GLOBALS['__teb_tmp_dirs'] ?? []) as $dir) {
                    teb_rrmdir($dir);
                }
            });
        }

        return $base;
    }
}

if (!function_exists('teb_rrmdir')) {
    /** Recursively delete a directory. Refuses to run outside a temp directory. */
    function teb_rrmdir(string $dir): void
    {
        $real = realpath($dir);
        $tmp  = realpath(sys_get_temp_dir());
        if ($real === false || $tmp === false || strpos($real, $tmp) !== 0 || $real === $tmp) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($real);
    }
}

if (!function_exists('teb_php_files')) {
    /** Every .php file under a directory, sorted, absolute paths. */
    function teb_php_files(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
                $out[] = $f->getPathname();
            }
        }
        sort($out);

        return $out;
    }
}
