<?php

declare(strict_types=1);

namespace TEB;

use PDO;
use Throwable;

/**
 * The router. Every route works both as a pretty URL (/section/us) and through
 * the ?r= fallback, because a host that ignores .htaccess must still serve a
 * working site rather than a wall of 404s.
 */
final class Router
{
    /** @return array{status:int,headers:array<string,string>,body:string} */
    public static function dispatch(PDO $pdo, array $cfg, string $route, array $query): array
    {
        $route = '/' . trim($route, '/');
        if ($route === '/') {
            return self::home($pdo, $cfg);
        }

        // /article/{slug}-{id}
        if (preg_match('#^/article/(.+?)-(\d+)$#', $route, $m) === 1) {
            return self::article($pdo, $cfg, (int) $m[2], $m[1]);
        }
        // Bare /article/{id} — tolerated, canonicalised.
        if (preg_match('#^/article/(\d+)$#', $route, $m) === 1) {
            return self::article($pdo, $cfg, (int) $m[1], null);
        }

        if (preg_match('#^/section/([a-z0-9-]+)$#', $route, $m) === 1) {
            return self::section($pdo, $cfg, $m[1], max(1, (int) ($query['p'] ?? 1)));
        }

        switch ($route) {
            case '/weather':
                return self::weather($pdo, $cfg, isset($query['place']) ? (string) $query['place'] : null);
            case '/recipes':
                return self::recipes($pdo, $cfg);
            case '/search':
                return self::search($pdo, $cfg, trim((string) ($query['q'] ?? '')));
            case '/sources':
                return self::sources($pdo, $cfg);
            case '/about':
                return self::about($pdo, $cfg);
            case '/placeholder.svg':
                return [
                    'status'  => 200,
                    'headers' => [
                        'Content-Type'  => 'image/svg+xml; charset=utf-8',
                        'Cache-Control' => 'public, max-age=31536000, immutable',
                    ],
                    'body'    => Placeholder::svg(
                        (string) ($query['s'] ?? ''),
                        (int) ($query['v'] ?? 0),
                        $cfg
                    ),
                ];
            case '/healthz':
                return self::healthz($pdo, $cfg);
            case '/admin/ingest':
                return self::adminIngest($pdo, $cfg, $query);
            case '/robots.txt':
                return self::text(Seo::robotsTxt($cfg), 'text/plain; charset=utf-8', 3600);
            case '/sitemap.xml':
                return self::text(Seo::sitemap($pdo, $cfg), 'application/xml; charset=utf-8', 3600);
            case '/sitemap-news.xml':
                return self::text(Seo::newsSitemap($pdo, $cfg), 'application/xml; charset=utf-8', 600);
            case '/feed.xml':
                return self::text(Seo::rss($pdo, $cfg, null), 'application/rss+xml; charset=utf-8', 900);
        }

        if (preg_match('#^/sitemap-(\d+)\.xml$#', $route, $m) === 1) {
            return self::text(Seo::sitemap($pdo, $cfg, (int) $m[1]), 'application/xml; charset=utf-8', 3600);
        }
        if (preg_match('#^/feed/([a-z0-9-]+)\.xml$#', $route, $m) === 1) {
            return self::text(Seo::rss($pdo, $cfg, $m[1]), 'application/rss+xml; charset=utf-8', 900);
        }

        return self::notFound($pdo, $cfg);
    }

    // ---------------------------------------------------------------- pages

    private static function home(PDO $pdo, array $cfg): array
    {
        $rows = Db::recentArticles($pdo, ['limit' => 400, 'window_hours' => 96]);

        // Recipes and weather publish far less often than the wires, so they fall off
        // the end of a flat "most recent 400" window and the block renders empty.
        // Top them up explicitly, then de-duplicate by id.
        $seen = [];
        foreach ($rows as $r) {
            $seen[(int) $r['id']] = true;
        }
        foreach (['recipes' => 24, 'weather' => 12, 'international' => 40, 'world' => 40] as $sec => $n) {
            foreach (Db::recentArticles($pdo, ['section' => [$sec], 'limit' => $n]) as $r) {
                if (!isset($seen[(int) $r['id']])) {
                    $seen[(int) $r['id']] = true;
                    $rows[] = $r;
                }
            }
        }

        $model = Compose::home($rows, $cfg, Db::nowMs());

        $model['weather'] = self::weatherSafe($cfg);
        $model['sources'] = self::sourceNames($pdo);

        $body = Render::home($model, $cfg);
        return self::html($body, 200, (int) ($cfg['cache']['home_seconds'] ?? 120));
    }

