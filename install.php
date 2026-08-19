<?php

declare(strict_types=1);

/**
 * Self-check page. Open this straight after uploading; it tells you what is
 * green and, for anything red, what to actually do about it.
 *
 * Safe to leave on the server — it prints no credentials and takes no
 * destructive action — but delete it once the site is up.
 */

namespace TEB;

use Throwable;

define('TEB_ROOT', __DIR__);
require_once __DIR__ . '/app/bootstrap.php';

$cfg  = App::boot($_SERVER);
$name = (string) ($cfg['site']['name'] ?? 'This site');
$e    = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

/** @var array<int,array{ok:bool|null,label:string,detail:string,fix:string}> $checks */
$checks = [];
$add = static function (?bool $ok, string $label, string $detail, string $fix = '') use (&$checks): void {
    $checks[] = ['ok' => $ok, 'label' => $label, 'detail' => $detail, 'fix' => $fix];
};

// --- PHP ---------------------------------------------------------------------
$php = PHP_VERSION;
$add(
    version_compare($php, '8.0.0', '>='),
    'PHP version',
    $php,
    'This site needs PHP 8.0 or newer. In cPanel: Software → Select PHP Version → pick 8.1 or above.'
);

foreach (['pdo' => 'PDO', 'curl' => 'cURL', 'mbstring' => 'mbstring', 'simplexml' => 'SimpleXML', 'json' => 'JSON'] as $ext => $label) {
    $add(extension_loaded($ext), 'Extension: ' . $label, extension_loaded($ext) ? 'loaded' : 'missing',
        'cPanel: Select PHP Version → Extensions → tick ' . $label . '. '
        . 'Heroku or another buildpack host: add "ext-' . $ext . '": "*" to composer.json and redeploy.');
}

$driver = (string) ($cfg['db']['driver'] ?? 'sqlite');
$needed = $driver === 'mysql' ? 'pdo_mysql' : 'pdo_sqlite';
$add(extension_loaded($needed), 'Extension: ' . $needed, extension_loaded($needed) ? 'loaded' : 'missing',
    'Without this the site cannot open its database. cPanel: enable ' . $needed . '. '
    . 'Heroku: add "ext-' . $needed . '": "*" to composer.json (and commit composer.lock) then redeploy. '
    . 'Or switch the driver in config.php.');

// --- writable data directory -------------------------------------------------
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0775, true);
}
$writable = is_dir($dataDir) && is_writable($dataDir);
$add($writable, 'data/ is writable', $writable ? $dataDir : 'not writable',
    'In cPanel File Manager, set the data folder permissions to 755 (or 775 if that fails).');

// --- database ----------------------------------------------------------------
$pdo = null;
try {
    $pdo = App::db();
    $add(true, 'Database', $driver . ' connected, schema ready');
} catch (Throwable $ex) {
    $add(false, 'Database', $ex->getMessage(),
        $driver === 'mysql'
            ? 'Check the host, name, user and password in config.php.'
            : 'The SQLite file lives in data/. Make that folder writable and reload.');
}

// --- content -----------------------------------------------------------------
$count = 0;
if ($pdo !== null) {
    try {
        $count = Db::countArticles($pdo);
        $last  = Db::lastIngestRun($pdo);
        $when  = is_array($last) && (int) ($last['finished_at'] ?? 0) > 0
            ? date('D j M, g:i a', (int) ($last['finished_at'] / 1000))
            : 'never';
        $add($count > 0, 'Stories in the database', $count . ' (last fetch: ' . $when . ')',
            'Press “Fetch stories now” below, or wait for the cron job to run.');
    } catch (Throwable $ex) {
        $add(false, 'Stories in the database', $ex->getMessage());
    }
}

// --- outbound network --------------------------------------------------------
$probe = ['ABC News' => 'https://feeds.abcnews.com/abcnews/usheadlines',
          'NPR'      => 'https://feeds.npr.org/1001/rss.xml',
          'Weather'  => 'https://api.open-meteo.com/v1/forecast?latitude=40.71&longitude=-74.01&current=temperature_2m'];
