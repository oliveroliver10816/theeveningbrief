export const meta = {
  name: 'evening-brief-php',
  description: 'Build The Evening Brief as a PHP 8 + PDO news aggregator, deliverable as an uploadable ZIP',
  phases: [
    { title: 'Modules', detail: 'config/paths, xml/ingest, db, compose, render, weather/recipes, seo/health' },
    { title: 'Harden', detail: 'adversarial verification of each module' },
    { title: 'Integrate', detail: 'front controller, live smoke from web root AND subdirectory' },
  ],
}

const ROOT = '/root/workspace/theeveningbrief'

const PRELUDE = `You are building a production news website at ${ROOT}.
It ships as a ZIP the client uploads to Apache/cPanel hosting and it must WORK ON FIRST UPLOAD.

READ THESE FILES IN FULL BEFORE WRITING ANYTHING — they are binding:
  ${ROOT}/docs/SPEC.md          non-negotiables, routes, gates
  ${ROOT}/docs/CONTRACT.md      exact PHP signatures + file ownership
  ${ROOT}/docs/RECON.md         the reference-site teardown + the HTTP-200-verified feed roster
  ${ROOT}/docs/design/FINAL.md  the chosen design system and its exact class names
  ${ROOT}/src/design.css        the production stylesheet (copy to assets/css/site.css, do not rewrite it)

RUNTIME: PHP 8.0+ (target shared cPanel hosting). PHP 8.1.2 CLI is installed on THIS box with
pdo_sqlite, pdo_mysql, curl, mbstring, SimpleXML, dom and zip — so you can and MUST actually run
your code and your tests. \`declare(strict_types=1);\` at the top of every PHP file, namespace TEB\\.
NO Composer, NO npm, NO build step, NO external dependency of any kind.

THE FOUR RULES THAT GET THIS REJECTED IF BROKEN:
1. NO hardcoded brand name or domain anywhere except config.php. Read it from config.
2. NO absolute URL paths. The site must run identically at a web root AND inside a
   subdirectory such as /teb/ with zero edits. Every emitted URL goes through TEB\\Paths.
3. NO redirects in .htaccess. That file is ALREADY WRITTEN and is CORRECT — do not edit it,
   do not add to it, do not create another one outside data/. Read it to understand the rules.
4. Every image except the one hero is loading="lazy" decoding="async" with explicit width and
   height attributes, referrerpolicy="no-referrer" and an onerror text fallback.

Write complete working code. No TODOs, no stubs, no lorem, no "in a real implementation".
Only create/edit files you own per the CONTRACT ownership table.
Add your own test file at ${ROOT}/tests/test_<module>.php using the harness described in
CONTRACT.md, and RUN \`php ${ROOT}/tests/run.php\` before you report done.`

