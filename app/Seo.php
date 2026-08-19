<?php

declare(strict_types=1);

namespace TEB;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Everything a search engine reads: robots.txt, the sitemaps, the RSS feed and
 * the structured data.
 *
 * WHY THIS FILE EXISTS AT ALL
 * ---------------------------
 * The site we were asked to beat serves neither a robots.txt nor a sitemap.xml
 * — both 404 (docs/RECON.md, "Gaps we exploit" #1). For a business whose whole
 * product is indexed news pages that is the single largest miss on the site,
 * and closing it costs one file.
 *
 * FOUR RULES THIS FILE OBEYS WITHOUT EXCEPTION
 * --------------------------------------------
 * 1. No brand name and no domain literal appears anywhere below. The
 *    publication name comes from config, the host comes from the request.
 *    tests/test_config.php greps every file under app/ to prove it.
 * 2. Every URL emitted is absolute and is built by Paths::absolute(), so a
 *    sitemap generated from a subfolder install describes that subfolder, and
 *    a sitemap generated on a staging hostname describes staging. No string in
 *    this file starts with a bare '/' and ends up in a document.
 * 3. Everything that reaches XML goes through esc() or cdata(), both of which
 *    first run xmlSafe(). A publisher headline carrying an ampersand, an angle
 *    bracket, a smart quote, an emoji, a vertical tab or a broken UTF-8 byte
 *    must not be able to produce a document that a parser rejects — because a
 *    sitemap that fails to parse is a sitemap Google discards whole, silently.
 * 4. We publish a summary and link out, so the structured data says exactly
 *    that: isBasedOn points at the publisher's page, sourceOrganization names
 *    the newsroom, and no field is emitted that we cannot substantiate. There
 *    is no invented author, no invented wordCount and no invented image
 *    dimensions — Google treats fabricated structured data as spam, and it
 *    would also simply be a lie.
 *
 * ROUTES THIS SERVES (app/Router.php dispatches them)
 * ---------------------------------------------------
 *   /robots.txt        robotsTxt($cfg)                   text/plain
 *   /sitemap.xml       sitemap($pdo, $cfg)               application/xml
 *   /sitemap.xml?page=N sitemap($pdo, $cfg, N)           application/xml
 *   /sitemap-news.xml  newsSitemap($pdo, $cfg)           application/xml
 *   /feed.xml          rss($pdo, $cfg)                   application/rss+xml
 *   /feed.xml?section= rss($pdo, $cfg, 'us')             application/rss+xml
 *
 * sitemap() with no page returns a <urlset> while everything fits in one file
 * and a <sitemapindex> the moment it does not; the index points back at
 * ?page=1..N of the same route, which needs no new file on disk and works
 * identically with and without mod_rewrite.
 */
final class Seo
{
    /** Sitemap page size. The protocol allows 50,000; the spec asks for 5,000. */
    public const MAX_URLS_PER_SITEMAP = 5000;

    /** Refuse to enumerate an unbounded index if retention is ever misconfigured. */
    public const MAX_SITEMAP_PAGES = 500;

    /** Google News accepts articles from the last two days only. */
    public const NEWS_WINDOW_HOURS = 48;

    /** …and at most 1,000 URLs in a news sitemap. */
    public const NEWS_MAX_URLS = 1000;

    /** Items in the RSS feed. */
    public const RSS_MAX_ITEMS = 50;

    /** Characters of summary carried in an RSS description. */
    public const RSS_SUMMARY_MAX = 400;

    private const NS_SITEMAP = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    private const NS_NEWS    = 'http://www.google.com/schemas/sitemap-news/0.9';
    private const NS_ATOM    = 'http://www.w3.org/2005/Atom';

    private const RSS_DOCS = 'https://www.rssboard.org/rss-specification';

    private const SCHEMA_CONTEXT = 'https://schema.org';

    /**
     * Routes that exist whatever is in the database. 'lastmod' names where the
     * date comes from: the newest article anywhere, the newest article on one
     * desk, or nothing at all for a page that genuinely does not change.
     */
    private const STATIC_ROUTES = [
        ['path' => '/',         'changefreq' => 'hourly',  'priority' => '1.0', 'lastmod' => 'newest'],
        ['path' => '/weather',  'changefreq' => 'hourly',  'priority' => '0.6', 'lastmod' => 'section:weather'],
        ['path' => '/recipes',  'changefreq' => 'daily',   'priority' => '0.6', 'lastmod' => 'section:recipes'],
        ['path' => '/sources',  'changefreq' => 'monthly', 'priority' => '0.3', 'lastmod' => 'newest'],
        ['path' => '/about',    'changefreq' => 'yearly',  'priority' => '0.3', 'lastmod' => 'none'],
    ];

    /**
     * Desks that have a dedicated route of their own. Listing both /weather and
     * /section/weather would put two URLs carrying the same stories into the
     * sitemap, which is the duplicate-content problem you write a sitemap to
     * avoid. The dedicated route wins because that is what the navigation links.
     */
    private const SECTION_ROUTES = ['weather' => '/weather', 'recipes' => '/recipes'];

    /** Routes no crawler should spend budget on. Never in a sitemap; always in robots.txt. */
    private const DISALLOWED_ROUTES = ['/search', '/healthz', '/admin'];

    /** Files that exist on disk and must not be indexed either. */
    private const DISALLOWED_FILES = ['/install.php', '/cron/', '/data/'];

    // =====================================================================
    //  robots.txt
    // =====================================================================

    /**
     * The crawl policy. Deliberately permissive — this is a news site and the
     * whole point is to be indexed — with three exclusions: the search route
     * (infinite URL space, zero index value), the health endpoint, and the
     * installer.
     *
     * Both URL shapes are emitted for every excluded route. The site serves
     * pretty URLs when mod_rewrite is available and index.php?r=… when it is
     * not, and a crawler that found the other shape from an old link must be
     * excluded too — so we never rely on which mode happens to be active while
     * this file is being generated.
     *
     * @param array<string,mixed> $cfg
     */
    public static function robotsTxt(array $cfg = []): string
    {
        $name = self::oneLine(self::conf($cfg, 'site.name', ''));

        $out = [];
        if ($name !== '') {
            $out[] = '# ' . $name;
        }
        $out[] = '# Full stories stay with their publishers; these pages carry headline, summary and link.';
        $out[] = '';
        $out[] = 'User-agent: *';
        $out[] = 'Allow: ' . self::pathOnly(Paths::url('/'));

        $seen = [];
        foreach (self::DISALLOWED_ROUTES as $route) {
            foreach (self::bothUrlForms($route) as $form) {
                if (!isset($seen[$form])) {
                    $seen[$form] = true;
                    $out[]       = 'Disallow: ' . $form;
                }
            }
        }
        foreach (self::DISALLOWED_FILES as $file) {
            $form = self::pathOnly(Paths::base() . $file);
            if (!isset($seen[$form])) {
                $seen[$form] = true;
                $out[]       = 'Disallow: ' . $form;
            }
        }

        $out[] = '';
        $out[] = '# The index of everything worth crawling, and the last 48 hours for news.';
        $out[] = 'Sitemap: ' . Paths::absolute('/sitemap.xml');
        $out[] = 'Sitemap: ' . Paths::absolute('/sitemap-news.xml');
        $out[] = '';

        return implode("\n", $out);
    }

    // =====================================================================
    //  sitemap.xml
    // =====================================================================

    /**
     * The front page, every desk that has stories, the standing pages, and
     * EVERY article the database is holding.
     *
     * There is deliberately no date filter here. The obvious one — "articles
     * inside the retention window" — is wrong, because retention is not what
     * decides whether an article page exists. Db::pruneOld() deletes a row only
     * when published_at AND fetched_at are both past the cutoff, so a story
     * dated two months ago that arrived in a feed this morning is served, is
     * linked from its desk, and is not going anywhere. Filtering the sitemap on
     * published_at alone left exactly those pages out of it — silently, and
     * mostly on the recipe and weekly-column feeds, which are the slowest desks
     * to earn traffic and the ones that need the sitemap most.
     *
     * The bound is the pruner's job. This file's own bound is MAX_SITEMAP_PAGES,
     * so even a database whose cron has never run cannot make an endless index.
     *
     * @param array<string,mixed> $cfg
     * @param int|null            $page null/0 = decide (one urlset, or an index when it overflows);
     *                                  1..N   = that page of the index
     */
    public static function sitemap(PDO $p, array $cfg = [], ?int $page = null): string
    {
        $perPage = self::perPage($cfg);
        $static  = self::staticUrls($p, $cfg);
        $pages   = self::pageCount($p, $cfg, count($static));

        $page = ($page === null || $page < 1) ? 0 : $page;

        if ($page === 0 && $pages > 1) {
            return self::sitemapIndexXml($p, $cfg, $pages, $perPage, count($static));
        }

        $wanted = $page === 0 ? 1 : $page;
        if ($wanted > $pages) {
            // A page that does not exist is an empty, well-formed sitemap, not a
            // parse error and not a fatal.
            return self::urlsetXml([], $cfg);
        }

        return self::urlsetXml(self::pageUrls($p, $cfg, $wanted, $perPage, $static), $cfg);
    }

    /** How many sitemap pages the current database produces. 1 means no index. */
    public static function sitemapPageCount(PDO $p, array $cfg = []): int
    {
        return self::pageCount($p, $cfg, count(self::staticUrls($p, $cfg)));
    }

    private static function pageCount(PDO $p, array $cfg, int $staticCount): int
    {
        $perPage = self::perPage($cfg);
        $total   = $staticCount + Db::countArticles($p);
        $pages   = (int) ceil(max(1, $total) / $perPage);

        return max(1, min(self::MAX_SITEMAP_PAGES, $pages));
    }

    /**
     * The URLs on one page. Page 1 carries the standing pages first (they are
     * the ones that must never fall off the end), then the newest articles;
     * every later page is articles only, offset so that no URL is emitted twice
     * and none is skipped.
     *
     * @param  array<int,array<string,mixed>> $static
     * @return array<int,array<string,mixed>>
     */
    private static function pageUrls(
        PDO $p,
        array $cfg,
        int $page,
        int $perPage,
        array $static
    ): array {
        $firstPageRoom = max(0, $perPage - count($static));

        if ($page <= 1) {
            $rows = $firstPageRoom > 0
                ? Db::recentArticles($p, ['limit' => $firstPageRoom, 'offset' => 0])
                : [];

            return self::dedupe(array_merge($static, self::articleUrls($rows)));
        }

        $offset = $firstPageRoom + (($page - 2) * $perPage);
        $rows   = Db::recentArticles($p, ['limit' => $perPage, 'offset' => $offset]);

        return self::dedupe(self::articleUrls($rows));
    }

    /**
     * The standing URLs: the front page, the standing pages, and one entry per
     * desk that actually has stories. A desk with nothing on it is left out —
     * submitting an empty index page is submitting a soft 404.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function staticUrls(PDO $p, array $cfg): array
    {
        $counts = Db::sectionCounts($p);
        $newest = self::newestArticleMs($p);

        $out = [];
        foreach (self::STATIC_ROUTES as $route) {
            $lastmod = 0;
            if ($route['lastmod'] === 'newest') {
                $lastmod = $newest;
            } elseif (strpos($route['lastmod'], 'section:') === 0) {
                $slug    = substr($route['lastmod'], 8);
                $lastmod = ((int) ($counts[$slug] ?? 0)) > 0
                    ? self::sectionNewestMs($p, $slug)
                    : 0;
            }
            $out[] = [
                'loc'        => Paths::absolute($route['path']),
                'lastmod'    => $lastmod,
                'changefreq' => $route['changefreq'],
                'priority'   => $route['priority'],
            ];
        }

        foreach (self::sectionSlugs($counts) as $slug) {
            if (isset(self::SECTION_ROUTES[$slug]) || ((int) ($counts[$slug] ?? 0)) < 1) {
                continue;
            }
            $out[] = [
                'loc'        => Paths::absolute('/section/' . $slug),
                'lastmod'    => self::sectionNewestMs($p, $slug),
                'changefreq' => 'hourly',
                'priority'   => self::isHomeSection($slug) ? '0.8' : '0.5',
            ];
        }

        return self::dedupe($out);
    }

    /**
     * @param  array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function articleUrls(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || ((int) ($row['id'] ?? 0)) < 1) {
                continue;
            }
            $out[] = [
                'loc'     => Paths::absolute(self::articlePath($row)),
                'lastmod' => (int) ($row['published_at'] ?? 0),
            ];
        }

        return $out;
    }

    /** @param array<int,array<string,mixed>> $urls */
    private static function urlsetXml(array $urls, array $cfg = []): string
    {
        $x = self::xmlDeclaration()
            . '<urlset xmlns="' . self::NS_SITEMAP . '">' . "\n";

        foreach ($urls as $u) {
            $loc = (string) ($u['loc'] ?? '');
            if ($loc === '') {
                continue;
            }
            $x .= '  <url>' . "\n";
            $x .= '    <loc>' . self::esc($loc) . '</loc>' . "\n";
            if (((int) ($u['lastmod'] ?? 0)) > 0) {
                $x .= '    <lastmod>' . self::esc(self::w3cDate((int) $u['lastmod'], $cfg)) . '</lastmod>' . "\n";
            }
            if (($u['changefreq'] ?? '') !== '') {
                $x .= '    <changefreq>' . self::esc((string) $u['changefreq']) . '</changefreq>' . "\n";
            }
            if (($u['priority'] ?? '') !== '') {
                $x .= '    <priority>' . self::esc((string) $u['priority']) . '</priority>' . "\n";
            }
            $x .= '  </url>' . "\n";
        }

        return $x . '</urlset>' . "\n";
    }

    private static function sitemapIndexXml(
        PDO $p,
        array $cfg,
        int $pages,
        int $perPage,
        int $staticCount
    ): string {
        $firstPageRoom = max(0, $perPage - $staticCount);

        $x = self::xmlDeclaration()
            . '<sitemapindex xmlns="' . self::NS_SITEMAP . '">' . "\n";

        for ($i = 1; $i <= $pages; $i++) {
            if ($i === 1) {
                $lastmod = self::newestArticleMs($p);
            } else {
                $offset  = $firstPageRoom + (($i - 2) * $perPage);
                $rows    = Db::recentArticles($p, ['limit' => 1, 'offset' => $offset]);
                $lastmod = (int) ($rows[0]['published_at'] ?? 0);
            }
            $x .= '  <sitemap>' . "\n";
            $x .= '    <loc>' . self::esc(Paths::absolute('/sitemap.xml?page=' . $i)) . '</loc>' . "\n";
            if ($lastmod > 0) {
                $x .= '    <lastmod>' . self::esc(self::w3cDate($lastmod, $cfg)) . '</lastmod>' . "\n";
            }
            $x .= '  </sitemap>' . "\n";
        }

        return $x . '</sitemapindex>' . "\n";
    }

    // =====================================================================
    //  sitemap-news.xml
    // =====================================================================

    /**
     * The Google News sitemap: the last 48 hours, nothing else, capped at the
     * 1,000 URLs the specification allows.
     *
     * The window is not decoration. Google rejects a news sitemap carrying
     * older articles, and an article that has aged out of it must disappear
     * from the file rather than linger — so this reads the clock every call
     * instead of caching a list.
     *
     * @param array<string,mixed> $cfg
     */
    public static function newsSitemap(PDO $p, array $cfg = []): string
    {
        $now    = self::nowMs();
        $cutoff = $now - (self::NEWS_WINDOW_HOURS * 3600 * 1000);

        $rows = Db::recentArticles($p, [
            'since_ms' => $cutoff,
            'limit'    => self::NEWS_MAX_URLS,
        ]);

        $publication = self::publicationName($cfg);
        $language    = self::newsLanguage($cfg);

        $x = self::xmlDeclaration()
            . '<urlset xmlns="' . self::NS_SITEMAP . '"'
            . ' xmlns:news="' . self::NS_NEWS . '">' . "\n";

        $seen = [];
        foreach ($rows as $row) {
            $published = (int) ($row['published_at'] ?? 0);
            $title     = self::oneLine((string) ($row['title'] ?? ''));
            if ($published < $cutoff || $published > $now + 86400000 || $title === '') {
                // Belt to the query's braces: a row the SQL let through but that
                // cannot legally be in this file is dropped here as well.
                continue;
            }
            $loc = Paths::absolute(self::articlePath($row));
            if ($loc === '' || isset($seen[$loc])) {
                continue;
            }
            $seen[$loc] = true;

            $x .= '  <url>' . "\n";
            $x .= '    <loc>' . self::esc($loc) . '</loc>' . "\n";
            $x .= '    <news:news>' . "\n";
            $x .= '      <news:publication>' . "\n";
            $x .= '        <news:name>' . self::esc($publication) . '</news:name>' . "\n";
            $x .= '        <news:language>' . self::esc($language) . '</news:language>' . "\n";
            $x .= '      </news:publication>' . "\n";
            $x .= '      <news:publication_date>' . self::esc(self::w3cDate($published, $cfg))
                . '</news:publication_date>' . "\n";
            $x .= '      <news:title>' . self::esc($title) . '</news:title>' . "\n";
            $x .= '    </news:news>' . "\n";
            $x .= '  </url>' . "\n";
        }

        return $x . '</urlset>' . "\n";
    }

    // =====================================================================
    //  feed.xml
    // =====================================================================

    /**
     * RSS 2.0.
     *
     * Every <link> and every <guid> points at OUR article page, not at the
     * publisher's. That is the whole reason the feed exists: a reader who
     * subscribes should arrive here, read the summary, see the credit and then
     * choose to follow the outbound link. The publisher's own URL is still
     * present and prominent — it is what <source> credits and what the article
     * page links to — but it is not the feed's link target.
     *
     * @param array<string,mixed> $cfg
     * @param string|null         $section desk slug, or null for everything
     */
    public static function rss(PDO $p, array $cfg = [], ?string $section = null): string
    {
        $slug = self::sectionSlug($section);

        $opts = ['limit' => self::RSS_MAX_ITEMS];
        if ($slug !== null) {
            $opts['section'] = $slug;
        }
        $rows = Db::recentArticles($p, $opts);

        $siteName = self::publicationName($cfg);
        $meta     = $slug === null ? null : self::sectionMeta($slug);

        $title = $siteName;
        if ($meta !== null) {
            $label = self::oneLine((string) ($meta['label'] ?? $slug));
            $title = $siteName === '' ? $label : $siteName . ' — ' . $label;
        }

        $description = self::oneLine(self::conf($cfg, 'site.description', ''));
        if ($meta !== null && ($meta['blurb'] ?? '') !== '') {
            $description = self::oneLine((string) $meta['blurb']);
        }

        $channelPath = $slug === null ? '/' : self::sectionPath($slug);
        $selfPath    = $slug === null ? '/feed.xml' : '/feed.xml?section=' . rawurlencode($slug);

        $newest = 0;
        foreach ($rows as $row) {
            $newest = max($newest, (int) ($row['published_at'] ?? 0));
        }
        $built = $newest > 0 ? $newest : self::nowMs();

        $x = self::xmlDeclaration()
            . '<rss version="2.0" xmlns:atom="' . self::NS_ATOM . '">' . "\n"
            . '  <channel>' . "\n"
            . '    <title>' . self::cdata($title) . '</title>' . "\n"
            . '    <link>' . self::esc(Paths::absolute($channelPath)) . '</link>' . "\n"
            . '    <description>' . self::cdata($description) . '</description>' . "\n"
            . '    <language>' . self::esc(self::rssLanguage($cfg)) . '</language>' . "\n"
            . '    <lastBuildDate>' . self::esc(self::rfc2822($built, $cfg)) . '</lastBuildDate>' . "\n"
            . '    <pubDate>' . self::esc(self::rfc2822($built, $cfg)) . '</pubDate>' . "\n"
            . '    <ttl>15</ttl>' . "\n"
            . '    <docs>' . self::esc(self::RSS_DOCS) . '</docs>' . "\n"
            . '    <copyright>' . self::cdata(
                'Headlines and summaries remain the property of the publishers credited in each item.'
            ) . '</copyright>' . "\n";

        $x .= '    <atom:link rel="self" type="application/rss+xml" href="'
            . self::esc(Paths::absolute($selfPath)) . '" />' . "\n";

        foreach ($rows as $row) {
            $x .= self::rssItem($row, $cfg);
        }

        return $x . '  </channel>' . "\n" . '</rss>' . "\n";
    }

    /** @param array<string,mixed> $row */
    private static function rssItem(array $row, array $cfg = []): string
    {
        $title = self::oneLine((string) ($row['title'] ?? ''));
        if ($title === '' || ((int) ($row['id'] ?? 0)) < 1) {
            return '';
        }
        $link = Paths::absolute(self::articlePath($row));

        $summary = self::clip(self::oneLine((string) ($row['summary'] ?? '')), self::RSS_SUMMARY_MAX);
        if ($summary === '') {
            $summary = $title;
        }

        $x = '    <item>' . "\n"
            . '      <title>' . self::cdata($title) . '</title>' . "\n"
            . '      <link>' . self::esc($link) . '</link>' . "\n"
            . '      <guid isPermaLink="true">' . self::esc($link) . '</guid>' . "\n"
            . '      <description>' . self::cdata($summary) . '</description>' . "\n";

        $published = (int) ($row['published_at'] ?? 0);
        if ($published > 0) {
            $x .= '      <pubDate>' . self::esc(self::rfc2822($published, $cfg)) . '</pubDate>' . "\n";
        }

        $meta = self::sectionMeta((string) ($row['section'] ?? ''));
        if ($meta !== null) {
            $x .= '      <category>' . self::cdata(self::oneLine((string) ($meta['label'] ?? ''))) . '</category>' . "\n";
        }

        // RSS <source> means "the channel this item came from" and its url
        // attribute is required, so it is only emitted when we can name the
        // publisher's real feed. Never guessed.
        $sourceName = self::oneLine((string) ($row['source_name'] ?? ''));
        $sourceFeed = self::sourceFeedUrl((string) ($row['source_slug'] ?? ''));
        if ($sourceName !== '' && $sourceFeed !== '') {
            $x .= '      <source url="' . self::esc($sourceFeed) . '">' . self::esc($sourceName) . '</source>' . "\n";
        }

        return $x . '    </item>' . "\n";
    }

    // =====================================================================
    //  structured data
    // =====================================================================

    /**
     * schema.org NewsArticle for one article page.
     *
     * The honest shape for an aggregator, and every part of it is deliberate:
     *
     *   publisher           us — we do publish this page
     *   sourceOrganization  the newsroom that reported it
     *   isBasedOn           the publisher's own URL for the story
     *   mainEntityOfPage    our page, so there is no ambiguity about which URL
     *                       this markup describes
     *
     * and, just as deliberately, what is NOT here: no author unless the feed
     * actually gave us one, no wordCount (we hold a summary, not an article),
     * no articleBody, no image dimensions unless the feed stated them, no
     * dateModified (we never modify a stored summary, so claiming a
     * modification date would be inventing a fact).
     *
     * @param array<string,mixed> $a   an article row from Db
     * @param array<string,mixed> $cfg
     */
    public static function articleJsonLd(array $a, array $cfg = []): string
    {
        $id    = (int) ($a['id'] ?? 0);
        $title = self::oneLine((string) ($a['title'] ?? ''));
        if ($id < 1 || $title === '') {
            return '';
        }

        $url  = Paths::absolute(self::articlePath($a));
        $node = [
            '@context'         => self::SCHEMA_CONTEXT,
            '@type'            => 'NewsArticle',
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            'url'              => $url,
            // Google truncates headline at 110 characters; a longer one is a warning.
            'headline'         => self::clip($title, 110),
        ];

        $summary = self::clip(self::oneLine((string) ($a['summary'] ?? '')), 400);
        if ($summary !== '') {
            $node['description'] = $summary;
        }

        $published = (int) ($a['published_at'] ?? 0);
        if ($published > 0) {
            $node['datePublished'] = self::w3cDate($published, $cfg);
        }

        $language = self::bcp47($cfg);
        if ($language !== '') {
            $node['inLanguage'] = $language;
        }

        $section = self::sectionMeta((string) ($a['section'] ?? ''));
        if ($section !== null && ($section['label'] ?? '') !== '') {
            $node['articleSection'] = self::oneLine((string) $section['label']);
        }

        // The publisher's own page for this story. This is the field that keeps
        // the markup truthful: it says outright that our page is derived from,
        // and not a substitute for, theirs.
        $sourceUrl = self::httpUrl((string) ($a['url'] ?? ''));
        if ($sourceUrl !== '') {
            $node['isBasedOn'] = $sourceUrl;
        }

        $sourceName = self::oneLine((string) ($a['source_name'] ?? ''));
        if ($sourceName !== '') {
            $organisation = ['@type' => 'NewsMediaOrganization', 'name' => $sourceName];
            $homepage     = self::httpUrl((string) ($a['source_homepage'] ?? ''));
            if ($homepage !== '') {
                $organisation['url'] = $homepage;
            }
            $node['sourceOrganization'] = $organisation;
        }

        // Only when the feed named one. An invented byline is the exact thing
        // Google's structured-data policy calls out, and it would be a lie.
        $author = self::oneLine((string) ($a['author'] ?? ''));
        if ($author !== '' && mb_strlen($author) <= 120) {
            $node['author'] = ['@type' => 'Person', 'name' => $author];
        }

        $image = self::httpUrl((string) ($a['image_url'] ?? ''));
        if ($image !== '') {
            $width  = (int) ($a['image_width'] ?? 0);
            $height = (int) ($a['image_height'] ?? 0);
            if ($width > 0 && $height > 0) {
                $node['image'] = [
                    '@type'  => 'ImageObject',
                    'url'    => $image,
                    'width'  => $width,
                    'height' => $height,
                ];
            } else {
                // Dimensions unknown, so none are claimed.
                $node['image'] = $image;
            }
        }

        $publisher = self::publisherNode($cfg);
        if ($publisher !== null) {
            $node['publisher'] = $publisher;
        }

        $node['isAccessibleForFree'] = true;

        return self::json($node);
    }

    /**
     * BreadcrumbList. Position 1 is always the front page, so a caller only has
     * to describe where it is, not where it came from.
     *
     * @param array<int,array<string,mixed>> $crumbs [['name'=>'U.S.','path'=>'/section/us'], …]
     *                                               'url' (already absolute) is accepted instead of 'path'
     * @param array<string,mixed>            $cfg
     */
    public static function breadcrumbJsonLd(array $crumbs, array $cfg = []): string
    {
        $items = [];

        $home = self::publicationName($cfg);
        $items[] = [
            'name' => $home !== '' ? $home : 'Front page',
            'url'  => Paths::absolute('/'),
        ];

        foreach ($crumbs as $crumb) {
            if (!is_array($crumb)) {
                continue;
            }
            $name = self::oneLine((string) ($crumb['name'] ?? ($crumb['label'] ?? '')));
            if ($name === '') {
                continue;
            }
            $url = self::httpUrl((string) ($crumb['url'] ?? ''));
            if ($url === '' && ($crumb['path'] ?? '') !== '') {
                $url = Paths::absolute((string) $crumb['path']);
            }
            if ($url === Paths::absolute('/')) {
                continue;                       // caller already had the front page in the trail
            }
            $items[] = ['name' => self::clip($name, 120), 'url' => $url];
        }

        if (count($items) < 2) {
            // A breadcrumb of one item describes nothing; emitting it is noise.
            return '';
        }

        $list = [];
        foreach (array_values($items) as $i => $item) {
            $entry = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $item['name'],
            ];
            if ($item['url'] !== '') {
                $entry['item'] = $item['url'];
            }
            $list[] = $entry;
        }

        return self::json([
            '@context'        => self::SCHEMA_CONTEXT,
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $list,
        ]);
    }

    /**
     * WebSite + SearchAction, for the front page only.
     *
     * The SearchAction target is built through Paths, so it stays correct in a
     * subfolder and in ?r= mode. {search_term_string} is left unencoded on
     * purpose — the placeholder is part of Google's grammar, not a value.
     *
     * @param array<string,mixed> $cfg
     */
    public static function websiteJsonLd(array $cfg = []): string
    {
        $name = self::publicationName($cfg);
        if ($name === '') {
            return '';
        }

        $node = [
            '@context' => self::SCHEMA_CONTEXT,
            '@type'    => 'WebSite',
            'name'     => $name,
            'url'      => Paths::absolute('/'),
        ];

        $description = self::oneLine(self::conf($cfg, 'site.description', ''));
        if ($description !== '') {
            $node['description'] = $description;
        }

        $alternate = self::oneLine(self::conf($cfg, 'site.short_name', ''));
        if ($alternate !== '' && $alternate !== $name) {
            $node['alternateName'] = $alternate;
        }

        $language = self::bcp47($cfg);
        if ($language !== '') {
            $node['inLanguage'] = $language;
        }

        $publisher = self::publisherNode($cfg);
        if ($publisher !== null) {
            $node['publisher'] = $publisher;
        }

        $node['potentialAction'] = [
            '@type'       => 'SearchAction',
            'target'      => [
                '@type'       => 'EntryPoint',
                'urlTemplate' => Paths::absolute('/search?q={search_term_string}'),
            ],
            'query-input' => 'required name=search_term_string',
        ];

        return self::json($node);
    }

    /** The publication as an Organization node. */
    private static function publisherNode(array $cfg): ?array
    {
        $name = self::publicationName($cfg);
        if ($name === '') {
            return null;
        }

        return [
            '@type' => 'Organization',
            'name'  => $name,
            'url'   => Paths::absolute('/'),
        ];
    }

    /**
     * The name to publish under.
     *
     * config.site.name is the answer whenever it has one. When it does not —
     * a client who emptied the field, a first upload before anything was
     * filled in — the fallback is the host this request arrived on, because
     * the alternative is worse than ugly: <news:name> is a REQUIRED element
     * and Google rejects a news sitemap whose publication is unnamed, and an
     * RSS channel with an empty <title> is invalid RSS. The host is a fact
     * about the install, not an invented brand, and it is never a literal in
     * this file.
     */
    private static function publicationName(array $cfg): string
    {
        $name = self::oneLine(self::conf($cfg, 'site.name', ''));
        if ($name !== '') {
            return $name;
        }

        return self::oneLine(Paths::host());
    }

    // =====================================================================
    //  routes
    // =====================================================================

    /**
     * /article/{slug}-{id}. Delegates to the renderer when it is loaded so the
     * sitemap can never point at a URL the site would answer with a canonical
     * redirect; the local copy is a byte-identical fallback for the CLI, where
     * only this class may be required.
     *
     * @param array<string,mixed> $a
     */
    public static function articlePath(array $a): string
    {
        $id = (int) ($a['id'] ?? 0);
        if ($id < 1) {
            return '/';
        }
        if (class_exists(Render::class) && method_exists(Render::class, 'articleHref')) {
            $href = Render::articleHref($a);
            if (is_string($href) && $href !== '') {
                return $href;
            }
        }

        $slug = trim((string) ($a['slug'] ?? ''));
        if ($slug === '') {
            $slug = trim((string) ($a['title'] ?? ''));
        }
        $slug = self::slug($slug);

        return '/article/' . ($slug !== '' ? $slug . '-' : '') . $id;
    }

    /** The canonical route for a desk: its own page when it has one. */
    public static function sectionPath(string $slug): string
    {
        $slug = strtolower(trim($slug));

        return self::SECTION_ROUTES[$slug] ?? ('/section/' . $slug);
    }

    /** Mirror of Render::slug, byte-wise so it can never return null on bad UTF-8. */
    private static function slug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = str_replace(['&', '@'], [' and ', ' at '], $s);
        $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim($s, '-');
        if (strlen($s) > 72) {
            $s   = substr($s, 0, 72);
            $cut = strrpos($s, '-');
            if ($cut !== false && $cut > 24) {
                $s = substr($s, 0, $cut);
            }
            $s = trim($s, '-');
        }

        return $s;
    }

    /**
     * Both URL shapes for one route: the pretty one and the ?r= one. robots.txt
     * has to exclude a route however a crawler found it.
     *
     * @return array<int,string>
     */
    private static function bothUrlForms(string $route): array
    {
        // Both forms are needed regardless of which mode is active, and
        // Paths::url() can only ever return the active one — so these are
        // composed from Paths::base() directly rather than by flipping the
        // rewrite flag, which is global state other requests are reading.
        // Every route in DISALLOWED_ROUTES is plain ASCII, so nothing here
        // needs percent-encoding.
        $base = Paths::base();

        return array_values(array_unique([
            self::pathOnly($base . $route),
            self::pathOnly($base . '/index.php?r=' . $route),
        ]));
    }

    /** Strip scheme+host if one ever appears — robots.txt paths are host relative. */
    private static function pathOnly(string $url): string
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            $path  = (string) (parse_url($url, PHP_URL_PATH) ?? '/');
            $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');

            return $path . ($query !== '' ? '?' . $query : '');
        }
        $url = self::oneLine($url);

        return $url === '' ? '/' : $url;
    }

    // =====================================================================
    //  data helpers
    // =====================================================================

    private static function newestArticleMs(PDO $p): int
    {
        $rows = Db::recentArticles($p, ['limit' => 1]);

        return (int) ($rows[0]['published_at'] ?? 0);
    }

    private static function sectionNewestMs(PDO $p, string $slug): int
    {
        $rows = Db::recentArticles($p, ['section' => $slug, 'limit' => 1]);

        return (int) ($rows[0]['published_at'] ?? 0);
    }

    /**
     * Desk slugs in navigation order when the registry is loaded, otherwise
     * whatever the database actually holds. Deterministic either way.
     *
     * @param  array<string,int> $counts
     * @return array<int,string>
     */
    private static function sectionSlugs(array $counts): array
    {
        if (class_exists(Feeds::class)) {
            return array_keys(Feeds::sections());
        }
        $slugs = array_keys($counts);
        sort($slugs);

        return $slugs;
    }

    private static function isHomeSection(string $slug): bool
    {
        $meta = self::sectionMeta($slug);

        return $meta !== null && ($meta['home'] ?? null) !== null;
    }

    /** @return array<string,mixed>|null */
    private static function sectionMeta(string $slug): ?array
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || !class_exists(Feeds::class)) {
            return null;
        }
        $meta = Feeds::section($slug);

        return is_array($meta) ? $meta : null;
    }

    /** Validate a desk slug against the registry; unknown desks become null. */
    private static function sectionSlug(?string $slug): ?string
    {
        if ($slug === null) {
            return null;
        }
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return null;
        }

        return self::sectionMeta($slug) === null ? null : $slug;
    }

    private static function sourceFeedUrl(string $sourceSlug): string
    {
        $sourceSlug = trim($sourceSlug);
        if ($sourceSlug === '' || !class_exists(Feeds::class)) {
            return '';
        }
        $feed = Feeds::bySlug($sourceSlug);

        return is_array($feed) ? self::httpUrl((string) ($feed['feed'] ?? '')) : '';
    }

    private static function perPage(array $cfg): int
    {
        $n = (int) self::conf($cfg, 'seo.urls_per_sitemap', self::MAX_URLS_PER_SITEMAP);

        return max(10, min(self::MAX_URLS_PER_SITEMAP, $n));
    }

    private static function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    // =====================================================================
    //  configuration
    // =====================================================================

    /**
     * Read a dotted path out of the caller's config, falling back to the loaded
     * configuration. A caller that passes [] still gets real values, and a
     * caller that passes a partial array still gets what it did pass.
     *
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

    private static function tz(array $cfg = []): DateTimeZone
    {
        $name = (string) self::conf($cfg, 'site.timezone', 'UTC');
        if ($name !== '') {
            try {
                return new DateTimeZone($name);
            } catch (Throwable $e) {
                // A timezone typed from memory must not break the sitemap.
            }
        }

        return new DateTimeZone('UTC');
    }

    /**
     * 'en-US' from a POSIX locale, for inLanguage.
     *
     * Stripping the characters a language tag may not contain is not enough on
     * its own: '"><x' survives that as 'x', and a one-letter language is not a
     * language — it reaches <language>, <news:language> and inLanguage as a
     * value no reader and no crawler can act on. Anything that is not shaped
     * like a real tag is refused here, once, so every caller below inherits the
     * refusal.
     */
    private static function bcp47(array $cfg): string
    {
        $locale = self::oneLine(self::conf($cfg, 'site.locale', ''));
        if ($locale === '') {
            return '';
        }
        $locale = str_replace('_', '-', $locale);
        $locale = (string) preg_replace('/[^A-Za-z0-9\-]/', '', $locale);
        $locale = trim($locale, '-');

        return preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $locale) === 1 ? $locale : '';
    }

    /** RSS <language> wants the RFC 1766 form, conventionally lowercased. */
    private static function rssLanguage(array $cfg): string
    {
        $tag = self::bcp47($cfg);

        return $tag === '' ? 'en' : strtolower($tag);
    }

    /**
     * Google News wants ISO 639-1 — two letters — with zh-cn and zh-tw as the
     * only three-plus-character values it accepts.
     */
    private static function newsLanguage(array $cfg): string
    {
        $tag = strtolower(self::bcp47($cfg));
        if ($tag === '') {
            return 'en';
        }
        if (strpos($tag, 'zh') === 0) {
            return strpos($tag, 'tw') !== false || strpos($tag, 'hant') !== false ? 'zh-tw' : 'zh-cn';
        }
        $primary = explode('-', $tag)[0];

        return preg_match('/^[a-z]{2,3}$/', $primary) === 1 ? $primary : 'en';
    }

    // =====================================================================
    //  dates
    // =====================================================================

    /** W3C datetime, e.g. 2026-08-19T18:42:07-04:00 — valid for lastmod and news dates. */
    private static function w3cDate(int $ms, array $cfg = []): string
    {
        return self::at($ms, $cfg)->format(DateTimeInterface::ATOM);
    }

    /** RFC 2822, e.g. Tue, 19 Aug 2026 18:42:07 -0400 — what RSS pubDate requires. */
    private static function rfc2822(int $ms, array $cfg = []): string
    {
        return self::at($ms, $cfg)->format(DateTimeInterface::RSS);
    }

    private static function at(int $ms, array $cfg = []): DateTimeImmutable
    {
        $seconds = intdiv(max(0, $ms), 1000);

        try {
            return (new DateTimeImmutable('@' . $seconds))->setTimezone(self::tz($cfg));
        } catch (Throwable $e) {
            return new DateTimeImmutable('@0');
        }
    }

    // =====================================================================
    //  XML and JSON safety
    // =====================================================================

    private static function xmlDeclaration(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    }

    /**
     * Make a string legal inside an XML 1.0 document.
     *
     * Two separate hazards, and both are real in a feed aggregator:
     *
     *  1. Invalid UTF-8. One truncated multibyte sequence from a publisher makes
     *     the whole document unparseable, and htmlspecialchars would hand back
     *     an empty string instead — silently deleting the headline.
     *  2. Control characters. XML 1.0 permits only tab, newline and carriage
     *     return below U+0020. A vertical tab or a form feed inside a title is
     *     rare but it happens, and it is not escapable: there is no character
     *     reference for it, so it has to be removed.
     */
    private static function xmlSafe(string $s): string
    {
        if ($s === '') {
            return '';
        }
        if (!mb_check_encoding($s, 'UTF-8')) {
            $repaired = @mb_convert_encoding($s, 'UTF-8', 'UTF-8');
            $s        = is_string($repaired) ? $repaired : '';
        }
        $clean = preg_replace(
            '/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
            '',
            $s
        );

        return is_string($clean) ? $clean : '';
    }

    /** Escaped text or attribute content. */
    private static function esc(string $s): string
    {
        return htmlspecialchars(self::xmlSafe($s), ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * CDATA, with the one sequence that can close a CDATA section early split
     * across two sections. Without this a headline containing ']]>' ends the
     * section, and everything after it becomes markup.
     */
    private static function cdata(string $s): string
    {
        return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', self::xmlSafe($s)) . ']]>';
    }

    /**
     * JSON-LD. Slashes stay escaped (the default) so the string '</script>' can
     * never close the block it is printed inside, and invalid UTF-8 is repaired
     * rather than turning the whole encode into false.
     *
     * @param array<string,mixed> $node
     */
    private static function json(array $node): string
    {
        $json = json_encode(
            $node,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION
        );

        return $json === false ? '' : $json;
    }

    // =====================================================================
    //  small string helpers
    // =====================================================================

    /** Collapse all whitespace, including newlines, to single spaces. */
    private static function oneLine($v): string
    {
        if (!is_string($v)) {
            $v = is_scalar($v) ? (string) $v : '';
        }
        $v = self::xmlSafe($v);
        $v = preg_replace('/\s+/u', ' ', $v);

        return trim(is_string($v) ? $v : '');
    }

    /** Cut on a word boundary, never mid-word, never mid-multibyte-character. */
    private static function clip(string $s, int $max): string
    {
        $s = trim($s);
        if ($max < 1 || mb_strlen($s) <= $max) {
            return $s;
        }
        $cut   = mb_substr($s, 0, $max);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > (int) ($max * 0.5)) {
            $cut = mb_substr($cut, 0, $space);
        }

        // rtrim() with a multibyte character in its list strips BYTES, which
        // would cut an em dash's bytes out of the middle of an unrelated
        // character (U+30D4 ends 0x94, the same as U+2014). Regex with /u
        // cannot make that mistake.
        $trimmed = preg_replace('/[\s.,;:\-\x{2010}-\x{2015}]+$/u', '', $cut);

        return (is_string($trimmed) ? $trimmed : $cut) . '…';
    }

    /** A URL we are willing to print. Only http and https survive. */
    private static function httpUrl(string $url): string
    {
        $url = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $url));
        if ($url === '') {
            return '';
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (($scheme !== 'http' && $scheme !== 'https') || (string) parse_url($url, PHP_URL_HOST) === '') {
            return '';
        }

        return $url;
    }

    /**
     * @param  array<int,array<string,mixed>> $urls
     * @return array<int,array<string,mixed>>
     */
    private static function dedupe(array $urls): array
    {
        $seen = [];
        $out  = [];
        foreach ($urls as $u) {
            $loc = (string) ($u['loc'] ?? '');
            if ($loc === '' || isset($seen[$loc])) {
                continue;
            }
            $seen[$loc] = true;
            $out[]      = $u;
        }

        return $out;
    }
}
