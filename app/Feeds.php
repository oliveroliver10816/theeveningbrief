<?php
declare(strict_types=1);

namespace TEB;

/**
 * The feed registry: which newsrooms we read, and how often.
 *
 * Data only — no fetching, no parsing, no I/O. app/Ingest.php consumes all()
 * and due(); app/Weather.php and app/Recipes.php consume services().
 *
 * Every URL below was requested and confirmed HTTP 200 with parseable items on
 * 2026-08-19 (the roster in docs/RECON.md, re-verified before it was written
 * in). Formats in the set: RSS 2.0, RSS with media:/content:/dc: namespaces,
 * RDF (Deutsche Welle) and Atom (the National Weather Service) — between them
 * they exercise every branch of the parser.
 *
 * ⚠ Known dead or blocking, do not re-add: seriouseats.com/feeds/all (402),
 * simplyrecipes.com/feeds/all (402), food52.com/blog.rss (429),
 * apnews.com/hub/ap-top-news/rss (404), reutersagency.com/feed/ (404). AP and
 * Reuters copy still reaches the site through SRN News, which carries both.
 *
 * Entry shape:
 *   slug     stable id, also the database key — never change one in place
 *   name     the credit line printed under every headline
 *   feed     the URL fetched
 *   section  which desk it files to; must be a key of sections()
 *   country  ISO-3166-1 alpha-2 of the newsroom, for the sources page
 *   tier     1 = fetch often, 2 = middling, 3 = slow. See TIER_MINUTES.
 *   weight   ranking multiplier in Compose; 1.0 is neutral
 *   homepage where the credit links to
 */
final class Feeds
{
    /** How many minutes between fetches of a feed in each tier. */
    private const TIER_MINUTES = [1 => 10, 2 => 20, 3 => 60];

    /** Consecutive failures after which a feed is parked and retried slowly. */
    public const PARK_AFTER_FAILURES = 8;

    /** How long a parked feed is left alone before one more attempt (minutes). */
    private const PARKED_RETRY_MINUTES = 360;

    /**
     * Desks. Order here is the order of the navigation; 'home' is the order of
     * the blocks on the front page (SPEC §0.8: U.S. → International → World →
     * Weather → Recipes) and null keeps a section off the front page entirely.
     *
     * 'finance' marks the desks the front-page money quota applies to. It is
     * data, so Compose, Render and the tests all read the same list.
     */
    private const SECTIONS = [
        'us' => [
            'label'   => 'U.S.',
            'note'    => 'the national desk',
            'blurb'   => 'National reporting from American newsrooms.',
            'home'    => 1,
            'finance' => false,
        ],
        'international' => [
            'label'   => 'International',
            'note'    => 'America abroad',
            'blurb'   => 'The world as American newsrooms are covering it.',
            'home'    => 2,
            'finance' => false,
        ],
        'world' => [
            'label'   => 'World',
            'note'    => 'the wire, from outside the United States',
            'blurb'   => 'Reporting from broadcasters and papers outside the United States.',
            'home'    => 3,
            'finance' => false,
        ],
        'weather' => [
            'label'   => 'Weather',
            'note'    => 'forecast and warnings',
            'blurb'   => 'The forecast, and every active National Weather Service warning.',
            'home'    => 4,
            'finance' => false,
        ],
        'recipes' => [
            'label'   => 'Recipes',
            'note'    => 'something to cook tonight',
            'blurb'   => 'One dish at a time, from kitchens that publish their own recipes.',
            'home'    => 5,
            'finance' => false,
        ],
        'politics' => [
            'label'   => 'Politics',
            'note'    => 'Washington',
            'blurb'   => 'Congress, the courts, the campaign.',
            'home'    => null,
            'finance' => false,
        ],
        'business' => [
            'label'   => 'Business',
            'note'    => 'money and markets',
            'blurb'   => 'Companies, markets and the economy.',
            'home'    => null,
            'finance' => true,
        ],
        'technology' => [
            'label'   => 'Technology',
            'note'    => 'the industry',
            'blurb'   => 'Technology, the companies building it and the rules catching up.',
            'home'    => null,
            'finance' => false,
        ],
        'health' => [
            'label'   => 'Health',
            'note'    => 'medicine and public health',
            'blurb'   => 'Medicine, public health and the science behind both.',
            'home'    => null,
            'finance' => false,
        ],
        'entertainment' => [
            'label'   => 'Entertainment',
            'note'    => 'screen, stage and sound',
            'blurb'   => 'Film, television, music and the business of all three.',
            'home'    => null,
            'finance' => false,
        ],
        'sports' => [
            'label'   => 'Sports',
            'note'    => 'the scoreboard',
            'blurb'   => 'Results, transfers and the stories around them.',
            'home'    => null,
            'finance' => false,
        ],
    ];