const MODULES = [
  {
    key: 'config-paths-feeds',
    own: 'config.php, app/Config.php, app/Paths.php, app/Feeds.php, tests/lib.php, tests/run.php',
    prompt: `You own \`config.php\`, \`app/Config.php\`, \`app/Paths.php\`, \`app/Feeds.php\`, and you also
build the shared TEST HARNESS at \`tests/lib.php\` + \`tests/run.php\` that every other agent will use —
build that FIRST and make it solid, because six other agents depend on it within minutes.

tests/run.php: discovers tests/test_*.php (each returns ['name' => callable]), runs them, prints one
line per test, prints a final \`PASS n / FAIL n\` summary, exits non-zero if any failed. tests/lib.php
provides assertTrue, assertFalse, assertSame, assertEquals, assertContains, assertNotContains,
assertNull, assertCount, assertThrows. An assertion failure must throw with a useful message showing
expected vs actual.

config.php: returns the array in CONTRACT.md. Defaults — name "The Evening Brief", short "TEB",
domain "theeveningbrief.com", timezone "America/New_York", driver "sqlite", ads disabled,
finance_max_on_home 2, finance_blocked_blocks [hero,us,international], hero_sub_count 4,
per_source_cap_per_block 2, ticker_count 12, retention_days 30, auto_on_empty true,
stale_after_minutes 20. Comment it generously — this is the ONLY file the client edits.

app/Paths.php is the highest-risk module in the project. It makes the site subdirectory-safe:
- base() derives the app base path from \$_SERVER['SCRIPT_NAME'] (NOT REQUEST_URI), so it is right
  at a web root ('') and in '/teb' and in '/a/b/c'. Never a trailing slash.
- currentRoute() reconstructs the route whether the request came through the mod_rewrite front
  controller or through the ?r= fallback, and it must strip the base path correctly in both cases.
- hasRewrite() decides whether to emit pretty URLs or ?r= URLs. Probe result cached in data/.
  It must NEVER throw and must default to the SAFE option (?r=, which works everywhere) if it
  cannot tell. A host that ignores .htaccess must not produce a site of 404s.
- absolute() builds scheme+host+base+path with the host taken from \$_SERVER (HTTP_HOST, validated
  against header injection) so a test upload on ANY hostname still emits correct canonical/OG/
  sitemap URLs. config.site.domain is only a CLI fallback.
- asset() appends a filemtime cache-buster.

app/Feeds.php: the registry, built from the verified roster in docs/RECON.md — use those exact URLs.
30-40 entries, sensible section/country/tier/weight, recipes and weather MUST be represented.

tests/test_config.php must actually prove subdirectory safety: table-drive \$_SERVER fixtures for
web root, '/teb', '/a/b', with and without rewrite, and assert the exact URL strings produced.
Also assert HTTP_HOST injection ("evil.com\\r\\nX:") is rejected. Also grep every other PHP file
under app/ and assert none contains the brand name or the domain literal.`,
  },
  {
    key: 'db',
    own: 'app/Db.php',
    prompt: `You own \`app/Db.php\` and \`tests/test_db.php\`.

Schema per SPEC.md §3, created by an IDEMPOTENT migrate() that produces the same logical schema on
BOTH sqlite and mysql (branch on driver for the type names and AUTOINCREMENT/AUTO_INCREMENT, keep
one source of truth for the column list). Times are INTEGER ms epoch. Indexes that this workload
actually needs: unique guid_hash, (section, published_at DESC), published_at DESC, title_key,
source_id.

SQLite specifics that matter on shared hosting: create data/ if missing, set WAL, set a busy_timeout
of several seconds, and use a transaction for batch inserts — without these, two concurrent requests
during ingest produce "database is locked".

Every statement is prepared with bound parameters INCLUDING search — escape LIKE wildcards in the
bound value, never interpolate. insertArticles dedups hard on guid_hash and soft on title_key within
a recent window, and reports honest counts.

canonicalUrl strips utm_*, fbclid, gclid, mc_cid, ref and a trailing '?' or '#'.
titleKey lowercases, strips punctuation, drops a small English stopword list, joins the first 8 tokens.

tests/test_db.php runs against a REAL temporary SQLite file (and skips-with-a-message for mysql if no
server): assert migrate is idempotent (run it twice), an exact duplicate URL is skipped, the same
story from two sources with different punctuation is soft-deduped, each tracking param is stripped,
a search containing a quote, a percent and an underscore returns sensibly and cannot inject,
pruneOld deletes only what it should, and a 500-row batch insert completes in one transaction.`,
  },
  {
    key: 'xml-ingest',
    own: 'app/Xml.php, app/Ingest.php, cron/ingest.php',
    prompt: `You own \`app/Xml.php\`, \`app/Ingest.php\`, \`cron/ingest.php\` and \`tests/test_xml.php\`.

app/Xml.php parses RSS 2.0, Atom and RDF/RSS 1.0 with SimpleXML plus proper namespace handling via
children(\$ns) for media:, content:, dc:, itunes:. Per item extract guid, url (Atom link rel=alternate
href), title, summary (description | summary | content:encoded, HTML stripped and entity-decoded),
image_url (media:content url | media:thumbnail url | enclosure of an image type | first <img src> in
content:encoded or description), published_at as MS EPOCH or null (never 0, never false — parse
pubDate, published, updated, dc:date), author.
Guard against XXE: libxml_set_external_entity_loader disabled / LIBXML_NONET on every load.
A malformed feed returns an empty item list, never a PHP warning and never an exception.
trimSummary cuts on a word boundary, never leaves a half-written HTML entity or dangling bracket,
and appends an ellipsis only when it actually cut.

app/Ingest.php: fetch with cURL (real User-Agent identifying the site + a contact URL, per-feed
timeout from config, follow redirects, gzip). Bounded batch per run. One bad feed NEVER fails the
run — record the error, increment fail_count, park the feed after 8 consecutive failures and log
that it was parked. Always write an ingest_runs row with honest counts. lock() uses flock on a file
in data/ and returns null if already held, so two concurrent hits cannot double-run.

cron/ingest.php runs from cPanel cron AND from the CLI. It must work when called as
\`php /home/user/public_html/cron/ingest.php\` with no web server present — so no reliance on
\$_SERVER, and it prints a human-readable summary and exits non-zero on total failure.

tests/test_xml.php: fetch REAL feeds yourself with curl from the URLs in docs/RECON.md — at least
five structurally different ones (an RSS 2.0 with media:content, an Atom feed, an RDF feed, one where
the image is only inside content:encoded, one with entities and non-ASCII) — trim them and save under
tests/fixtures/. Assert on real extracted values: exact titles, that image_url is found in each shape,
that published_at is a plausible ms epoch, that a date-less item yields null. Add a malformed-XML case
and a billion-laughs/XXE case and assert they are handled safely. A test that only asserts "did not
throw" does not count.`,
  },
  {
    key: 'compose',
    own: 'app/Compose.php',
    prompt: `You own \`app/Compose.php\` and \`tests/test_compose.php\`. This module is where the client's
commercial requirement is enforced, so it matters more than its size suggests.

TEB\\Compose::home(array \$rows, array \$cfg, int \$nowMs): array — PURE and DETERMINISTIC. No time(),
no rand(), no I/O, no static mutable state.

Scoring: recency decay (half-life a few hours) × source weight × section priority (us highest, then
international, then world) + image bonus − penalty for a source already used in the same block.

Constraints, all of which need a test that can actually fail:
- FINANCE QUOTA. Sections business/finance/markets/crypto are banned outright from the hero and from
  every block named in cfg.compose.finance_blocked_blocks, and capped at cfg.compose.finance_max_on_home
  across the entire front page, surfacing only in the low 'markets' strip. The client is buying ads to
  build a general-news audience; a markets-heavy front page fights that.
- Per-source cap per block; no source leads twice.
- No article id appears twice anywhere in the model, ticker included.
- Block order: us, international, world, weather, recipes — then markets last.
- Degrades: 3 articles total; zero recipes; every article from one source; every article finance;
  zero articles. Each must return a valid model and never throw.

tests/test_compose.php: build fixture row sets and assert a finance-heavy input STILL yields a
finance-free hero and first two blocks; the total finance count respects the cap; per-source caps hold;
no duplicate ids; exact block order; determinism (run twice, assert deep equality); and every degenerate
case above. Also assert that raising finance_max_on_home in config actually changes the output — a
config value nothing reads is a bug we have shipped before.`,
  },
  {
    key: 'render',
    own: 'app/Render.php, assets/js/app.js, assets/css/site.css',
    prompt: `You own \`app/Render.php\`, \`assets/js/app.js\`, and you COPY \`${ROOT}/src/design.css\` to
\`assets/css/site.css\` (copy it; extend it only if a component you must render genuinely has no class —
never restyle what the designer chose).

Read docs/design/FINAL.md and match its class names EXACTLY. If the markup and the stylesheet disagree
the page renders unstyled, so verify class-by-class rather than assuming.

Signatures per CONTRACT.md. esc() escapes & < > " ' and everything interpolated goes through it unless
it is markup you generated.

IMAGE RULES — the client named these as the priority, get them exactly right:
- The single hero image: loading="eager" fetchpriority="high" decoding="async".
- EVERY other image: loading="lazy" decoding="async".
- Every image: explicit width and height attributes (stored dimensions if known, else the card's
  nominal box — design.css sets height:auto so they scale rather than squash), alt = the headline,
  referrerpolicy="no-referrer", and an onerror that hides the img, reveals the text-only sibling and
  swaps the card to its no-photo class. That inline JS lives inside an HTML attribute — escape it right.
- A card with no image must render the text-only variant with NO <img> element at all.

layout(): correct doctype/lang, viewport, theme-color from cfg, canonical + OG + Twitter via
TEB\\Paths::absolute, optional JSON-LD, preconnect for fonts, the stylesheet linked (not inlined),
app.js DEFERRED. No render-blocking script. Include the tiny inline pre-paint theme snippet so the
dark-mode toggle does not flash.

adSlot(): renders a height-reserved container from cfg.ads.slots so enabling ads later causes ZERO
layout shift; renders the reserved box and nothing else while ads are disabled.

assets/js/app.js — the only client JS, under 8 KB, no dependencies, safe with defer, site fully usable
without it: live clock in the masthead timezone read from a data attribute; headline ticker that pauses
on hover and focus-within, does not animate at all under prefers-reduced-motion, and pauses when the tab
is hidden; relative timestamps over <time datetime> refreshed each minute (server renders the absolute
time so it is correct without JS); light/dark/system theme toggle persisted in localStorage. Nothing else.

tests/test_render.php: assert esc escapes each dangerous character in BOTH text and attribute position;
render a full home page from a fixture model and assert exactly ONE eager image and that every other
img has loading="lazy"; assert every img has width, height, alt and referrerpolicy; assert a null-image
card emits no <img>; assert a headline containing <script>, quotes and an ampersand cannot break out of
either context; assert the brand name appears only via cfg; assert every href goes through Paths.`,
  },
  {
    key: 'weather-recipes',
    own: 'app/Weather.php, app/Recipes.php',
    prompt: `You own \`app/Weather.php\`, \`app/Recipes.php\` and \`tests/test_weather_recipes.php\`.

WEATHER — two keyless sources, both verified working from this box:
- https://api.open-meteo.com/v1/forecast — current conditions + 5-day daily. Pass the timezone from
  config. Request Fahrenheit, and carry Celsius too.
- https://api.weather.gov/alerts/active — US alerts. It REQUIRES a real User-Agent with a contact or
  it rejects you, AND it will NOT accept a comma-joined severity list: repeat the severity key instead.
  A comma list returns HTTP 400 — this was verified, do not "fix" it back.
Map WMO weather codes to a human label and an inline SVG icon, covering all documented codes with a
neutral fallback (never the string "undefined"). Cache responses in data/ for 15 minutes keyed by
rounded lat/lon. If either upstream fails, return what you have with a degraded flag — the weather
strip must NEVER take the page down. Default place from config; ?place= over a built-in table of ~25
US metros with coordinates. No geocoding API, no browser location prompt.

RECIPES — this is a real differentiator: the reference site advertises a recipes section that does not
exist (section.php?slug=recipes returns 200 but silently serves the Nation feed). Ours must be real.
recipeItems() reads recipe-section articles already ingested into the DB. enrichRecipe() pulls total
time, yield and an ingredient count out of the feed content WHEN PRESENT — these are WordPress feeds
and the shape varies per publisher, so parse defensively and OMIT a field that is absent. Never invent
a cook time. pageModel() groups into a lead plus a grid. mealOfTheDay() calls
https://www.themealdb.com/api/json/v1/1/random.php (keyless, verified) with a 10-minute cache and
returns null on any failure rather than throwing.
⚠ Same rule as everywhere: headline + feed summary + link to the publisher. We do NOT reproduce the
method or the full ingredient list.

tests: record REAL JSON/XML fixtures with curl (open-meteo, weather.gov, budgetbytes, smittenkitchen,
loveandlemons, themealdb) under tests/fixtures/ and assert against them. Prove: WMO mapping is total
over the documented set; an upstream 500 yields degraded, not an exception; the alerts URL is built
with REPEATED severity params, not a comma list; time/yield are extracted when present and the fields
are simply absent when not; themealdb failure returns null.`,
  },
  {
    key: 'seo-health',
    own: 'app/Seo.php, app/Health.php',
    prompt: `You own \`app/Seo.php\`, \`app/Health.php\`, \`tests/test_seo.php\` and \`tests/test_htaccess.php\`.

⚠ You do NOT own \`.htaccess\` — it is already written and correct. Read it, understand the rules in
its header comment, and write the TEST that guards it. Do not edit it.

tests/test_htaccess.php is a client-mandated regression guard. Strip comment lines first, then assert
against the DIRECTIVES ONLY: no \`Redirect\`/\`RedirectMatch\`/\`RedirectPermanent\` directive; no
RewriteRule carrying an R flag in any form ([R], [R=301], [R=302], or R combined with other flags like
[R=301,L]); no \`RewriteBase\`; no http:// or https:// URL; no occurrence of the production domain; and
no ErrorDocument pointing at a leading-slash path (that breaks in a subdirectory). Also assert the
front-controller rule IS present, and that data/.htaccess denies access. Write the assertions so they
would genuinely catch the old broken file — paste the old pattern
\`RewriteCond %{HTTPS} !=on\` + \`RewriteRule ^(.*)$ https://example.org/$1 [R=301,L]\` into a fixture
string and assert your checker flags it. A guard that does not catch the bug it was written for is worthless.

app/Seo.php — the reference site has NO robots.txt and NO sitemap.xml (both 404); closing that is our edge.
- robotsTxt(): allow crawling, disallow the search and admin routes, point at the sitemap via Paths::absolute.
- sitemap(): front page + every section + every article in the retention window, absolute URLs, lastmod,
  paginated at 5,000 URLs with an index when it overflows.
- newsSitemap(): last 48 hours only, correct news: namespace, publication name from config.
- rss(): valid RSS 2.0 carrying OUR summary and a link to OUR article page (not the publisher's),
  CDATA-wrapped titles and descriptions.
- articleJsonLd(): schema.org NewsArticle. Because we publish a summary and link out, set isBasedOn to
  the publisher URL and name the publisher as the source rather than claiming authorship. Emit no field
  we cannot substantiate — no fake author, no fake wordCount, no fake image dimensions.
- breadcrumbJsonLd, websiteJsonLd with a SearchAction.

app/Health.php: report() returns last ingest run, article and source counts, per-section counts, the
list of failing/parked feeds with their last error, DB driver and size, and PHP version + missing
extensions. This is what tells us the site has gone quiet before the client notices.

tests/test_seo.php: parse every generated XML back with a real parser and assert well-formedness when a
title contains an ampersand, an angle bracket, a quote and a non-ASCII character; assert no URL is
relative; assert no hardcoded brand literal; assert the news sitemap excludes anything older than 48h;
assert the JSON-LD is valid JSON and contains isBasedOn.`,
  },
]