    private static function section(PDO $pdo, array $cfg, string $slug, int $page): array
    {
        $meta = Feeds::section($slug);
        if ($meta === null) {
            return self::notFound($pdo, $cfg);
        }

        // Weather and recipes are sections in the nav but have their own templates.
        if ($slug === 'weather') {
            return self::weather($pdo, $cfg, null);
        }
        if ($slug === 'recipes') {
            return self::recipes($pdo, $cfg);
        }

        $per = 24;
        $rows = Db::recentArticles($pdo, [
            'section'  => [$slug],
            'limit'    => $per + 1,
            'offset'   => ($page - 1) * $per,
        ]);
        $hasMore = count($rows) > $per;
        $rows = array_slice($rows, 0, $per);

        $model = [
            'slug'        => $slug,
            'label'       => (string) ($meta['label'] ?? ucfirst($slug)),
            'note'        => (string) ($meta['note'] ?? ''),
            'items'       => $rows,
            'page'        => $page,
            'pages'       => $hasMore ? $page + 1 : $page,
            'template'    => '/section/' . $slug,
            'canonical'   => Paths::absolute('/section/' . $slug . ($page > 1 ? '?p=' . $page : '')),
            'route'       => '/section/' . $slug,
            'href'        => '/section/' . $slug,
            'description' => (string) ($meta['note'] ?? ''),
            'ticker'      => self::ticker($pdo, $cfg),
            'weather'     => self::weatherSafe($cfg),
            'sources'     => self::sourceNames($pdo),
        ];

        return self::html(Render::section($model, $cfg), 200, (int) ($cfg['cache']['section_seconds'] ?? 300));
    }

    private static function article(PDO $pdo, array $cfg, int $id, ?string $slug): array
    {
        $a = Db::articleById($pdo, $id);
        if ($a === null) {
            return self::notFound($pdo, $cfg);
        }

        // Canonical slug redirect. This is the ONE intentional redirect in the
        // project and it lives here, at the application level — never in .htaccess.
        $want = Render::slug((string) ($a['title'] ?? ''));
        if ($want !== '' && $slug !== $want) {
            return [
                'status'  => 301,
                'headers' => ['Location' => Paths::url(Seo::articlePath($a)), 'Cache-Control' => 'public, max-age=600'],
                'body'    => '',
            ];
        }

        $model = [
            'article'   => $a,
            'related'   => Db::relatedArticles($pdo, $a, 6),
            'jsonld'    => Seo::articleJsonLd($a, $cfg),
            'canonical' => Paths::absolute(Seo::articlePath($a)),
            'route'     => Seo::articlePath($a),
            'ticker'    => self::ticker($pdo, $cfg),
            'weather'   => self::weatherSafe($cfg),
            'sources'   => self::sourceNames($pdo),
        ];

        return self::html(Render::article($model, $cfg), 200, (int) ($cfg['cache']['article_seconds'] ?? 600));
    }

    private static function weather(PDO $pdo, array $cfg, ?string $place): array
    {
        $w = self::weatherSafe($cfg, $place);
        $rows = Db::recentArticles($pdo, ['section' => ['weather'], 'limit' => 18]);

        $model = [
            'slug'        => 'weather',
            'label'       => 'Weather',
            'note'        => 'Current conditions, the five-day outlook and any active National Weather Service alerts.',
            'items'       => $rows,
            'template'    => '/weather',
            'canonical'   => Paths::absolute('/weather'),
            'route'       => '/weather',
            'href'        => '/weather',
            'description' => 'US weather: current conditions, five-day forecast and active National Weather Service alerts.',
            'ticker'      => self::ticker($pdo, $cfg),
            'weather'     => $w,
            'sources'     => self::sourceNames($pdo),
        ];

        return self::html(Render::section($model, $cfg), 200, 600);
    }

    private static function recipes(PDO $pdo, array $cfg): array
    {
        try {
            $rm = Recipes::pageModel($pdo, $cfg);
        } catch (Throwable $e) {
            error_log('[teb] recipes: ' . $e->getMessage());
            $rm = ['items' => [], 'lead' => null];
        }

        // pageModel's 'items' is ALREADY lead + grid, with size and lazy set per
        // row. Prepending 'lead' again is what put the same recipe on the page
        // twice.
        $items = is_array($rm['items'] ?? null) ? $rm['items'] : [];

        $model = [
            'slug'        => 'recipes',
            'label'       => (string) ($rm['label'] ?? 'Recipes'),
            'note'        => (string) ($rm['blurb'] ?? 'Something to cook tonight, from kitchens we read every day.'),
            'items'       => $items,
            'grid'        => 'block-grid block-grid--3',
            'template'    => '/recipes',
            'canonical'   => Paths::absolute('/recipes'),
            'route'       => '/recipes',
            'href'        => '/recipes',
            'description' => 'Weeknight recipes and cooking ideas from independent food writers.',
            'ticker'      => self::ticker($pdo, $cfg),
            'weather'     => self::weatherSafe($cfg),
            'sources'     => self::sourceNames($pdo),
        ];

        return self::html(Render::section($model, $cfg), 200, 900);
    }

