<?php

declare(strict_types=1);

/**
 * app/Xml.php — the feed parser.
 *
 * Every fixture under tests/fixtures/ except the four hand-written edge cases is a REAL
 * feed, fetched from the roster in docs/RECON.md on 2026-08-19 and trimmed to its first
 * few items. The assertions are on the actual published values — exact headlines, exact
 * image URLs, exact millisecond timestamps — so a regression in namespace handling or
 * entity decoding fails loudly instead of silently returning fewer items.
 *
 * Fixture                      shape it pins down
 *   rss2-media-content.xml     RSS 2.0 + media:content + dc:creator          (NYT U.S.)
 *   media-thumbnail.xml        RSS 2.0 + media:thumbnail + CDATA titles      (BBC World)
 *   atom.xml                   Atom 1.0, link rel=alternate, author>name     (NWS alerts)
 *   rdf.xml                    RDF / RSS 1.0 + dc:date, no images            (DW World)
 *   content-encoded-image.xml  image ONLY inside the HTML body              (smitten kitchen)
 *   enclosure.xml              image ONLY in <enclosure>, 5 KB description   (SRN News)
 *   entities-nonascii.xml      escaped HTML, curly quotes, 3 image widths    (Guardian)
 *   tracking-pixel.xml         src='undefined' + a 1x1 beacon                (NPR)
 */

require_once __DIR__ . '/lib.php';
teb_require_app('Xml');

use TEB\Xml;

/** @return array{title:string,items:array} */
function tx_parse(string $fixture): array
{
    $path = __DIR__ . '/fixtures/' . $fixture;
    assertFileExists($path, 'fixture missing — tests/fixtures/ ships with the repo');
    $xml = file_get_contents($path);
    assertTrue(is_string($xml) && $xml !== '', 'fixture is empty: ' . $fixture);

    return Xml::parseFeed((string) $xml);
}

function tx_raw(string $fixture): string
{
    return (string) file_get_contents(__DIR__ . '/fixtures/' . $fixture);
}

/** Parse while counting every PHP diagnostic the parser raises. */
function tx_parse_counting_warnings(string $xml, ?array &$diagnostics = null): array
{
    $diagnostics = [];
    set_error_handler(static function (int $no, string $msg, string $file = '', int $line = 0) use (&$diagnostics): bool {
        $diagnostics[] = $no . ': ' . $msg . ' (' . basename($file) . ':' . $line . ')';

        return true;
    });
    try {
        return Xml::parseFeed($xml);
    } finally {
        restore_error_handler();
    }
}

/**
 * The <description> of the nth <item> in a raw fixture — used to show that the feed really
 * did ship the whole article, so that capping the stored summary means something.
 * It must look inside <item>, because the first <description> in an RSS file is the
 * channel's own (empty, in this fixture).
 */
function tx_item_description(string $raw, int $n): string
{
    preg_match_all('#<item[\s>].*?</item>#s', $raw, $items);
    $item = (string) ($items[0][$n] ?? '');
    if ($item === '' || preg_match('#<description[^>]*>(.*?)</description>#s', $item, $m) !== 1) {
        return '';
    }

    return (string) $m[1];
}