phase('Modules')
const built = await pipeline(
  MODULES,
  (m) => agent(`${PRELUDE}\n\n${m.prompt}`, { label: `build:${m.key}`, phase: 'Modules' }),
  (_r, m) => agent(
    `${PRELUDE}

Adversarially verify the "${m.key}" module (${m.own}). Read the real files on disk. Your job is to
REFUTE that it works, not to praise it.

1. Run \`cd ${ROOT} && php tests/run.php\` and report the true output verbatim.
2. Check every signature against docs/CONTRACT.md character by character — drift here breaks integration.
3. Find tests that CANNOT FAIL: asserting nothing, try/catch swallowing, asserting on a value the code
   just computed, fixtures reverse-engineered from the implementation's quirks. Fix them so they can fail,
   then confirm they still pass.
4. Hunt for real defects: a hardcoded brand name or domain; an absolute URL path that breaks in a
   subdirectory; SQL built by interpolation; unescaped output; an image missing lazy/width/height;
   an unhandled null or a PHP 8 deprecation; a function claiming purity that reads the clock; silent
   truncation; an upstream failure that would 500 the whole page; XXE or billion-laughs exposure.
5. Fix everything you find, then re-run until green.

Report what you found, what you fixed, and the final PASS/FAIL summary line verbatim. If nothing real
was wrong, say so plainly rather than inventing a finding.`,
    { label: `harden:${m.key}`, phase: 'Harden', effort: 'high' }
  )
)

