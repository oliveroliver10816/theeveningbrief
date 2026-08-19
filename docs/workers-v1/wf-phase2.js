export const meta = {
  name: 'evening-brief-render',
  description: 'Renderer, router, weather, recipes, SEO surfaces and client JS for The Evening Brief',
  phases: [
    { title: 'Surfaces', detail: 'render, router, weather, recipes, SEO, client JS' },
    { title: 'Harden', detail: 'adversarial verification of each surface' },
    { title: 'Integrate', detail: 'wire it together and make the whole suite green' },
  ],
}

const ROOT = '/root/workspace/theeveningbrief'
const PRELUDE = `You are building a production news-aggregation website at ${ROOT}.
FIRST read these files in full — they are binding:
  ${ROOT}/docs/SPEC.md          (non-negotiables, routes, quality gates)
  ${ROOT}/docs/CONTRACT.md      (exact module signatures + file ownership)
  ${ROOT}/docs/RECON.md         (the teardown and the verified feed roster)
  ${ROOT}/docs/design/FINAL.md  (the chosen design system and its class names)
  ${ROOT}/src/design.css        (the production stylesheet you must render markup for)
  ${ROOT}/src/config.js         (getConfig — the ONLY place the brand name may appear)
  ${ROOT}/src/compose.js        (the front-page model you must render)
  ${ROOT}/src/db.js             (all data access)
Runtime = Cloudflare Workers, ES modules, Web Crypto, fetch, D1 binding \`env.DB\`.
NO npm runtime dependencies. Node 24 for tests (\`node --test\`).
Write complete working code. No TODOs, no stubs, no placeholder copy.
NEVER write the brand name or domain as a literal outside src/config.js — read it from cfg.
Only create/edit files you own.`

