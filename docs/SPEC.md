# The Evening Brief — Build Spec (v2, PHP/cPanel)

Reference site torn down: `dailynews18.com` (see `docs/RECON.md`).
Deliverable: **a ZIP Bob uploads to hosting**, unzips, and it works.

> v1 targeted Cloudflare Workers. Superseded 2026-08-19: Bob wants a ZIP he can upload,
> and `.htaccess`, which means Apache/cPanel. Stack is now PHP 8 + PDO. The Fable 5
> design system (`src/design.css`) carries over unchanged.

## 0. Non-negotiables

1. **`.htaccess` MUST NOT contain a single external redirect.** No force-HTTPS, no
   force-www, no force-non-www, no hardcoded domain, no `Redirect`, no `RewriteRule`
   with `[R]` or `[R=301]`. This bug cost us a whole batch: an `.htaccess` that 301s to
   the production domain means the moment you upload the ZIP anywhere else — a CDN, a
   staging subdomain, a subfolder — the browser is thrown off to a domain that isn't
   live yet, and the 301 is then cached so it keeps happening. The only rewrite allowed
   is the **internal** front-controller rewrite (no `R` flag), and it must be
   subdirectory-safe. There is a test for this.
2. **It must run from a subdirectory as well as a web root**, with no edits. Every URL
   the app emits is built from a base path detected at runtime from `$_SERVER`.
   Zero absolute-path assumptions, zero `href="/..."`.
3. **It must work on first upload with no configuration**: default storage is a **SQLite**
   file under `data/`. MySQL is opt-in by filling in `config.php`. If the database is
   empty on first request, the app ingests inline (behind a lock) so the very first page
   view shows real news.
4. **Brand is config, never hardcoded.** Everything visible comes from `config.php`.
   A brand literal anywhere else in `app/` is a build failure — there is a test.
5. **Front page must not read as a finance site.** Hard quota in code: zero
   business/finance/markets/crypto in the hero or the first two blocks; at most 2 on the
   whole front page, in a separate strip below the fold. This is an ads/audience
   decision enforced by a test, not a style note.
6. **Every image lazy-loads** except the single hero (`loading=eager fetchpriority=high`).
   All others `loading="lazy" decoding="async"` + explicit `width`/`height` + CSS
   `height:auto` + `referrerpolicy="no-referrer"` + an `onerror` text fallback.
7. **No full article text is ever republished.** Headline + feed summary + source +
   prominent outbound link, and a standing line saying so.
8. **Section order: US → International → World → Weather → Recipes.**

## 1. Stack

- PHP **8.0+** (works on 8.1/8.2/8.3 shared hosting), PDO.
- **SQLite by default**, MySQL by config. One `Db` class, same SQL both ways.
- Zero Composer dependencies. Zero build step. Zero npm.
- Client JS budget < 8 KB, deferred, site fully usable without it.

## 2. Layout on disk (what's inside the ZIP)

```
index.php            front controller (the ONLY entry point)
.htaccess            no redirects — see §0.1
config.php           brand, storage, feature flags   <- the only file Bob edits
app/                 Config Db Router Feeds Xml Ingest Compose Render Weather Recipes Seo Health
data/                sqlite db + cache + lock  (0777-friendly, created on demand)
assets/css/site.css  the Fable 5 design system
assets/js/app.js     clock, ticker, relative times, theme toggle
cron/ingest.php      cPanel cron entry (also runnable from CLI)
install.php          one-page self-check: PHP version, extensions, writability, feed reachability
README.txt           upload instructions for Bob / the VA
```

## 3. Data model (identical on SQLite and MySQL)

`sources`, `articles`, `ingest_runs` per v1 §2. Times stored as INTEGER ms epoch.
`guid_hash` unique for hard dedup; `title_key` for soft dedup so one AP story syndicated
to five outlets appears once.

## 4. Ingestion

- `cron/ingest.php` every 10 min from cPanel cron. Also reachable as
  `index.php?__ingest=TOKEN` when a token is set, and auto-triggered inline when the DB
  is empty or stale beyond a threshold (with a lock file so two hits don't double-run).
- Feeds fetched with cURL, per-feed timeout, real User-Agent, bounded batch.
- One bad feed never fails the run: increment `fail_count`, park after 8, log it.
- Parser handles RSS 2.0, Atom, RDF, CDATA, `media:content`, `media:thumbnail`,
  `enclosure`, `<img>` inside `content:encoded`. Use PHP's XMLReader/SimpleXML with
  namespace handling — not regex.
- Retention: prune past 30 days on the hourly pass.

## 5. Front-page composition — `app/Compose.php`

Pure function of (rows, config, now) → model. Deterministic, therefore testable.
Score = recency decay × source weight × section priority + image bonus − repeat-source penalty.
Enforces the finance quota (§0.5), per-source caps per block, no article twice, block order.
Degrades gracefully: 3 articles, zero recipes, or everything from one source must all
still render a valid page.

## 6. Routes (pretty URLs via internal rewrite; every one also works as `?r=` if mod_rewrite is off)

| Route | Notes |
|---|---|
| `/` | front page |
| `/section/{slug}` | section index, paginated |
| `/article/{slug}-{id}` | one story; wrong slug + right id → canonical 301 (this is the one intentional redirect, and it is app-level, not `.htaccess`) |
| `/weather`, `/recipes`, `/search?q=`, `/about`, `/sources` | |
| `/feed.xml`, `/sitemap.xml`, `/sitemap-news.xml`, `/robots.txt` | reference site has neither robots nor sitemap — our edge |
| `/healthz` | JSON: last ingest, counts, failing feeds |

⚠ **If `mod_rewrite` is unavailable the site must still work.** Detect it once and emit
`?r=/section/us` style URLs instead. A CDN or a locked-down host that ignores `.htaccess`
must not produce a site of 404s.

## 7. Ticker

Headline ticker (not markets — see §0.5), CSS-animated, pauses on hover/focus, static
under `prefers-reduced-motion`, paused when the tab is hidden.

## 8. Ads

`adSlot($name)` renders a height-reserved container so enabling ads causes zero layout
shift. Off by default in `config.php`; no network calls until a real tag is set.

## 9. Gates — all must pass before the ZIP is built

- `php tests/run.php` — parser, dedup, compose (incl. finance quota), summary trimming,
  base-path building, config non-hardcoding, **and an `.htaccess` assertion that there is
  no `[R]`/`[R=301]`/`Redirect`/hardcoded domain in it**.
- Live smoke against `php -S`: every route 200 with real ingested data, from a web root
  **and** from a subdirectory.
- Same smoke with `mod_rewrite` simulated off.
- WCAG AA on every text/background pair; no horizontal overflow at 360/768/1440/2560;
  zero console errors.
- `install.php` reports all-green on a clean unzip.