    /**
     * The roster. 34 feeds.
     *
     * Weighting rests on how well a feed behaves as a feed — how promptly it
     * updates, whether it carries images, and how much its summaries actually
     * summarise — not on any judgement about the newsroom.
     */
    private const FEEDS = [
        // ---------------------------------------------------------- U.S. desk
        ['slug' => 'abc-us',        'name' => 'ABC News',            'feed' => 'https://feeds.abcnews.com/abcnews/usheadlines',            'section' => 'us', 'country' => 'US', 'tier' => 1, 'weight' => 1.00, 'homepage' => 'https://abcnews.go.com/'],
        ['slug' => 'abc-top',       'name' => 'ABC News',            'feed' => 'https://feeds.abcnews.com/abcnews/topstories',              'section' => 'us', 'country' => 'US', 'tier' => 1, 'weight' => 1.00, 'homepage' => 'https://abcnews.go.com/'],
        ['slug' => 'cbs-us',        'name' => 'CBS News',            'feed' => 'https://www.cbsnews.com/latest/rss/us',                     'section' => 'us', 'country' => 'US', 'tier' => 1, 'weight' => 0.95, 'homepage' => 'https://www.cbsnews.com/'],
        ['slug' => 'nbc-news',      'name' => 'NBC News',            'feed' => 'https://feeds.nbcnews.com/nbcnews/public/news',             'section' => 'us', 'country' => 'US', 'tier' => 1, 'weight' => 0.95, 'homepage' => 'https://www.nbcnews.com/'],
        ['slug' => 'npr-news',      'name' => 'NPR',                 'feed' => 'https://feeds.npr.org/1001/rss.xml',                        'section' => 'us', 'country' => 'US', 'tier' => 1, 'weight' => 1.00, 'homepage' => 'https://www.npr.org/'],
        ['slug' => 'nyt-us',        'name' => 'The New York Times',  'feed' => 'https://rss.nytimes.com/services/xml/rss/nyt/US.xml',       'section' => 'us', 'country' => 'US', 'tier' => 1, 'weight' => 1.05, 'homepage' => 'https://www.nytimes.com/'],
        ['slug' => 'nyt-home',      'name' => 'The New York Times',  'feed' => 'https://rss.nytimes.com/services/xml/rss/nyt/HomePage.xml', 'section' => 'us', 'country' => 'US', 'tier' => 1, 'weight' => 1.00, 'homepage' => 'https://www.nytimes.com/'],
        ['slug' => 'pbs-newshour',  'name' => 'PBS NewsHour',        'feed' => 'https://www.pbs.org/newshour/feeds/rss/headlines',          'section' => 'us', 'country' => 'US', 'tier' => 2, 'weight' => 0.95, 'homepage' => 'https://www.pbs.org/newshour/'],
        ['slug' => 'upi-top',       'name' => 'UPI',                 'feed' => 'https://rss.upi.com/news/top_news.rss',                     'section' => 'us', 'country' => 'US', 'tier' => 2, 'weight' => 0.85, 'homepage' => 'https://www.upi.com/'],
        ['slug' => 'srn-news',      'name' => 'SRN News',            'feed' => 'https://www.srnnews.com/feed/',                             'section' => 'us', 'country' => 'US', 'tier' => 2, 'weight' => 0.85, 'homepage' => 'https://www.srnnews.com/'],
        ['slug' => 'fox-latest',    'name' => 'Fox News',            'feed' => 'https://moxie.foxnews.com/google-publisher/latest.xml',     'section' => 'us', 'country' => 'US', 'tier' => 2, 'weight' => 0.85, 'homepage' => 'https://www.foxnews.com/'],
        ['slug' => 'guardian-us',   'name' => 'The Guardian',        'feed' => 'https://www.theguardian.com/us-news/rss',                   'section' => 'us', 'country' => 'GB', 'tier' => 2, 'weight' => 0.90, 'homepage' => 'https://www.theguardian.com/us-news'],

        // ------------------------------------------------------- Politics desk
        ['slug' => 'npr-politics',  'name' => 'NPR Politics',        'feed' => 'https://feeds.npr.org/1014/rss.xml',                        'section' => 'politics', 'country' => 'US', 'tier' => 2, 'weight' => 0.90, 'homepage' => 'https://www.npr.org/sections/politics/'],

        // -------------------------------------------------- International desk
        ['slug' => 'abc-international', 'name' => 'ABC News',        'feed' => 'https://feeds.abcnews.com/abcnews/internationalheadlines',  'section' => 'international', 'country' => 'US', 'tier' => 1, 'weight' => 1.00, 'homepage' => 'https://abcnews.go.com/International'],
        ['slug' => 'cbs-world',     'name' => 'CBS News',            'feed' => 'https://www.cbsnews.com/latest/rss/world',                  'section' => 'international', 'country' => 'US', 'tier' => 1, 'weight' => 0.95, 'homepage' => 'https://www.cbsnews.com/world/'],
        ['slug' => 'nyt-world',     'name' => 'The New York Times',  'feed' => 'https://rss.nytimes.com/services/xml/rss/nyt/World.xml',    'section' => 'international', 'country' => 'US', 'tier' => 1, 'weight' => 1.05, 'homepage' => 'https://www.nytimes.com/section/world'],
        ['slug' => 'upi-world',     'name' => 'UPI',                 'feed' => 'https://rss.upi.com/news/world_news.rss',                   'section' => 'international', 'country' => 'US', 'tier' => 2, 'weight' => 0.85, 'homepage' => 'https://www.upi.com/Top_News/World-News/'],
        ['slug' => 'wapo-world',    'name' => 'The Washington Post', 'feed' => 'https://feeds.washingtonpost.com/rss/world',                'section' => 'international', 'country' => 'US', 'tier' => 2, 'weight' => 1.00, 'homepage' => 'https://www.washingtonpost.com/world/'],

        // ---------------------------------------------------------- World desk
        ['slug' => 'bbc-world',     'name' => 'BBC News',            'feed' => 'https://feeds.bbci.co.uk/news/world/rss.xml',               'section' => 'world', 'country' => 'GB', 'tier' => 1, 'weight' => 1.05, 'homepage' => 'https://www.bbc.com/news/world'],
        ['slug' => 'bbc-top',       'name' => 'BBC News',            'feed' => 'https://feeds.bbci.co.uk/news/rss.xml',                     'section' => 'world', 'country' => 'GB', 'tier' => 1, 'weight' => 1.00, 'homepage' => 'https://www.bbc.com/news'],
        ['slug' => 'guardian-world','name' => 'The Guardian',        'feed' => 'https://www.theguardian.com/world/rss',                     'section' => 'world', 'country' => 'GB', 'tier' => 1, 'weight' => 1.00, 'homepage' => 'https://www.theguardian.com/world'],
        ['slug' => 'aljazeera',     'name' => 'Al Jazeera',          'feed' => 'https://www.aljazeera.com/xml/rss/all.xml',                 'section' => 'world', 'country' => 'QA', 'tier' => 2, 'weight' => 0.90, 'homepage' => 'https://www.aljazeera.com/'],
        ['slug' => 'dw-world',      'name' => 'Deutsche Welle',      'feed' => 'https://rss.dw.com/rdf/rss-en-world',                       'section' => 'world', 'country' => 'DE', 'tier' => 2, 'weight' => 0.90, 'homepage' => 'https://www.dw.com/en/'],
        ['slug' => 'sky-world',     'name' => 'Sky News',            'feed' => 'https://feeds.skynews.com/feeds/rss/world.xml',             'section' => 'world', 'country' => 'GB', 'tier' => 2, 'weight' => 0.90, 'homepage' => 'https://news.sky.com/world'],

        // ------------------------------------------------------- Section desks
        ['slug' => 'abc-money',     'name' => 'ABC News',            'feed' => 'https://feeds.abcnews.com/abcnews/moneyheadlines',          'section' => 'business',      'country' => 'US', 'tier' => 2, 'weight' => 0.80, 'homepage' => 'https://abcnews.go.com/Business'],
        ['slug' => 'abc-technology','name' => 'ABC News',            'feed' => 'https://feeds.abcnews.com/abcnews/technologyheadlines',     'section' => 'technology',    'country' => 'US', 'tier' => 2, 'weight' => 0.85, 'homepage' => 'https://abcnews.go.com/Technology'],
        ['slug' => 'abc-health',    'name' => 'ABC News',            'feed' => 'https://feeds.abcnews.com/abcnews/healthheadlines',         'section' => 'health',        'country' => 'US', 'tier' => 2, 'weight' => 0.85, 'homepage' => 'https://abcnews.go.com/Health'],
        ['slug' => 'abc-entertainment', 'name' => 'ABC News',        'feed' => 'https://feeds.abcnews.com/abcnews/entertainmentheadlines',  'section' => 'entertainment', 'country' => 'US', 'tier' => 3, 'weight' => 0.80, 'homepage' => 'https://abcnews.go.com/Entertainment'],
        ['slug' => 'abc-sports',    'name' => 'ABC News',            'feed' => 'https://feeds.abcnews.com/abcnews/sportsheadlines',         'section' => 'sports',        'country' => 'US', 'tier' => 3, 'weight' => 0.80, 'homepage' => 'https://abcnews.go.com/Sports'],
        ['slug' => 'espn-news',     'name' => 'ESPN',                'feed' => 'https://www.espn.com/espn/rss/news',                        'section' => 'sports',        'country' => 'US', 'tier' => 3, 'weight' => 0.85, 'homepage' => 'https://www.espn.com/'],

        // -------------------------------------------------------- Weather desk
        // Atom, and the ONLY spelling of this endpoint that returns Atom
        // without content negotiation: api.weather.gov/alerts/active answers
        // GeoJSON unless the request asks otherwise, and the '.atom' suffix
        // asks for it in the URL where a cache or a proxy cannot lose it.
        // 'severity' must be repeated, never comma-joined (the API rejects
        // that), and 'limit' is not a parameter this endpoint accepts at all.
        ['slug' => 'nws-alerts',    'name' => 'National Weather Service', 'feed' => 'https://api.weather.gov/alerts/active.atom?severity=Extreme&severity=Severe', 'section' => 'weather', 'country' => 'US', 'tier' => 1, 'weight' => 0.70, 'homepage' => 'https://www.weather.gov/'],

        // -------------------------------------------------------- Recipes desk
        ['slug' => 'budget-bytes',  'name' => 'Budget Bytes',        'feed' => 'https://www.budgetbytes.com/feed/',                         'section' => 'recipes', 'country' => 'US', 'tier' => 3, 'weight' => 0.95, 'homepage' => 'https://www.budgetbytes.com/'],
        ['slug' => 'smitten-kitchen','name' => 'Smitten Kitchen',    'feed' => 'https://smittenkitchen.com/feed/',                          'section' => 'recipes', 'country' => 'US', 'tier' => 3, 'weight' => 0.95, 'homepage' => 'https://smittenkitchen.com/'],
        ['slug' => 'love-and-lemons','name' => 'Love and Lemons',    'feed' => 'https://www.loveandlemons.com/feed/',                       'section' => 'recipes', 'country' => 'US', 'tier' => 3, 'weight' => 0.90, 'homepage' => 'https://www.loveandlemons.com/'],
    ];

