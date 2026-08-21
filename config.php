<?php

declare(strict_types=1);

/**
 * Read a database out of the environment, if the host put one there.
 *
 * Returns null when there is nothing to find, which is the normal case on
 * shared hosting — the settings in the returned array below are then used.
 *
 * Guarded because this file is a `require`d expression, not an include-once:
 * anything that loads the config twice would otherwise die on a redeclare.
 */
if (!function_exists('teb_db_from_env')) {
    function teb_db_from_env(): ?array
    {
        // JawsDB is the usual MySQL add-on on Heroku; ClearDB is the older one.
        // DATABASE_URL is the generic convention used by most other platforms.
        foreach (['JAWSDB_URL', 'JAWSDB_MARIA_URL', 'CLEARDB_DATABASE_URL', 'DATABASE_URL', 'MYSQL_URL'] as $key) {
            $url = getenv($key);
            if (!is_string($url) || trim($url) === '') {
                continue;
            }
            $u = parse_url(trim($url));
            if (!is_array($u) || !isset($u['host'])) {
                continue;
            }

            $scheme = strtolower((string) ($u['scheme'] ?? 'mysql'));
            // Postgres is not supported by this build; say so rather than failing
            // later with an unreadable PDO error.
            if (str_starts_with($scheme, 'postgres')) {
                error_log('[teb] ' . $key . ' is a PostgreSQL database; this build supports MySQL or SQLite. Ignoring it.');
                continue;
            }

            return [
                'driver'  => 'mysql',
                'host'    => (string) $u['host'],
                'port'    => (int) ($u['port'] ?? 3306),
                'name'    => ltrim((string) ($u['path'] ?? ''), '/'),
                'user'    => isset($u['user']) ? rawurldecode((string) $u['user']) : '',
                'pass'    => isset($u['pass']) ? rawurldecode((string) $u['pass']) : '',
                'charset' => 'utf8mb4',
            ];
        }

        // Discrete variables, the other common convention.
        $host = getenv('MYSQL_HOST') ?: getenv('DB_HOST');
        $name = getenv('MYSQL_DATABASE') ?: getenv('DB_NAME');
        if (is_string($host) && $host !== '' && is_string($name) && $name !== '') {
            return [
                'driver'  => 'mysql',
                'host'    => $host,
                'port'    => (int) (getenv('MYSQL_PORT') ?: getenv('DB_PORT') ?: 3306),
                'name'    => $name,
                'user'    => (string) (getenv('MYSQL_USER') ?: getenv('DB_USER') ?: ''),
                'pass'    => (string) (getenv('MYSQL_PASSWORD') ?: getenv('DB_PASS') ?: ''),
                'charset' => 'utf8mb4',
            ];
        }

        return null;
    }
}

/**
 * ============================================================================
 *  THE ONLY FILE YOU EVER NEED TO EDIT.
 * ============================================================================
 *
 *  Everything the site shows — its name, its domain, its clock, its database,
 *  its ad slots, its weather cities — is set here. Nothing is hardcoded
 *  anywhere else: rename the site in this one file and the whole thing,
 *  including the page titles, the RSS feed, the sitemap and the copyright
 *  line, renames itself.
 *
 *  HOW TO USE IT
 *  -------------
 *  1. Upload the ZIP and unzip it. The site works immediately — you do not
 *     have to touch anything to see it running.
 *  2. Come back here and change 'name', 'domain' and 'tagline' to yours.
 *  3. That's it. Everything below has a working default.
 *
 *  RULES OF THE FILE
 *  -----------------
 *  · It is PHP, so every line ends with a comma and text goes inside 'quotes'.
 *  · If you break the syntax the site shows a blank page — undo your last edit.
 *  · true / false are typed WITHOUT quotes.
 *  · Numbers are typed WITHOUT quotes.
 *  · Keep a copy before you edit. Upgrades never overwrite this file.
 * ============================================================================
 */