phase('Integrate')
const integrated = await agent(
  `${PRELUDE}

Every module now exists. You own \`index.php\`, \`app/bootstrap.php\`, \`app/Router.php\`,
\`install.php\`, \`README.txt\`. At this integration step you may edit ANY file to make the whole
thing work, but keep the contracts intact and DO NOT touch \`.htaccess\`.

index.php is the only entry point: bootstrap -> route -> echo. Routes per SPEC.md §6, each working
BOTH as a pretty URL and as ?r=/path so the site survives a host that ignores .htaccess.
- On first request, if the database is empty (or staler than config ingest.stale_after_minutes),
  run ingestion inline behind the lock so the very first page view shows real news. Bound it so the
  first request is not absurdly slow — ingest the tier-1 feeds inline and let cron catch the rest.
- /article/{slug}-{id}: a wrong slug with a right id issues a canonical 301 at the APPLICATION level
  (this is the one intentional redirect in the project and it must never migrate into .htaccess).
- /healthz returns JSON, never cached.
- Any unhandled exception renders a styled error page, never a stack trace, and NEVER a 500 on the
  home page — an empty database must render a valid page that says news is on its way.
- Send sensible Cache-Control per SPEC, plus ETag with 304 handling on HTML.

install.php: a single self-check page the client opens after uploading — PHP version, each required
extension, data/ writability, database connectivity, whether mod_rewrite was detected, feed
reachability (probe three feeds), last ingest time, and a "Run ingest now" button. Green ticks and red
crosses, plain English fixes for each failure. It must be safe to leave on the server (no secrets, no
destructive action without the config token) and it must tell the client to delete it when done.

README.txt: plain text, for Bob and a VA. What to upload where, that it runs at a web root or in a
subfolder, that SQLite needs no setup, how to switch to MySQL, the exact cPanel cron line, and an
explicit note that .htaccess deliberately contains no redirects and canonical-domain rules belong at
the host level after the site is confirmed working.

THEN ACTUALLY PROVE IT RUNS — this is the part that counts:
  a) \`php -l\` every PHP file.
  b) \`php tests/run.php\` — everything green. Fix whatever is broken anywhere.
  c) Serve from a WEB ROOT: \`php -S 127.0.0.1:8801 -t ${ROOT}\` (note: php -S has no mod_rewrite,
     so this also proves the ?r= fallback). Curl EVERY route and record real status codes.
  d) Serve from a SUBDIRECTORY: copy the tree to /tmp/tebsub/teb/ and
     \`php -S 127.0.0.1:8802 -t /tmp/tebsub\`, then curl /teb/ and /teb/index.php?r=/section/us etc.
     and prove the pages render with working links and assets — this is the subdirectory guarantee.
  e) Trigger a real ingest against the live feeds and confirm the home page then shows real
     headlines with real images.
  f) Verify the finance rule ON THE RENDERED HTML, not just in the unit test: fetch the home page and
     assert no business/finance/crypto card appears in the hero or the first two blocks.
  g) Count images in the rendered home page: exactly one eager, all the rest lazy.

Write \`${ROOT}/docs/ROUTES.md\` with each route, its cache policy and its VERIFIED status code from
both the web-root and subdirectory runs. Report the verbatim test summary and the verbatim curl tables.
Do not claim anything works unless you actually ran it.`,
  { label: 'integrate', phase: 'Integrate', effort: 'high' }
)

return { built, integrated }
