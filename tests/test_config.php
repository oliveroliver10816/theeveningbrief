<?php
declare(strict_types=1);

/**
 * config.php · TEB\Config · TEB\Paths · TEB\Feeds
 *
 * The heart of this file is the table in paths_cases(): the same six
 * $_SERVER fixtures — a web root, a /teb subfolder and an /a/b subfolder,
 * each with the rewrite on and off — walked through base(), url(), asset(),
 * absolute() and currentRoute(), asserting the exact strings. If the site
 * ever stops working in a subdirectory, or stops working on a host that
 * ignores .htaccess, one of these goes red.
 */

require_once __DIR__ . '/lib.php';
teb_require_app('Config', 'Paths', 'Feeds');

use TEB\Config;
use TEB\Feeds;
use TEB\Paths;

/**
 * Six installations × two rewrite states. 'root' is where the app is unzipped
 * on disk; SCRIPT_FILENAME sits inside it exactly as Apache would report.
 */
function paths_cases(): array
{
    return [
        'web root, rewrite on' => [
            'root'   => '/srv/www/htdocs',
            'server' => [
                'SCRIPT_NAME'     => '/index.php',
                'SCRIPT_FILENAME' => '/srv/www/htdocs/index.php',
                'DOCUMENT_ROOT'   => '/srv/www/htdocs',
                'REQUEST_URI'     => '/section/us',
                'QUERY_STRING'    => '',
                'HTTP_HOST'       => 'example.com',
                'HTTPS'           => 'on',
            ],
            'rewrite'  => true,
            'base'     => '',
            'route'    => '/section/us',
            'urls'     => [
                '/'                    => '/',
                '/section/us'          => '/section/us',
                '/article/talks-1421'  => '/article/talks-1421',
                '/search?q=iran'       => '/search?q=iran',
                '/feed.xml'            => '/feed.xml',
                '/weather'             => '/weather',
            ],
            'asset'    => '/assets/css/site.css',
            'absolute' => 'https://example.com/section/us',
        ],

        'web root, no rewrite' => [
            'root'   => '/srv/www/htdocs',
            'server' => [
                'SCRIPT_NAME'     => '/index.php',
                'SCRIPT_FILENAME' => '/srv/www/htdocs/index.php',
                'DOCUMENT_ROOT'   => '/srv/www/htdocs',
                'REQUEST_URI'     => '/index.php?r=/section/us',
                'QUERY_STRING'    => 'r=/section/us',
                'HTTP_HOST'       => 'example.com',
                'HTTPS'           => 'on',
            ],
            'rewrite'  => false,
            'base'     => '',
            'route'    => '/section/us',
            'urls'     => [
                '/'                    => '/',
                '/section/us'          => '/index.php?r=/section/us',
                '/article/talks-1421'  => '/index.php?r=/article/talks-1421',
                '/search?q=iran'       => '/index.php?r=/search&q=iran',
                '/feed.xml'            => '/index.php?r=/feed.xml',
                '/weather'             => '/index.php?r=/weather',
            ],
            'asset'    => '/assets/css/site.css',
            'absolute' => 'https://example.com/index.php?r=/section/us',
        ],

        'subdirectory /teb, rewrite on' => [
            'root'   => '/home/user/public_html/teb',
            'server' => [
                'SCRIPT_NAME'     => '/teb/index.php',
                'SCRIPT_FILENAME' => '/home/user/public_html/teb/index.php',
                'DOCUMENT_ROOT'   => '/home/user/public_html',
                'REQUEST_URI'     => '/teb/section/us',
                'QUERY_STRING'    => '',
                'HTTP_HOST'       => 'staging.example.net',
                'HTTPS'           => 'on',
            ],
            'rewrite'  => true,
            'base'     => '/teb',
            'route'    => '/section/us',
            'urls'     => [
                '/'                    => '/teb/',
                '/section/us'          => '/teb/section/us',
                '/article/talks-1421'  => '/teb/article/talks-1421',
                '/search?q=iran'       => '/teb/search?q=iran',
                '/feed.xml'            => '/teb/feed.xml',
                '/weather'             => '/teb/weather',
            ],
            'asset'    => '/teb/assets/css/site.css',
            'absolute' => 'https://staging.example.net/teb/section/us',
        ],

        'subdirectory /teb, no rewrite' => [
            'root'   => '/home/user/public_html/teb',
            'server' => [
                'SCRIPT_NAME'     => '/teb/index.php',
                'SCRIPT_FILENAME' => '/home/user/public_html/teb/index.php',
                'DOCUMENT_ROOT'   => '/home/user/public_html',
                'REQUEST_URI'     => '/teb/index.php?r=/section/us',
                'QUERY_STRING'    => 'r=/section/us',
                'HTTP_HOST'       => 'staging.example.net',
                'HTTPS'           => 'on',
            ],
            'rewrite'  => false,
            'base'     => '/teb',
            'route'    => '/section/us',
            'urls'     => [
                '/'                    => '/teb/',
                '/section/us'          => '/teb/index.php?r=/section/us',
                '/article/talks-1421'  => '/teb/index.php?r=/article/talks-1421',
                '/search?q=iran'       => '/teb/index.php?r=/search&q=iran',
                '/feed.xml'            => '/teb/index.php?r=/feed.xml',
                '/weather'             => '/teb/index.php?r=/weather',
            ],
            'asset'    => '/teb/assets/css/site.css',
            'absolute' => 'https://staging.example.net/teb/index.php?r=/section/us',
        ],

        'nested /a/b, rewrite on' => [
            'root'   => '/var/www/html/a/b',
            'server' => [
                'SCRIPT_NAME'     => '/a/b/index.php',
                'SCRIPT_FILENAME' => '/var/www/html/a/b/index.php',
                'DOCUMENT_ROOT'   => '/var/www/html',
                'REQUEST_URI'     => '/a/b/section/us',
                'QUERY_STRING'    => '',
                'HTTP_HOST'       => '203.0.113.10:8080',
                'SERVER_PORT'     => '8080',
            ],
            'rewrite'  => true,
            'base'     => '/a/b',
            'route'    => '/section/us',
            'urls'     => [
                '/'                    => '/a/b/',
                '/section/us'          => '/a/b/section/us',
                '/article/talks-1421'  => '/a/b/article/talks-1421',
                '/search?q=iran'       => '/a/b/search?q=iran',
                '/feed.xml'            => '/a/b/feed.xml',
                '/weather'             => '/a/b/weather',
            ],
            'asset'    => '/a/b/assets/css/site.css',
            'absolute' => 'http://203.0.113.10:8080/a/b/section/us',
        ],

        'nested /a/b, no rewrite' => [
            'root'   => '/var/www/html/a/b',
            'server' => [
                'SCRIPT_NAME'     => '/a/b/index.php',
                'SCRIPT_FILENAME' => '/var/www/html/a/b/index.php',
                'DOCUMENT_ROOT'   => '/var/www/html',
                'REQUEST_URI'     => '/a/b/index.php?r=/section/us',
                'QUERY_STRING'    => 'r=/section/us',
                'HTTP_HOST'       => '203.0.113.10:8080',
                'SERVER_PORT'     => '8080',
            ],
            'rewrite'  => false,
            'base'     => '/a/b',
            'route'    => '/section/us',
            'urls'     => [
                '/'                    => '/a/b/',
                '/section/us'          => '/a/b/index.php?r=/section/us',
                '/article/talks-1421'  => '/a/b/index.php?r=/article/talks-1421',
                '/search?q=iran'       => '/a/b/index.php?r=/search&q=iran',
                '/feed.xml'            => '/a/b/index.php?r=/feed.xml',
                '/weather'             => '/a/b/index.php?r=/weather',
            ],
            'asset'    => '/a/b/assets/css/site.css',
            'absolute' => 'http://203.0.113.10:8080/a/b/index.php?r=/section/us',
        ],
    ];
}

