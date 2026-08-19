<?php

declare(strict_types=1);

/**
 * .htaccess — the client-mandated regression guard.
 *
 * WHAT WENT WRONG, AND WHY THIS FILE EXISTS
 * -----------------------------------------
 * Earlier site batches shipped an .htaccess carrying a force-HTTPS 301 to a
 * hardcoded production domain:
 *
 *     RewriteCond %{HTTPS} !=on
 *     RewriteRule ^(.*)$ https://example.org/$1 [R=301,L]
 *
 * Upload that ZIP anywhere other than the final domain — a CDN, a staging
 * subdomain, a subfolder, a raw IP — and Apache throws the visitor at a domain
 * that is not serving yet. You can never see what you just uploaded, and
 * because a 301 is cached by the browser it keeps happening after the rule is
 * deleted.
 *
 * THE POINT OF THE FIXTURES BELOW
 * -------------------------------
 * A guard that does not catch the bug it was written for is worthless, so this
 * file does not only assert that the shipped .htaccess is clean. It runs the
 * SAME checker over the exact broken pattern above and asserts it is flagged,
 * and over one minimal fixture per rule — every spelling of the R flag, every
 * Redirect directive, RewriteBase, an absolute URL, the production domain, and
 * a root-absolute ErrorDocument.
 *
 * COMMENTS ARE STRIPPED FIRST, AND THAT IS LOAD-BEARING
 * -----------------------------------------------------
 * The shipped file's header comment says, in words, "no Redirect directive, no
 * RewriteRule carrying [R] or [R=301]" — so a checker that scanned the raw text
 * would fail on the very sentence that documents the rule. The checker
 * therefore drops comment lines before it looks at anything, and there is a
 * test that proves it: the same file, checked WITHOUT stripping, is flagged.
 *
 * Apache has no trailing comments — a '#' is only a comment at the start of a
 * line (leading whitespace allowed) — so dropping whole comment lines is the
 * complete and correct rule, not an approximation.
 */

require_once __DIR__ . '/lib.php';
teb_require_app('Config');

use TEB\Config;

// ---------------------------------------------------------------- the checker

/**
 * Every rule violation in one .htaccess, as human-readable strings.
 *
 * Deliberately paranoid: it looks for the offending tokens ANYWHERE on a
 * directive line rather than only at the start, so an oddly indented or
 * unusually spelled directive cannot slip past. That paranoia is exactly why
 * comment stripping has to happen first.
 *
 * @param  array{strip_comments?:bool,domain?:string} $opts
 * @return array<int,string>
 */
function teb_htaccess_violations(string $text, array $opts = []): array
{
    $strip  = $opts['strip_comments'] ?? true;
    $domain = trim((string) ($opts['domain'] ?? ''));

    $out = [];
    foreach (preg_split('/\R/', $text) ?: [] as $n => $raw) {
        $line = rtrim($raw);
        $lineNo = $n + 1;

        if (trim($line) === '') {
            continue;
        }
        if ($strip && preg_match('/^\s*#/', $line) === 1) {
            continue;                       // Apache: '#' only starts a comment at line start
        }

        // 1. Any Redirect* directive. All of them are external redirects.
        if (preg_match('/\bRedirect(Match|Permanent|Temp)?\b/i', $line) === 1) {
            $out[] = "line $lineNo: Redirect directive — " . trim($line);
        }

        // 2. A RewriteRule carrying the R flag in any spelling:
        //    [R] [r] [R=301] [R=302] [R=301,L] [L,R=301] [NC,R,L] …
        if (preg_match('/\bRewriteRule\b/i', $line) === 1
            && preg_match_all('/\[([^\]]*)\]/', $line, $m) > 0) {
            foreach ($m[1] as $flagGroup) {
                foreach (explode(',', $flagGroup) as $flag) {
                    if (preg_match('/^\s*R\s*(=\s*\d+)?\s*$/i', $flag) === 1) {
                        $out[] = "line $lineNo: RewriteRule with an R flag — " . trim($line);
                        break 2;
                    }
                }
            }
        }

        // 3. RewriteBase pins the rules to one install path and breaks the
        //    "unzip it anywhere" promise.
        if (preg_match('/\bRewriteBase\b/i', $line) === 1) {
            $out[] = "line $lineNo: RewriteBase — " . trim($line);
        }

        // 4. Any absolute URL. There is no legitimate reason for one here, and
        //    every historical version of this bug contained one.
        if (preg_match('~\bhttps?://~i', $line) === 1) {
            $out[] = "line $lineNo: absolute URL — " . trim($line);
        }

        // 5. The production domain, however it got in.
        if ($domain !== '' && stripos($line, $domain) !== false) {
            $out[] = "line $lineNo: hardcoded production domain — " . trim($line);
        }

        // 6. ErrorDocument pointing at a root-absolute path: correct at a web
        //    root, a 404-inside-a-404 in a subdirectory.
        if (preg_match('/\bErrorDocument\s+\d+\s+\//i', $line) === 1) {
            $out[] = "line $lineNo: ErrorDocument with a leading-slash path — " . trim($line);
        }
    }

    return $out;
}