    private static function search(PDO $pdo, array $cfg, string $q): array
    {
        $items = $q === '' ? [] : Db::searchArticles($pdo, $q, 40);

        $model = [
            'slug'      => 'search',
            'label'     => 'Results',
            'search'    => true,
            'q'         => $q,
            'items'     => $items,
            'total'     => count($items),
            'template'  => '/search',
            'canonical' => Paths::absolute('/search'),
            'route'     => '/search',
            'href'      => '/search',
            'noindex'   => true,
            'ticker'    => self::ticker($pdo, $cfg),
            'weather'   => self::weatherSafe($cfg),
            'sources'   => self::sourceNames($pdo),
        ];

        return self::html(Render::section($model, $cfg), 200, 0);
    }

    private static function sources(PDO $pdo, array $cfg): array
    {
        $rows = Db::sources($pdo, true);
        $li = '';
        foreach ($rows as $s) {
            $name = Render::esc((string) ($s['name'] ?? ''));
            $sec  = Render::esc((string) ($s['section'] ?? ''));
            $url  = (string) ($s['url'] ?? $s['feed_url'] ?? '');
            $li .= '<li><strong>' . $name . '</strong> <span class="card-src">' . $sec . '</span>'
                . ($url !== '' ? ' — <a class="card-out" href="' . Render::esc($url) . '" rel="noopener nofollow external" target="_blank">' . Render::esc(parse_url($url, PHP_URL_HOST) ?: $url) . '</a>' : '')
                . '</li>';
        }

        $body = '<section class="block wrap"><div class="block-head"><p><span class="block-label">Sources</span></p></div>'
            . '<p class="result-note">Every headline here is gathered from a publisher’s own public feed. '
            . 'We show the headline, the summary the publisher supplies, and a link back to them. '
            . 'The full article always stays with the newsroom that reported it.</p>'
            . '<ul class="source-list">' . $li . '</ul></section>';

        return self::html(Render::layout([
            'cfg'         => $cfg,
            'title'       => 'Sources',
            'description' => 'The newsrooms whose public feeds we gather.',
            'canonical'   => Paths::absolute('/sources'),
            'route'       => '/sources',
            'body'        => $body,
            'ticker'      => self::ticker($pdo, $cfg),
            'weather'     => self::weatherSafe($cfg),
            'sources'     => self::sourceNames($pdo),
        ]), 200, 3600);
    }

    private static function about(PDO $pdo, array $cfg): array
    {
        $name = Render::esc((string) ($cfg['site']['name'] ?? ''));
        $body = '<section class="block wrap"><div class="block-head"><p><span class="block-label">About</span></p></div>'
            . '<p class="result-note">' . $name . ' gathers the day’s reporting from established newsrooms and lays it '
            . 'out in one place: the United States first, then the wider world, with the weather and something to cook.</p>'
            . '<p class="result-note">We are an aggregator, not a newsroom. Every card shows the publisher’s own headline '
            . 'and their own summary, and every link goes back to them. We do not reproduce articles.</p>'
            . '<p class="result-note">See <a href="' . Render::esc(Paths::url('/sources')) . '">our sources</a>.</p>'
            . '</section>';

        return self::html(Render::layout([
            'cfg'         => $cfg,
            'title'       => 'About',
            'description' => 'What this site is, and what it is not.',
            'canonical'   => Paths::absolute('/about'),
            'route'       => '/about',
            'body'        => $body,
            'ticker'      => self::ticker($pdo, $cfg),
            'weather'     => self::weatherSafe($cfg),
            'sources'     => self::sourceNames($pdo),
        ]), 200, 3600);
    }

