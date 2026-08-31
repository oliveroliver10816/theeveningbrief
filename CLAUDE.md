# The Evening Brief — theeveningbrief.com

**What it is:** a dynamic US-first news aggregation site, modelled on (and built to beat)
`dailynews18.com`. Front page recomposed continuously from ~35 public RSS feeds; every card
opens its own small article page. Sections run **US → International → World → Weather → Recipes**.

**⭐ LIVE 2026-08-19: https://the-evening-brief-4ac580dc21ce.herokuapp.com** — MySQL-backed,
fetching every 10 minutes, 34/34 feeds green, 0 parked. Heroku app `the-evening-brief` on
**emilyparker19814014@gmail.com** (one of 6 Heroku accounts in Gitku's `deploy_bot_us` D1).
DB = **JawsDB Kitefin, free**. Schedule = **CF Worker `evening-brief-scheduler`, cron `*/10`**
(Osanix acct) — Heroku Scheduler was rejected because it can ONLY be configured by clicking its
dashboard, and Bob does not run commands or click panels. Deployment facts + ingest token:
`/root/.config/theeveningbrief/deployment.json` (600); the account's GitHub + Heroku keys are
also at `/root/.config/newsroom/credentials.json` (600), shared with the newsroom-2 sites.

**Status: v1.3 BUILT, TESTED, DEPLOYED 2026-08-19.** 324 tests pass. Nothing bought, $0 spent.
📦 **ZIP:** github.com/oliveroliver10816/theeveningbrief/releases/download/v1.0/theeveningbrief.zip
(169,736 bytes, md5 `bec750c8937a7b400f4a9e8e9c3d1e40`, download re-verified). Source repo is now
**emilyparker19814014/the-evening-brief** (see the 2026-08-31 section at the end);
oliveroliver10816/theeveningbrief is kept as the `oliver-backup` remote and holds the same commit.
12,134 lines of PHP across 12 modules + router + front controller.

**Proven, not asserted** — every route curled on a CLEAN UNZIP with an empty database:
all 200s, 404 only for a bad path. First request self-seeds (7.2s, fetches 14 feeds across all
five front-page sections), then **0.18s**. 34/34 feeds green, 756 articles. Weather live from
open-meteo + NWS. Article pages carry NewsArticle JSON-LD with `isBasedOn`; a wrong slug 301s to
the canonical URL. Front page: 1 eager hero + 19 lazy images, every one with width/height/alt/
referrerpolicy/onerror. Finance count on the front page = 0-2, hero and first two blocks always 0.

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


## ⚠ What went wrong on the way — worth not repeating

1. **The ultracode workflow STALLED SILENTLY.** All 7 module agents and all 7 verifiers finished
   (324 tests green), then the Integrate agent never produced a file. The last agent transcript
   was written at 12:52 and nothing happened for 83 minutes; there was no error, no notification,
   nothing in `journal.jsonl` but 25 entries of build/harden results. **Poll the journal's mtime,
   don't wait on the completion notification** — a stalled workflow looks exactly like a slow one.
   Fixed by killing it and writing `index.php`, `app/Router.php`, `app/bootstrap.php`,
   `install.php` and `README.txt` by hand in ~20 minutes.
2. **`Db::recentArticles` takes `section` (singular, accepts an array) and `window_hours`** — I
   wired the Router with `sections` and `since_days`. Unknown option keys are silently ignored,
   so every section page served the SAME rows and it looked plausible. Caught only by comparing
   headlines across `/section/us`, `/section/world`, `/section/recipes`.
3. **`Recipes::pageModel()['items']` is ALREADY lead + grid.** My Router prepended `lead` again,
   so the lead recipe rendered twice. Caught by a unique-vs-total count on the rendered page.
4. ⭐ **`Paths::hasRewrite()` probes over loopback HTTP, and an INCONCLUSIVE probe was never
   cached** — so it re-probed on every single request, costing a 3s timeout per page view on any
   host that cannot answer its own request. Now a null probe writes a negative with `NEGATIVE_TTL`.
   Safe because `evidenceFromRequest()` is consulted *before* the negative cache, so a real
   rewrite still upgrades it to true. **This was a genuine production risk, not a harness artefact.**
5. ⚠ **But the subdirectory 404s WERE a harness artefact** — my own dev-server router made
   `SCRIPT_FILENAME` point at the router script, outside the app root, which Apache never does.
   Resisted "fixing" `Paths.php` for a phantom bug; emulating Apache's `SCRIPT_NAME` made all
   subdirectory routes 200.
6. ⚠ **My own `install.php` reachability probe used HEAD and reported NPR as blocked.** NPR
   answers HEAD with 403 and GET with 200. A false "your host blocks outbound HTTP" would have
   sent Bob to his host for nothing. Now a Range-capped GET with the ingester's own UA, and
   401/403/429 is reported as *reachable but declining us* — the same lesson as `tombstone.py`.
7. ⚠ **Seeding tier-1 only left Recipes and Weather empty on a fresh upload** — those publish
   rarely and sit in slower tiers, and an empty section is the first thing anyone notices. The
   first-run seed now takes the best few feeds *per front-page section*.
8. ⚠ **A single `Ingest::run` is capped by `ingest.batch` (14)**, so one press of install.php's
   "Fetch stories now" could never cover a 34-feed roster. It now loops to a 45s budget.


## Deploying this to Heroku — everything that bit us

⚠ **Heroku wipes the dyno disk on every restart.** SQLite there loses every story about once a
day. Bob's words: *"I don't want it to be wiped out on each restart. FIX IT!"* Fix = read the DB
from the environment. `config.php` now picks up `JAWSDB_URL` / `JAWSDB_MARIA_URL` /
`CLEARDB_DATABASE_URL` / `DATABASE_URL` / `MYSQL_URL` and discrete `MYSQL_*`, and switches to
MySQL with no edit. **Postgres is explicitly ignored-and-logged**, not half-supported.

⚠ **The MySQL driver had NEVER been executed** — `tests/test_db.php` skipped itself when no server
was present, so "324 tests pass" said nothing about it. Installed MariaDB locally and ran the whole
app against it before trusting it: migrate idempotent, re-ingest skipped 208/208, section filter and
injection probe clean, compose+render identical. **Then wiped the entire app directory, redeployed
fresh, and confirmed all 378 articles survived.** A skipped test is not a passing test.

⚠ **`ext-pdo_sqlite` and `ext-mbstring` are NOT in Heroku's default PHP build** → "could not find
driver" → 503 on the front page. Must be declared in `composer.json`, and once `require` exists
Heroku demands a matching `composer.lock`.

⚠ **The upload path silently dropped `.htaccess`** (a dot-file), so every pretty URL 404'd at Apache
before PHP saw it. `apache.conf` is the same ruleset under a name nothing strips, wired via
`Procfile: heroku-php-apache2 -C apache.conf /`. ⚠ **`heroku-php-nginx` ignores .htaccess entirely** —
picking it would 404 the whole site.

⚠ **The cPanel ZIP has a wrapper folder that hides composer.json/Procfile from the buildpack.**
The Heroku package is flat. Two packages, one packager: `scripts/build-zip.sh`.

⚠ **`config.php` is a `require`d expression the test suite loads more than once** — a bare
`function` in it fatals on redeclare and cost 4 tests. Guarded with `function_exists`.

⚠ **Heroku Scheduler has no API** — jobs can only be created in its web dashboard. That is why the
timetable lives in a Cloudflare Worker cron instead.

⚠ **`/admin/ingest` did not exist** — SPEC §6 listed it, the built Router never had it. Added,
guarded by `ingest.token` (also readable from `INGEST_TOKEN` env). **Closed by default**: no token
configured returns 503, wrong token 403, compared with `hash_equals`. An open endpoint that fires 34
outbound requests is a free amplifier.

⚠ `error code: 1042` from `workers.dev` is Cloudflare throttling **our datacenter IP**, not a broken
Worker — the scheduled trigger runs inside Cloudflare and is unaffected. Verified by watching
`/healthz` `last_run` move on its own: **19:30:54 UTC, 14 feeds ok / 0 failed, 130 new, 257 deduped.**

⚠ **Six Heroku accounts are stored in Gitku** (`deploy_bot_us` + `deploy_bot_apac` D1). The app name
is `the-evening-brief`; the hash in the URL is not part of it — matching on the URL found nothing.


## 2026-08-19 rev 2 — the five things the client rejected, and what was actually wrong

1. ⭐⭐ **"The black background is putting me off"** — the site was never meant to be black.
   Fable 5's palette is warm paper `#FBFAF7`; the dark palette was a secondary, wired to
   `@media (prefers-color-scheme:dark)`. **His browser was in dark mode, so he had never once
   seen the real design.** Auto-switching is now disabled (the query is parked behind
   `and (min-width:99999px)` with the reason in a comment); dark survives only via the toggle.
   ⚠ Lesson: a news brand must not follow the OS theme. Light is the product decision.
2. **Front-page cards linked straight to the publisher.** The lead card carried
   `card-out` → abcnews.com, i.e. the home page handed our traffic away. Cards now link only
   to our own article page; the publisher link lives at the foot of the story. Two tests were
   INVERTED to assert the opposite of what they used to.
3. ⚠⚠ **"Proper article, not a 3-4 liner" is bounded by the feeds, not by us.** Measured:
   ABC 0 chars of body, UPI 147, NPR 200, Guardian 761, CBS 121 — publishers withhold the body
   deliberately. Only WordPress feeds carry the whole piece (Budget Bytes **14,949 chars**).
   **dailynews18, his own reference, shows ONE summary paragraph + "Read full story at …" —
   verified by fetching its article page.** So: a new `body` column stores the fullest text the
   feed really gives, `Xml::itemBody()` keeps paragraph breaks, and `.article-body` renders it
   at a 44rem measure with a byline and a "Read more at X" at the end. A recipe now renders as
   a genuine 3,444-word article. We do NOT scrape publisher pages — that is republishing.
4. ⭐ **Images were never measured.** `image_width`/`image_height` existed and the renderer read
   them, but nothing ever populated them — so a **CBS 60x60 thumbnail was declared 640 wide and
   stretched across a lead slot**. New `app/Images.php`: verified CDN upgrades (BBC 240→1024 on
   both path shapes; NPR Brightspot 4032x3024/3.3 MB → 1200x900/573 KB), concurrent measurement
   with a per-host cap of 2 (**UPI answers a burst with HTTP 429 / Cloudflare 1015**), a 256 KB
   header sniff (**64 KB missed NPR's SOF marker entirely**), and a floor per card size.
   CBS's 72 unusable images are now dropped, not stretched. `image_tries` caps retries at 4 so a
   rate-limiting CDN is not re-hammered hourly for ever.
5. **Placeholder for image-less sources** — Washington Post, Al Jazeera, Deutsche Welle and the
   National Weather Service publish no pictures. `app/Placeholder.php` draws a masthead SVG on
   our own domain (per-section ink, deterministic variant, `immutable` cache). Live home page:
   23 images, 4 placeholders, **0 cards with no image**.

⚠ `Render::outbound()` rejects anything without a scheme+host, which silently swallowed our own
relative placeholder URL — the placeholder rendered nowhere until that was special-cased.
⚠ `src/design.css` is the designer's file; `assets/css/site.css` is that file **plus** a marked
`RENDERER EXTENSION` block. Copying the extension back into design.css broke the integrity test.
Tests: **336 pass, 0 fail.**


## Repo moved to the Heroku identity — 2026-08-31 (Bob's call)

Bob: *"use emilyparrker account for the repo because its better to use the same email for both
heroku app link and repo for files hosting."*

**`origin` is now `emilyparker19814014/the-evening-brief`** (private) — the same identity that
owns the Heroku app, so one login covers the files and the app. The old
`oliveroliver10816/theeveningbrief` remote is **kept** as `oliver-backup` and both were pushed,
so they hold the same commit. Nothing was deleted.

⚠ **The two repos had UNRELATED histories.** The `emilyparker` one was made by Gitku's own
"new site" flow (`Initial commit` → `Upload 23 files` → `Upload 25 files`, HEAD `b8b72cc`) and
was **26 files, missing `apache.conf` and `composer.lock`** — i.e. it could not have produced a
working build. It was NOT force-pushed over. Instead `git merge -s ours --allow-unrelated-histories`
put our tree on top with their history as a second parent, and their `README.md` and
`data/.gitkeep` were restored afterwards, so the repo is now 86 files and nothing was lost.
Merge commit `c88c138`, HEAD `20b0644`.

Wired into **Gitku** (`ail.com.de/deploy`): `buildpack='php'` written after reading the repo's
real tree; the tarball fetch a build performs was tested and returns 200 with every
build-critical file at the root.

⚠ `apps.built_sha` still reads `b8b72cc` — honest, not a bug: the live dyno was deployed by
direct tarball, not from the repo, so the panel will show a rebuild as available. The live site
was verified unchanged throughout (`/healthz` ok, ingest running, stylesheet byte-identical).
