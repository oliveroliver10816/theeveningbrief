<?php

declare(strict_types=1);

/**
 * app/Seo.php and app/Health.php.
 *
 * Everything below runs against a REAL temporary SQLite database with REAL
 * hostile content in it, and every XML document generated is parsed back with
 * two independent parsers (DOMDocument for well-formedness, SimpleXML for
 * navigation) before a single assertion is made about what is inside it.
 *
 * That order matters. A sitemap that does not parse is not a sitemap with a
 * small bug in it — Google discards the whole file, silently, and the site
 * simply stops being crawled. So the first thing every test here proves is
 * that the document is well formed; only then does it ask what it says.
 *
 * The hostile fixture is not decoration either. It carries, in one headline:
 * an ampersand, an angle bracket, a double quote, a single quote, an em dash,
 * accented Latin, CJK, an emoji, the CDATA terminator ']]>', a vertical tab
 * (which XML 1.0 forbids outright and which cannot be escaped), and a raw
 * invalid UTF-8 byte. Each of those has a different failure mode and each one
 * has to be handled by a different line of Seo.
 */

require_once __DIR__ . '/lib.php';
teb_require_app('Config', 'Paths', 'Feeds', 'Db', 'Render', 'Seo', 'Health');

use TEB\Config;
use TEB\Db;
use TEB\Feeds;
use TEB\Health;
use TEB\Paths;
use TEB\Seo;

// =====================================================================
//  fixtures
// =====================================================================

/** One fixed "now" for the whole file, so the 48-hour window cannot drift mid-run. */
function teb_seo_now(): int
{
    static $t = 0;
    if ($t === 0) {
        $t = (int) floor(microtime(true) * 1000);
    }

    return $t;
}

/**
 * Load the real config and point Paths at a synthetic request.
 *
 * @param  array<string,string> $server
 * @return array<string,mixed>  the configuration
 */
function teb_seo_boot(array $server = [], ?bool $rewrite = true): array
{
    Config::reset();
    $cfg = Config::load(teb_root());

    Paths::init($server + [
        'HTTP_HOST'   => 'brief.example',
        'SCRIPT_NAME' => '/index.php',
        'REQUEST_URI' => '/',
        'HTTPS'       => 'on',
    ], teb_root());

    // Never let a test make a network call to probe for mod_rewrite.
    Paths::allowProbe(false);
    Paths::forceRewrite($rewrite);

    return $cfg;
}

/** A fresh migrated SQLite database in a scratch directory. Never data/. */
function teb_seo_db(): PDO
{
    $dir = teb_tmp_dir('teb-seo');
    $p   = Db::connect(['db' => ['driver' => 'sqlite', 'sqlite_path' => $dir . '/seo.sqlite']]);
    Db::migrate($p);

    return $p;
}

/**
 * One article row. source_id is 0 on purpose: the reads LEFT JOIN sources, so a
 * non-zero id would make the denormalised source_slug lose to whatever row that
 * id happens to be, and the fixture would silently describe a different feed.
 *
 * @param  array<string,mixed> $over
 * @return array<string,mixed>
 */
function teb_seo_row(array $over = []): array
{
    static $n = 0;
    $n++;

    return $over + [
        'source_id'    => 0,
        'source_slug'  => 'abc-us',
        'source_name'  => 'ABC News',
        'section'      => 'us',
        'guid'         => 'seo-guid-' . $n,
        'url'          => 'https://abcnews.go.com/US/story-' . $n,
        'title'        => 'Harbour rescue number ' . $n . ' after the storm turned north',
        'summary'      => 'Crews worked through the night off the coast, story ' . $n . '.',
        'image_url'    => '',
        'image_width'  => 0,
        'image_height' => 0,
        'author'       => '',
        'published_at' => teb_seo_now() - ($n * 60000),
        'fetched_at'   => teb_seo_now(),
    ];
}

/**
 * The nastiest headline that can actually reach the database.
 *
 * Everything in it — ampersand, angle brackets, both quote characters, an em
 * dash, accented Latin, CJK, an emoji and the CDATA terminator — survives
 * ingestion intact, so this is what the XML layer really has to cope with.
 * The two characters that CANNOT get this far (an XML-illegal control
 * character and an invalid UTF-8 byte, both of which Db normalises away) are
 * tested separately, by writing them straight into the table.
 */
function teb_seo_hostile_title(): string
{
    return "Storm & Co <b>surge</b> \"quoted\" 'single' — café ñ 日本語 😀 ]]> end of line";
}

/**
 * Insert a row STRAIGHT into the table, bypassing Db::insertArticles.
 *
 * Db normalises a title before storing it — a /u regex turns a vertical tab
 * into a space, and a row whose title is invalid UTF-8 is rejected outright —
 * so those two cases can never be produced through the front door. That makes
 * them exactly the cases worth forcing: the XML layer must not be relying on a
 * guarantee made by a different module, because the day that module changes,
 * the sitemap silently stops parsing.
 *
 * @param array<string,mixed> $row
 */
function teb_seo_insert_raw(PDO $p, array $row): int
{
    $now = teb_seo_now();
    $url = (string) ($row['url'] ?? 'https://abcnews.go.com/US/raw');

    $columns = [
        'source_id'    => 0,
        'source_slug'  => (string) ($row['source_slug'] ?? 'abc-us'),
        'source_name'  => (string) ($row['source_name'] ?? 'ABC News'),
        'section'      => (string) ($row['section'] ?? 'us'),
        'guid'         => (string) ($row['guid'] ?? $url),
        'guid_hash'    => hash('sha256', $url),
        'url'          => $url,
        'title'        => (string) ($row['title'] ?? ''),
        'title_key'    => 'raw-' . md5($url),
        'summary'      => (string) ($row['summary'] ?? ''),
        'image_url'    => (string) ($row['image_url'] ?? ''),
        'image_width'  => 0,
        'image_height' => 0,
        'author'       => (string) ($row['author'] ?? ''),
        'published_at' => (int) ($row['published_at'] ?? ($now - 60000)),
        'fetched_at'   => $now,
    ];

    $st = $p->prepare(
        'INSERT INTO articles (' . implode(', ', array_keys($columns)) . ') '
        . 'VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
    );
    $st->execute(array_values($columns));

    return (int) $p->lastInsertId();
}

/** Parse XML with DOMDocument — the strict gate. Fails the test with libxml's own message. */
function teb_seo_wellformed(string $xml, string $label): void
{
    $prev = libxml_use_internal_errors(true);
    libxml_clear_errors();

    $doc = new DOMDocument();
    $ok  = $doc->loadXML($xml, LIBXML_NONET);

    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if ($ok === false || $errors !== []) {
        $messages = [];
        foreach ($errors as $e) {
            $messages[] = 'line ' . $e->line . ': ' . trim($e->message);
        }
        teb_fail(
            'the document is not well-formed XML — ' . implode(' | ', $messages),
            $label,
            'a parseable document',
            substr($xml, 0, 900),
            true
        );
    }
}

/** Parse with SimpleXML, after proving well-formedness with DOMDocument. */
function teb_seo_xml(string $xml, string $label): SimpleXMLElement
{
    teb_seo_wellformed($xml, $label);

    $prev = libxml_use_internal_errors(true);
    $doc  = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if ($doc === false) {
        teb_fail('SimpleXML refused a document DOMDocument accepted', $label);
    }

    return $doc;
}

/** @return array<int,string> every <loc> in a sitemap or sitemap index */
function teb_seo_locs(string $xml): array
{
    $doc  = teb_seo_xml($xml, 'sitemap');
    $out  = [];
    foreach ($doc->children('http://www.sitemaps.org/schemas/sitemap/0.9') as $node) {
        $loc = (string) $node->loc;
        if ($loc !== '') {
            $out[] = $loc;
        }
    }

    return $out;
}