const SURFACES = [
  {
    key: 'render',
    own: 'src/render.js, src/html.js',
    prompt: `Own \`${ROOT}/src/html.js\` and \`${ROOT}/src/render.js\`.

html.js: \`esc(s)\` (escape & < > " '), \`attr(obj)\`, and a tiny tagged-template helper so
markup reads cleanly. Everything interpolated into HTML goes through esc unless it is
markup you generated yourself.

render.js exports layout, card, homePage, sectionPage, articlePage per CONTRACT.md, plus
\`ticker(items,cfg)\`, \`weatherStrip(model,cfg)\`, \`marketsStrip(items,cfg)\`, \`adSlot(name,cfg)\`,
\`searchPage\`, \`errorPage(status,message,cfg)\`. Markup must match the class names in
docs/design/FINAL.md exactly.

IMAGE RULES — these are the client's stated priority, get them exactly right:
- The single hero image: \`loading="eager" fetchpriority="high" decoding="async"\`.
- EVERY other image: \`loading="lazy" decoding="async"\`.
- Every image carries explicit \`width\` and \`height\` attributes (use the stored dimensions if
  present, otherwise the card's nominal box) so the browser reserves space — design.css already
  sets \`height:auto\` so they scale instead of squashing.
- Every image carries \`referrerpolicy="no-referrer"\` (publishers block hotlinks by referrer)
  and an \`onerror\` handler that hides the img, reveals the text-only fallback sibling, and
  swaps the card to its no-photo class. Escape it properly — it is inline JS inside an attribute.
- \`alt\` is the headline. Never empty, never "image".

layout() emits: correct doctype and lang, viewport, theme-color from cfg, canonical, Open Graph
and Twitter card, the JSON-LD block if given, a preconnect to fonts, design.css inlined or linked
(link is fine — it is one small file we serve ourselves), and the client script deferred.
It must not emit a render-blocking script anywhere.

Ad slots reserve fixed height from cfg so enabling ads later causes zero layout shift, and
render nothing but the reserved box while \`cfg.ads.enabled\` is false.

Write \`${ROOT}/test/render.test.js\`: assert esc actually escapes each dangerous character
including in attribute position; assert exactly one eager image and that every other image in a
full rendered home page has loading="lazy"; assert every img has width, height, alt and
referrerpolicy; assert a card with a null image renders the text fallback and no <img> at all;
assert a headline containing <script> and quotes cannot break out of either text or attribute
context; assert the brand name appears only via cfg. Run \`node --test\` and pass.`,
  },
  {
    key: 'weather',
    own: 'src/weather.js',
    prompt: `Own \`${ROOT}/src/weather.js\`.

Export \`getWeather(env, cfg, {lat, lon, place})\` returning
\`{place, current:{tempF,tempC,code,label,icon,windMph,humidity}, days:[{date,hiF,loF,code,label,icon}], alerts:[{event,severity,area,headline,url,ends}]}\`.

Sources, both keyless and both verified working:
- \`https://api.open-meteo.com/v1/forecast\` — current + 5-day daily, request Fahrenheit AND
  Celsius-derivable values, and pass the timezone from cfg.
- \`https://api.weather.gov/alerts/active\` — US alerts. It REQUIRES a real User-Agent with a
  contact or it rejects you, and it will NOT accept a comma-joined severity list: repeat the
  \`severity\` key instead (this was verified — a comma list returns HTTP 400).

Map WMO weather codes to a human label and a simple inline SVG icon name. Cover all documented
codes; unknown codes fall back to a neutral label, never \`undefined\`.

Cache in the Workers Cache API for 15 minutes keyed by rounded lat/lon so a traffic spike does
not hammer either API. If either upstream fails, return what you have with that part empty and a
\`degraded\` flag — the weather strip must never take the page down.

Default location comes from cfg (New York). Support \`?place=\` over a small built-in table of
~25 major US metros with their coordinates — no geocoding API, no user location prompt.

Write \`${ROOT}/test/weather.test.js\` with recorded real JSON fixtures (fetch them yourself with
curl, trim, save under test/fixtures/) and assert: code mapping is total over the documented WMO
set, an upstream 500 yields degraded rather than a throw, and the alerts request builds repeated
severity params rather than a comma list.`,
  },
  {
    key: 'recipes',
    own: 'src/recipes.js',
    prompt: `Own \`${ROOT}/src/recipes.js\`.

Recipes are a differentiator here: the reference site advertises a recipes section that does not
actually exist — \`section.php?slug=recipes\` returns HTTP 200 but silently serves the Nation feed.
Ours must be real.

Export \`recipeItems(db, cfg, limit)\` returning recipe articles already in D1 (ingested from the
recipe feeds in src/feeds.js), and \`enrichRecipe(item)\` which pulls the extra bits a recipe card
wants out of the feed content when they are present: total time, yield, and a short ingredient
count. Parse defensively from the summary/content — these are WordPress feeds and the shape
varies by publisher. When a field is absent, omit it; never invent a cook time.

Also export \`recipesPageModel(db, cfg)\` grouping into a lead recipe plus a grid, and
\`mealOfTheDay(env)\` which calls \`https://www.themealdb.com/api/json/v1/1/random.php\` (keyless,
verified) with a 10-minute cache and returns a normalised item, or null on failure.

⚠ Same rule as everywhere else: we show the headline, the feed summary and a link to the
publisher. We do NOT reproduce the recipe method or the full ingredient list.

Write \`${ROOT}/test/recipes.test.js\` with real fixture snippets from budgetbytes / smittenkitchen /
loveandlemons feeds; assert time and yield are extracted when present, that a feed item with none
of them yields an item with those fields simply absent, and that a themealdb outage returns null
rather than throwing.`,
  },
  {
    key: 'seo',
    own: 'src/seo.js',
    prompt: `Own \`${ROOT}/src/seo.js\`.

The reference site has NO robots.txt and NO sitemap.xml — both 404. For a site whose entire
business is indexed news pages that is its biggest miss, and closing it is our edge. Export:

- \`robotsTxt(cfg)\` — allow crawling, disallow /admin and /search, and point at the sitemap
  using cfg.baseUrl.
- \`sitemapIndex(cfg)\` and \`sitemapPage(db, cfg, page)\` — paginated, 5,000 URLs per page,
  every URL absolute from cfg.baseUrl, with lastmod. Include the front page, every section,
  and every article from the retention window.
- \`newsSitemap(db, cfg)\` — a Google News sitemap of the last 48 hours only, using the
  \`news:\` namespace correctly, with publication name from cfg.
- \`rssOut(db, cfg, section)\` — our own valid RSS 2.0 feed. It must carry our summary and a
  link to OUR article page, not the publisher's, and must escape correctly (CDATA for titles
  and descriptions).
- \`articleJsonLd(article, cfg)\` — schema.org NewsArticle. Because we publish a summary and
  link out, it must set \`isBasedOn\` to the publisher URL and name the publisher as the source
  rather than claiming authorship. Do not emit fields we cannot substantiate (no fake author,
  no fake wordCount).
- \`breadcrumbJsonLd\`, \`websiteJsonLd\` (with SearchAction pointing at /search?q=).

Every generated XML must be well-formed with an ampersand, an angle bracket and a non-ASCII
character in a title. Write \`${ROOT}/test/seo.test.js\` proving exactly that — parse the output
back with a real XML parse (reuse src/xml.js) and assert round-trip, plus assert no URL is
relative and none contains a hardcoded brand literal.`,
  },
  {
    key: 'client',
    own: 'public/app.js',
    prompt: `Own \`${ROOT}/public/app.js\` — the ONLY client-side JavaScript on the site.

Hard budget: under 8 KB uncompressed, no dependencies, no build step, must be safe to load with
\`defer\`. It must degrade completely: with JS disabled the page is still fully readable and
every link works.

It does exactly these things and nothing more:
1. Live clock in the masthead, formatted in cfg's timezone via Intl, ticking every 30s. Read the
   timezone from a data attribute the server rendered — do not hardcode it.
2. Headline ticker: pause on hover and on focus-within, respect prefers-reduced-motion (if the
   user prefers reduced motion, do not animate at all — render it as a static scrollable strip),
   and pause when the tab is hidden so it stops burning CPU in a background tab.
3. Relative timestamps: turn every \`<time datetime>\` into "12 min ago" / "3 hr ago", refreshed
   each minute. Server renders the absolute time so it is correct without JS.
4. Theme toggle: light / dark / system, persisted in localStorage, applied by setting
   data-theme on the root element. Must not flash — pair it with a tiny inline snippet in
   layout() that reads localStorage before first paint (tell the render agent what you need by
   writing the exact snippet into \`${ROOT}/docs/design/CLIENT-NOTES.md\`).
5. Lazy-image fallback: nothing at all in normal operation — the onerror attribute already
   handles a broken hotlink. Do not reimplement lazy loading; native loading="lazy" does it.

Write \`${ROOT}/docs/design/CLIENT-NOTES.md\` listing every data attribute and DOM hook you rely
on, so the renderer emits them. Keep the file short and exact.`,
  },
]