    /**
     * Endpoints that are NOT feeds: JSON APIs the weather and recipe pages call
     * directly. They are kept out of all() so the ingester never tries to parse
     * JSON as XML and park a perfectly healthy endpoint after eight "failures".
     *
     * None of them needs an account, a key or a signup.
     */
    private const SERVICES = [
        'weather_forecast' => [
            'name'   => 'Open-Meteo',
            'url'    => 'https://api.open-meteo.com/v1/forecast',
            'format' => 'json',
            'note'   => 'Current conditions and the daily forecast. Free, no key, no attribution required. Takes latitude/longitude from config.weather.places.',
            'home'   => 'https://open-meteo.com/',
        ],
        'weather_alerts' => [
            'name'   => 'National Weather Service',
            'url'    => 'https://api.weather.gov/alerts/active',
            'format' => 'geojson',
            'note'   => 'Active US warnings as GeoJSON. Send a real User-Agent with contact details — the API asks for one and throttles requests without it. Filter with area=XX (the two-letter state) and repeat severity= rather than comma-joining it.',
            'home'   => 'https://www.weather.gov/',
        ],
        'recipe_random' => [
            'name'   => 'TheMealDB',
            'url'    => 'https://www.themealdb.com/api/json/v1/1/random.php',
            'format' => 'json',
            'note'   => 'One random recipe with an image. Test key "1" is public and rate-limited; used only to keep the recipes page populated when the recipe feeds are quiet.',
            'home'   => 'https://www.themealdb.com/',
        ],
    ];

