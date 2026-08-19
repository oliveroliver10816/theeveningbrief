<?php

declare(strict_types=1);

/**
 * Bootstrap — the single place where the application is assembled.
 *
 * Deliberately no autoloader: shared hosting is where clever autoloading goes to
 * die, and twelve explicit requires cost nothing and never surprise anyone.
 */

namespace TEB;

use PDO;
use Throwable;

if (!defined('TEB_ROOT')) {
    define('TEB_ROOT', dirname(__DIR__));
}

require_once TEB_ROOT . '/app/Config.php';
require_once TEB_ROOT . '/app/Paths.php';
require_once TEB_ROOT . '/app/Feeds.php';
require_once TEB_ROOT . '/app/Xml.php';
require_once TEB_ROOT . '/app/Images.php';
require_once TEB_ROOT . '/app/Placeholder.php';
require_once TEB_ROOT . '/app/Db.php';
require_once TEB_ROOT . '/app/Ingest.php';
require_once TEB_ROOT . '/app/Compose.php';
require_once TEB_ROOT . '/app/Render.php';
require_once TEB_ROOT . '/app/Weather.php';
require_once TEB_ROOT . '/app/Recipes.php';
require_once TEB_ROOT . '/app/Seo.php';
require_once TEB_ROOT . '/app/Health.php';
require_once TEB_ROOT . '/app/Router.php';

final class App
{
    /** @var array<string,mixed>|null */
    private static ?array $cfg = null;
    private static ?PDO $pdo = null;
    private static bool $booted = false;

    /**
     * Prepare config + paths. Safe to call from the web or from the CLI.
     *
     * @param array<string,mixed>|null $server
     * @return array<string,mixed>
     */
    public static function boot(?array $server = null): array
    {
        if (self::$booted) {
            return self::$cfg ?? [];
        }

        self::$cfg = Config::load(TEB_ROOT);

        $tz = (string) (self::$cfg['site']['timezone'] ?? 'America/New_York');
        // An invalid timezone in config must not take the site down.
        if (@timezone_open($tz) === false) {
            $tz = 'UTC';
        }
        date_default_timezone_set($tz);

        Paths::init($server ?? $_SERVER, TEB_ROOT);

        self::$booted = true;
        return self::$cfg;
    }

    /** @return array<string,mixed> */
    public static function config(): array
    {
        if (self::$cfg === null) {
            self::boot();
        }
        return self::$cfg ?? [];
    }

    /**
     * Connect and migrate. Migration is idempotent, so calling it per request is
     * safe; on SQLite it is a handful of "CREATE TABLE IF NOT EXISTS" statements.
     */
    public static function db(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $cfg = self::config();
        $pdo = Db::connect(is_array($cfg['db'] ?? null) ? $cfg['db'] : []);
        Db::migrate($pdo);
        Db::upsertSources($pdo, Feeds::all());
        self::$pdo = $pdo;
        return $pdo;
    }

    /**
     * First-run and staleness ingest.
     *
     * The client uploads a ZIP and opens the page; there is no cron yet and no
     * data. Rather than showing an empty site, pull a bounded set of feeds
     * inline — behind the lock, and never fatal. Cron then takes over.
     */
    public static function ensureContent(PDO $pdo, array $cfg): void
    {
        $ing = is_array($cfg['ingest'] ?? null) ? $cfg['ingest'] : [];
        if (empty($ing['enabled']) || empty($ing['auto_on_empty'])) {
            return;
        }

        $count = Db::countArticles($pdo);
        $staleAfter = max(1, (int) ($ing['stale_after_minutes'] ?? 20));
        $last = Db::lastIngestRun($pdo);
        $lastMs = is_array($last) ? (int) ($last['finished_at'] ?? 0) : 0;
        $ageMin = $lastMs > 0 ? (Db::nowMs() - $lastMs) / 60000 : PHP_INT_MAX;

        if ($count > 0 && $ageMin < $staleAfter) {
            return;
        }

        // Empty database: seed so that EVERY front-page section has something in
        // it. Seeding tier 1 alone looks fast but leaves Recipes and Weather
        // blank on a fresh upload, because those publish rarely and sit in the
        // slower tiers — the first thing anyone checks is the section that is
        // empty. Take the best few feeds per front-page section instead, which
        // costs about the same and produces a complete-looking page.
        $only = null;
        if ($count === 0) {
            $perSection = ['us' => 4, 'international' => 3, 'world' => 3, 'weather' => 1, 'recipes' => 3];
            $picked     = [];
            foreach ($perSection as $section => $want) {
                $cands = array_values(array_filter(
                    Feeds::all(),
                    static fn(array $f): bool => ($f['section'] ?? '') === $section
                ));
                usort($cands, static function (array $a, array $b): int {
                    return ((int) ($a['tier'] ?? 3) <=> (int) ($b['tier'] ?? 3))
                        ?: ((float) ($b['weight'] ?? 1) <=> (float) ($a['weight'] ?? 1));
                });
                foreach (array_slice($cands, 0, $want) as $f) {
                    $picked[(string) $f['slug']] = $f;
                }
            }
            $only = array_values($picked);
        }

        $lock = Ingest::lock(Ingest::dataDir($cfg));
        if ($lock === null) {
            return; // another request is already doing it
        }
        try {
            Ingest::run($pdo, $cfg, $only);
        } catch (Throwable $e) {
            error_log('[teb] inline ingest failed: ' . $e->getMessage());
        } finally {
            Ingest::unlock();
        }
    }

    public static function reset(): void
    {
        self::$cfg = null;
        self::$pdo = null;
        self::$booted = false;
    }
}
