# The Evening Brief — theeveningbrief.com

**What it is:** a dynamic US-first news aggregation site, modelled on (and built to beat)
`dailynews18.com`. Front page recomposed continuously from ~35 public RSS feeds; every card
opens its own small article page. Sections run **US → International → World → Weather → Recipes**.

**Status:** IN BUILD (started 2026-08-19). Nothing deployed yet, nothing bought, $0 spent.

## Where the reference site's feed comes from — answered

`dailynews18.com` is a **PHP + MySQL RSS aggregator on GoDaddy shared hosting**
(`173.201.178.221`, `ns31/ns32.domaincontrol.com`). No wire deal, no API, no proprietary feed.
It republishes headline + feed summary + source link from ~25 public RSS feeds and **hotlinks
publisher images straight off their CDNs** (`s.abcnews.com`, `srnnews.com`, `static01.nyt.com`,
`cdnph.upi.com`, NBC, KTLA, WTOP, KFF, DW, PBS) with `referrerpolicy="no-referrer"` and an
`onerror` text fallback. Markets = three TradingView embed widgets. Full teardown +
the complete, HTTP-200-verified feed roster: `docs/RECON.md`.

## Decisions

- **Stack: PHP 8 + PDO, delivered as an uploadable ZIP.** ⚠ This REPLACED a Cloudflare
  Workers build mid-flight (2026-08-19) when Bob asked for "the zip file... I'll upload to a
  CDN" and mentioned `.htaccess` — a Worker cannot be zipped and uploaded. The Fable 5 design
  system survived the pivot unchanged; the JS data layer was dropped before it was written.
  **SQLite by default so it runs on first upload with zero configuration**; MySQL is one edit
  in `config.php`. The unused D1 `evening_brief` (`ad189cb4-...`) still exists on the Osanix CF
  account, costing nothing — the Workers scaffolding is parked in `docs/workers-v1/`.
- **It must run from a subdirectory as well as a web root**, because Bob test-uploads to
  arbitrary locations. Every URL goes through `TEB\Paths`; nothing is root-absolute.
- **Brand is config, never hardcoded** — everything visible comes from `config.php`, the only
  file Bob edits. A brand literal anywhere else in `app/` is a build failure (there is a test).
- **Front page is deliberately light on finance/crypto** — enforced in `app/Compose.php` as a
  hard quota with a test, not as a style note. Bob is buying ads to acquire a general-news
  audience; a markets-heavy front page fights that. Markets live in a small strip low on the page.
- **Ticker = headlines, not markets** — same reason.
- **We never republish full article text.** Headline + feed summary + prominent outbound link.
  This is what the reference site does and it is the only defensible position.

## Traps already hit / avoided

- ⚠ `theeveningbrief.com` is **NOT REGISTERED** (RDAP 404, 2026-08-19). Bob must buy it.
  It is free as of today.
- ⚠ PHP was NOT installed on this box — `php-cli php-sqlite3 php-mysql php-curl php-mbstring
  php-xml php-zip` were installed 2026-08-19 (PHP 8.1.2) specifically so this build could be
  run and tested here rather than shipped blind.
- ⚠ Feeds that are dead or block us — do not re-add: Serious Eats (402), Simply Recipes (402),
  Food52 (429), `apnews.com/hub/ap-top-news/rss` (404), `reutersagency.com/feed/` (404).
  AP and Reuters copy still arrives via SRN News.
- ⚠ `api.weather.gov` rejects a comma-joined `severity` param — repeat the key instead.
- ⚠ Reference site has **no robots.txt and no sitemap.xml** (both 404). We ship both.

## Files

- `docs/RECON.md` — the teardown + verified feed roster
- `docs/SPEC.md` — the build spec (non-negotiables, routes, quality gates)
- `docs/CONTRACT.md` — module signatures + file ownership
- `docs/design/` — the three Fable 5 directions, and `FINAL.md` = the judged winner (AUTHORITY,
  an evening broadsheet) plus the exact class contract the renderer builds against
- `src/design.css` — the production stylesheet (copied to `assets/css/site.css` at build)
- `scripts/build-zip.sh` — packages `dist/theeveningbrief.zip`; refuses to package a bad `.htaccess`
- `docs/workers-v1/` — the abandoned Cloudflare Workers scaffolding, kept for reference only

## ⚠ The `.htaccess` rule — the bug Bob flagged, and why the file looks bare

Bob: *"make sure that htaccess doesn't have any force redirects etc...like we fixed the same
bug yesterday in those 30 sites."*

The older batches (`nutressentials`, `thriveserver2`, the 0804 set) shipped an `.htaccess`
carrying **force-HTTPS + force-non-www 301s to the hardcoded production domain**:

```apache
RewriteCond %{HTTPS} !=on
RewriteRule ^(.*)$ https://nutressentials.org/$1 [R=301,L]
```

Upload that ZIP anywhere other than the final domain — a CDN, a staging subdomain, a subfolder,
an IP — and Apache 301s the visitor straight off to a domain that isn't serving yet. **You can
never see what you just uploaded**, and because a 301 is browser-cached it keeps happening after
the rule is removed. Yesterday's 30-site batch (`worldos`, `dotcloud`, `arsdigita`, …) fixed it by
shipping only `Options -Indexes` / `DirectoryIndex` / `ErrorDocument`.

This project needs a front controller, so it cannot be quite that bare. The rule here is:
**the only rewrite is INTERNAL (no `R` flag), there is no `RewriteBase`, no `Redirect` directive,
no hardcoded URL, and no root-absolute `ErrorDocument`** (that last one also breaks in a
subdirectory — I wrote it, caught it, and removed it). Guarded three ways:
`tests/test_htaccess.php`, a refuse-to-package gate in `scripts/build-zip.sh`, and the file's
own header comment explaining why it is empty of redirects.

Canonical-domain enforcement, when wanted, belongs at the host/CDN level **after** the site is
confirmed working — never in this file.