    /** @var array<int,array<string,mixed>>|null */
    private static ?array $cache = null;

    /**
     * Every feed, normalised and validated.
     *
     * @return array<int,array{slug:string,name:string,feed:string,section:string,country:string,tier:int,weight:float,homepage:string}>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $out  = [];
        $seen = [];
        foreach (self::FEEDS as $f) {
            $slug = (string) ($f['slug'] ?? '');
            $url  = (string) ($f['feed'] ?? '');
            if ($slug === '' || $url === '' || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;

            $section = (string) ($f['section'] ?? 'us');
            if (!isset(self::SECTIONS[$section])) {
                $section = 'us';
            }
            $tier = (int) ($f['tier'] ?? 2);

            $out[] = [
                'slug'     => $slug,
                'name'     => (string) ($f['name'] ?? $slug),
                'feed'     => $url,
                'section'  => $section,
                'country'  => strtoupper(substr((string) ($f['country'] ?? ''), 0, 2)),
                'tier'     => isset(self::TIER_MINUTES[$tier]) ? $tier : 2,
                'weight'   => round(max(0.05, min(3.0, (float) ($f['weight'] ?? 1.0))), 2),
                'homepage' => (string) ($f['homepage'] ?? ''),
            ];
        }

        return self::$cache = $out;
    }

    /**
     * The feeds worth fetching right now, most overdue first.
     *
     * $tierState carries what the last run knew, and may be keyed by feed slug
     * or by tier number — whichever the caller has to hand. Each value is
     * either a timestamp in milliseconds, or an array holding any of
     * 'last_fetched_at' / 'fetched_at' / 'last' (ms) and 'fail_count'.
     * A slug that is not mentioned has never been fetched, so it is due.
     *
     * Failures push a feed further out, doubling per failure, and after
     * PARK_AFTER_FAILURES it is only retried every six hours — one broken feed
     * must never eat the fetch budget the working ones need.
     *
     * @param  array<string|int,mixed> $tierState
     * @return array<int,array<string,mixed>>
     */
    public static function due(int $nowMs, array $tierState): array
    {
        $due = [];

        foreach (self::all() as $feed) {
            $state = self::stateFor($feed, $tierState);
            $last  = $state['last'];
            $fails = $state['fails'];

            $minutes = self::TIER_MINUTES[$feed['tier']] ?? 20;
            if ($fails > 0) {
                $minutes = $fails >= self::PARK_AFTER_FAILURES
                    ? self::PARKED_RETRY_MINUTES
                    : (int) min(self::PARKED_RETRY_MINUTES, $minutes * (2 ** min($fails, 5)));
            }
            $intervalMs = $minutes * 60000;

            $overdueBy = $last <= 0 ? PHP_INT_MAX : ($nowMs - $last) - $intervalMs;
            if ($overdueBy < 0) {
                continue;
            }

            $feed['_overdue_ms'] = $overdueBy;
            $due[]               = $feed;
        }

        // Most overdue first, then the faster tiers, then the heavier sources.
        // Feeds that have never been fetched sort to the very front, which is
        // what makes the first run on a fresh install fill the front page.
        usort($due, static function (array $a, array $b): int {
            return [$b['_overdue_ms'], $a['tier'], $b['weight']]
                <=> [$a['_overdue_ms'], $b['tier'], $a['weight']];
        });

        foreach ($due as &$feed) {
            unset($feed['_overdue_ms']);
        }
        unset($feed);

        return $due;
    }