foreach ($probe as $label => $url) {
    $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        // A real GET, capped by Range, using the SAME user-agent the ingester
        // uses. A HEAD request is not a fair test: several publishers answer
        // HEAD with 403 while serving GET perfectly, which would report a
        // blocked host when nothing is blocked.
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RANGE          => '0-2047',
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => Ingest::userAgent($cfg),
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }
    // 401/403/429 mean the publisher is refusing US, not that the server cannot
    // reach the internet — which is what this check is actually asking.
    $refused  = in_array($code, [401, 403, 429], true);
    $reached  = ($code >= 200 && $code < 400) || $refused;
    $add(
        $reached ? ($refused ? null : true) : false,
        'Can reach ' . $label,
        $code > 0 ? 'HTTP ' . $code . ($refused ? ' — reachable, but this publisher declined this probe' : '') : 'no response',
        $reached
            ? 'Outbound HTTP works. This one feed may still be fetched fine during a real run; check /healthz after a fetch.'
            : 'Your host may block outbound HTTP. Ask them to allow outgoing connections on port 443.'
    );
}

// --- rewrite -----------------------------------------------------------------
$rw = Paths::hasRewrite();
$add($rw ? true : null, 'Pretty URLs (mod_rewrite)',
    $rw ? 'on — links look like /section/us' : 'off — links fall back to ?r=/section/us',
    'This is not an error. The site works either way; pretty URLs are just tidier. '
    . 'If you want them, ask your host to enable mod_rewrite and AllowOverride All.');

$ht = is_file(__DIR__ . '/.htaccess');
$ap = is_file(__DIR__ . '/apache.conf');
$add($ht || $ap, 'Server rules present',
    ($ht ? '.htaccess' : '') . ($ht && $ap ? ' + ' : '') . ($ap ? 'apache.conf' : '')
        . ($ht || $ap ? ' — no redirects in either, by design' : 'neither found'),
    'Many upload tools silently skip dot-files, so .htaccess never arrives. Re-upload with hidden '
    . 'files shown, or deploy apache.conf (Heroku: Procfile → heroku-php-apache2 -C apache.conf /).');

// --- ingest trigger ----------------------------------------------------------
$ranNow = null;
$token  = (string) ($cfg['ingest']['token'] ?? '');
if (($_POST['action'] ?? '') === 'ingest' && $pdo !== null) {
    try {
        $lock = Ingest::lock(Ingest::dataDir($cfg));
        if ($lock === null) {
            $ranNow = ['ok' => false, 'msg' => 'Another fetch is already running. Reload in a moment.'];
        } else {
            // One run is capped by ingest.batch, so a single press would only
            // ever cover part of the roster. Keep going until nothing is left
            // due, or until we run out of time budget.
            $ok = $failed = $new = 0;
            $deadline = microtime(true) + 45.0;
            for ($pass = 0; $pass < 6; $pass++) {
                $r = Ingest::run($pdo, $cfg, null);
                $ok     += (int) ($r['feeds_ok'] ?? 0);
                $failed += (int) ($r['feeds_failed'] ?? 0);
                $new    += (int) ($r['inserted'] ?? 0);
                if ((int) ($r['feeds_ok'] ?? 0) + (int) ($r['feeds_failed'] ?? 0) === 0) {
                    break;
                }
                if (microtime(true) > $deadline) {
                    break;
                }
            }
            Ingest::unlock();
            $ranNow = ['ok' => true, 'msg' => sprintf(
                '%d feeds fetched, %d failed, %d new stories.', $ok, $failed, $new
            )];
        }
    } catch (Throwable $ex) {
        $ranNow = ['ok' => false, 'msg' => $ex->getMessage()];
    }
}

