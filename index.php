<?php

declare(strict_types=1);

/**
 * The Evening Brief — front controller and the only entry point.
 *
 * Works three ways, deliberately:
 *   1. Pretty URLs via the internal rewrite in .htaccess   /section/us
 *   2. The query fallback when mod_rewrite is unavailable  index.php?r=/section/us
 *   3. From a web root OR any subdirectory, with no edits.
 */

namespace TEB;

use Throwable;

define('TEB_ROOT', __DIR__);
require_once __DIR__ . '/app/bootstrap.php';

// Buffer so a late warning from a module cannot corrupt headers or truncate a page.
ob_start();

try {
    $cfg = App::boot($_SERVER);

    // Static assets: served through PHP only when the web server did not pick
    // them up itself (a locked-down host, or a subdirectory oddity). Apache
    // normally short-circuits these via the -f condition in .htaccess.
    $asset = Paths::currentRoute();
    if ($asset === '/assets/css/site.css' || $asset === '/assets/js/app.js') {
        $file = TEB_ROOT . $asset;
        if (is_file($file)) {
            $type = str_ends_with($asset, '.css') ? 'text/css' : 'application/javascript';
            header('Content-Type: ' . $type . '; charset=utf-8');
            header('Cache-Control: public, max-age=604800');
            ob_end_clean();
            readfile($file);
            exit;
        }
    }

    $pdo = App::db();
    App::ensureContent($pdo, $cfg);

    $route = Paths::currentRoute();
    $res   = Router::dispatch($pdo, $cfg, $route, $_GET);

    $status  = (int) ($res['status'] ?? 200);
    $headers = is_array($res['headers'] ?? null) ? $res['headers'] : [];
    $body    = (string) ($res['body'] ?? '');

    // Conditional GET on cacheable HTML: cheap, and it makes repeat views instant.
    $isHtml    = str_starts_with((string) ($headers['Content-Type'] ?? ''), 'text/html');
    $cacheable = ($headers['Cache-Control'] ?? '') !== 'no-store' && $status === 200;
    if ($isHtml && $cacheable && $body !== '') {
        $etag = '"' . substr(sha1($body), 0, 27) . '"';
        $headers['ETag'] = $etag;
        $inm = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($inm !== '' && ($inm === $etag || $inm === 'W/' . $etag)) {
            http_response_code(304);
            foreach ($headers as $k => $v) {
                if ($k !== 'Content-Type') {
                    header($k . ': ' . $v);
                }
            }
            ob_end_clean();
            exit;
        }
    }

    http_response_code($status);
    foreach ($headers as $k => $v) {
        header($k . ': ' . $v);
    }

    // Discard any stray output a module may have emitted before our real body.
    ob_end_clean();
    echo $body;
} catch (Throwable $e) {
    // Nothing reaches the visitor except a styled page. Never a stack trace,
    // never a blank white screen, and never a 500 that a crawler will remember.
    error_log('[teb] fatal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 120');
    header('Cache-Control: no-store');

    $msg = 'The newsroom is briefly unavailable. Please try again in a moment.';
    try {
        echo Render::error(503, $msg, App::config());
    } catch (Throwable $inner) {
        // Absolute last resort — config or the renderer itself is broken.
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Temporarily unavailable</title>'
            . '<style>body{font:18px/1.6 Georgia,serif;margin:0;display:grid;place-items:center;'
            . 'min-height:100vh;background:#FBFAF7;color:#171511;padding:24px}'
            . 'div{max-width:32rem;text-align:center}h1{font-size:28px;margin:0 0 12px}</style>'
            . '</head><body><div><h1>Temporarily unavailable</h1><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')
            . '</p><p><a href="' . htmlspecialchars((string) ($_SERVER['SCRIPT_NAME'] ?? '/'), ENT_QUOTES, 'UTF-8')
            . '">Try the front page</a></p></div></body></html>';
    }
}