/** The directive lines only — comments and blanks removed. */
function teb_htaccess_directives(string $text): string
{
    $keep = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        if (trim($line) === '' || preg_match('/^\s*#/', $line) === 1) {
            continue;
        }
        $keep[] = $line;
    }

    return implode("\n", $keep);
}

function teb_htaccess_path(): string
{
    return teb_root() . '/.htaccess';
}

function teb_htaccess_text(): string
{
    return (string) file_get_contents(teb_htaccess_path());
}

/** The production domain, read from config — never typed into this file. */
function teb_htaccess_domain(): string
{
    Config::reset();
    Config::load(teb_root());

    return (string) Config::get('site.domain', '');
}

/**
 * The exact broken pattern from the previous batch. This is the bug the guard
 * exists to catch; if the checker ever stops flagging it, the guard is dead.
 */
const TEB_OLD_BROKEN_HTACCESS = <<<'HTACCESS'
Options -Indexes
DirectoryIndex index.php

<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteCond %{HTTPS} !=on
  RewriteRule ^(.*)$ https://example.org/$1 [R=301,L]
</IfModule>
HTACCESS;

return [
    // ------------------------------------------------- the shipped file is clean

    'the shipped .htaccess exists and is not empty' => function (): void {
        assertFileExists(teb_htaccess_path());
        assertGreaterThan(100, strlen(teb_htaccess_text()), 'the file has content');
    },

    'the shipped .htaccess breaks none of the six rules' => function (): void {
        $violations = teb_htaccess_violations(
            teb_htaccess_text(),
            ['domain' => teb_htaccess_domain()]
        );
        assertSame([], $violations, "the shipped .htaccess must be clean:\n" . implode("\n", $violations));
    },

    // ------------------------------------------------- the guard catches the bug

    'the checker flags the exact pattern that broke the last batch' => function (): void {
        $violations = teb_htaccess_violations(TEB_OLD_BROKEN_HTACCESS, ['domain' => teb_htaccess_domain()]);

        assertGreaterThanOrEqual(2, count($violations), 'the old file breaks more than one rule');

        $joined = implode("\n", $violations);
        assertContains('R flag', $joined, 'the [R=301] must be caught');
        assertContains('absolute URL', $joined, 'the https:// target must be caught');
    },

    'the checker flags the same pattern written against the real production domain' => function (): void {
        $domain = teb_htaccess_domain();
        assertTrue($domain !== '', 'the test needs a configured domain to be meaningful');

        $broken = "RewriteEngine On\n"
            . "RewriteCond %{HTTPS} !=on\n"
            . 'RewriteRule ^(.*)$ https://' . $domain . '/$1 [R=301,L]' . "\n";

        $joined = implode("\n", teb_htaccess_violations($broken, ['domain' => $domain]));
        assertContains('R flag', $joined);
        assertContains('absolute URL', $joined);
        assertContains('hardcoded production domain', $joined);
    },

    'the shipped file, with the old rule put back into it, is flagged' => function (): void {
        // A mutation test on the real artefact. The two tests above prove the
        // checker works on a fixture; this one proves it works on THIS file —
        // that nothing in its structure, its IfModule blocks or its comments
        // can hide a reintroduced redirect.
        $mutated = teb_htaccess_text() . "\n\n"
            . "RewriteCond %{HTTPS} !=on\n"
            . 'RewriteRule ^(.*)$ https://' . teb_htaccess_domain() . '/$1 [R=301,L]' . "\n";

        $violations = teb_htaccess_violations($mutated, ['domain' => teb_htaccess_domain()]);
        assertGreaterThanOrEqual(3, count($violations), 'the reintroduced rule breaks three rules at once');

        $joined = implode("\n", $violations);
        assertContains('R flag', $joined);
        assertContains('absolute URL', $joined);
        assertContains('hardcoded production domain', $joined);
    },

    'a force-www redirect and a trailing-slash redirect are caught too' => function (): void {
        // The other two shapes this bug has taken in previous batches.
        $forceWww = "RewriteCond %{HTTP_HOST} !^www\\. [NC]\n"
            . 'RewriteRule ^(.*)$ https://www.%{HTTP_HOST}/$1 [R=301,L]';
        $joined = implode("\n", teb_htaccess_violations($forceWww));
        assertContains('R flag', $joined);
        assertContains('absolute URL', $joined);

        $trailingSlash = 'RewriteRule ^(.*[^/])$ /$1/ [R=301,L]';
        assertContains('R flag', implode("\n", teb_htaccess_violations($trailingSlash)));
    },

    'every spelling of the R flag is caught' => function (): void {
        $spellings = [
            'RewriteRule ^(.*)$ /x [R]',
            'RewriteRule ^(.*)$ /x [r]',
            'RewriteRule ^(.*)$ /x [R=301]',
            'RewriteRule ^(.*)$ /x [R=302]',
            'RewriteRule ^(.*)$ /x [R=301,L]',
            'RewriteRule ^(.*)$ /x [L,R=301]',
            'RewriteRule ^(.*)$ /x [NC,R,L]',
            'RewriteRule ^(.*)$ /x [ R = 301 , L ]',
            'rewriterule ^(.*)$ /x [r=301,l]',
        ];
        foreach ($spellings as $rule) {
            $found = implode("\n", teb_htaccess_violations($rule));
            assertContains('R flag', $found, 'not caught: ' . $rule);
        }
    },

    'a RewriteRule with no R flag is NOT flagged' => function (): void {
        // The guard has to be capable of passing, or "no violations" proves nothing.
        $fine = [
            'RewriteRule ^ index.php [L]',
            'RewriteRule ^data/ - [F,L]',
            'RewriteRule ^ - [L]',
            'RewriteRule ^(.*)$ index.php?r=$1 [QSA,L]',
            'RewriteRule ^old$ new [NC,NE,L]',
        ];
        foreach ($fine as $rule) {
            assertSame([], teb_htaccess_violations($rule), 'wrongly flagged: ' . $rule);
        }
    },

    'every Redirect directive is caught' => function (): void {
        foreach ([
            'Redirect 301 /old /new',
            'Redirect permanent /old /new',
            'RedirectMatch 301 ^/old(.*)$ /new$1',
            'RedirectPermanent /old /new',
            'RedirectTemp /old /new',
            'redirect 302 /a /b',
        ] as $line) {
            $found = implode("\n", teb_htaccess_violations($line));
            assertContains('Redirect directive', $found, 'not caught: ' . $line);
        }
    },

    'RewriteBase, absolute URLs and root-absolute ErrorDocuments are caught' => function (): void {
        assertContains('RewriteBase', implode("\n", teb_htaccess_violations('RewriteBase /')));
        assertContains('RewriteBase', implode("\n", teb_htaccess_violations('  rewritebase /teb/')));

        assertContains('absolute URL', implode("\n", teb_htaccess_violations('Header set Link "<http://example.org/x>"')));
        assertContains('absolute URL', implode("\n", teb_htaccess_violations('ErrorDocument 404 https://example.org/404')));

        assertContains(
            'ErrorDocument with a leading-slash path',
            implode("\n", teb_htaccess_violations('ErrorDocument 404 /404.php'))
        );
        assertContains(
            'ErrorDocument with a leading-slash path',
            implode("\n", teb_htaccess_violations('ErrorDocument 500 /error/500.html'))
        );

        // A message-style ErrorDocument is legal and must not be flagged.
        assertSame([], teb_htaccess_violations('ErrorDocument 404 "Not found"'));
    },

    // ------------------------------------------------- comment stripping matters

    'comment stripping is load-bearing: the same file fails without it' => function (): void {
        $text   = teb_htaccess_text();
        $domain = teb_htaccess_domain();

        $strict = teb_htaccess_violations($text, ['domain' => $domain, 'strip_comments' => true]);
        $naive  = teb_htaccess_violations($text, ['domain' => $domain, 'strip_comments' => false]);

        assertSame([], $strict, 'the directives are clean');
        assertGreaterThan(
            0,
            count($naive),
            'the header comment explains the rule in words, so a checker that did not strip '
            . 'comments would flag the documentation — proving the strip is doing real work'
        );
    },

    'the file really does carry the explanatory comment it is judged on' => function (): void {
        // Positive control for the test above: if the comment were ever deleted,
        // the "naive check fails" assertion would silently become vacuous.
        $text = teb_htaccess_text();
        assertMatches('/^\s*#/m', $text, 'there are comment lines');
        assertContains('redirect', strtolower($text), 'the header explains the no-redirect rule');
    },

    // ------------------------------------------------- the front controller works

    'the internal front-controller rewrite is present and carries no R flag' => function (): void {
        $directives = teb_htaccess_directives(teb_htaccess_text());

        assertMatches('/\bRewriteEngine\s+On\b/i', $directives, 'mod_rewrite is switched on');

        $rules = [];
        foreach (preg_split('/\R/', $directives) ?: [] as $line) {
            if (preg_match('/^\s*RewriteRule\s+(\S+)\s+(\S+)/i', $line, $m) === 1) {
                $rules[] = ['pattern' => $m[1], 'target' => $m[2], 'line' => trim($line)];
            }
        }
        assertGreaterThan(0, count($rules), 'there is at least one RewriteRule');

        $front = null;
        foreach ($rules as $rule) {
            if (strpos($rule['target'], 'index.php') !== false) {
                $front = $rule;
                break;
            }
        }
        assertNotNull($front, 'a rule hands unmatched requests to index.php: ' . $directives);
        assertSame([], teb_htaccess_violations($front['line']), 'the front-controller rule is internal only');
    },

    'the front-controller target is relative, so it survives a subdirectory install' => function (): void {
        $directives = teb_htaccess_directives(teb_htaccess_text());

        foreach (preg_split('/\R/', $directives) ?: [] as $line) {
            if (preg_match('/^\s*RewriteRule\s+(\S+)\s+(\S+)/i', $line, $m) !== 1) {
                continue;
            }
            $target = $m[2];
            if ($target === '-') {
                continue;                   // "make no substitution" — always fine
            }
            assertFalse(
                $target[0] === '/',
                'a substitution starting with / resolves against the SERVER root, not the '
                . 'install directory, so it 404s in a subfolder: ' . trim($line)
            );
        }
    },

    'real files and directories are served without touching the front controller' => function (): void {
        $directives = teb_htaccess_directives(teb_htaccess_text());
        assertMatches('/REQUEST_FILENAME\}?\s+-f/i', $directives, 'existing files are passed through');
        assertMatches('/REQUEST_FILENAME\}?\s+-d/i', $directives, 'existing directories are passed through');
    },

    // ------------------------------------------------- data/ is not web readable

    'the root .htaccess forbids data/ rather than redirecting away from it' => function (): void {
        $directives = teb_htaccess_directives(teb_htaccess_text());

        assertMatches(
            '#RewriteRule\s+\^data/\s+-\s+\[[^\]]*F#i',
            $directives,
            'data/ is answered with 403, not a redirect'
        );
    },

    'data/.htaccess denies access on both Apache authorisation modules' => function (): void {
        $path = teb_root() . '/data/.htaccess';
        assertFileExists($path);

        $text = (string) file_get_contents($path);
        assertContains('Require all denied', $text, 'Apache 2.4');
        assertContains('Deny from all', $text, 'Apache 2.2');
        assertSame([], teb_htaccess_violations($text, ['domain' => teb_htaccess_domain()]));
    },

    // ------------------------------------------------- housekeeping directives

    'directory listings are off and index.php is the directory index' => function (): void {
        $directives = teb_htaccess_directives(teb_htaccess_text());
        assertMatches('/Options\s+-Indexes/i', $directives);
        assertMatches('/DirectoryIndex\s+index\.php/i', $directives);
    },

    'the database, its lock and the log files cannot be fetched over HTTP' => function (): void {
        $directives = teb_htaccess_directives(teb_htaccess_text());
        assertMatches('/FilesMatch/i', $directives, 'there is a FilesMatch guard');
        foreach (['sqlite', 'db', 'lock'] as $ext) {
            assertContains($ext, $directives, 'the ' . $ext . ' extension is covered');
        }
    },

    // ------------------------------------------------- no stray copies

    'the only .htaccess files in the project are the root one and data/' => function (): void {
        $root  = teb_root();
        $found = [];

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            $rel = ltrim(str_replace($root, '', $file->getPathname()), '/');
            if (strpos($rel, '.git/') === 0 || strpos($rel, 'dist/') === 0 || strpos($rel, 'node_modules/') === 0) {
                continue;
            }
            if ($file->isFile() && $file->getFilename() === '.htaccess') {
                $found[] = $rel;
            }
        }
        sort($found);

        assertSame(
            ['.htaccess', 'data/.htaccess'],
            $found,
            'a second .htaccess outside data/ would be a second place for a redirect to hide'
        );
    },
];