    /** One feed by slug, or null. */
    public static function bySlug(string $slug): ?array
    {
        foreach (self::all() as $feed) {
            if ($feed['slug'] === $slug) {
                return $feed;
            }
        }

        return null;
    }

    /**
     * Every feed filed to one desk.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function bySection(string $section): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (array $f): bool => $f['section'] === $section
        ));
    }

    /**
     * The desks, in navigation order, each with its slug folded in.
     *
     * @return array<string,array{slug:string,label:string,note:string,blurb:string,home:int|null,finance:bool}>
     */
    public static function sections(): array
    {
        $out = [];
        foreach (self::SECTIONS as $slug => $meta) {
            $out[$slug] = ['slug' => $slug] + $meta;
        }

        return $out;
    }

    /** One desk's metadata, or null when the slug is not a section. */
    public static function section(string $slug): ?array
    {
        $sections = self::sections();

        return $sections[$slug] ?? null;
    }

    /**
     * The desks that appear on the front page, in the order they appear:
     * U.S. → International → World → Weather → Recipes.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function homeSections(): array
    {
        $out = array_values(array_filter(self::sections(), static fn (array $s): bool => $s['home'] !== null));
        usort($out, static fn (array $a, array $b): int => $a['home'] <=> $b['home']);

        return $out;
    }

    /**
     * Desks the front-page money quota applies to (SPEC §0.5). Kept here so
     * Compose, Render and the tests cannot drift apart on what "finance" means.
     *
     * @return array<int,string>
     */
    public static function financeSections(): array
    {
        return array_values(array_keys(array_filter(
            self::SECTIONS,
            static fn (array $s): bool => !empty($s['finance'])
        )));
    }

