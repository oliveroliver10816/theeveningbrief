# Module contract (PHP) — every agent MUST match these signatures exactly

Project root: `/root/workspace/theeveningbrief`.
PHP **8.0+**, PDO, no Composer, no autoloader magic — `app/bootstrap.php` requires the
classes explicitly. Namespace `TEB\`. `declare(strict_types=1);` at the top of every file.
PHP 8.1.2 CLI is installed on this box with pdo_sqlite, pdo_mysql, curl, mbstring,
SimpleXML, dom, zip — test everything for real.

## config.php  (root — the ONLY file Bob edits, and the ONLY place the brand name lives)
```php
return [
  'site' => ['name','short_name','domain','tagline','description','timezone','locale','theme_color'],
  'db'   => ['driver' => 'sqlite'|'mysql', 'sqlite_path', 'host','port','name','user','pass','charset'],
  'ingest' => ['enabled','auto_on_empty','stale_after_minutes','token','timeout_seconds','batch','retention_days'],
  'compose'=> ['finance_max_on_home'=>2,'finance_blocked_blocks'=>['hero','us','international'],
               'hero_sub_count'=>4,'per_source_cap_per_block'=>2,'ticker_count'=>12],
  'ads'  => ['enabled'=>false,'slots'=>['leaderboard'=>[970,250],'rail'=>[300,600],'inline'=>[728,90]]],
  'weather' => ['default_place','places'=>[...]],
  'cache' => ['home_seconds','section_seconds','article_seconds'],
];
```

## app/Config.php
```php
TEB\Config::load(string $rootDir): array      // merges config.php over DEFAULTS
TEB\Config::get(string $dotPath, $default=null)
```

## app/Paths.php   ← the subdirectory-safety module, get this exactly right
```php
TEB\Paths::init(array $server): void
TEB\Paths::base(): string          // '' at web root, '/sub' in a subdirectory. No trailing slash.
TEB\Paths::url(string $path): string   // '/section/us' -> '/sub/section/us' or '/sub/index.php?r=/section/us'
TEB\Paths::asset(string $rel): string  // cache-busted by filemtime
TEB\Paths::absolute(string $path): string  // scheme+host+base+path, host from $_SERVER, NOT from config
TEB\Paths::hasRewrite(): bool      // probed once, cached in data/, falsy-safe
TEB\Paths::currentRoute(): string
```
⚠ `absolute()` derives the host from the actual request so a test upload on any hostname
produces correct canonical/OG/sitemap URLs. `config.site.domain` is used only for display
and as a fallback when the request host is empty (CLI).

## app/Db.php
```php
TEB\Db::connect(array $cfg): PDO      // sqlite: mkdir data/, WAL, busy_timeout; mysql: utf8mb4
TEB\Db::migrate(PDO $p): void         // idempotent; same logical schema on both drivers
TEB\Db::upsertSources(PDO,$feeds): void
TEB\Db::insertArticles(PDO,array $rows): array   // ['inserted'=>int,'skipped'=>int]
TEB\Db::recentArticles(PDO, array $opts): array
TEB\Db::articleById(PDO,int $id): ?array
TEB\Db::relatedArticles(PDO,array $a,int $limit): array
TEB\Db::searchArticles(PDO,string $q,int $limit): array
TEB\Db::pruneOld(PDO,int $days): int
TEB\Db::health(PDO): array
TEB\Db::canonicalUrl(string $u): string   // strips utm_*, fbclid, gclid, mc_cid, ref
TEB\Db::guidHash(string $u): string       // sha256 hex
TEB\Db::titleKey(string $t): string       // soft-dedup key
```
Every query uses prepared statements with bound params — **including search**. No interpolation.

## app/Feeds.php
```php
TEB\Feeds::all(): array   // [['slug','name','feed','section','country','tier','weight','homepage'], ...]
TEB\Feeds::due(int $nowMs, array $tierState): array
```
Built from the HTTP-200-verified roster in `docs/RECON.md`. Data only, no logic.

## app/Xml.php
```php
TEB\Xml::parseFeed(string $xml): array   // ['title'=>string,'items'=>[['guid','url','title','summary','image_url','published_at','author'],...]]
TEB\Xml::stripHtml(string $s): string
TEB\Xml::trimSummary(string $s, int $max): string   // word boundary, never mid-entity
```
RSS 2.0 + Atom + RDF. Use SimpleXML with `children($ns)` for `media:`/`content:`/`dc:`.
`published_at` is ms epoch or `null` — never `0`, never false.

## app/Ingest.php
```php
TEB\Ingest::run(PDO $p, array $cfg, ?array $only=null): array  // ['feeds_ok','feeds_failed','inserted','skipped','errors'=>[]]
TEB\Ingest::lock(string $dataDir): ?resource   // null if already held
```

## app/Compose.php
```php
TEB\Compose::home(array $rows, array $cfg, int $nowMs): array
// ['ticker'=>[], 'hero'=>['lead'=>..,'subs'=>[]], 'blocks'=>[['id','label','href','items'=>[]]], 'markets'=>[]]
```
Pure + deterministic. No `time()`, no `rand()`, no I/O.

## app/Render.php
```php
TEB\Render::layout(array $o): string   // ['title','description','canonical','body','jsonld','cfg']
TEB\Render::card(array $a, array $o): string  // ['size'=>'lead|large|medium|small|text','lazy'=>bool]
TEB\Render::home(array $model, array $cfg): string
TEB\Render::section(array $model, array $cfg): string
TEB\Render::article(array $model, array $cfg): string
TEB\Render::ticker(array $items, array $cfg): string
TEB\Render::adSlot(string $name, array $cfg): string
TEB\Render::error(int $status, string $msg, array $cfg): string
TEB\Render::esc(string $s): string
```
Markup must match the class names in `docs/design/FINAL.md` exactly.

## app/Weather.php / app/Recipes.php / app/Seo.php / app/Health.php
```php
TEB\Weather::get(array $cfg, ?string $place=null): array   // ['place','current','days','alerts','degraded']
TEB\Recipes::pageModel(PDO,$cfg): array
TEB\Seo::robotsTxt($cfg): string
TEB\Seo::sitemap(PDO,$cfg): string
TEB\Seo::newsSitemap(PDO,$cfg): string
TEB\Seo::rss(PDO,$cfg,?string $section): string
TEB\Seo::articleJsonLd(array $a,$cfg): string
TEB\Health::report(PDO,$cfg): array
```

## app/Router.php + index.php
`index.php` is the only entry point: bootstrap → route → echo. Routes per SPEC §6.
Must serve correctly whether the URL arrived via rewrite or `?r=`.

## File ownership — do not edit a file you do not own
| Files | Owner |
|---|---|
| `config.php`, `app/Config.php`, `app/Paths.php`, `app/Feeds.php` | agent A |
| `app/Xml.php`, `app/Ingest.php`, `cron/ingest.php` | agent B |
| `app/Db.php` | agent C |
| `app/Compose.php` | agent D |
| `app/Render.php`, `assets/js/app.js` | agent E |
| `app/Weather.php`, `app/Recipes.php` | agent F |
| `app/Seo.php`, `app/Health.php`, `.htaccess`, `robots` output | agent G |
| `index.php`, `app/Router.php`, `app/bootstrap.php`, `install.php`, `README.txt` | integrator |
| `assets/css/site.css` | design (already produced as `src/design.css`) |
| `tests/` | each agent adds its own file: `tests/test_<module>.php` |

## Tests
Plain PHP, no PHPUnit. `tests/run.php` discovers `tests/test_*.php`, each returning an
array of `['name' => callable]`. Provide `assertTrue/assertSame/assertContains/assertThrows`
in `tests/lib.php`. `php tests/run.php` exits non-zero on any failure and prints a summary
line `PASS n / FAIL n`.