/** Every URL-ish string in any generated document, for the "nothing is relative" gate. */
function teb_seo_all_urls(string $xml): array
{
    $out = [];
    foreach (['loc', 'link', 'guid'] as $tag) {
        if (preg_match_all('#<' . $tag . '[^>]*>([^<]+)</' . $tag . '>#', $xml, $m) > 0) {
            foreach ($m[1] as $value) {
                $out[] = html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
    }
    if (preg_match_all('#href="([^"]+)"#', $xml, $m) > 0) {
        foreach ($m[1] as $value) {
            $out[] = html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    }

    return $out;
}

/** Seed one small, mixed corpus: fresh, stale, imageless, hostile. */
function teb_seo_seed(PDO $p): array
{
    $now  = teb_seo_now();
    $rows = [
        teb_seo_row([
            'guid'         => 'fresh-us',
            'url'          => 'https://abcnews.go.com/US/fresh?utm_source=rss&utm_medium=feed',
            'title'        => teb_seo_hostile_title(),
            'summary'      => 'A summary carrying & < > " and — an em dash, plus 日本語 text.',
            'image_url'    => 'https://s.abcnews.com/photo.jpg',
            'image_width'  => 992,
            'image_height' => 558,
            'author'       => 'Jane Reporter',
            'published_at' => $now - 3600000,          // 1 hour
        ]),
        teb_seo_row([
            'guid'         => 'fresh-world',
            'source_slug'  => 'bbc-world',
            'source_name'  => 'BBC News',
            'section'      => 'world',
            'url'          => 'https://www.bbc.com/news/world-1',
            'title'        => 'Talks resume in the capital after a week of silence',
            'published_at' => $now - (47 * 3600000),   // 47 hours: inside the news window
        ]),
        teb_seo_row([
            'guid'         => 'stale-world',
            'source_slug'  => 'bbc-world',
            'source_name'  => 'BBC News',
            'section'      => 'world',
            'url'          => 'https://www.bbc.com/news/world-2',
            'title'        => 'A story from four days ago that must not be in the news sitemap',
            'published_at' => $now - (4 * 86400000),   // 4 days: outside it
        ]),
        teb_seo_row([
            'guid'         => 'recipe',
            'source_slug'  => 'budget-bytes',
            'source_name'  => 'Budget Bytes',
            'section'      => 'recipes',
            'url'          => 'https://www.budgetbytes.com/sheet-pan',
            'title'        => 'Sheet pan dinner for two on a weeknight',
            'published_at' => $now - 7200000,
        ]),
    ];

    $res = Db::insertArticles($p, $rows, ['soft_dedup' => false]);
    assertSame(4, $res['inserted'], 'the fixture must land in the database');

    return $rows;
}

/**
 * A MySQL connection if one is reachable, otherwise null so the caller can say
 * it skipped. Never fakes a pass. The MySQL branch of Health::databaseBlock()
 * reads information_schema and cannot be exercised on SQLite at all, so this is
 * the only way that code is ever run before it reaches a client's cPanel.
 */
function teb_seo_mysql(): ?PDO
{
    $host = getenv('TEB_TEST_MYSQL_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('TEB_TEST_MYSQL_PORT') ?: 3306);

    $sock = @fsockopen($host, $port, $errno, $errstr, 0.35);
    if ($sock === false) {
        return null;
    }
    fclose($sock);

    $name  = getenv('TEB_TEST_MYSQL_DB') ?: 'teb_test';
    $tries = [];
    if (getenv('TEB_TEST_MYSQL_USER') !== false) {
        $tries[] = [getenv('TEB_TEST_MYSQL_USER'), (string) getenv('TEB_TEST_MYSQL_PASS')];
    }
    $tries[] = ['teb', 'teb'];
    $tries[] = ['root', ''];

    foreach ($tries as [$user, $pass]) {
        try {
            return Db::connect(['db' => [
                'driver' => 'mysql', 'host' => $host, 'port' => $port,
                'name' => $name, 'user' => $user, 'pass' => $pass,
            ]]);
        } catch (Throwable $e) {
            continue;
        }
    }

    return null;
}

return [

    // =================================================================
    //  robots.txt
    // =================================================================

    'robots.txt allows crawling and points at both sitemaps, absolutely' => function (): void {
        $cfg = teb_seo_boot();
        $txt = Seo::robotsTxt($cfg);

        assertContains('User-agent: *', $txt);
        assertContains('Allow: /', $txt);

        assertMatches('#^Sitemap: https://brief\.example/sitemap\.xml$#m', $txt);
        assertMatches('#^Sitemap: https://brief\.example/sitemap-news\.xml$#m', $txt);

        // Every Sitemap: line must be an absolute URL — a relative one is ignored.
        foreach (preg_split('/\R/', $txt) ?: [] as $line) {
            if (stripos($line, 'Sitemap:') === 0) {
                assertMatches('#^Sitemap: https?://#', trim($line), 'relative sitemap reference: ' . $line);
            }
        }
    },

    'robots.txt disallows the search and admin routes in BOTH URL shapes' => function (): void {
        $cfg = teb_seo_boot();
        $txt = Seo::robotsTxt($cfg);

        // Pretty URLs and the ?r= fallback are both live URL shapes for this
        // site, so excluding only the active one leaves the other crawlable.
        assertContains('Disallow: /search', $txt);
        assertContains('Disallow: /index.php?r=/search', $txt);
        assertContains('Disallow: /admin', $txt);
        assertContains('Disallow: /index.php?r=/admin', $txt);
        assertContains('Disallow: /install.php', $txt);
    },

    'robots.txt carries the base path when the site lives in a subdirectory' => function (): void {
        $cfg = teb_seo_boot(['SCRIPT_NAME' => '/teb/index.php', 'REQUEST_URI' => '/teb/']);
        $txt = Seo::robotsTxt($cfg);

        assertContains('Allow: /teb/', $txt);
        assertContains('Disallow: /teb/search', $txt);
        assertContains('Sitemap: https://brief.example/teb/sitemap.xml', $txt);
    },

    'robots.txt takes the publication name from config, not from a literal' => function (): void {
        teb_seo_boot();
        $txt = Seo::robotsTxt(['site' => ['name' => 'Zzyzx Ledger']]);

        assertContains('Zzyzx Ledger', $txt, 'the name is read from the config it was handed');
    },

    // =================================================================
    //  sitemap.xml
    // =================================================================

    'the sitemap parses, and every URL in it is absolute' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();
        teb_seo_seed($p);

        $xml = Seo::sitemap($p, $cfg);
        $doc = teb_seo_xml($xml, 'sitemap.xml');

        assertSame('urlset', $doc->getName());

        $locs = teb_seo_locs($xml);
        assertGreaterThan(4, count($locs));

        foreach ($locs as $loc) {
            assertMatches('#^https://brief\.example/#', $loc, 'relative or foreign URL in the sitemap: ' . $loc);
        }
    },

    'the sitemap carries the front page, the desks with stories, and every article' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();
        teb_seo_seed($p);

        $locs = teb_seo_locs(Seo::sitemap($p, $cfg));

        assertContains('https://brief.example/', $locs, 'the front page');
        assertContains('https://brief.example/section/us', $locs, 'a desk that has stories');
        assertContains('https://brief.example/section/world', $locs, 'a desk that has stories');
        assertContains('https://brief.example/weather', $locs, 'the weather page');
        assertContains('https://brief.example/recipes', $locs, 'the recipes page');
        assertContains('https://brief.example/about', $locs);
        assertContains('https://brief.example/sources', $locs);

        // A desk with nothing on it is a soft 404; it must not be submitted.
        assertNotContains('https://brief.example/section/sports', $locs);
        assertNotContains('https://brief.example/section/business', $locs);

        // The desks that have their own route must not ALSO appear under /section/.
        assertNotContains('https://brief.example/section/recipes', $locs);
        assertNotContains('https://brief.example/section/weather', $locs);

        $articles = array_filter($locs, static fn (string $l): bool => strpos($l, '/article/') !== false);
        assertCount(4, $articles, 'every seeded article is listed');
    },

    'the sitemap never lists the search route, the health endpoint or a URL twice' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();
        teb_seo_seed($p);

        $locs = teb_seo_locs(Seo::sitemap($p, $cfg));

        foreach ($locs as $loc) {
            assertFalse(strpos($loc, '/search') !== false, 'the search route is not crawlable: ' . $loc);
            assertFalse(strpos($loc, '/healthz') !== false, $loc);
            assertFalse(strpos($loc, 'install.php') !== false, $loc);
        }
        assertSame(count($locs), count(array_unique($locs)), 'no URL appears twice');
    },

    'every lastmod is a valid W3C datetime' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();
        teb_seo_seed($p);

        $xml = Seo::sitemap($p, $cfg);
        assertMatches('#<lastmod>#', $xml, 'there are lastmod elements to check');

        preg_match_all('#<lastmod>([^<]+)</lastmod>#', $xml, $m);
        foreach ($m[1] as $value) {
            assertMatches(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+\-]\d{2}:\d{2})$/',
                $value,
                'lastmod is not a W3C datetime: ' . $value
            );
            assertNotSame(false, strtotime($value), 'unparseable date: ' . $value);
        }
    },

    'an empty database still produces a well-formed sitemap' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();

        $xml = Seo::sitemap($p, $cfg);
        teb_seo_wellformed($xml, 'empty sitemap');

        $locs = teb_seo_locs($xml);
        assertContains('https://brief.example/', $locs, 'the standing pages are still listed');
    },

    // =================================================================
    //  sitemap pagination
    // =================================================================

    'the page size is the 5,000 the spec asks for' => function (): void {
        assertSame(5000, Seo::MAX_URLS_PER_SITEMAP);
    },

    'past 5,000 URLs the sitemap becomes an index, and the pages partition the set exactly'
        => function (): void {
            $cfg = teb_seo_boot();
            $p   = teb_seo_db();

            // The real threshold, with the real default. No shrunken constant:
            // the partition arithmetic is what is being tested and it is only
            // interesting at the real boundary.
            $now  = teb_seo_now();
            $rows = [];
            for ($i = 0; $i < 5010; $i++) {
                $rows[] = teb_seo_row([
                    'guid'         => 'bulk-' . $i,
                    'url'          => 'https://abcnews.go.com/US/bulk-' . $i,
                    'title'        => 'Bulk story number ' . $i . ' about a harbour rescue',
                    'published_at' => $now - ($i * 60000),
                ]);
            }
            $res = Db::insertArticles($p, $rows, ['soft_dedup' => false]);
            assertSame(5010, $res['inserted']);

            $index = Seo::sitemap($p, $cfg);
            $doc   = teb_seo_xml($index, 'sitemap index');
            assertSame('sitemapindex', $doc->getName(), 'over 5,000 URLs must produce an index');

            $pages = Seo::sitemapPageCount($p, $cfg);
            assertSame(2, $pages);
            assertCount($pages, teb_seo_locs($index));

            foreach (teb_seo_locs($index) as $i => $loc) {
                assertSame('https://brief.example/sitemap.xml?page=' . ($i + 1), $loc);
            }

            // The pages must partition the URL set: every URL exactly once.
            $seen = [];
            for ($page = 1; $page <= $pages; $page++) {
                $xml  = Seo::sitemap($p, $cfg, $page);
                $doc  = teb_seo_xml($xml, 'sitemap page ' . $page);
                assertSame('urlset', $doc->getName());
                foreach (teb_seo_locs($xml) as $loc) {
                    assertFalse(isset($seen[$loc]), 'URL appears on two pages: ' . $loc);
                    $seen[$loc] = true;
                }
            }

            // 5,010 articles + the front page, weather, recipes, sources, about
            // and the one desk that has stories.
            assertSame(5016, count($seen));
            assertLessThanOrEqual(
                Seo::MAX_URLS_PER_SITEMAP,
                count(teb_seo_locs(Seo::sitemap($p, $cfg, 1))),
                'no page may exceed the limit'
            );

            // A page beyond the end is empty, not a fatal and not malformed.
            teb_seo_wellformed(Seo::sitemap($p, $cfg, 99), 'out-of-range page');
            assertSame([], teb_seo_locs(Seo::sitemap($p, $cfg, 99)));
        },

    // =================================================================
    //  sitemap-news.xml
    // =================================================================

    'the news sitemap parses and uses the correct news namespace' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();
        teb_seo_seed($p);

        $xml = Seo::newsSitemap($p, $cfg);
        $doc = teb_seo_xml($xml, 'sitemap-news.xml');

        assertSame('urlset', $doc->getName());

        $namespaces = $doc->getDocNamespaces(true);
        assertContains('http://www.google.com/schemas/sitemap-news/0.9', array_values($namespaces));
        assertContains('http://www.sitemaps.org/schemas/sitemap/0.9', array_values($namespaces));

        $news = $doc->children('http://www.sitemaps.org/schemas/sitemap/0.9')->url[0]
            ->children('http://www.google.com/schemas/sitemap-news/0.9');
        assertSame('news', $news->news->getName());
        assertTrue((string) $news->news->publication->name !== '', 'the publication is named');
        assertTrue((string) $news->news->publication->language !== '', 'the language is stated');
        assertTrue((string) $news->news->publication_date !== '', 'the date is stated');
        assertTrue((string) $news->news->title !== '', 'the headline is stated');
    },

    'the news sitemap excludes anything older than 48 hours' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();
        teb_seo_seed($p);

        $xml  = Seo::newsSitemap($p, $cfg);
        $locs = teb_seo_locs($xml);

        assertSame(48, Seo::NEWS_WINDOW_HOURS);

        // The 1-hour and 47-hour stories are in; the 4-day one is not.
        assertMatches('/harbour|storm|surge/i', $xml, 'the fresh story is present');
        assertNotContains('four days ago', $xml, 'a four-day-old story is outside the window');

        assertCount(3, $locs, 'only the three articles inside 48 hours');

        // And every publication_date in the file really is inside the window.
        preg_match_all('#<news:publication_date>([^<]+)</news:publication_date>#', $xml, $m);
        assertCount(3, $m[1]);
        $cutoff = (teb_seo_now() / 1000) - (Seo::NEWS_WINDOW_HOURS * 3600);
        foreach ($m[1] as $value) {
            $ts = strtotime($value);
            assertNotSame(false, $ts, 'unparseable news date: ' . $value);
            assertGreaterThanOrEqual($cutoff, (float) $ts, 'a story older than 48h is in the file: ' . $value);
        }
    },

    'the 48-hour boundary is exact' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();
        $now = teb_seo_now();

        Db::insertArticles($p, [
            teb_seo_row([
                'guid'         => 'inside',
                'url'          => 'https://abcnews.go.com/US/inside',
                'title'        => 'Just inside the window by ten minutes',
                'published_at' => $now - ((48 * 3600000) - 600000),
            ]),
            teb_seo_row([
                'guid'         => 'outside',
                'url'          => 'https://abcnews.go.com/US/outside',
                'title'        => 'Just outside the window by ten minutes',
                'published_at' => $now - ((48 * 3600000) + 600000),
            ]),
        ], ['soft_dedup' => false]);

        $xml = Seo::newsSitemap($p, $cfg);
        assertContains('Just inside the window', $xml);
        assertNotContains('Just outside the window', $xml);
    },

    'the news sitemap names the publication from config, never from a literal' => function (): void {
        teb_seo_boot();
        $p = teb_seo_db();
        teb_seo_seed($p);

        $xml = Seo::newsSitemap($p, ['site' => ['name' => 'Zzyzx Ledger', 'locale' => 'fr_FR']]);
        teb_seo_wellformed($xml, 'news sitemap with a custom publication');

        assertContains('<news:name>Zzyzx Ledger</news:name>', $xml);
        assertContains('<news:language>fr</news:language>', $xml, 'ISO 639-1, not the full locale');
    },

    'an empty database still produces a well-formed news sitemap' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();

        $xml = Seo::newsSitemap($p, $cfg);
        teb_seo_wellformed($xml, 'empty news sitemap');
        assertSame([], teb_seo_locs($xml));
    },

    // =================================================================
    //  feed.xml
    // =================================================================

    'the feed parses as RSS 2.0 with a complete channel' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();
        teb_seo_seed($p);

        $xml = Seo::rss($p, $cfg);
        $doc = teb_seo_xml($xml, 'feed.xml');

        assertSame('rss', $doc->getName());
        assertSame('2.0', (string) $doc['version']);

        $channel = $doc->channel;
        assertTrue((string) $channel->title !== '', 'the channel has a title');
        assertTrue((string) $channel->description !== '', 'the channel has a description');
        assertMatches('#^https://brief\.example/#', (string) $channel->link);

        assertNotSame(false, strtotime((string) $channel->lastBuildDate), 'lastBuildDate is RFC 2822');
        assertCount(4, iterator_to_array($channel->item, false));
    },

    'every feed item links to OUR article page, never to the publisher' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();
        teb_seo_seed($p);

        $doc = teb_seo_xml(Seo::rss($p, $cfg), 'feed.xml');

        foreach ($doc->channel->item as $item) {
            $link = (string) $item->link;
            $guid = (string) $item->guid;

            assertMatches('#^https://brief\.example/#', $link, 'an item links off site: ' . $link);
            assertContains('/article/', $link, 'an item does not link to an article page: ' . $link);
            assertSame($link, $guid, 'the guid is the article page');

            assertFalse(strpos($link, 'abcnews.go.com') !== false, 'the publisher URL is not the link target');
            assertFalse(strpos($link, 'bbc.com') !== false, 'the publisher URL is not the link target');

            assertNotSame(false, strtotime((string) $item->pubDate), 'pubDate is RFC 2822: ' . $item->pubDate);
        }
    },

    'the feed carries OUR summary, CDATA wrapped, and survives a ]]> in the headline' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();
        teb_seo_seed($p);

        $xml = Seo::rss($p, $cfg);

        assertContains('<![CDATA[', $xml, 'titles and descriptions are CDATA wrapped');
        // The one sequence that can close a CDATA section early has to be split.
        assertContains(']]]]><![CDATA[>', $xml, 'a ]]> inside a headline is split across two sections');

        $doc   = teb_seo_xml($xml, 'feed.xml with a CDATA terminator in a title');
        $first = $doc->channel->item[0];

        // Parsed back, the headline reads correctly — the split is invisible.
        assertContains(']]> end', (string) $first->title, 'the terminator survives as text');
        assertContains('Storm & Co', (string) $first->title, 'the ampersand survives');
        assertContains('<b>surge</b>', (string) $first->title, 'the angle brackets survive');
        assertContains('日本語', (string) $first->title, 'non-ASCII survives');

        assertContains('em dash', (string) $first->description, 'our summary, not the publisher body');
    },

    'a section feed carries only that desk, and an unknown desk falls back to everything'
        => function (): void {
            $cfg = teb_seo_boot();
            $p   = teb_seo_db();
            teb_seo_seed($p);

            $world = teb_seo_xml(Seo::rss($p, $cfg, 'world'), 'world feed');
            assertCount(2, iterator_to_array($world->channel->item, false));
            foreach ($world->channel->item as $item) {
                assertSame('World', (string) $item->category);
            }
            assertContains('feed.xml?section=world', (string) $world->channel->children(
                'http://www.w3.org/2005/Atom'
            )->link->attributes()->href);

            $all = teb_seo_xml(Seo::rss($p, $cfg, 'not-a-desk'), 'unknown desk');
            assertCount(4, iterator_to_array($all->channel->item, false), 'an unknown desk is not a filter');
        },

    'an empty database still produces a well-formed feed' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();

        $xml = Seo::rss($p, $cfg);
        $doc = teb_seo_xml($xml, 'empty feed');
        assertCount(0, iterator_to_array($doc->channel->item, false));
    },

    // =================================================================
    //  hostile input — the whole point of the escaping layer
    // =================================================================

    'a headline full of hostile characters cannot break any document' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();

        // Every one of these has broken an XML generator somewhere. They are
        // written straight into the table so that not one of them can be
        // quietly normalised away before the code under test sees it.
        $titles = [
            'Ampersand & more & more',
            'Angle < bracket > pair',
            'Quotes "double" and \'single\'',
            'Entity-looking &amp; &#38; &notreal;',
            'CDATA terminator ]]> inline',
            "Vertical\x0Btab and form\x0Cfeed",
            "Invalid UTF-8: \xC3\x28 and \xE2\x82",
            'Emoji 😀🌍 and CJK 日本語 and RTL العربية',
            "Null\x00byte",
            str_repeat('Very long headline ', 40),
        ];

        foreach ($titles as $i => $title) {
            teb_seo_insert_raw($p, [
                'url'          => 'https://abcnews.go.com/US/hostile-' . $i,
                'title'        => $title,
                'summary'      => $title . ' — and the same again in the summary.',
                'published_at' => teb_seo_now() - (($i + 1) * 60000),
            ]);
        }
        assertSame(count($titles), Db::countArticles($p), 'every hostile row really is in the table');

        teb_seo_wellformed(Seo::sitemap($p, $cfg), 'sitemap with hostile titles');
        teb_seo_wellformed(Seo::newsSitemap($p, $cfg), 'news sitemap with hostile titles');
        teb_seo_wellformed(Seo::rss($p, $cfg), 'feed with hostile titles');

        foreach (Db::recentArticles($p, ['limit' => 50]) as $article) {
            $json = Seo::articleJsonLd($article, $cfg);
            assertTrue($json !== '', 'every article produces markup');
            assertNotNull(json_decode($json, true), 'invalid JSON for: ' . $article['title']);
        }
    },

    'the illegal XML control characters are removed rather than escaped' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();

        // Forced past Db, which would otherwise turn the vertical tab into a
        // space and hide the case this test exists for.
        teb_seo_insert_raw($p, [
            'url'          => 'https://abcnews.go.com/US/vtab',
            'title'        => "Before\x0Bafter",
            'published_at' => teb_seo_now() - 60000,
        ]);

        $xml = Seo::newsSitemap($p, $cfg);
        teb_seo_wellformed($xml, 'news sitemap with a vertical tab');

        // There is no character reference for U+000B in XML 1.0, so neither the
        // raw byte nor an escape of it may appear.
        assertFalse(strpos($xml, "\x0B") !== false, 'the raw control character is gone');
        assertNotContains('&#11;', $xml, 'and it was not "escaped" into something equally illegal');
        assertNotContains('&#x0B;', $xml);
        assertContains('Beforeafter', $xml, 'the surrounding text is intact');
    },

    'nothing in any generated document is a relative URL' => function (): void {
        $cfg = teb_seo_boot(['SCRIPT_NAME' => '/teb/index.php', 'REQUEST_URI' => '/teb/']);
        $p   = teb_seo_db();
        teb_seo_seed($p);

        $documents = [
            'sitemap'      => Seo::sitemap($p, $cfg),
            'news sitemap' => Seo::newsSitemap($p, $cfg),
            'feed'         => Seo::rss($p, $cfg),
        ];

        foreach ($documents as $label => $xml) {
            $urls = teb_seo_all_urls($xml);
            assertGreaterThan(0, count($urls), $label . ' contains URLs to check');
            foreach ($urls as $url) {
                assertMatches('#^https?://#', $url, $label . ' contains a relative URL: ' . $url);
                assertContains('/teb/', $url, $label . ' lost the subdirectory: ' . $url);
            }
        }
    },

    // =================================================================
    //  subdirectory and no-rewrite installs
    // =================================================================

    'with mod_rewrite off the URLs use ?r= and the ampersand is still escaped' => function (): void {
        $cfg = teb_seo_boot(['SCRIPT_NAME' => '/teb/index.php', 'REQUEST_URI' => '/teb/index.php'], false);
        $p   = teb_seo_db();

        // Enough articles to force an index, which is where the ?page= query —
        // and therefore a second '&' — appears.
        $now  = teb_seo_now();
        $rows = [];
        for ($i = 0; $i < 5010; $i++) {
            $rows[] = teb_seo_row([
                'guid'         => 'nr-' . $i,
                'url'          => 'https://abcnews.go.com/US/nr-' . $i,
                'title'        => 'No-rewrite story ' . $i,
                'published_at' => $now - ($i * 60000),
            ]);
        }
        Db::insertArticles($p, $rows, ['soft_dedup' => false]);

        $index = Seo::sitemap($p, $cfg);

        // A raw '&' inside <loc> makes the document unparseable. This is the
        // single most likely way this file could ship broken.
        teb_seo_wellformed($index, 'sitemap index in ?r= mode');
        assertContains('&amp;', $index, 'the query separator is escaped');
        assertNotMatches('/&(?!amp;|lt;|gt;|quot;|apos;|#)/', $index, 'a bare ampersand is in the document');

        $locs = teb_seo_locs($index);
        assertSame('https://brief.example/teb/index.php?r=/sitemap.xml&page=1', $locs[0]);

        foreach (teb_seo_locs(Seo::sitemap($p, $cfg, 1)) as $loc) {
            if ($loc === 'https://brief.example/teb/') {
                continue;   // the front page needs no route parameter in either mode
            }
            assertContains('/teb/index.php?r=', $loc, 'every URL uses the fallback shape: ' . $loc);
        }

        teb_seo_wellformed(Seo::rss($p, $cfg), 'feed in ?r= mode');
        teb_seo_wellformed(Seo::newsSitemap($p, $cfg), 'news sitemap in ?r= mode');
    },

    'robots.txt still works with mod_rewrite off' => function (): void {
        $cfg = teb_seo_boot([], false);
        $txt = Seo::robotsTxt($cfg);

        assertContains('Sitemap: https://brief.example/index.php?r=/sitemap.xml', $txt);
        assertContains('Disallow: /search', $txt, 'both shapes are listed whichever mode is active');
        assertContains('Disallow: /index.php?r=/search', $txt);
    },

    // =================================================================
    //  structured data
    // =================================================================

    'the article markup is valid JSON, is a NewsArticle, and carries isBasedOn' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();
        teb_seo_seed($p);

        $article = Db::recentArticles($p, ['limit' => 1])[0];
        $json    = Seo::articleJsonLd($article, $cfg);

        $node = json_decode($json, true);
        assertNotNull($node, 'the markup is valid JSON: ' . $json);
        assertSame('https://schema.org', $node['@context']);
        assertSame('NewsArticle', $node['@type']);

        assertArrayHasKey('isBasedOn', $node);
        assertSame('https://abcnews.go.com/US/fresh', $node['isBasedOn'], 'the publisher URL, tracking stripped');

        assertSame('NewsMediaOrganization', $node['sourceOrganization']['@type']);
        assertSame('ABC News', $node['sourceOrganization']['name'], 'the newsroom is named as the source');

        assertMatches('#^https://brief\.example/article/#', $node['url'], 'the markup describes OUR page');
        assertSame($node['url'], $node['mainEntityOfPage']['@id']);

        assertNotSame(false, strtotime($node['datePublished']));
    },

    'no field is emitted that we cannot substantiate' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();

        Db::insertArticles($p, [teb_seo_row([
            'guid'         => 'bare',
            'url'          => 'https://abcnews.go.com/US/bare',
            'title'        => 'A story the feed gave us almost nothing about',
            'summary'      => '',
            'image_url'    => 'https://s.abcnews.com/unknown-size.jpg',
            'image_width'  => 0,
            'image_height' => 0,
            'author'       => '',
            'published_at' => teb_seo_now() - 60000,
        ])], ['soft_dedup' => false]);

        $node = json_decode(Seo::articleJsonLd(Db::recentArticles($p, ['limit' => 1])[0], $cfg), true);
        assertNotNull($node);

        assertArrayNotHasKey('author', $node, 'the feed named no author, so none is invented');
        assertArrayNotHasKey('wordCount', $node, 'we hold a summary, not an article');
        assertArrayNotHasKey('articleBody', $node, 'we never republish the body');
        assertArrayNotHasKey('dateModified', $node, 'nothing here is ever modified after ingest');

        // An image whose dimensions the feed did not state is a bare URL, not an
        // ImageObject carrying made-up numbers.
        assertSame('https://s.abcnews.com/unknown-size.jpg', $node['image']);
    },

    'a stated author and stated image dimensions ARE carried through' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();
        teb_seo_seed($p);

        // Positive control for the test above: if these were dropped too, the
        // "no invented fields" assertion would be passing for the wrong reason.
        $node = json_decode(Seo::articleJsonLd(Db::recentArticles($p, ['limit' => 1])[0], $cfg), true);

        assertSame('Jane Reporter', $node['author']['name']);
        assertSame('ImageObject', $node['image']['@type']);
        assertSame(992, $node['image']['width']);
        assertSame(558, $node['image']['height']);
    },

    'the article markup cannot break out of the script block it is printed in' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();

        Db::insertArticles($p, [teb_seo_row([
            'guid'         => 'breakout',
            'url'          => 'https://abcnews.go.com/US/breakout',
            'title'        => 'Headline with </script><script>alert(1)</script> inside it',
            'published_at' => teb_seo_now() - 60000,
        ])], ['soft_dedup' => false]);

        $json = Seo::articleJsonLd(Db::recentArticles($p, ['limit' => 1])[0], $cfg);

        assertNotContains('</script>', $json, 'the closing tag is escaped as <\\/script>');
        assertContains('<\\/script>', $json);
        assertNotNull(json_decode($json, true), 'and it is still valid JSON');
    },

    'the breadcrumb is valid, starts at the front page and numbers from one' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();
        teb_seo_seed($p);

        $article = Db::recentArticles($p, ['limit' => 1])[0];
        $json    = Seo::breadcrumbJsonLd([
            ['name' => 'U.S.', 'path' => '/section/us'],
            ['name' => $article['title'], 'path' => Seo::articlePath($article)],
        ], $cfg);

        $node = json_decode($json, true);
        assertNotNull($node, 'valid JSON: ' . $json);
        assertSame('BreadcrumbList', $node['@type']);
        assertCount(3, $node['itemListElement']);

        foreach ($node['itemListElement'] as $i => $item) {
            assertSame('ListItem', $item['@type']);
            assertSame($i + 1, $item['position'], 'positions run 1, 2, 3 with no gap');
            assertMatches('#^https://brief\.example/#', $item['item']);
        }
        assertSame('https://brief.example/', $node['itemListElement'][0]['item']);
        assertSame('https://brief.example/section/us', $node['itemListElement'][1]['item']);
    },

    'a breadcrumb with nothing in it is not emitted' => function (): void {
        $cfg = teb_seo_boot();
        assertSame('', Seo::breadcrumbJsonLd([], $cfg), 'a one-item trail describes nothing');
    },

    'the website markup carries a SearchAction that points at the search route' => function (): void {
        $cfg = teb_seo_boot(['SCRIPT_NAME' => '/teb/index.php', 'REQUEST_URI' => '/teb/']);

        $node = json_decode(Seo::websiteJsonLd($cfg), true);
        assertNotNull($node);
        assertSame('WebSite', $node['@type']);
        assertSame('https://brief.example/teb/', $node['url']);

        $action = $node['potentialAction'];
        assertSame('SearchAction', $action['@type']);
        assertSame('required name=search_term_string', $action['query-input']);

        $template = $action['target']['urlTemplate'];
        assertContains('{search_term_string}', $template, 'the placeholder is intact and unencoded');
        assertSame('https://brief.example/teb/search?q={search_term_string}', $template);
    },

    'the website markup takes its name from config' => function (): void {
        teb_seo_boot();
        $node = json_decode(Seo::websiteJsonLd(['site' => ['name' => 'Zzyzx Ledger']]), true);

        assertSame('Zzyzx Ledger', $node['name']);
        assertSame('Zzyzx Ledger', $node['publisher']['name']);
    },

    // =================================================================
    //  the sitemap must never point at a URL the site would redirect
    // =================================================================

    'the sitemap URL for an article is exactly the URL the site links to' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();
        teb_seo_seed($p);

        $locs = teb_seo_locs(Seo::sitemap($p, $cfg));

        // The router answers /article/{wrong-slug}-{id} with a canonical 301.
        // A sitemap full of URLs that 301 is a sitemap that wastes crawl budget
        // on every single article, so the two must agree exactly.
        foreach (Db::recentArticles($p, ['limit' => 50]) as $article) {
            $expected = Paths::absolute(TEB\Render::articleHref($article));
            assertContains($expected, $locs, 'the sitemap disagrees with the renderer for: ' . $article['title']);
        }
    },

    'the standalone slug mirror agrees with the renderer, character for character' => function (): void {
        // Seo carries its own copy of the slug rule so a sitemap can be built
        // from the command line without loading the renderer. If the two ever
        // drift, every article URL in the sitemap starts 301ing — silently, and
        // only in the CLI build. This is the assertion that catches it.
        $mirror = new ReflectionMethod(TEB\Seo::class, 'slug');
        $mirror->setAccessible(true);

        $cases = [
            'Simple headline',
            'Ampersand & Company @ home',
            'Punctuation, everywhere: it "is" — really?',
            'café ñ 日本語 😀',
            '   leading and trailing   ',
            '---already-slugged---',
            str_repeat('a very long headline indeed ', 12),
            '',
            '!!!',
            'Numbers 123 and 456',
        ];

        foreach ($cases as $case) {
            assertSame(
                TEB\Render::slug($case),
                $mirror->invoke(null, $case),
                'slug drift for: ' . $case
            );
        }
    },

    // =================================================================
    //  the brand and the domain live in config only
    // =================================================================

    'neither Seo nor Health contains the brand name or the domain' => function (): void {
        Config::reset();
        Config::load(teb_root());

        $brand  = (string) Config::get('site.name');
        $domain = (string) Config::get('site.domain');
        assertTrue($brand !== '' && $domain !== '', 'the test needs both values to be meaningful');

        // Positive control: the grep has to be capable of finding them.
        $config = (string) file_get_contents(teb_root() . '/config.php');
        assertContains($brand, $config);
        assertContains($domain, $config);

        foreach (['Seo', 'Health'] as $module) {
            $src = (string) file_get_contents(teb_root() . '/app/' . $module . '.php');
            assertFalse(stripos($src, $brand) !== false, $module . ' hardcodes the brand name');
            assertFalse(stripos($src, $domain) !== false, $module . ' hardcodes the domain');
        }
    },

    'renaming the site in config renames it in every document' => function (): void {
        teb_seo_boot();
        $p = teb_seo_db();
        teb_seo_seed($p);

        $cfg = ['site' => ['name' => 'Zzyzx Ledger', 'description' => 'A test edition.', 'locale' => 'en_GB']];

        assertContains('Zzyzx Ledger', Seo::robotsTxt($cfg));
        assertContains('Zzyzx Ledger', Seo::newsSitemap($p, $cfg));
        assertContains('Zzyzx Ledger', Seo::rss($p, $cfg));
        assertContains('Zzyzx Ledger', Seo::websiteJsonLd($cfg));
        assertContains('Zzyzx Ledger', Seo::articleJsonLd(Db::recentArticles($p, ['limit' => 1])[0], $cfg));
    },

    // =================================================================
    //  Health
    // =================================================================

    'the health report answers every question it exists to answer' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();
        Db::upsertSources($p, Feeds::all());
        teb_seo_seed($p);
        Db::recordIngestRun($p, [
            'started_at'   => teb_seo_now() - 40000,
            'finished_at'  => teb_seo_now() - 20000,
            'run_mode'     => 'cron',
            'feeds_ok'     => 12,
            'feeds_failed' => 2,
            'inserted'     => 31,
            'skipped'      => 90,
            'errors'       => ['sky-world: HTTP 503'],
        ]);

        $report = Health::report($p, $cfg);

        foreach (['ok', 'status', 'generated_at', 'php', 'database', 'articles', 'sections', 'sources', 'ingest'] as $key) {
            assertArrayHasKey($key, $report);
        }

        // last ingest run
        assertSame('cron', $report['ingest']['last_run']['run_mode']);
        assertSame(12, $report['ingest']['last_run']['feeds_ok']);
        assertSame(31, $report['ingest']['last_run']['inserted']);
        assertContains('sky-world: HTTP 503', $report['ingest']['last_run']['errors']);
        assertSame(0, $report['ingest']['age_minutes'], 'the run finished twenty seconds ago');
        assertFalse($report['ingest']['late']);

        // article and source counts
        assertSame(4, $report['articles']['total']);
        assertSame(count(Feeds::all()), $report['sources']['known']);
        assertSame(count(Feeds::all()), $report['sources']['registry']);

        // per-section counts, including the desks sitting at zero
        assertSame(1, $report['sections']['us']['last_24h']);
        assertSame(2, $report['sections']['world']['last_7_days']);
        assertSame(0, $report['sections']['sports']['last_7_days'], 'a quiet desk is visible, not absent');

        // database driver and size
        assertSame('sqlite', $report['database']['driver']);
        assertGreaterThan(0, $report['database']['size_bytes']);
        assertMatches('/^[\d.]+ (B|KB|MB|GB|TB)$/', $report['database']['size_human']);
        assertTrue($report['database']['writable']);

        // PHP version and extensions
        assertSame(PHP_VERSION, $report['php']['version']);
        assertTrue($report['php']['version_ok']);
        assertSame([], $report['php']['extensions_missing'], 'this box has everything the build needs');
        assertSame('8.0.0', $report['php']['minimum']);
    },

    'a failing feed is reported with the error it actually returned' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();
        Db::upsertSources($p, Feeds::all());
        teb_seo_seed($p);

        Db::recordFeedResult($p, 'sky-world', false, 'cURL error 28: Operation timed out after 12001 ms');
        for ($i = 0; $i < 8; $i++) {
            Db::recordFeedResult($p, 'dw-world', false, 'HTTP 403 from the publisher');
        }

        $report = Health::report($p, $cfg);

        assertSame(2, $report['sources']['failing_count']);
        assertSame(1, $report['sources']['parked'], 'eight consecutive failures parks a feed');

        $bySlug = [];
        foreach ($report['sources']['failing'] as $feed) {
            $bySlug[$feed['slug']] = $feed;
        }

        assertArrayHasKey('sky-world', $bySlug);
        assertSame(1, $bySlug['sky-world']['fail_count']);
        assertFalse($bySlug['sky-world']['parked']);
        assertContains('Operation timed out', $bySlug['sky-world']['last_error']);

        assertArrayHasKey('dw-world', $bySlug);
        assertSame(8, $bySlug['dw-world']['fail_count']);
        assertTrue($bySlug['dw-world']['parked']);
        assertContains('HTTP 403', $bySlug['dw-world']['last_error']);

        assertSame('degraded', $report['status'], 'a parked feed is a warning, not an outage');
        assertTrue($report['ok']);
        assertSame(200, Health::statusCode($report));
        assertMatches('/parked/i', implode(' ', $report['warnings']));
    },

    'an empty database is reported as down, not as healthy' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();

        $report = Health::report($p, $cfg);

        assertFalse($report['ok']);
        assertSame('down', $report['status']);
        assertSame(503, Health::statusCode($report));
        assertMatches('/no articles/i', implode(' ', $report['problems']));
    },

    'a site that has gone quiet is flagged before anyone notices' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();

        Db::insertArticles($p, [teb_seo_row([
            'guid'         => 'ancient',
            'url'          => 'https://abcnews.go.com/US/ancient',
            'title'        => 'The last story anyone ingested',
            'published_at' => teb_seo_now() - (3 * 86400000),
        ])], ['soft_dedup' => false]);

        Db::recordIngestRun($p, [
            'started_at'  => teb_seo_now() - (3 * 86400000),
            'finished_at' => teb_seo_now() - (3 * 86400000),
            'run_mode'    => 'cron',
        ]);

        $report = Health::report($p, $cfg);
        $words  = implode(' ', array_merge($report['problems'], $report['warnings']));

        assertMatches('/stale news/i', $words, 'the stale front page is called out');
        assertMatches('/cron job has stopped/i', $words, 'a three-day-old run is stopped, not late');
        assertSame('down', $report['status']);
        assertGreaterThan(4000, $report['articles']['newest_age_minutes']);
    },

    'the health report never throws, whatever state the database is in' => function (): void {
        $cfg = teb_seo_boot();

        // Fresh, empty, migrated.
        $p = teb_seo_db();
        assertTrue(is_array(Health::report($p, $cfg)));

        // Migrated with sources but no articles.
        Db::upsertSources($p, Feeds::all());
        assertTrue(is_array(Health::report($p, $cfg)));

        // With no configuration handed to it at all.
        assertTrue(is_array(Health::report($p, [])));

        // And with a config full of nonsense.
        $report = Health::report($p, ['site' => ['timezone' => 'Not/AZone'], 'ingest' => ['retention_days' => 'x']]);
        assertTrue(is_array($report));
        assertTrue(is_string($report['generated_at_iso']));
    },

    'the health report encodes to JSON that a monitor can read' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();
        teb_seo_seed($p);

        $json = Health::json(Health::report($p, $cfg));
        $back = json_decode($json, true);

        assertNotNull($back, 'the report round-trips through JSON');
        assertArrayHasKey('status', $back);
        assertArrayHasKey('sources', $back);
        assertSame(4, $back['articles']['total']);
    },

    'MySQL: the same documents, and a real table size (skipped when no server)' => function (): void {
        $cfg = teb_seo_boot();
        $m   = teb_seo_mysql();
        if ($m === null) {
            echo "        [skip] no usable MySQL on 127.0.0.1:3306 — set TEB_TEST_MYSQL_* to run this leg\n";

            return;
        }

        $dbName = (string) $m->query('SELECT DATABASE()')->fetchColumn();
        if (strpos($dbName, 'test') === false) {
            echo '        [skip] refusing to write to "' . $dbName . "\" — the name must contain 'test'\n";

            return;
        }

        // tests/test_db.php rebuilds these same fixed-name tables in this same
        // shared database, so the two legs are serialised on one named lock.
        // Without it a concurrent run tears the fixture down mid-assertion and
        // reports a failure that is not a bug.
        $lock = $m->prepare('SELECT GET_LOCK(?, ?)');
        $lock->execute(['teb_test_db_schema', 25]);
        if ((int) $lock->fetchColumn() !== 1) {
            echo "        [skip] another test run holds the MySQL fixture lock\n";

            return;
        }

        try {
            Db::migrate($m);
            $m->exec('DELETE FROM articles');

            $now = teb_seo_now();
            Db::insertArticles($m, [
                teb_seo_row([
                    'guid'         => 'my-1',
                    'url'          => 'https://abcnews.go.com/US/mysql-1',
                    'title'        => 'A headline with & < > " and 日本語 on MySQL',
                    'published_at' => $now - 3600000,
                ]),
                teb_seo_row([
                    'guid'         => 'my-2',
                    'source_slug'  => 'bbc-world',
                    'source_name'  => 'BBC News',
                    'section'      => 'world',
                    'url'          => 'https://www.bbc.com/news/mysql-2',
                    'title'        => 'A second headline, four days old',
                    'published_at' => $now - (4 * 86400000),
                ]),
            ], ['soft_dedup' => false]);

            $report = Health::report($m, $cfg);

            assertSame('mysql', $report['database']['driver']);
            assertGreaterThan(0, $report['database']['size_bytes'], 'information_schema reports a real size');
            assertMatches('/^[\d.]+ (B|KB|MB|GB|TB)$/', $report['database']['size_human']);
            assertSame([], $report['database']['files'], 'a server-hosted database has no local files');
            assertNull($report['database']['error']);
            assertSame(2, $report['articles']['total']);
            assertSame(1, $report['sections']['us']['last_24h']);

            // And every document generates identically on the other driver.
            teb_seo_wellformed(Seo::sitemap($m, $cfg), 'sitemap on MySQL');
            teb_seo_wellformed(Seo::newsSitemap($m, $cfg), 'news sitemap on MySQL');
            teb_seo_wellformed(Seo::rss($m, $cfg), 'feed on MySQL');

            assertCount(2, array_filter(
                teb_seo_locs(Seo::sitemap($m, $cfg)),
                static fn (string $l): bool => strpos($l, '/article/') !== false
            ));
            assertCount(1, teb_seo_locs(Seo::newsSitemap($m, $cfg)), 'the 48-hour window works the same');
        } finally {
            $release = $m->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute(['teb_test_db_schema']);
            $release->fetchColumn();
        }
    },

    // =================================================================
    //  regressions found by attacking this module (2026-08-19)
    // =================================================================

    'a story the pruner will not delete is in the sitemap, however old its dateline'
        => function (): void {
            // Db::pruneOld() deletes a row only when published_at AND fetched_at
            // are both past the cutoff. A weekly column or a recipe that carries
            // a two-month-old dateline but arrived in a feed this morning is
            // therefore SERVED and LINKED for another full retention period —
            // and a sitemap that filtered on published_at alone left it out.
            // That is the one page shape most in need of a sitemap entry.
            $cfg = teb_seo_boot();
            $p   = teb_seo_db();
            $now = teb_seo_now();

            Db::insertArticles($p, [teb_seo_row([
                'guid'         => 'old-but-live',
                'source_slug'  => 'budget-bytes',
                'source_name'  => 'Budget Bytes',
                'section'      => 'recipes',
                'url'          => 'https://www.budgetbytes.com/old-but-live',
                'title'        => 'A recipe dated sixty days ago that arrived in the feed today',
                'published_at' => $now - (60 * 86400000),
                'fetched_at'   => $now,
            ])], ['soft_dedup' => false]);

            $article = Db::recentArticles($p, ['limit' => 1])[0];

            // The premise: this row is live and the pruner leaves it alone.
            assertNotNull(Db::articleById($p, (int) $article['id']), 'the article page is served');
            assertSame(0, Db::pruneOld($p, 30), 'and retention does not delete it');

            $locs = teb_seo_locs(Seo::sitemap($p, $cfg));
            assertContains(
                Paths::absolute(Seo::articlePath($article)),
                $locs,
                'a page the site serves is missing from the sitemap'
            );
            assertContains('https://brief.example/recipes', $locs, 'and its desk is listed too');

            // The desk page must carry the dateline it actually has, not nothing.
            $xml = Seo::sitemap($p, $cfg);
            assertMatches(
                '#<loc>https://brief\.example/recipes</loc>\s*<lastmod>#',
                $xml,
                'the desk with the only story on it has no lastmod'
            );
        },

    'a blank publication name falls back to the host instead of shipping an empty one'
        => function (): void {
            // <news:name> is required and Google rejects a news sitemap without
            // it; an RSS channel with an empty <title> is invalid RSS. A client
            // who empties the field in config must not silently produce either.
            $cfg = teb_seo_boot();
            $p   = teb_seo_db();
            teb_seo_seed($p);

            $blank = ['site' => ['name' => '', 'locale' => 'en_US']];

            $news = Seo::newsSitemap($p, $blank);
            teb_seo_wellformed($news, 'news sitemap with no publication name');
            preg_match('#<news:name>(.*?)</news:name>#', $news, $m);
            assertSame('brief.example', $m[1] ?? '', 'the host names the publication when config does not');

            $feed = Seo::rss($p, $blank);
            $doc  = teb_seo_xml($feed, 'feed with no publication name');
            assertSame('brief.example', (string) $doc->channel->title);

            // And the configured name still wins whenever there is one.
            preg_match('#<news:name>(.*?)</news:name>#', Seo::newsSitemap($p, ['site' => ['name' => 'Zzyzx Ledger']]), $m2);
            assertSame('Zzyzx Ledger', $m2[1] ?? '');
        },

    'a nonsense locale never reaches a document as a language' => function (): void {
        // Stripping illegal characters is not enough on its own: '"><x' comes
        // out of that as 'x', and a one-letter language tag is not a language.
        $cfg  = teb_seo_boot();
        $p    = teb_seo_db();
        teb_seo_seed($p);

        $junk = ['site' => ['name' => 'Zzyzx Ledger', 'locale' => '"><x']];

        preg_match('#<language>([^<]*)</language>#', Seo::rss($p, $junk), $m);
        assertSame('en', $m[1] ?? '', 'RSS falls back to a real language tag');

        preg_match('#<news:language>([^<]*)</news:language>#', Seo::newsSitemap($p, $junk), $m2);
        assertSame('en', $m2[1] ?? '', 'Google News wants ISO 639-1, not junk');

        $node = json_decode(Seo::articleJsonLd(Db::recentArticles($p, ['limit' => 1])[0], $junk), true);
        assertArrayNotHasKey('inLanguage', $node, 'an unusable tag is omitted, not published');

        // Positive control: a real locale still comes through, in both spellings.
        $good = ['site' => ['name' => 'Zzyzx Ledger', 'locale' => 'fr_FR']];
        preg_match('#<language>([^<]*)</language>#', Seo::rss($p, $good), $m3);
        assertSame('fr-fr', $m3[1] ?? '');
        preg_match('#<news:language>([^<]*)</news:language>#', Seo::newsSitemap($p, $good), $m4);
        assertSame('fr', $m4[1] ?? '');
    },

    'a headline longer than Google will print is clipped, not published whole' => function (): void {
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();

        $long = 'A very long headline about a harbour rescue that keeps going and going well past '
            . 'the point at which any search engine would print it in a result and then keeps going '
            . 'for a while longer still, just to be sure';

        Db::insertArticles($p, [teb_seo_row([
            'guid'         => 'long',
            'url'          => 'https://abcnews.go.com/US/long',
            'title'        => $long,
            'published_at' => teb_seo_now() - 60000,
        ])], ['soft_dedup' => false]);

        $row = Db::recentArticles($p, ['limit' => 1])[0];
        assertGreaterThan(150, mb_strlen((string) $row['title']), 'the stored headline really is long');

        $node = json_decode(Seo::articleJsonLd($row, $cfg), true);
        assertLessThanOrEqual(111, mb_strlen($node['headline']), 'headline is clipped to Google\'s 110');
        assertContains('…', $node['headline'], 'and the cut is marked');
        assertContains('A very long headline about a harbour rescue', $node['headline']);
    },

    'a hostile URL out of a feed never becomes structured data' => function (): void {
        $cfg = teb_seo_boot();
        teb_seo_db();

        // Db stores what the publisher sent; only http and https may be printed.
        $node = json_decode(Seo::articleJsonLd([
            'id'              => 99,
            'title'           => 'A story with a weaponised link',
            'section'         => 'us',
            'url'             => 'javascript:alert(1)',
            'image_url'       => 'data:text/html;base64,PHNjcmlwdD4=',
            'source_name'     => 'ABC News',
            'source_homepage' => 'ftp://abcnews.go.com/',
            'published_at'    => teb_seo_now() - 60000,
        ], $cfg), true);

        assertNotNull($node);
        assertArrayNotHasKey('isBasedOn', $node, 'javascript: is not a publisher URL');
        assertArrayNotHasKey('image', $node, 'data: is not an image');
        assertArrayNotHasKey('url', $node['sourceOrganization'], 'ftp: is not a homepage');

        // Positive control: the real thing is carried.
        $ok = json_decode(Seo::articleJsonLd([
            'id'              => 100,
            'title'           => 'A story with ordinary links',
            'url'             => 'https://abcnews.go.com/US/ok',
            'image_url'       => 'https://s.abcnews.com/ok.jpg',
            'source_name'     => 'ABC News',
            'source_homepage' => 'https://abcnews.go.com/',
            'published_at'    => teb_seo_now() - 60000,
        ], $cfg), true);
        assertSame('https://abcnews.go.com/US/ok', $ok['isBasedOn']);
        assertSame('https://s.abcnews.com/ok.jpg', $ok['image']);
        assertSame('https://abcnews.go.com/', $ok['sourceOrganization']['url']);
    },

    'the publication name cannot inject a line into robots.txt' => function (): void {
        // config.php is the one file the client edits, and robots.txt is a
        // line-oriented format: a name carrying a newline would otherwise write
        // its own rules — 'Disallow: /' among them, which delists the site.
        teb_seo_boot();

        $txt = Seo::robotsTxt(['site' => ['name' => "Evil\nDisallow: /\nUser-agent: *"]]);

        assertSame(1, preg_match_all('/^User-agent:/m', $txt), 'exactly one user-agent group');
        assertSame(0, preg_match_all('/^Disallow: \/$/m', $txt), 'nothing disallows the whole site');
        assertContains('# Evil Disallow: / User-agent: *', $txt, 'the name is flattened onto its comment line');

        foreach (preg_split('/\R/', $txt) ?: [] as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            assertMatches(
                '/^(User-agent|Allow|Disallow|Sitemap): /',
                $line,
                'robots.txt grew a line nobody wrote: ' . $line
            );
        }
    },

    'the health report survives a database whose tables have gone' => function (): void {
        // A half-restored backup, a migration that never ran, a host that moved
        // the file: the report is the one page whose job is to say so, and it
        // must not be the page that dies with it.
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();
        teb_seo_seed($p);

        $p->exec('DROP TABLE articles');

        $report = Health::report($p, $cfg);

        assertTrue(is_array($report));
        assertSame('down', $report['status']);
        assertSame(503, Health::statusCode($report));
        assertMatches('/database could not be read/i', implode(' ', $report['problems']));
        assertNotNull(json_decode(Health::json($report), true), 'and it still encodes');
        assertSame(0, $report['articles']['total']);
    },

    'the health report writes nothing to the database' => function (): void {
        // Read-only is a claim, so it is measured: every row of every table is
        // hashed before and after. A report that recorded its own run, pruned
        // anything, or reset a fail_count would change the hash.
        $cfg = teb_seo_boot();
        $p   = teb_seo_db();
        Db::upsertSources($p, Feeds::all());
        teb_seo_seed($p);
        Db::recordFeedResult($p, 'sky-world', false, 'HTTP 503');
        Db::recordIngestRun($p, [
            'started_at'  => teb_seo_now() - 40000,
            'finished_at' => teb_seo_now() - 20000,
            'run_mode'    => 'cron',
        ]);

        $snapshot = static function (PDO $p): string {
            $out = '';
            foreach (['articles', 'sources', 'ingest_runs'] as $table) {
                foreach ($p->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $out .= json_encode($row);
                }
            }

            return hash('sha256', $out);
        };

        $before = $snapshot($p);
        Health::report($p, $cfg);
        Health::report($p, $cfg);
        assertSame($before, $snapshot($p), 'the health report changed the database');

        // Positive control: the hash is capable of moving.
        Db::recordFeedResult($p, 'sky-world', false, 'HTTP 500');
        assertNotSame($before, $snapshot($p), 'the snapshot would not have noticed a write');
    },

    'the health report does not take the ingest lock' => function (): void {
        // Taking it, even for an instant, would make a concurrent ingest skip
        // its run. The report describes the lock file; it never opens it for
        // writing and never calls flock().
        // Judged on the CODE, not on the comments — the docblock explains the
        // rule in words and would otherwise fail the test that enforces it.
        $code = '';
        foreach (token_get_all((string) file_get_contents(teb_root() . '/app/Health.php')) as $token) {
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        assertContains('flock', (string) file_get_contents(teb_root() . '/app/Health.php'),
            'positive control: the rule is documented in the file, so the strip is doing work');

        assertNotContains('flock', $code, 'Health must never take a lock');
        assertNotContains('Ingest::lock', $code);
        assertNotContains('fopen(', $code, 'nothing here opens a handle that could hold a lock');
    },
];