return [

    /* ------------------------------------------------------------------ site
     | Identity. This is the only place the brand exists.
     */
    'site' => [

        // Shown in the masthead, the browser tab, the RSS feed and the footer.
        'name' => 'The Evening Brief',

        // A 2–4 letter version for tight spaces (mobile tab title, app icon).
        'short_name' => 'TEB',

        // Your domain, WITHOUT https:// and WITHOUT a trailing slash.
        //
        // The site does NOT use this to build links — it reads the real
        // address out of the browser request, so the same ZIP works on your
        // live domain, on a staging subdomain, on a raw IP, and inside a
        // subfolder, with no edit. This value is only used when there is no
        // browser to ask: the cron job, the sitemap built from the command
        // line, and anywhere the domain is printed as text.
        'domain' => 'theeveningbrief.com',

        // Sits under the masthead. One short line.
        'tagline' => 'The day, gathered and set in order.',

        // Used for the homepage <meta name="description"> and the RSS feed.
        // Aim for 140–160 characters.
        'description' => 'An evening edition of the day\'s news: national, '
            . 'international and world headlines, the forecast, and something '
            . 'to cook — gathered from the newsrooms that reported them.',

        // The clock, the edition date and every timestamp use this zone.
        // Full list: https://www.php.net/manual/en/timezones.php
        // Examples: America/New_York, America/Chicago, America/Denver,
        //           America/Los_Angeles, Europe/London, Asia/Kolkata
        'timezone' => 'America/New_York',

        // Language of the site, used in <html lang> and in the feeds.
        'locale' => 'en_US',

        // The colour mobile browsers paint their toolbar with. Matches the
        // paper colour of the design.
        'theme_color' => '#FBFAF7',
    ],

    /* -------------------------------------------------------------------- db
     | Where the stories are stored.
     |
     | LEAVE THIS ALONE unless you have a reason. The default 'sqlite' needs
     | no database, no username, no password and no cPanel setup: the site
     | creates a single file under data/ on the first page view.
     |
     | Switch to MySQL only if you expect heavy traffic or your host has a
     | slow disk. Create the database and user in cPanel first, then set
     | 'driver' => 'mysql' and fill in the four lines below it.
     */
    // If the host hands us a database in the environment, USE IT and ignore the
    // settings below. Heroku, Railway, Render and friends all work this way, and
    // their local disk is wiped on every restart — so a file-based SQLite
    // database there would lose every story each time the dyno cycles. Adding a
    // MySQL add-on sets one of these variables and this picks it up with no edit.
    // On normal cPanel hosting none of these exist and the values below are used.
    'db' => teb_db_from_env() ?? [

        'driver' => 'sqlite',            // 'sqlite' (no setup) or 'mysql'

        // SQLite only. Relative to this folder. Must stay inside data/,
        // which is blocked from the web by data/.htaccess.
        'sqlite_path' => 'data/news.sqlite',

        // MySQL only — ignored entirely while driver is 'sqlite'.
        'host'    => 'localhost',        // cPanel is nearly always 'localhost'
        'port'    => 3306,
        'name'    => '',                 // e.g. 'cpaneluser_news'
        'user'    => '',                 // e.g. 'cpaneluser_news'
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    /* ---------------------------------------------------------------- ingest
     | Fetching the news.
     |
     | Best practice is a cPanel cron job every 10 minutes:
     |     /usr/local/bin/php /home/USER/public_html/cron/ingest.php
     |
     | You do not have to set that up to see the site work — with
     | 'auto_on_empty' on, the first visitor triggers a fetch.
     */
    'ingest' => [

        // Master switch. false = stop fetching entirely (the site keeps
        // serving whatever it already has).
        'enabled' => true,

        // Fetch inline when the database is empty or the newest story is
        // older than 'stale_after_minutes'. This is what makes the very
        // first page view show real news with no cron job configured.
        'auto_on_empty' => true,

        // How old the newest story may get before a page view triggers a
        // fetch of its own. Raise it if your host is slow.
        'stale_after_minutes' => 20,

        // Optional. Set a long random word here and you can trigger a fetch
        // from a browser or an uptime monitor:
        //     https://yourdomain.com/index.php?__ingest=YOURWORD
        // Empty means that URL is switched off, which is the safe default.
        // A scheduler calls /admin/ingest?token=... on a timetable. Set it here,
        // or leave it blank and set an INGEST_TOKEN environment variable — which
        // is how platform hosts do it. Blank in both places CLOSES the route.
        'token' => (string) (getenv('INGEST_TOKEN') ?: ''),

        // Seconds to wait on any one publisher before giving up on it.
        // A slow feed must never hold up the others.
        // Seconds allowed per run for measuring new images. Measuring is what
        // stops a 60x60 thumbnail being blown up into a lead photo. 0 disables
        // it, and then unmeasured images are only ever used in small cards.
        'image_measure_seconds' => 20,

        'timeout_seconds' => 12,

        // How many feeds to fetch in a single run. The registry holds ~35;
        // they are rotated by priority, so every feed still gets its turn.
        'batch' => 14,

        // Delete stories older than this many days. 30 days of archive keeps
        // the database small and the site fast on shared hosting.
        'retention_days' => 2,
    ],

    /* --------------------------------------------------------------- compose
     | How the front page is built.
     |
     | ⚠ The finance limits are a business decision, not decoration. This is
     | a general-news site bought with general-news advertising; a front page
     | that opens with markets and crypto reads as a finance site and turns
     | that audience away. Money stories still get their own section page and
     | a quiet strip at the bottom of the front page — they just never lead.
     */
    'compose' => [

        // Most business/markets/crypto stories allowed anywhere on the front
        // page. 0 removes them from it completely.
        'finance_max_on_home' => 2,

        // Areas of the front page money stories may never appear in, no
        // matter how big the news. 'hero' is the top of the page.
        'finance_blocked_blocks' => ['hero', 'us', 'international'],

        // How many secondary stories sit beside the lead story up top.
        'hero_sub_count' => 4,

        // Most stories one publisher may supply to a single block, so the
        // page never turns into one newsroom's front page.
        'per_source_cap_per_block' => 2,

        // Headlines in the scrolling ticker across the top.
        'ticker_count' => 12,
    ],

    /* ------------------------------------------------------------------- ads
     | Advertising slots.
     |
     | Every slot is drawn at its exact size from the moment the page loads,
     | whether ads are on or off, so switching them on never makes the page
     | jump around. Nothing is requested from any ad network until you turn
     | this on AND paste a real ad tag in.
     */
    'ads' => [

        'enabled' => false,              // true when your ad code is ready

        // name => [width, height] in pixels.
        'slots' => [
            'leaderboard' => [970, 250], // wide banner under the masthead
            'rail'        => [300, 600],  // tall unit beside the stories
            'inline'      => [728, 90],   // between two blocks of stories
        ],
    ],

    /* --------------------------------------------------------------- weather
     | The forecast strip and the /weather page.
     |
     | No account and no API key: the forecast comes from Open-Meteo and the
     | warnings from the US National Weather Service, both free and public.
     |
     | 'region' is the two-letter US state, used to look up official weather
     | warnings. Outside the US leave 'region' as an empty string — the
     | forecast still works, the warnings simply do not apply.
     */
    'weather' => [

        'default_place' => 'new-york',   // must be one of the keys below

        'places' => [
            'new-york'    => ['name' => 'New York',      'region' => 'NY', 'lat' => 40.7128,  'lon' => -74.0060, 'timezone' => 'America/New_York'],
            'washington'  => ['name' => 'Washington',    'region' => 'DC', 'lat' => 38.9072,  'lon' => -77.0369, 'timezone' => 'America/New_York'],
            'chicago'     => ['name' => 'Chicago',       'region' => 'IL', 'lat' => 41.8781,  'lon' => -87.6298, 'timezone' => 'America/Chicago'],
            'houston'     => ['name' => 'Houston',       'region' => 'TX', 'lat' => 29.7604,  'lon' => -95.3698, 'timezone' => 'America/Chicago'],
            'denver'      => ['name' => 'Denver',        'region' => 'CO', 'lat' => 39.7392,  'lon' => -104.9903, 'timezone' => 'America/Denver'],
            'los-angeles' => ['name' => 'Los Angeles',   'region' => 'CA', 'lat' => 34.0522,  'lon' => -118.2437, 'timezone' => 'America/Los_Angeles'],
            'seattle'     => ['name' => 'Seattle',       'region' => 'WA', 'lat' => 47.6062,  'lon' => -122.3321, 'timezone' => 'America/Los_Angeles'],
            'miami'       => ['name' => 'Miami',         'region' => 'FL', 'lat' => 25.7617,  'lon' => -80.1918, 'timezone' => 'America/New_York'],
            'atlanta'     => ['name' => 'Atlanta',       'region' => 'GA', 'lat' => 33.7490,  'lon' => -84.3880, 'timezone' => 'America/New_York'],
            'phoenix'     => ['name' => 'Phoenix',       'region' => 'AZ', 'lat' => 33.4484,  'lon' => -112.0740, 'timezone' => 'America/Phoenix'],
        ],
    ],

    /* ----------------------------------------------------------------- cache
     | How long a built page may be reused before it is built again, in
     | seconds. Higher numbers = a faster, cheaper site that updates a little
     | less often. 0 switches caching off for that page type.
     */
    'cache' => [
        'home_seconds'    => 120,        // 2 minutes — the front page moves
        'section_seconds' => 300,        // 5 minutes
        'article_seconds' => 900,        // 15 minutes — these barely change
    ],
];