phase('Surfaces')
const built = await pipeline(
  SURFACES,
  (s) => agent(`${PRELUDE}\n\n${s.prompt}`, { label: `build:${s.key}`, phase: 'Surfaces' }),
  (_r, s) => agent(
    `${PRELUDE}

Adversarially verify the "${s.key}" surface (${s.own}). Read the real files. Try to REFUTE it.
1. Run \`cd ${ROOT} && node --test\` and report the true output verbatim.
2. Check every signature against docs/CONTRACT.md and every class name against
   docs/design/FINAL.md — a drift here means the page renders unstyled.
3. Find tests that cannot fail, and fix them so they can.
4. Hunt hard for: XSS through an unescaped headline in text OR attribute position, an image
   without lazy/width/height, a hardcoded brand string, an unhandled upstream failure that
   would 500 the whole page, XML that breaks on an ampersand, and any relative URL in a
   sitemap or feed.
5. Fix everything you find and re-run until green.
Report what you found, what you fixed, and the final test summary line. If nothing real was
wrong, say so plainly instead of inventing a finding.`,
    { label: `harden:${s.key}`, phase: 'Harden', effort: 'high' }
  )
)

phase('Integrate')
const integrated = await agent(
  `${PRELUDE}

All modules now exist. Own \`${ROOT}/src/index.js\` and wire the whole application together.

Default export with \`fetch(request, env, ctx)\` and \`scheduled(event, env, ctx)\`.

ROUTER — hand-rolled, no framework. Routes per docs/SPEC.md §6:
  /                      home            s-maxage from cfg.cache.home
  /section/:slug         section index (paginated ?p=)
  /article/:slugAndId    one story  — id is the trailing numeric segment; a wrong slug with a
                         right id 301s to the canonical URL
  /weather  /recipes  /search?q=  /about  /sources
  /feed.xml  /sitemap.xml  /sitemap-news.xml  /sitemap-:n.xml  /robots.txt
  /healthz               JSON, no cache
  /app.js                the client script, immutable cache, correct content-type
  /design.css            the stylesheet, immutable cache
  POST /admin/ingest     manual ingest trigger, guarded by env.ADMIN_TOKEN (bearer). If
                         ADMIN_TOKEN is unset the route returns 503 and says so — it must
                         never be open by default.
  anything else          404 through errorPage()

Serve HTML with \`Cache-Control: public, max-age=0, s-maxage=N, stale-while-revalidate=600\`
and use the Workers Cache API so repeat hits never touch D1. Add ETag and honour
If-None-Match with a 304. Never cache /healthz, /search or /admin/*.

SCHEDULED: the \`*/10\` cron runs ingestion (src/ingest.js — if it does not exist yet, write it
per docs/SPEC.md §4 and CONTRACT.md, you own it). The \`7 * * * *\` hourly cron additionally
prunes articles past retention and refreshes source health. Ingestion must:
  - select only feeds due by tier, bounded concurrency, per-feed timeout
  - never let one bad feed fail the run
  - record an ingest_runs row every time, with honest counts
  - increment fail_count and auto-park a feed after 8 consecutive failures, logged

Every handler must be defensive: a D1 error or an empty database renders a valid page that says
there is nothing yet, never a stack trace and never a 500 on the home page.

Then MAKE THE WHOLE THING RUN:
  - \`cd ${ROOT} && npm test\` — all suites green. Fix whatever is broken across any file;
    at this integration step you may edit any file, but keep contracts intact.
  - \`npx wrangler d1 execute evening_brief --config ./wrangler.json --local --file=./migrations/0001_init.sql\`
  - Start \`npx wrangler dev --config ./wrangler.json --local --port 8788\` in the background,
    trigger an ingest, then actually CURL every route above and confirm real status codes and
    real content. Note: with --local, ingestion fetches the real feeds over the network, which
    works from this box.
  - Fix everything that is not a 200 with sensible content.

Write \`${ROOT}/docs/ROUTES.md\` recording each route, its cache policy and its verified live
status code. Report the verbatim final \`npm test\` summary and the verbatim curl status table.
Do not claim a route works unless you actually curled it.`,
  { label: 'integrate', phase: 'Integrate', effort: 'high' }
)

return { built, integrated }