return [

    // ---------------------------------------------------------------- RSS 2.0

    'RSS 2.0: real NYT items, exact values' => function (): void {
        $feed = tx_parse('rss2-media-content.xml');

        assertSame('NYT > U.S. News', $feed['title'], 'channel title, with &gt; decoded');
        assertCount(4, $feed['items']);

        $a = $feed['items'][0];
        assertSame(
            'Chinese Comic Investigated After Singing a Riff of a Patriotic Song',
            $a['title']
        );
        assertSame(
            'https://www.nytimes.com/2026/08/19/us/guo-degang-china-comic-investigated.html',
            $a['url']
        );
        assertSame($a['url'], $a['guid'], 'NYT publishes the article URL as the guid');
        assertSame('Yan Zhuang', $a['author'], 'author comes from dc:creator');
        assertSame(1787130959000, $a['published_at']);
        assertSame('2026-08-19T09:15:59+00:00', gmdate('c', intdiv((int) $a['published_at'], 1000)));
        assertContains('best-known comedians', $a['summary']);
    },

    'RSS 2.0: image comes out of media:content' => function (): void {
        $feed = tx_parse('rss2-media-content.xml');
        assertSame(
            'https://static01.nyt.com/images/2026/08/19/multimedia/19int-china-comedian-kwmc/'
            . '19int-china-comedian-kwmc-mediumSquareAt3X.jpg',
            $feed['items'][0]['image_url']
        );
        foreach ($feed['items'] as $i => $item) {
            assertMatches('#^https://static01\.nyt\.com/images/#', $item['image_url'], 'item ' . $i);
        }
    },

    // ------------------------------------------------------------ media:thumbnail

    'RSS 2.0: image comes out of media:thumbnail (BBC)' => function (): void {
        $feed = tx_parse('media-thumbnail.xml');

        assertSame('BBC News', $feed['title'], 'CDATA channel title');
        assertCount(3, $feed['items']);

        $a = $feed['items'][0];
        assertSame('Sacked Ukrainian defence minister calls for presidential election', $a['title']);
        assertSame(
            'https://ichef.bbci.co.uk/ace/standard/240/cpsprodpb/26e2/live/88af3c20-9b5f-11f1-aed2-8d6da8d75094.jpg',
            $a['image_url']
        );
        // &amp; in the link must survive as a single &, or every BBC link 404s.
        assertContains('?at_medium=RSS&at_campaign=rss', $a['url']);
        assertNotContains('&amp;', $a['url']);
        assertNotSame($a['guid'], $a['url'], 'BBC guid is the article id, not the tracking link');
        assertSame(1787137221000, $a['published_at']);
    },

    // ------------------------------------------------------------------- Atom

    'Atom: entries, rel=alternate links, author name' => function (): void {
        $raw  = tx_raw('atom.xml');
        $feed = tx_parse('atom.xml');

        assertSame('Current watches, warnings, and advisories', $feed['title']);
        assertSame(3, substr_count($raw, '<entry>'), 'fixture really does hold three entries');
        assertCount(2, $feed['items'], 'the titleless NWS keep-alive entry is dropped');

        $a = $feed['items'][0];
        assertSame(
            'Heat Advisory issued August 19 at 7:29AM EDT until August 19 at 8:00PM EDT by NWS Raleigh NC',
            $a['title']
        );
        // The url is the rel="alternate" href, NOT the <id> (they differ by the .cap suffix).
        assertSame(
            'https://api.weather.gov/alerts/urn:oid:2.49.0.1.840.0.7e5dbb64ec4545ef23b2c4d3d3d89c6157f260c9.001.1.cap',
            $a['url']
        );
        assertSame(
            'https://api.weather.gov/alerts/urn:oid:2.49.0.1.840.0.7e5dbb64ec4545ef23b2c4d3d3d89c6157f260c9.001.1',
            $a['guid']
        );
        assertSame('NWS', $a['author'], 'atom author>name');
        assertContains('Heat index values up to 104', $a['summary']);
        assertSame(1787138940000, $a['published_at'], 'published, in -04:00, converted to UTC ms');
        assertSame('2026-08-19T11:29:00+00:00', gmdate('c', intdiv((int) $a['published_at'], 1000)));
    },

    // -------------------------------------------------------------------- RDF

    'RDF / RSS 1.0: items live outside <channel> and dates come from dc:date' => function (): void {
        $raw  = tx_raw('rdf.xml');
        $feed = tx_parse('rdf.xml');

        assertContains('<rdf:RDF', $raw, 'fixture is genuinely RDF, not RSS 2.0');
        assertSame('World | Deutsche Welle', $feed['title']);
        assertCount(4, $feed['items']);

        $a = $feed['items'][0];
        assertSame('Fact check: How do I spot fake social media accounts, bots and trolls?', $a['title']);
        assertMatches('#^https://www\.dw\.com/en/fact-check-#', $a['url']);
        assertSame(1787136720000, $a['published_at'], 'dc:date 2026-08-19T10:52:00Z');
        assertSame('', $a['image_url'], 'this feed carries no images at all');
        foreach ($feed['items'] as $item) {
            assertNotSame('', $item['title']);
            assertNotSame('', $item['summary']);
            assertNotNull($item['published_at']);
        }
    },

    // ---------------------------------------------------- image inside the HTML

    'image is found inside content:encoded / description HTML' => function (): void {
        $raw  = tx_raw('content-encoded-image.xml');
        $feed = tx_parse('content-encoded-image.xml');

        // Prove the ONLY place an image could have come from is the HTML body.
        assertSame(0, substr_count($raw, 'media:'), 'fixture has no media: elements');
        assertSame(0, substr_count($raw, '<enclosure'), 'fixture has no enclosures');
        assertContains('&amp;quality=89', $raw, 'the src in the HTML is entity-encoded');

        $a = $feed['items'][0];
        assertSame('cucumber salad with sesame and rice vinegar', $a['title']);
        assertSame(
            'https://i0.wp.com/smittenkitchen.com/wp-content/uploads/2026/08/'
            . 'cucumber-salad-with-sesame-7-scaled.jpg?fit=640%2C427&quality=89&ssl=1',
            $a['image_url'],
            'src extracted from the <img> and entity-decoded'
        );
        assertNotContains('&amp;', $a['image_url']);
        assertNotContains('<img', $a['summary'], 'the same HTML must not leak into the summary');
        assertSame('deb', $a['author']);
    },

    // -------------------------------------------------------------- enclosure

    'image is found in <enclosure>, and a 5 KB description is capped' => function (): void {
        $raw  = tx_raw('enclosure.xml');
        $feed = tx_parse('enclosure.xml');

        assertSame(0, substr_count($raw, 'media:'), 'fixture has no media: elements');
        assertSame(0, substr_count($raw, '<img'), 'and no <img> in the body either');
        assertCount(3, $feed['items']);

        $a = $feed['items'][0];
        assertSame(
            'Save money and gas with a used hybrid vehicle. Here are some picks from Edmunds',
            $a['title']
        );
        assertSame(
            'https://www.srnnews.com/media/2026/08/1787138480545860P8yMz0icqx-apn.jpg?x39338',
            $a['image_url']
        );

        // SPEC §0.7 — we never store, and so can never republish, the full article.
        $shipped = tx_item_description($raw, 0);
        assertContains('Buying a used car', $shipped, 'the helper really did read item 0');
        assertGreaterThan(4000, strlen($shipped), 'the feed really does ship the whole story');
        assertLessThanOrEqual(Xml::SUMMARY_MAX, mb_strlen($a['summary']), 'stored summary is capped');
        assertContains("\u{2026}", $a['summary'], 'and is visibly truncated');
    },

    // -------------------------------------------------- entities and non-ASCII

    'entities decoded, non-ASCII preserved, widest image chosen' => function (): void {
        $raw  = tx_raw('entities-nonascii.xml');
        $feed = tx_parse('entities-nonascii.xml');

        $a = $feed['items'][0];
        assertSame(
            "Ebola outbreak in Democratic Republic of the Congo now deadliest in country\u{2019}s history",
            $a['title'],
            'U+2019 right single quote survives the round trip'
        );
        assertTrue(mb_check_encoding($a['title'], 'UTF-8'), 'title is valid UTF-8');
        assertTrue(mb_check_encoding($a['summary'], 'UTF-8'), 'summary is valid UTF-8');

        // The description arrives as escaped HTML (&lt;p&gt;…) and must come out as prose.
        assertContains('&lt;p&gt;', $raw);
        assertNotContains('&lt;', $a['summary']);
        assertNotContains('<p>', $a['summary']);
        assertNotContains('&amp;', $a['summary']);
        assertContains('At least 2,325 people have died', $a['summary']);

        // Three renditions are offered: 140, 460 and 700 wide. The widest wins.
        assertContains('width="140"', $raw);
        assertContains('width="460"', $raw);
        assertContains('width=700', $a['image_url'], 'widest media:content is the one kept');
        assertNotContains('width=140', $a['image_url']);
    },

    // --------------------------------------------------------- beacons / junk

    "src='undefined' and 1x1 beacons never become a card image" => function (): void {
        $raw  = tx_raw('tracking-pixel.xml');
        $feed = tx_parse('tracking-pixel.xml');

        assertContains("src='undefined'", $raw);
        assertContains('tracking/npr-rss-pixel', $raw);
        assertCount(2, $feed['items']);

        assertSame('', $feed['items'][0]['image_url'], 'neither the literal "undefined" nor the beacon');
        assertNotSame('', $feed['items'][1]['image_url'], 'a real image in the same feed is still found');
        assertContains('npr.brightspotcdn.com', $feed['items'][1]['image_url']);
    },

    // ---------------------------------------------------------------- dates

    'a date-less item yields null, never 0 and never false' => function (): void {
        $feed = tx_parse('edge-cases.xml');
        $byUrl = [];
        foreach ($feed['items'] as $i) {
            $byUrl[$i['url']] = $i;
        }

        assertArrayHasKey('https://example.org/no-date', $byUrl);
        assertNull($byUrl['https://example.org/no-date']['published_at'], 'no date element at all');
        assertNull($byUrl['https://example.org/bad-date']['published_at'], 'pubDate "not a date at all"');
        assertNull($byUrl['https://example.org/future']['published_at'], 'the year 3000 is a broken clock');
        assertNull(
            $byUrl['https://example.org/epoch-zero']['published_at'],
            'a 1970 timestamp is "we do not know", and must arrive as null rather than as 0'
        );
        assertNotSame(0, $byUrl['https://example.org/epoch-zero']['published_at']);

        assertSame(1787045400000, $byUrl['https://example.org/proto']['published_at']);
    },

    'every parsed date across every fixture is null or a plausible ms epoch' => function (): void {
        $checked = 0;
        foreach (glob(__DIR__ . '/fixtures/*.xml') ?: [] as $path) {
            $feed = Xml::parseFeed((string) file_get_contents($path));
            foreach ($feed['items'] as $item) {
                $checked++;
                $v = $item['published_at'];
                if ($v === null) {
                    continue;
                }
                assertTrue(is_int($v), 'published_at must be an int or null, got ' . gettype($v));
                assertNotSame(0, $v, 'never 0');
                assertGreaterThan(1000000000000, $v, 'looks like milliseconds, not seconds');
                assertLessThan((time() + 172800) * 1000, $v, 'not in the future');
            }
        }
        assertGreaterThan(25, $checked, 'the sweep actually looked at items');
    },

    // ----------------------------------------------------------- other edges

    'items with no headline or no destination are dropped' => function (): void {
        $raw  = tx_raw('edge-cases.xml');
        $feed = tx_parse('edge-cases.xml');

        assertSame(10, substr_count($raw, '<item>'), 'ten items in the file');
        assertCount(8, $feed['items'], 'the one with no link and the one with no title are dropped');
        foreach ($feed['items'] as $i) {
            assertNotSame('', $i['title']);
            assertMatches('#^https?://#', $i['url']);
        }
    },

    'a video rendition is skipped and a protocol-relative image is made absolute' => function (): void {
        $feed = tx_parse('edge-cases.xml');
        foreach ($feed['items'] as $i) {
            if ($i['url'] === 'https://example.org/proto') {
                assertSame('https://cdn.example.org/thumb.jpg', $i['image_url']);

                return;
            }
        }
        teb_fail('the protocol-relative fixture item was not parsed at all');
    },

    'an author that is only an email address is not published' => function (): void {
        $feed  = tx_parse('edge-cases.xml');
        $byUrl = [];
        foreach ($feed['items'] as $i) {
            $byUrl[$i['url']] = $i;
        }
        assertSame('', $byUrl['https://example.org/email-author']['author'], 'a bare address is dropped');
        assertSame('Dana Reyes', $byUrl['https://example.org/entities']['author'], 'the name inside (…) is kept');
    },

    'numeric and named entities are decoded once, and only once' => function (): void {
        $feed  = tx_parse('edge-cases.xml');
        $byUrl = [];
        foreach ($feed['items'] as $i) {
            $byUrl[$i['url']] = $i;
        }
        $s = $byUrl['https://example.org/entities']['summary'];

        assertContains('Café', $s, '&#233;');
        assertContains('München', $s, '&#252;');
        assertContains('“we will not budge”', $s, '&ldquo; / &rdquo;');
        assertContains('AT&T', $s, 'double-encoded &amp;amp; resolves to one ampersand');
        assertNotContains('&amp;', $s);
        assertNotContains('&#', $s);
    },

    // ------------------------------------------------------------- resilience

    'malformed XML returns an empty list, with no warning and no exception' => function (): void {
        $raw  = tx_raw('malformed.xml');
        $feed = tx_parse_counting_warnings($raw, $diagnostics);

        assertSame([], $feed['items']);
        assertSame('', $feed['title']);
        assertSame([], $diagnostics, 'the parser raised PHP diagnostics: ' . implode(' | ', $diagnostics));
    },

    'junk input of every shape is handled, never fatal' => function (): void {
        $junk = [
            '',
            '   ',
            'not xml at all',
            '<',
            '<?xml version="1.0"?>',
            '<rss><channel><item><title>x</title>',
            '<html><body><h1>404 Not Found</h1></body></html>',
            str_repeat('<a>', 500),
            "\x00\x01\x02binary\xff\xfe",
            '{"json":"not xml"}',
            "\xEF\xBB\xBF<?xml version=\"1.0\"?><rss version=\"2.0\"><channel><title>BOM</title></channel></rss>",
        ];
        foreach ($junk as $n => $input) {
            $feed = tx_parse_counting_warnings($input, $diagnostics);
            assertTrue(is_array($feed), 'case ' . $n);
            assertArrayHasKey('items', $feed, 'case ' . $n);
            assertArrayHasKey('title', $feed, 'case ' . $n);
            assertTrue(is_array($feed['items']), 'case ' . $n);
            assertSame([], $diagnostics, 'case ' . $n . ' raised: ' . implode(' | ', $diagnostics));
        }
    },

    'a BOM and a stray leading newline do not stop a good feed parsing' => function (): void {
        $raw  = "\xEF\xBB\xBF\n" . tx_raw('media-thumbnail.xml');
        $feed = Xml::parseFeed($raw);
        assertCount(3, $feed['items'], 'BOM + leading whitespace tolerated');
    },

    // --------------------------------------------------------------- security

    'XXE: an external entity is never resolved and no file content leaks' => function (): void {
        // Positive control: the file the attack targets really is readable by this process,
        // so "it did not leak" means something.
        $passwd = @file_get_contents('/etc/passwd');
        assertTrue(is_string($passwd) && strpos($passwd, 'root:') === 0, 'positive control: /etc/passwd is readable here');

        $raw  = tx_raw('xxe.xml');
        assertContains('<!ENTITY xxe SYSTEM "file:///etc/passwd">', $raw, 'the fixture really is an XXE attempt');

        $feed = tx_parse_counting_warnings($raw, $diagnostics);

        assertSame([], $feed['items'], 'a feed that declares entities is refused outright');
        assertSame('', $feed['title']);
        assertSame([], $diagnostics, 'and it is refused quietly: ' . implode(' | ', $diagnostics));

        $serialised = json_encode($feed);
        assertNotContains('root:', (string) $serialised, 'no /etc/passwd content anywhere in the result');
        assertNotContains('/bin/', (string) $serialised);
    },

    'billion laughs: the entity bomb is refused, quickly and without memory growth' => function (): void {
        $raw = tx_raw('billion-laughs.xml');
        assertContains('<!ENTITY lol9', $raw, 'the fixture really is an entity bomb');

        $before = memory_get_usage(true);
        $t0     = microtime(true);
        $feed   = tx_parse_counting_warnings($raw, $diagnostics);
        $ms     = (microtime(true) - $t0) * 1000;
        $grew   = memory_get_usage(true) - $before;

        assertSame([], $feed['items']);
        assertSame([], $diagnostics, 'refused quietly: ' . implode(' | ', $diagnostics));
        assertLessThan(1000.0, $ms, 'it must fail fast, not expand 10^9 entities');
        assertLessThan(4 * 1024 * 1024, $grew, 'and must not balloon memory');
    },

    /**
     * The cheap DOCTYPE screen only reads the first 8 KB, so padding the document past that
     * window slips a live entity declaration past it. This exercises the SECOND line of
     * defence, and it is worth being exact about what that defence is, because the four
     * combinations were measured rather than assumed:
     *
     *   NOENT off, loader neutered   no leak      <- what we ship
     *   NOENT off, loader live       no leak      libxml does not resolve external entities at all
     *   NOENT on,  loader neutered   no leak      our loader answers null
     *   NOENT on,  loader live       LEAKS        /etc/passwd lands in the summary
     *
     * So the end-to-end assertion below fails only in that fourth configuration, and
     * LIBXML_NOENT is the switch that makes the pair dangerous — hence the separate,
     * direct assertion that it is never in the flags. Internal entities are expanded by
     * libxml either way; NOENT changes nothing about them.
     */
    'XXE: entities are still never resolved when the DOCTYPE is padded past the head screen' => function (): void {
        $passwd = @file_get_contents('/etc/passwd');
        assertTrue(is_string($passwd) && strpos($passwd, 'root:') === 0, 'positive control: /etc/passwd is readable here');

        $pad = str_repeat('<!-- ' . str_repeat('x', 90) . " -->\n", 120);   // ~11.5 KB
        $raw = '<?xml version="1.0"?>' . "\n" . $pad
            . '<!DOCTYPE rss [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>' . "\n"
            . '<rss version="2.0"><channel><title>Padded</title>'
            . '<item><title>Leak</title><link>https://example.org/x</link>'
            . '<description>&xxe;</description></item></channel></rss>';
        assertGreaterThan(8192, strpos($raw, '<!DOCTYPE'), 'the DOCTYPE really is past the 8 KB screen');

        $feed = tx_parse_counting_warnings($raw, $diagnostics);

        assertSame([], $diagnostics, 'and it is handled quietly: ' . implode(' | ', $diagnostics));
        $serialised = (string) json_encode($feed);
        assertNotContains('root:', $serialised, '/etc/passwd must not reach the summary');
        assertNotContains('/bin/', $serialised);
        assertNotContains('daemon', $serialised);
        foreach ($feed['items'] as $item) {
            assertSame('', $item['summary'], 'the entity reference expands to nothing, not to a file');
        }

        // Same shape, but the payload expansion: it must not be expanded either.
        $bomb = '<?xml version="1.0"?>' . "\n" . $pad . '<!DOCTYPE r ['
            . '<!ENTITY a "aaaaaaaaaa">'
            . '<!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">'
            . '<!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;">'
            . '<!ENTITY d "&c;&c;&c;&c;&c;&c;&c;&c;&c;&c;">'
            . '<!ENTITY e "&d;&d;&d;&d;&d;&d;&d;&d;&d;&d;">'
            . '<!ENTITY f "&e;&e;&e;&e;&e;&e;&e;&e;&e;&e;">'
            . '<!ENTITY g "&f;&f;&f;&f;&f;&f;&f;&f;&f;&f;">'
            . ']><rss version="2.0"><channel><title>&g;</title></channel></rss>';

        $before = memory_get_usage(true);
        $t0     = microtime(true);
        $out    = tx_parse_counting_warnings($bomb, $diagnostics);
        $ms     = (microtime(true) - $t0) * 1000;

        assertSame('', $out['title'], 'a 10^7-character expansion never happens');
        assertLessThan(1000.0, $ms, 'it must fail fast');
        assertLessThan(4 * 1024 * 1024, memory_get_usage(true) - $before);
        assertSame([], $diagnostics);

        // The one switch that turns external entities into a resolvable thing at all.
        // Adding it is the plausible regression ("entities in my feed are not decoding"),
        // and it is invisible to the behavioural assertions above unless the loader is
        // also relaxed in the same edit.
        $src = (string) file_get_contents(teb_root() . '/app/Xml.php');
        $src = (string) preg_replace('#^\s*(\*|//).*$#m', '', $src);
        assertNotContains('LIBXML_NOENT', $src, 'app/Xml.php must never substitute entities — that is the XXE foot-gun');
        assertContains('LIBXML_NONET', $src, 'and the parser must never be allowed to hit the network');
    },

    'a document with a harmless DOCTYPE but no entities still parses' => function (): void {
        $raw = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<!DOCTYPE rss PUBLIC "-//Netscape Communications//DTD RSS 0.91//EN" "https://my.netscape.com/publish/formats/rss-0.91.dtd">' . "\n"
            . '<rss version="0.91"><channel><title>Old school</title>'
            . '<item><title>Still a story</title><link>https://example.org/old</link>'
            . '<description>Body.</description><pubDate>Tue, 18 Aug 2026 09:30:00 +0000</pubDate></item>'
            . '</channel></rss>';

        $feed = tx_parse_counting_warnings($raw, $diagnostics);
        assertCount(1, $feed['items'], 'only ENTITY declarations are refused, not every DOCTYPE');
        assertSame('Still a story', $feed['items'][0]['title']);
        assertSame([], $diagnostics);
    },

    // ------------------------------------------------------------- stripHtml

    'stripHtml removes markup, scripts and styles and decodes entities' => function (): void {
        assertSame('Hello world', Xml::stripHtml('<p>Hello <b>world</b></p>'));
        assertSame('One Two', Xml::stripHtml('<p>One</p><p>Two</p>'), 'block tags become a space, not nothing');
        assertSame('One Two', Xml::stripHtml('One<br>Two'));
        assertSame('Visible', Xml::stripHtml('<script>alert("x")</script>Visible'));
        assertSame('Visible', Xml::stripHtml('<style>.a{color:red}</style>Visible'));
        assertSame('AT&T', Xml::stripHtml('AT&amp;T'));
        assertSame('AT&T', Xml::stripHtml('AT&amp;amp;T'), 'double-encoded feeds are common');
        assertSame('a b', Xml::stripHtml("a\u{00A0}b"), 'non-breaking space becomes a normal one');
        assertSame('a b', Xml::stripHtml("a \n\t  b"));
        assertSame('', Xml::stripHtml(''));
        assertSame('', Xml::stripHtml('   '));
        assertSame('Café', Xml::stripHtml('Caf&eacute;'));
    },

    // ----------------------------------------------------------- trimSummary

    'trimSummary cuts on a word boundary and marks the cut' => function (): void {
        $s = 'The quick brown fox jumps over the lazy dog';

        assertSame($s, Xml::trimSummary($s, 200), 'no ellipsis when nothing was cut');
        assertSame($s, Xml::trimSummary($s, mb_strlen($s)), 'exactly the limit is not a cut');

        $cut = Xml::trimSummary($s, 20);
        assertContains("\u{2026}", $cut, 'a real cut is marked');
        assertLessThanOrEqual(20, mb_strlen($cut), 'the ellipsis is inside the budget');
        assertNotContains('jum', $cut, 'never cuts mid-word');
        assertSame('The quick brown…', $cut);
    },

    'trimSummary never leaves half an entity or a dangling bracket' => function (): void {
        assertNotContains('&', Xml::trimSummary('Fisher & Sons said the deal is off', 12));
        assertSame('Fisher…', Xml::trimSummary('Fisher & Sons said the deal is off', 12));

        $ent = Xml::trimSummary('Johnson &amp; Johnson and friends', 12);
        assertNotMatches('/&[a-z#0-9]*$/i', $ent, 'no half-written entity at the end');

        $tag = Xml::trimSummary('Read this <a href="https://example.org/very/long">link</a>', 18);
        assertNotContains('<', $tag, 'no dangling opening bracket');

        foreach ([1, 2, 3, 5, 8, 13, 21, 34, 55] as $max) {
            $out = Xml::trimSummary('Johnson &amp; Johnson &mdash; a very long line of prose &copy; 2026', $max);
            assertLessThanOrEqual($max, mb_strlen($out), 'budget honoured at max=' . $max);
            assertNotMatches('/&[a-z#0-9]*$/i', $out, 'no half entity at max=' . $max);
            assertNotMatches('/<[^>]*$/', $out, 'no half tag at max=' . $max);
        }
    },

    'trimSummary is multibyte safe' => function (): void {
        $s   = 'héllo wörld ünicode straße test';
        $out = Xml::trimSummary($s, 14);
        assertTrue(mb_check_encoding($out, 'UTF-8'), 'never splits a UTF-8 sequence');
        assertSame('héllo wörld…', $out);
        assertSame('', Xml::trimSummary('anything', 0));
        assertSame('', Xml::trimSummary('', 50));
    },

    // -------------------------------------------------- shape and non-hardcoding

    'every item has exactly the eight contract keys' => function (): void {
        $expected = ['guid', 'url', 'title', 'summary', 'body', 'image_url', 'published_at', 'author'];
        $seen     = 0;

        foreach (glob(__DIR__ . '/fixtures/*.xml') ?: [] as $path) {
            $feed = Xml::parseFeed((string) file_get_contents($path));
            assertArrayHasKey('title', $feed, basename($path));
            assertArrayHasKey('items', $feed, basename($path));
            assertTrue(is_string($feed['title']), basename($path) . ': title is a string');

            foreach ($feed['items'] as $item) {
                $seen++;
                assertSame($expected, array_keys($item), basename($path) . ': item keys');
                foreach (['guid', 'url', 'title', 'summary', 'image_url', 'author'] as $k) {
                    assertTrue(is_string($item[$k]), basename($path) . ": {$k} must be a string");
                }
                assertTrue(
                    $item['published_at'] === null || is_int($item['published_at']),
                    basename($path) . ': published_at is int|null'
                );
            }
        }
        assertGreaterThan(25, $seen, 'the sweep actually looked at items');
    },

    'the parser and the ingester carry no brand, domain or feed URL' => function (): void {
        // The only thing named TEB in these files is the namespace, which CONTRACT.md fixes.
        $cfg    = require teb_root() . '/config.php';
        $brand  = (string) ($cfg['site']['name'] ?? '');
        $domain = (string) ($cfg['site']['domain'] ?? '');

        foreach (['app/Xml.php', 'app/Ingest.php', 'cron/ingest.php'] as $rel) {
            $src = (string) file_get_contents(teb_root() . '/' . $rel);
            assertNotSame('', $src, $rel . ' is empty');

            if ($brand !== '') {
                assertNotContains($brand, $src, $rel . ' hardcodes the brand name');
            }
            if ($domain !== '') {
                assertNotContains($domain, $src, $rel . ' hardcodes the domain');
            }
            assertNotMatches(
                '#https?://(?!(www\\.)?(w3|purl|search\\.yahoo|itunes|apple)\\.)[a-z0-9.-]+\\.[a-z]{2,}/#i',
                preg_replace('#^\\s*\\*.*$#m', '', $src) ?? $src,
                $rel . ' contains a live URL outside a comment — feeds belong in app/Feeds.php'
            );
        }
    },
];