$fails = count(array_filter($checks, static fn(array $c): bool => $c['ok'] === false));
$cron  = 'cd ' . __DIR__ . ' && /usr/local/bin/php cron/ingest.php >/dev/null 2>&1';
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Setup check · <?= $e($name) ?></title>
<style>
:root{--pa:#FBFAF7;--in:#171511;--i2:#55524B;--ru:#DAD6CC;--ok:#1E6B33;--no:#B0271E;--wa:#8A6A12}
@media(prefers-color-scheme:dark){:root{--pa:#141519;--in:#EDEBE6;--i2:#A8A49C;--ru:#33353B;--ok:#5FBF7C;--no:#F08B84;--wa:#D9B454}}
*{box-sizing:border-box}
body{margin:0;background:var(--pa);color:var(--in);font:18px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;padding:32px 20px}
.w{max-width:60rem;margin:0 auto}
h1{font:700 30px/1.2 Georgia,"Times New Roman",serif;margin:0 0 6px}
p.sub{color:var(--i2);margin:0 0 28px}
table{width:100%;border-collapse:collapse;margin:0 0 28px}
td{padding:11px 8px;border-bottom:1px solid var(--ru);vertical-align:top}
td:first-child{width:34px;font-weight:700}
td:nth-child(2){width:34%}
.ok{color:var(--ok)}.no{color:var(--no)}.wa{color:var(--wa)}
.d{color:var(--i2);font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:14px;word-break:break-word}
.fix{display:block;margin-top:6px;color:var(--no);font-size:15px}
.banner{padding:14px 16px;border-left:4px solid;margin:0 0 24px;font-size:17px}
.banner.g{border-color:var(--ok)}.banner.r{border-color:var(--no)}
button{font:600 16px/1 inherit;padding:13px 20px;min-height:44px;background:var(--in);color:var(--pa);border:0;cursor:pointer}
code{background:rgba(128,128,128,.14);padding:2px 6px;font-size:14px;word-break:break-all}
pre{background:rgba(128,128,128,.14);padding:12px;overflow-x:auto;font-size:14px}
a{color:inherit}
</style></head><body><div class="w">

<h1>Setup check</h1>
<p class="sub"><?= $e($name) ?> — run this once after uploading, then delete this file.</p>

<?php if ($ranNow !== null): ?>
  <p class="banner <?= $ranNow['ok'] ? 'g' : 'r' ?>"><?= $e($ranNow['msg']) ?></p>
<?php endif; ?>

<p class="banner <?= $fails === 0 ? 'g' : 'r' ?>">
<?= $fails === 0
    ? 'Everything needed is in place.' . ($count > 0 ? ' The site is ready.' : ' Fetch some stories below and you are done.')
    : $fails . ' item' . ($fails === 1 ? '' : 's') . ' need attention — see the red rows.' ?>
</p>

<table>
<?php foreach ($checks as $c): ?>
  <tr>
    <td class="<?= $c['ok'] === true ? 'ok' : ($c['ok'] === false ? 'no' : 'wa') ?>">
      <?= $c['ok'] === true ? '&#10003;' : ($c['ok'] === false ? '&#10007;' : '&#8226;') ?>
    </td>
    <td><?= $e($c['label']) ?></td>
    <td class="d"><?= $e($c['detail']) ?>
      <?php if ($c['ok'] === false && $c['fix'] !== ''): ?><span class="fix"><?= $e($c['fix']) ?></span><?php endif; ?>
      <?php if ($c['ok'] === null && $c['fix'] !== ''): ?><span class="fix" style="color:var(--i2)"><?= $e($c['fix']) ?></span><?php endif; ?>
    </td>
  </tr>
<?php endforeach; ?>
</table>

<?php if ($pdo !== null): ?>
<form method="post">
  <input type="hidden" name="action" value="ingest">
  <button type="submit">Fetch stories now</button>
</form>
<p class="d" style="margin-top:10px">The first fetch pulls every feed and takes about 20&nbsp;seconds.</p>
<?php endif; ?>

<h2 style="font:700 22px/1.3 Georgia,serif;margin:32px 0 8px">Keep it updating</h2>
<p>Add this as a cron job in cPanel, set to run <strong>every 10 minutes</strong> (<code>*/10 * * * *</code>):</p>
<pre><?= $e($cron) ?></pre>
<p class="d">The PHP path varies by host — cPanel usually shows the right one on its Cron Jobs page.
Without cron the site still updates itself when someone visits, just less predictably.</p>

<h2 style="font:700 22px/1.3 Georgia,serif;margin:32px 0 8px">Then</h2>
<p><a href="<?= $e(Paths::url('/')) ?>">Open the front page</a> &middot;
   <a href="<?= $e(Paths::url('/healthz')) ?>">status JSON</a></p>
<p class="d">Delete <code>install.php</code> when you are finished here.</p>

</div></body></html>