    /**
     * The JSON endpoints that are not feeds — weather and recipes.
     *
     * @return array<string,array{name:string,url:string,format:string,note:string,home:string}>
     */
    public static function services(): array
    {
        return self::SERVICES;
    }

    /** One service by key, or null. */
    public static function service(string $key): ?array
    {
        return self::SERVICES[$key] ?? null;
    }

    /** Minutes between fetches for a tier. */
    public static function tierMinutes(int $tier): int
    {
        return self::TIER_MINUTES[$tier] ?? 20;
    }

    /**
     * Pull one feed's last-run state out of whatever shape the caller passed.
     *
     * @param  array<string,mixed>     $feed
     * @param  array<string|int,mixed> $state
     * @return array{last:int,fails:int}
     */
    private static function stateFor(array $feed, array $state): array
    {
        $raw = null;
        foreach ([$feed['slug'], 'tier' . $feed['tier'], $feed['tier'], (string) $feed['tier']] as $key) {
            if (array_key_exists($key, $state)) {
                $raw = $state[$key];
                break;
            }
        }

        if ($raw === null) {
            return ['last' => 0, 'fails' => 0];
        }
        if (is_int($raw) || is_float($raw) || (is_string($raw) && ctype_digit($raw))) {
            return ['last' => (int) $raw, 'fails' => 0];
        }
        if (!is_array($raw)) {
            return ['last' => 0, 'fails' => 0];
        }

        $last = 0;
        // 'last_fetch_at' is the actual column name on the sources table. It
        // was missing here, so a state array handed straight from the database
        // read 0 for every feed, every feed looked overdue, and the tier
        // cadence and the parked-feed backoff below did nothing at all.
        foreach (['last_fetched_at', 'fetched_at', 'last_fetch_ms', 'last_fetch_at', 'last', 'at'] as $k) {
            if (isset($raw[$k]) && (is_int($raw[$k]) || is_float($raw[$k]) || (is_string($raw[$k]) && ctype_digit($raw[$k])))) {
                $last = (int) $raw[$k];
                break;
            }
        }
        $fails = 0;
        foreach (['fail_count', 'failures', 'fails'] as $k) {
            if (isset($raw[$k]) && is_numeric($raw[$k])) {
                $fails = max(0, (int) $raw[$k]);
                break;
            }
        }

        return ['last' => $last, 'fails' => $fails];
    }
}