    /**
     * Fetch trigger for an external scheduler.
     *
     * Guarded by ingest.token. With no token set the route is CLOSED, not open —
     * an unauthenticated endpoint that makes 34 outbound requests is a free
     * amplifier for anyone who finds it.
     */
    private static function adminIngest(PDO $pdo, array $cfg, array $query): array
    {
        $want = (string) ($cfg['ingest']['token'] ?? '');
        if ($want === '') {
            return self::json(503, ['ok' => false, 'error' => 'ingest token not configured']);
        }

        $given = (string) ($query['token'] ?? '');
        if ($given === '') {
            $hdr = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
            if (stripos($hdr, 'bearer ') === 0) {
                $given = trim(substr($hdr, 7));
            }
        }
        // Constant-time compare so the token cannot be guessed a byte at a time.
        if (!hash_equals($want, $given)) {
            return self::json(403, ['ok' => false, 'error' => 'forbidden']);
        }

        $lock = Ingest::lock(Ingest::dataDir($cfg));
        if ($lock === null) {
            // Already running. Not an error — the previous tick is still going.
            return self::json(200, ['ok' => true, 'skipped' => 'already running']);
        }

        try {
            $r = Ingest::run($pdo, $cfg, null);
            $pruned = 0;
            $backfill = ['checked' => 0, 'measured' => 0, 'dropped' => 0];
            if ((int) ($query['prune'] ?? 0) === 1) {
                $pruned = Db::pruneOld($pdo, (int) ($cfg['ingest']['retention_days'] ?? 30));
                // Drain the measuring backlog a batch at a time on the hourly
                // tick, so images that missed their run still get sized.
                $backfill = Ingest::backfillImages($pdo, $cfg, 120, 15.0);
            }
            return self::json(200, [
                'ok'       => true,
                'feeds_ok' => (int) ($r['feeds_ok'] ?? 0),
                'failed'   => (int) ($r['feeds_failed'] ?? 0),
                'inserted' => (int) ($r['inserted'] ?? 0),
                'skipped'  => (int) ($r['skipped'] ?? 0),
                'pruned'   => $pruned,
                'images'   => $backfill,
                'articles' => Db::countArticles($pdo),
            ]);
        } catch (Throwable $e) {
            error_log('[teb] admin ingest: ' . $e->getMessage());
            return self::json(500, ['ok' => false, 'error' => 'ingest failed']);
        } finally {
            Ingest::unlock();
        }
    }

    private static function json(int $status, array $payload): array
    {
        return [
            'status'  => $status,
            'headers' => ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
            'body'    => (string) json_encode($payload, JSON_UNESCAPED_SLASHES),
        ];
    }

    private static function healthz(PDO $pdo, array $cfg): array
    {
        $report = Health::report($pdo, $cfg);
        return [
            'status'  => Health::statusCode($report),
            'headers' => [
                'Content-Type'  => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store',
            ],
            'body'    => Health::json($report),
        ];
    }

    public static function notFound(PDO $pdo, array $cfg): array
    {
        return self::html(
            Render::error(404, 'That page is not here. It may have moved, or the link may be old.', $cfg),
            404,
            0
        );
    }

    // ---------------------------------------------------------------- helpers

    /** Headlines for the ticker on pages that are not the front page. */
    private static function ticker(PDO $pdo, array $cfg): array
    {
        $n = max(1, (int) ($cfg['compose']['ticker_count'] ?? 12));
        return Db::recentArticles($pdo, ['limit' => $n]);
    }

    /** Weather must never take a page down. */
    private static function weatherSafe(array $cfg, ?string $place = null): ?array
    {
        try {
            return Weather::get($cfg, $place);
        } catch (Throwable $e) {
            error_log('[teb] weather: ' . $e->getMessage());
            return null;
        }
    }

    /** @return array<int,string> */
    private static function sourceNames(PDO $pdo): array
    {
        try {
            $out = [];
            foreach (Db::sources($pdo, true) as $s) {
                $n = trim((string) ($s['name'] ?? ''));
                if ($n !== '') {
                    // "ABC News — Top Stories" reads as "ABC News" in a credit line.
                    $out[explode(' — ', $n)[0]] = true;
                }
            }
            return array_slice(array_keys($out), 0, 24);
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function html(string $body, int $status, int $sMaxAge): array
    {
        $cc = $sMaxAge > 0
            ? 'public, max-age=0, s-maxage=' . $sMaxAge . ', stale-while-revalidate=600'
            : 'no-store';
        return [
            'status'  => $status,
            'headers' => ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => $cc],
            'body'    => $body,
        ];
    }

    private static function text(string $body, string $type, int $maxAge): array
    {
        return [
            'status'  => 200,
            'headers' => [
                'Content-Type'  => $type,
                'Cache-Control' => 'public, max-age=' . max(0, $maxAge),
            ],
            'body'    => $body,
        ];
    }
}