/** Put Paths into a known state: no cache file, no network, rewrite pinned. */
function paths_boot(array $case): void
{
    Paths::init($case['server'], $case['root']);
    Paths::allowProbe(false);
    Paths::forceRewrite($case['rewrite']);
}

return [

    // ------------------------------------------------------------ config.php

    'config.php parses and returns every top-level section' => function (): void {
        $raw = require teb_root() . '/config.php';
        assertTrue(is_array($raw), 'config.php must return an array');
        foreach (['site', 'db', 'ingest', 'compose', 'ads', 'weather', 'cache'] as $key) {
            assertArrayHasKey($key, $raw);
        }
    },

    'config defaults are the ones the spec fixed' => function (): void {
        Config::reset();
        $c = Config::load(teb_root());

        assertSame('The Evening Brief', $c['site']['name']);
        assertSame('TEB', $c['site']['short_name']);
        assertSame('theeveningbrief.com', $c['site']['domain']);
        assertSame('America/New_York', $c['site']['timezone']);
        assertSame('sqlite', $c['db']['driver']);
        assertFalse($c['ads']['enabled']);

        assertSame(2, $c['compose']['finance_max_on_home']);
        assertSame(['hero', 'us', 'international'], $c['compose']['finance_blocked_blocks']);
        assertSame(4, $c['compose']['hero_sub_count']);
        assertSame(2, $c['compose']['per_source_cap_per_block']);
        assertSame(12, $c['compose']['ticker_count']);

        assertTrue($c['ingest']['auto_on_empty']);
        assertSame(20, $c['ingest']['stale_after_minutes']);
        assertSame(2, $c['ingest']['retention_days']);
    },

    'Config::get reads dotted paths and honours the default' => function (): void {
        Config::reset();
        Config::load(teb_root());

        assertSame('The Evening Brief', Config::get('site.name'));
        assertSame(2, Config::get('compose.finance_max_on_home'));
        assertSame([970, 250], Config::get('ads.slots.leaderboard'));
        assertNull(Config::get('site.no_such_key'));
        assertSame('fallback', Config::get('nothing.here.at.all', 'fallback'));
        assertSame('fallback', Config::get('site.name.deeper', 'fallback'));
    },

    'Config::get works before load(), using the defaults' => function (): void {
        Config::reset();
        assertFalse(Config::isLoaded());
        assertTrue(is_string(Config::get('site.name')));
        assertSame('sqlite', Config::get('db.driver'));
        Config::load(teb_root());
    },

    'a partial config.php is merged over the defaults, not swapped in' => function (): void {
        $dir = teb_tmp_dir('cfg');
        file_put_contents($dir . '/config.php', '<?php return ' . var_export([
            'site'    => ['name' => 'The Morning Wire'],
            'compose' => ['ticker_count' => 5],
        ], true) . ';');

        Config::reset();
        $c = Config::load($dir);

        assertSame('The Morning Wire', $c['site']['name']);      // overridden
        assertSame(5, $c['compose']['ticker_count']);            // overridden
        assertSame(2, $c['compose']['finance_max_on_home']);     // default kept
        assertSame('sqlite', $c['db']['driver']);                // whole section kept
        assertSame('MW', $c['site']['short_name'],
            'a renamed site must not inherit the placeholder short name — initials are derived');
        Config::reset();
        Config::load(teb_root());
    },

    'a missing config.php still produces a complete configuration' => function (): void {
        $dir = teb_tmp_dir('cfg-none');
        Config::reset();
        $c = Config::load($dir);

        foreach (['site', 'db', 'ingest', 'compose', 'ads', 'weather', 'cache'] as $key) {
            assertArrayHasKey($key, $c);
        }
        assertSame('sqlite', $c['db']['driver']);
        assertTrue($c['site']['name'] !== '');
        Config::reset();
        Config::load(teb_root());
    },

    'weather.places and ads.slots are replaced by the client, never merged' => function (): void {
        $dir = teb_tmp_dir('cfg-replace');
        file_put_contents($dir . '/config.php', '<?php return ' . var_export([
            'weather' => [
                'default_place' => 'austin',
                'places'        => ['austin' => ['name' => 'Austin', 'region' => 'TX', 'lat' => 30.2672, 'lon' => -97.7431, 'timezone' => 'America/Chicago']],
            ],
            'ads' => ['slots' => ['banner' => [320, 50]]],
        ], true) . ';');

        Config::reset();
        $c = Config::load($dir);

        assertSame(['austin'], array_keys($c['weather']['places']));
        assertSame('austin', $c['weather']['default_place']);
        assertSame(['banner'], array_keys($c['ads']['slots']));
        Config::reset();
        Config::load(teb_root());
    },

    'a mistyped config is repaired instead of taking the site down' => function (): void {
        $dir = teb_tmp_dir('cfg-bad');
        file_put_contents($dir . '/config.php', '<?php return ' . var_export([
            'site'    => ['timezone' => 'Mars/Olympus', 'domain' => 'https://WWW.Example.com/news/', 'theme_color' => 'blue'],
            'db'      => ['driver' => 'MySQL', 'name' => '', 'user' => ''],
            'ingest'  => ['enabled' => 'yes', 'retention_days' => '9999999'],
            'compose' => ['ticker_count' => '99', 'hero_sub_count' => -4, 'finance_blocked_blocks' => 'hero'],
            'ads'     => ['enabled' => 'true'],
            'weather' => ['default_place' => 'nowhere'],
        ], true) . ';');

        Config::reset();
        $c = Config::load($dir);

        assertSame('UTC', $c['site']['timezone'], 'an unknown timezone falls back');
        assertSame('example.com', $c['site']['domain'], 'scheme, www and path are stripped');
        assertSame('#FBFAF7', $c['site']['theme_color'], 'a non-hex colour falls back');
        assertSame('sqlite', $c['db']['driver'], 'mysql with no database name would 500 every page');
        assertTrue($c['ingest']['enabled'], '"yes" means true');
        assertTrue($c['ads']['enabled'], '"true" means true');
        assertSame(3650, $c['ingest']['retention_days'], 'clamped to the maximum');
        assertSame(60, $c['compose']['ticker_count'], 'clamped to the maximum');
        assertSame(0, $c['compose']['hero_sub_count'], 'clamped to the minimum');
        assertSame(
            ['hero', 'us', 'international'],
            $c['compose']['finance_blocked_blocks'],
            'a string where a list belongs falls back to the shipped list — dropping it would silently delete a guard rail'
        );
        assertTrue(isset($c['weather']['places'][$c['weather']['default_place']]), 'default place always exists');

        Config::reset();
        Config::load(teb_root());
    },

    'a whole section typed as a scalar keeps the shipped section, and never fatals' => function (): void {
        // config.php is the one file edited by hand, so a section can lose its
        // brackets: 'site' => 'The Wire', 'compose' => 7, 'ads' => false.
        // Before this was fixed each of those took the entire site down —
        // "Cannot access offset of type string on string", "Cannot use a
        // scalar value as an array", or a PHP 8.1 deprecation notice printed
        // above the doctype — on EVERY url, from a one-character typo.
        $dir = teb_tmp_dir('cfg-scalar');

        $broken = [
            "'site' as a string"   => "<?php return ['site' => 'The Wire'];",
            "'site' as false"      => "<?php return ['site' => false];",
            "'db' as null"         => "<?php return ['db' => null];",
            "'compose' as an int"  => "<?php return ['compose' => 7];",
            "'ads' as a string"    => "<?php return ['ads' => 'off'];",
            "'weather' as a float" => "<?php return ['weather' => 1.5];",
            'a bare string'        => "<?php return 'nope';",
            'a bare int'           => '<?php return 7;',
            'nothing at all'       => '<?php',
        ];

        foreach ($broken as $label => $src) {
            file_put_contents($dir . '/config.php', $src);
            Config::reset();

            // A warning or a deprecation is a failure here too: it prints
            // above the doctype on a live page and breaks the document.
            $noise = [];
            set_error_handler(static function (int $no, string $msg) use (&$noise): bool {
                $noise[] = $msg;

                return true;
            });
            try {
                $c = Config::load($dir);
            } finally {
                restore_error_handler();
            }

            assertSame([], $noise, $label . ' raised PHP diagnostics');
            foreach (['site', 'db', 'ingest', 'compose', 'ads', 'weather', 'cache'] as $section) {
                assertTrue(is_array($c[$section] ?? null), $label . ": '" . $section . "' must survive as a section");
            }
            assertSame('News', $c['site']['name'], $label . ': the shipped default name stands in');
            assertSame('sqlite', $c['db']['driver'], $label);
            assertSame(12, $c['compose']['ticker_count'], $label . ': an unusable section falls back whole');
        }

        // The good half of a half-broken file is still honoured.
        file_put_contents($dir . '/config.php', "<?php return ['site' => 'The Wire', 'compose' => ['ticker_count' => 7]];");
        Config::reset();
        $c = Config::load($dir);
        assertSame(7, $c['compose']['ticker_count'], 'one broken section must not discard the sections around it');

        Config::reset();
        Config::load(teb_root());
    },

    'a mistyped list falls back to the shipped list, not to no protection' => function (): void {
        // finance_blocked_blocks is the front page's guard rail. Typing it as
        // a bare string used to leave it EMPTY, which is the one outcome that
        // silently removes a protection the client asked for — every other
        // unusable value in this file falls back to the shipped default.
        $dir = teb_tmp_dir('cfg-list');
        file_put_contents($dir . '/config.php', "<?php return ['compose' => ['finance_blocked_blocks' => 'hero']];");
        Config::reset();
        $c = Config::load($dir);
        assertSame(['hero', 'us', 'international'], $c['compose']['finance_blocked_blocks']);

        // An explicitly empty list is a decision, not a typo, and is obeyed.
        file_put_contents($dir . '/config.php', "<?php return ['compose' => ['finance_blocked_blocks' => []]];");
        Config::reset();
        $c = Config::load($dir);
        assertSame([], $c['compose']['finance_blocked_blocks']);

        Config::reset();
        Config::load(teb_root());
    },

    'the sqlite path is resolved against the project root, not the working directory' => function (): void {
        $dir = teb_tmp_dir('cfg-sqlite');
        file_put_contents($dir . '/config.php', '<?php return ' . var_export(['db' => ['sqlite_path' => 'data/news.sqlite']], true) . ';');

        Config::reset();
        $c = Config::load($dir);
        assertSame($dir . '/data/news.sqlite', $c['db']['sqlite_path'],
            'cron runs from another directory and must open the same file the web request does');

        Config::reset();
        Config::load(teb_root());
    },

    // -------------------------------------------------- the subdirectory table

    'base() is derived from SCRIPT_NAME in every installation' => function (): void {
        foreach (paths_cases() as $label => $case) {
            paths_boot($case);
            assertSame($case['base'], Paths::base(), $label);
            assertNotSame('/', substr(Paths::base() . 'x', -2, 1), $label . ': base never ends in a slash');
        }
    },

    'url() emits the exact string for every route, in both URL styles' => function (): void {
        foreach (paths_cases() as $label => $case) {
            paths_boot($case);
            foreach ($case['urls'] as $path => $expected) {
                assertSame($expected, Paths::url($path), $label . ' — url(' . $path . ')');
            }
        }
    },

    'every emitted URL starts with the base path and has no doubled slash' => function (): void {
        foreach (paths_cases() as $label => $case) {
            paths_boot($case);
            $base = Paths::base();
            foreach (array_keys($case['urls']) as $path) {
                $url = Paths::url($path);
                assertSame(0, strpos($url, $base === '' ? '/' : $base), $label . ' — ' . $url . ' must start with the base');
                assertNotContains('//', $url, $label . ' — ' . $url);
                assertNotSame('//', substr($url, 0, 2), $label . ' — must not be protocol-relative');
            }
        }
    },

    'currentRoute() strips the base whether the request was rewritten or not' => function (): void {
        foreach (paths_cases() as $label => $case) {
            paths_boot($case);
            assertSame($case['route'], Paths::currentRoute(), $label);
        }
    },

    'asset() points inside the install and cache-busts on the file time' => function (): void {
        foreach (paths_cases() as $label => $case) {
            paths_boot($case);
            // The file does not exist under these fictional roots, so there is
            // no ?v= to add — but the PATH must still be right.
            assertSame($case['asset'], Paths::asset('css/site.css'), $label);
            assertSame($case['asset'], Paths::asset('/assets/css/site.css'), $label . ' — leading slash and assets/ prefix are both accepted');
        }

        // Now with a real file, to prove the cache-buster appears.
        $dir = teb_tmp_dir('asset');
        mkdir($dir . '/assets/css', 0777, true);
        file_put_contents($dir . '/assets/css/site.css', 'body{}');
        touch($dir . '/assets/css/site.css', 1755600000);

        Paths::init(['SCRIPT_NAME' => '/teb/index.php', 'SCRIPT_FILENAME' => $dir . '/index.php', 'REQUEST_URI' => '/teb/'], $dir);
        Paths::allowProbe(false);
        Paths::forceRewrite(true);

        assertSame('/teb/assets/css/site.css?v=1755600000', Paths::asset('css/site.css'));
        assertSame('/teb/assets/js/app.js', Paths::asset('js/app.js'), 'a missing file must not emit ?v=');
    },

    'absolute() builds canonical URLs from the request host' => function (): void {
        foreach (paths_cases() as $label => $case) {
            paths_boot($case);
            assertSame($case['absolute'], Paths::absolute('/section/us'), $label);
        }
    },

    // -------------------------------------------------------- host injection

    'a CRLF-injected Host header is rejected' => function (): void {
        $case = paths_cases()['subdirectory /teb, rewrite on'];
        $case['server']['HTTP_HOST']   = "evil.com\r\nX:";
        $case['server']['SERVER_NAME'] = 'good.example';
        paths_boot($case);

        $url = Paths::absolute('/section/us');

        assertSame('https://good.example/teb/section/us', $url);
        assertNotContains("\r", $url);
        assertNotContains("\n", $url);
        assertNotContains('evil.com', $url);
        assertNotContains('evil.com', Paths::host());
    },

    'other malformed hosts fall through to SERVER_NAME, then to config' => function (): void {
        $base = paths_cases()['web root, rewrite on'];

        $bad = [
            "evil.com\r\nX:",
            "evil.com\nSet-Cookie: a=b",
            'evil.com/path',
            'user:pass@evil.com',
            'evil com',
            '',
            '-leadinghyphen.example',
            str_repeat('a', 300) . '.example',
        ];

        foreach ($bad as $host) {
            $case = $base;
            $case['server']['HTTP_HOST']   = $host;
            $case['server']['SERVER_NAME'] = 'fallback.example';
            paths_boot($case);
            assertSame('https://fallback.example/section/us', Paths::absolute('/section/us'), 'host ' . teb_dump($host));
        }

        // With no usable request host at all, config.site.domain is the last
        // resort — this is the CLI/cron case.
        Config::reset();
        Config::load(teb_root());
        $case = $base;
        $case['server']['HTTP_HOST']   = "evil.com\r\nX:";
        $case['server']['SERVER_NAME'] = '';
        paths_boot($case);
        assertSame('https://' . Config::get('site.domain') . '/section/us', Paths::absolute('/section/us'));
    },

    'a good host with a port survives, and a default port is dropped' => function (): void {
        $case = paths_cases()['web root, rewrite on'];

        $case['server']['HTTP_HOST'] = 'example.com:8000';
        paths_boot($case);
        assertSame('https://example.com:8000/section/us', Paths::absolute('/section/us'));

        $case['server']['HTTP_HOST'] = 'example.com:443';
        paths_boot($case);
        assertSame('https://example.com/section/us', Paths::absolute('/section/us'));

        $case['server']['HTTP_HOST'] = 'EXAMPLE.COM';
        paths_boot($case);
        assertSame('https://example.com/section/us', Paths::absolute('/section/us'));
    },

    'a CDN in front of a plain-HTTP origin still yields https URLs' => function (): void {
        $case = paths_cases()['web root, rewrite on'];
        unset($case['server']['HTTPS']);
        $case['server']['HTTP_X_FORWARDED_PROTO'] = 'https,http';
        paths_boot($case);
        assertSame('https://example.com/section/us', Paths::absolute('/section/us'));
    },

    // ------------------------------------------------------------- routing

    'currentRoute() normalises every shape a request can arrive in' => function (): void {
        $root = '/home/user/public_html/teb';
        $base = [
            'SCRIPT_NAME'     => '/teb/index.php',
            'SCRIPT_FILENAME' => $root . '/index.php',
            'DOCUMENT_ROOT'   => '/home/user/public_html',
            'HTTP_HOST'       => 'example.com',
        ];

        $cases = [
            ['/teb/section/us',            '',                 '/section/us'],
            ['/teb/section/us/',           '',                 '/section/us'],
            ['/teb/',                      '',                 '/'],
            ['/teb',                       '',                 '/'],
            ['/teb/index.php',             '',                 '/'],
            ['/teb//section//us',          '',                 '/section/us'],
            ['/teb/weather?place=chicago', 'place=chicago',    '/weather'],
            ['/teb/index.php?r=/weather',  'r=/weather',       '/weather'],
            ['/teb/index.php?r=weather',   'r=weather',        '/weather'],
            ['/teb/index.php?r=/teb/weather', 'r=/teb/weather', '/weather'],
            ['/teb/article/a-b-c-12',      '',                 '/article/a-b-c-12'],
            ['/teb/section/us%20east',     '',                 '/section/us east'],
            ['/teb/../../etc/passwd',      '',                 '/etc/passwd'],
            ['/teb/search?q=a&r=/hacked',  'q=a&r=/hacked',    '/hacked'],
        ];

        foreach ($cases as [$uri, $qs, $expected]) {
            Paths::init($base + ['REQUEST_URI' => $uri, 'QUERY_STRING' => $qs], $root);
            Paths::allowProbe(false);
            Paths::forceRewrite(true);
            assertSame($expected, Paths::currentRoute(), 'REQUEST_URI ' . $uri);
        }
    },

    'currentRoute() is / on the command line' => function (): void {
        Paths::init([], teb_root());
        Paths::allowProbe(false);
        assertSame('/', Paths::currentRoute());
        assertSame('', Paths::base());
    },

    'base() is right for a second entry point such as cron/ingest.php' => function (): void {
        // A plain dirname(SCRIPT_NAME) would answer '/teb/cron' here and every
        // link on the page would 404.
        Paths::init([
            'SCRIPT_NAME'     => '/teb/cron/ingest.php',
            'SCRIPT_FILENAME' => '/home/user/public_html/teb/cron/ingest.php',
            'DOCUMENT_ROOT'   => '/home/user/public_html',
            'REQUEST_URI'     => '/teb/cron/ingest.php',
        ], '/home/user/public_html/teb');
        Paths::allowProbe(false);
        Paths::forceRewrite(true);

        assertSame('/teb', Paths::base());
        assertSame('/teb/section/us', Paths::url('/section/us'));
    },

    'PHP built-in server variables produce a web-root install' => function (): void {
        // Measured from a live `php -S 127.0.0.1:PORT index.php` router.
        //
        // ⚠ The root MUST be a fresh temp directory, not a fictional path.
        // hasRewrite() writes its answer to <root>/data/cache/rewrite.json,
        // and this suite runs as root on the build box: with a made-up root
        // like /srv/app the mkdir SUCCEEDS, the file is left on the machine,
        // and from the second run onward the assertion below is satisfied by
        // that stale file instead of by the detection it is supposed to be
        // testing. Verified: with evidenceFromRequest() hard-wired to false
        // the old version of this test still passed.
        $dir = teb_tmp_dir('rw-builtin');
        assertFalse(is_file($dir . '/data/cache/rewrite.json'), 'the run must start with no cached answer');

        Paths::init([
            'SCRIPT_NAME'     => '/index.php',
            'SCRIPT_FILENAME' => $dir . '/index.php',
            'PHP_SELF'        => '/index.php/section/us',
            'PATH_INFO'       => '/section/us',
            'DOCUMENT_ROOT'   => $dir,
            'REQUEST_URI'     => '/section/us',
            'HTTP_HOST'       => '127.0.0.1:8080',
        ], $dir);
        Paths::allowProbe(false);

        assertSame('', Paths::base(), 'PHP_SELF carries PATH_INFO and must not be preferred over SCRIPT_NAME');
        assertSame('/section/us', Paths::currentRoute());
        assertTrue(Paths::hasRewrite(), 'the built-in server routes every path to the front controller');

        // The counter-fixture, in a root of its own so no cache can carry an
        // answer across: the identical server array minus the evidence must
        // come back false. Together the two prove the answer is computed from
        // the request and not read off the disk.
        $bare = teb_tmp_dir('rw-builtin-bare');
        Paths::init([
            'SCRIPT_NAME'     => '/index.php',
            'SCRIPT_FILENAME' => $bare . '/index.php',
            'DOCUMENT_ROOT'   => $bare,
            'REQUEST_URI'     => '/',
            'HTTP_HOST'       => '127.0.0.1:8080',
        ], $bare);
        Paths::allowProbe(false);
        assertFalse(Paths::hasRewrite(), 'the front page alone is not evidence of a rewrite');
    },

    'Paths::init() works with the one argument the contract specifies' => function (): void {
        // CONTRACT.md declares init(array $server): void, and index.php will
        // call it exactly that way. Nothing else in this file exercises the
        // defaulted root, so a change to it would go unnoticed until upload.
        Paths::init([
            'SCRIPT_NAME'     => '/index.php',
            'SCRIPT_FILENAME' => teb_root() . '/index.php',
            'DOCUMENT_ROOT'   => teb_root(),
            'REQUEST_URI'     => '/',
            'HTTP_HOST'       => 'example.com',
        ]);
        Paths::allowProbe(false);
        Paths::forceRewrite(true);

        assertSame(teb_root(), Paths::root(), 'the root defaults to the folder holding config.php');
        assertSame(teb_root() . '/data', Paths::dataDir());
        assertMatches(
            '#^/assets/css/site\.css\?v=\d+$#',
            Paths::asset('css/site.css'),
            'the defaulted root must find the real stylesheet, or every page ships an unversioned one'
        );
    },

    'TEB_BASE overrides detection for a proxy that mounts us under a prefix' => function (): void {
        Paths::init([
            'SCRIPT_NAME'     => '/index.php',
            'SCRIPT_FILENAME' => '/srv/app/index.php',
            'REQUEST_URI'     => '/news/section/us',
            'TEB_BASE'        => '/news',
            'HTTP_HOST'       => 'example.com',
        ], '/srv/app');
        Paths::allowProbe(false);
        Paths::forceRewrite(true);

        assertSame('/news', Paths::base());
        assertSame('/news/section/us', Paths::url('/section/us'));
        assertSame('/section/us', Paths::currentRoute());
    },

    'external and in-page links are handed back untouched' => function (): void {
        paths_boot(paths_cases()['subdirectory /teb, rewrite on']);

        foreach ([
            'https://www.nytimes.com/2026/08/19/us/story.html',
            'http://example.org/x',
            '//cdn.example.com/a.png',
            'mailto:desk@example.com',
            '#main',
        ] as $link) {
            assertSame($link, Paths::url($link), 'a publisher link must never be prefixed with our base path');
        }

        // absolute() has to agree with url(). It used to test only for '//',
        // so mailto: and tel: fell through and came back with our origin glued
        // to the front — 'https://staging.example.netmailto:desk@example.com'
        // — which is a dead link anywhere it lands, canonical tags included.
        foreach ([
            'https://www.nytimes.com/2026/08/19/us/story.html',
            '//cdn.example.com/a.png',
            'mailto:desk@example.com',
            'tel:+15550100',
        ] as $link) {
            assertSame($link, Paths::absolute($link), 'absolute() must not prefix an address that is already one');
        }
    },

    // ----------------------------------------------------------- hasRewrite

    'hasRewrite() proves itself from the request when a pretty URL arrives' => function (): void {
        $dir = teb_tmp_dir('rw-yes');
        Paths::init([
            'SCRIPT_NAME'     => '/teb/index.php',
            'SCRIPT_FILENAME' => $dir . '/index.php',
            'REQUEST_URI'     => '/teb/section/us',
            'QUERY_STRING'    => '',
            'HTTP_HOST'       => 'example.com',
        ], $dir);
        Paths::allowProbe(false);

        assertTrue(Paths::hasRewrite(), 'nothing but the internal rewrite delivers /teb/section/us to index.php');
        assertSame('/teb/section/us', Paths::url('/section/us'));
    },

    'hasRewrite() defaults to the safe ?r= form when it cannot tell' => function (): void {
        $dir = teb_tmp_dir('rw-unknown');

        // The front page: served by DirectoryIndex, so it proves nothing.
        Paths::init([
            'SCRIPT_NAME'     => '/index.php',
            'SCRIPT_FILENAME' => $dir . '/index.php',
            'REQUEST_URI'     => '/',
            'QUERY_STRING'    => '',
            'HTTP_HOST'       => 'example.com',
        ], $dir);
        Paths::allowProbe(false);

        assertFalse(Paths::hasRewrite(), 'unknown must mean ?r=, which works on every host');
        assertSame('/index.php?r=/section/us', Paths::url('/section/us'));
    },

    'a request that arrived as ?r= is not evidence of a rewrite' => function (): void {
        $dir = teb_tmp_dir('rw-r');
        Paths::init([
            'SCRIPT_NAME'     => '/index.php',
            'SCRIPT_FILENAME' => $dir . '/index.php',
            'REQUEST_URI'     => '/index.php?r=/section/us',
            'QUERY_STRING'    => 'r=/section/us',
            'HTTP_HOST'       => 'example.com',
        ], $dir);
        Paths::allowProbe(false);

        assertFalse(Paths::hasRewrite());
    },

    'PATH_INFO front controllers are not mistaken for a rewrite' => function (): void {
        $dir = teb_tmp_dir('rw-pathinfo');
        Paths::init([
            'SCRIPT_NAME'     => '/index.php',
            'SCRIPT_FILENAME' => $dir . '/index.php',
            'REQUEST_URI'     => '/index.php/section/us',
            'QUERY_STRING'    => '',
            'HTTP_HOST'       => 'example.com',
        ], $dir);
        Paths::allowProbe(false);

        assertFalse(Paths::hasRewrite(), '/index.php/... needs no rewrite, so it proves none is running');
    },

    'hasRewrite() caches its answer under data/ and reuses it' => function (): void {
        $dir = teb_tmp_dir('rw-cache');
        mkdir($dir . '/data', 0777, true);

        Paths::init([
            'SCRIPT_NAME'     => '/index.php',
            'SCRIPT_FILENAME' => $dir . '/index.php',
            'REQUEST_URI'     => '/section/us',
            'QUERY_STRING'    => '',
            'HTTP_HOST'       => 'example.com',
        ], $dir);
        Paths::allowProbe(false);
        assertTrue(Paths::hasRewrite());
        assertFileExists($dir . '/data/cache/rewrite.json');

        // A later request for the front page knows nothing by itself, but the
        // cached answer keeps the pretty URLs.
        Paths::init([
            'SCRIPT_NAME'     => '/index.php',
            'SCRIPT_FILENAME' => $dir . '/index.php',
            'REQUEST_URI'     => '/',
            'QUERY_STRING'    => '',
            'HTTP_HOST'       => 'example.com',
        ], $dir);
        Paths::allowProbe(false);
        assertTrue(Paths::hasRewrite(), 'the cached answer survives a request that carries no evidence');

        $cache = json_decode((string) file_get_contents($dir . '/data/cache/rewrite.json'), true);
        assertTrue(is_array($cache));
        assertArrayHasKey('/', $cache, 'the answer is keyed by the base path, because it depends on it');
    },

    'hasRewrite() never throws, whatever the request looks like' => function (): void {
        $rubbish = [
            [],
            ['REQUEST_URI' => null, 'SCRIPT_NAME' => null],
            ['REQUEST_URI' => str_repeat('/x', 5000), 'SCRIPT_NAME' => '/index.php'],
            ['REQUEST_URI' => "\x00\x01\x02", 'SCRIPT_NAME' => "\x00"],
            ['REQUEST_URI' => ['array'], 'SCRIPT_NAME' => ['array'], 'HTTP_HOST' => ['array']],
            ['REQUEST_URI' => '/%%%', 'SCRIPT_NAME' => '/index.php', 'HTTP_HOST' => "\r\n"],
        ];

        foreach ($rubbish as $i => $server) {
            Paths::init($server, teb_tmp_dir('rw-junk'));
            Paths::allowProbe(false);
            $answer = Paths::hasRewrite();
            assertTrue(is_bool($answer), 'case ' . $i);
            assertTrue(is_string(Paths::url('/section/us')), 'case ' . $i);
            assertTrue(is_string(Paths::absolute('/')), 'case ' . $i);
            assertTrue(is_string(Paths::currentRoute()), 'case ' . $i);
        }
    },

    // ----------------------------------------------- brand is config-only

    'no file under app/ contains the brand name or the domain' => function (): void {
        Config::reset();
        Config::load(teb_root());

        $brand  = (string) Config::get('site.name');
        $domain = (string) Config::get('site.domain');
        assertTrue($brand !== '' && $domain !== '', 'the test needs both values to be meaningful');

        // Positive control: the grep must be capable of finding them, or this
        // test proves nothing at all.
        $config = (string) file_get_contents(teb_root() . '/config.php');
        assertContains($brand, $config, 'config.php is where the brand lives');
        assertContains($domain, $config, 'config.php is where the domain lives');

        $files = teb_php_files(teb_root() . '/app');
        assertGreaterThan(0, count($files), 'app/ must contain PHP files for this gate to mean anything');

        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            assertFalse(
                stripos($src, $brand) !== false,
                teb_rel_path($file) . ' hardcodes the brand name — read it from Config::get(\'site.name\')'
            );
            assertFalse(
                stripos($src, $domain) !== false,
                teb_rel_path($file) . ' hardcodes the domain — build URLs with Paths::absolute()'
            );
        }
    },

    'no file under app/ emits a root-absolute URL' => function (): void {
        // href="/section/us" works at a web root and 404s in a subfolder.
        // Every link has to go through Paths::url() / Paths::asset().

        // Positive control FIRST. A scan with a broken pattern returns an
        // empty offender list, which is indistinguishable from a clean
        // codebase — so prove the scanner catches a known offender before
        // trusting it to say app/ is clean.
        $probe = teb_tmp_dir('abs-url') . '/Bad.php';
        file_put_contents($probe, "<?php\n"
            . "// href=\"/comments/are/fine\"\n"
            . " * href=\"/docblocks/are/fine\"\n"
            . "echo '<a href=\"/section/us\">U.S.</a>';\n"
            . "echo '<img src=\"/assets/logo.png\">';\n"
            . "echo '<a href=\"' . TEB\\Paths::url('/section/us') . '\">ok</a>';\n");

        $caught = teb_scan_root_absolute([$probe]);
        assertCount(2, $caught, 'the scanner must flag the two real offenders in the control file');
        assertContains('href="/section/us"', implode("\n", $caught));
        assertContains('src="/assets/logo.png"', implode("\n", $caught));

        $files = teb_php_files(teb_root() . '/app');
        assertGreaterThan(0, count($files), 'app/ must contain PHP files for this gate to mean anything');
        assertSame([], teb_scan_root_absolute($files), 'root-absolute URLs break every subdirectory install');
    },

    // ------------------------------------------------------------- feeds

    'the feed registry is the size and shape the build expects' => function (): void {
        $feeds = Feeds::all();

        assertGreaterThanOrEqual(30, count($feeds));
        assertLessThanOrEqual(40, count($feeds));

        $slugs    = [];
        $urls     = [];
        $sections = array_keys(Feeds::sections());

        foreach ($feeds as $f) {
            foreach (['slug', 'name', 'feed', 'section', 'country', 'tier', 'weight', 'homepage'] as $key) {
                assertArrayHasKey($key, $f, 'every entry carries the contract keys');
            }
            assertMatches('/^[a-z0-9][a-z0-9\-]*$/', $f['slug'], 'slug ' . $f['slug']);
            assertMatches('#^https://#', $f['feed'], $f['slug'] . ' must be fetched over TLS');
            assertContains($f['section'], $sections, $f['slug'] . ' files to a real section');
            assertContains($f['tier'], [1, 2, 3], $f['slug']);
            assertTrue($f['weight'] > 0.0 && $f['weight'] <= 3.0, $f['slug'] . ' weight');
            assertTrue($f['name'] !== '', $f['slug'] . ' needs a credit line');

            assertNotContains($f['slug'], $slugs, 'duplicate slug');
            assertNotContains($f['feed'], $urls, 'duplicate feed URL ' . $f['feed']);
            $slugs[] = $f['slug'];
            $urls[]  = $f['feed'];
        }
    },

    'recipes and weather are both represented' => function (): void {
        assertGreaterThanOrEqual(3, count(Feeds::bySection('recipes')), 'the recipes desk is a differentiator, not a placeholder');
        assertGreaterThanOrEqual(1, count(Feeds::bySection('weather')));

        // The weather feed must be the Atom spelling: the bare endpoint answers
        // GeoJSON, which the XML parser cannot read.
        foreach (Feeds::bySection('weather') as $f) {
            assertContains('.atom', $f['feed'], 'api.weather.gov only returns Atom for the .atom URL');
            assertNotContains('severity=Extreme,Severe', $f['feed'], 'the API rejects a comma-joined severity');
        }

        $services = Feeds::services();
        assertArrayHasKey('weather_forecast', $services);
        assertArrayHasKey('weather_alerts', $services);
        assertArrayHasKey('recipe_random', $services);
        foreach ($services as $key => $s) {
            assertMatches('#^https://#', $s['url'], $key);
            assertContains($s['format'], ['json', 'geojson'], $key . ' is a JSON API, which is exactly why it is not in all()');
        }

        // A JSON endpoint in the RSS registry would be parsed as XML, fail, and
        // be parked after eight runs.
        foreach (Feeds::all() as $f) {
            assertNotContains('themealdb', $f['feed']);
            assertNotContains('open-meteo', $f['feed']);
        }
    },

    'every feed URL in RECON.md is registered' => function (): void {
        $recon = (string) file_get_contents(teb_root() . '/docs/RECON.md');
        assertTrue($recon !== '');

        preg_match_all('#`(https://[^`\s]+)`#', $recon, $m);
        $wanted = array_values(array_unique($m[1]));
        assertGreaterThan(20, count($wanted), 'the roster should yield a long list of URLs');

        $have = array_column(Feeds::all(), 'feed');
        foreach (Feeds::services() as $s) {
            $have[] = $s['url'];
        }

        $missing = [];
        foreach ($wanted as $url) {
            $found = false;
            foreach ($have as $mine) {
                // The NWS alerts endpoint is registered as its .atom variant,
                // which starts with the URL the recon recorded.
                if ($mine === $url || strpos($mine, $url) === 0) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missing[] = $url;
            }
        }
        assertSame([], $missing, 'the verified roster is the contract for what we fetch');
    },

    'no feed on the do-not-add list is registered' => function (): void {
        $banned = ['seriouseats.com', 'simplyrecipes.com', 'food52.com', 'apnews.com/hub', 'reutersagency.com'];
        foreach (Feeds::all() as $f) {
            foreach ($banned as $bad) {
                assertNotContains($bad, $f['feed'], $f['slug'] . ' is on the dead-or-blocking list');
            }
        }
    },

    'the front page section order is the one the spec fixes' => function (): void {
        assertSame(
            ['us', 'international', 'world', 'weather', 'recipes'],
            array_column(Feeds::homeSections(), 'slug')
        );
        assertContains('business', Feeds::financeSections());
        assertNotContains('us', Feeds::financeSections());

        foreach (Feeds::sections() as $slug => $meta) {
            assertSame($slug, $meta['slug']);
            assertTrue($meta['label'] !== '', $slug . ' needs a label');
        }
        assertSame('U.S.', Feeds::sections()['us']['label']);
        assertNull(Feeds::section('no-such-desk'));
    },

    'due() fetches everything on a cold start, then respects the tier interval' => function (): void {
        $now  = 1755600000000;
        $all  = Feeds::all();

        assertCount(count($all), Feeds::due($now, []), 'nothing fetched yet means everything is due');

        $fresh = [];
        foreach ($all as $f) {
            $fresh[$f['slug']] = ['last_fetched_at' => $now - 60000, 'fail_count' => 0];
        }
        assertCount(0, Feeds::due($now, $fresh), 'a minute after a full run, nothing is due');

        // Eleven minutes is past the tier-1 interval but not past tier 2 or 3.
        $eleven = [];
        foreach ($all as $f) {
            $eleven[$f['slug']] = ['last_fetched_at' => $now - 11 * 60000];
        }
        $due = Feeds::due($now, $eleven);
        assertGreaterThan(0, count($due));
        foreach ($due as $f) {
            assertSame(1, $f['tier'], $f['slug'] . ' should not be due yet');
        }
    },

    'due() backs a failing feed off and parks it after eight failures' => function (): void {
        $now  = 1755600000000;
        $feed = Feeds::all()[0];

        $state = [$feed['slug'] => ['last_fetched_at' => $now - 30 * 60000, 'fail_count' => 8]];
        $due   = array_column(Feeds::due($now, $state), 'slug');
        assertNotContains($feed['slug'], $due, 'a parked feed must not eat the fetch budget');

        $state = [$feed['slug'] => ['last_fetched_at' => $now - 7 * 3600000, 'fail_count' => 8]];
        $due   = array_column(Feeds::due($now, $state), 'slug');
        assertContains($feed['slug'], $due, 'after six hours a parked feed gets one more try');
    },

    'due() reads the timestamp under the name the sources table actually uses' => function (): void {
        // Db writes the column as last_fetch_at. If due() does not recognise
        // that spelling it reads 0, calls every feed overdue on every run, and
        // the tier cadence and the parked-feed backoff below become decoration
        // — quietly, with no error anywhere.
        $now = 1755600000000;

        foreach (['last_fetch_at', 'last_fetched_at', 'fetched_at'] as $column) {
            $state = [];
            foreach (Feeds::all() as $f) {
                $state[$f['slug']] = [$column => $now - 60000, 'fail_count' => 0];
            }
            assertCount(0, Feeds::due($now, $state), 'a minute after a full run, ' . $column . ' must hold everything back');
        }

        // And the backoff has to be reachable through that spelling too.
        $feed  = Feeds::all()[0];
        $state = [$feed['slug'] => ['last_fetch_at' => $now - 30 * 60000, 'fail_count' => 8]];
        assertNotContains($feed['slug'], array_column(Feeds::due($now, $state), 'slug'));
    },

    'due() accepts a plain timestamp and a tier-keyed state too' => function (): void {
        $now = 1755600000000;

        $byMs = [];
        foreach (Feeds::all() as $f) {
            $byMs[$f['slug']] = $now - 1000;
        }
        assertCount(0, Feeds::due($now, $byMs));

        $byTier = ['tier1' => $now - 1000, 'tier2' => $now - 1000, 'tier3' => $now - 1000];
        assertCount(0, Feeds::due($now, $byTier));
    },

    'bySlug and bySection answer honestly' => function (): void {
        $first = Feeds::all()[0];
        assertSame($first, Feeds::bySlug($first['slug']));
        assertNull(Feeds::bySlug('no-such-feed'));

        foreach (Feeds::bySection('recipes') as $f) {
            assertSame('recipes', $f['section']);
        }
        assertSame([], Feeds::bySection('no-such-desk'));
    },
];

/**
 * Every line in $files that writes a root-absolute URL into an attribute.
 * Comment lines are allowed to spell one out; code is not.
 *
 * @param  array<int,string> $files
 * @return array<int,string> "file:line  the offending line"
 */
function teb_scan_root_absolute(array $files): array
{
    $offenders = [];
    foreach ($files as $file) {
        foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $n => $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || $trimmed[0] === '*' || strpos($trimmed, '//') === 0 || strpos($trimmed, '#') === 0) {
                continue;                      // comments are allowed to say it
            }
            if (preg_match('/\b(href|src|action|srcset)\s*=\s*[\'"]\/(?!\/)/i', $line) === 1) {
                $offenders[] = teb_rel_path($file) . ':' . ($n + 1) . '  ' . trim($line);
            }
        }
    }

    return $offenders;
}

/** Path relative to the project root, for readable failure messages. */
function teb_rel_path(string $path): string
{
    $root = teb_root() . '/';

    return strpos($path, $root) === 0 ? substr($path, strlen($root)) : $path;
}
