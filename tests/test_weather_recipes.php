<?php

declare(strict_types=1);

/**
 * app/Weather.php and app/Recipes.php.
 *
 * Every upstream response in here was recorded from the real service with curl
 * and lives under tests/fixtures/:
 *
 *   open-meteo-forecast.json        api.open-meteo.com/v1/forecast, New York, 5 days
 *   weather-gov-alerts.json         api.weather.gov/alerts/active?area=IN, two Flood Watches
 *   weather-gov-alerts-empty.json   the same endpoint for a state with nothing active
 *   budgetbytes.xml                 a WordPress feed with a full recipe card in content:encoded
 *   smittenkitchen.xml              a WordPress feed that ships prose and no recipe card
 *   loveandlemons.xml               a WordPress feed that ships a short excerpt only
 *   themealdb-random.json           themealdb.com/api/json/v1/1/random.php
 *
 * They are served over a local php -S so the code under test goes through cURL,
 * redirects, gzip and the JSON decoder exactly as it does in production, while
 * the suite still runs offline and deterministically. The same server serves a
 * script that answers 500 and one that answers 200 with HTML, which is how the
 * degraded paths are exercised.
 */

require_once __DIR__ . '/lib.php';
teb_require_app('Config', 'Feeds', 'Xml', 'Db', 'Weather', 'Recipes');

use TEB\Db;
use TEB\Recipes;
use TEB\Weather;

// ---------------------------------------------------------------------------
//  a local server over the recorded fixtures
// ---------------------------------------------------------------------------

/**
 * @return array{base:string,dir:string}
 */
function twr_server(): array
{
    static $server = null;
    if ($server !== null) {
        return $server;
    }

    $dir = teb_tmp_dir('teb-upstream');
    $fx  = __DIR__ . '/fixtures/';

    copy($fx . 'open-meteo-forecast.json', $dir . '/forecast.json');
    copy($fx . 'themealdb-random.json', $dir . '/meal.json');
    copy($fx . 'weather-gov-alerts-empty.json', $dir . '/alerts-empty.json');

    // The alerts endpoint records the raw query string it was called with, so a
    // test can prove what actually went over the wire rather than trusting the
    // string the builder returned.
    file_put_contents($dir . '/alerts.php', '<?php' . "\n"
        . 'file_put_contents(__DIR__ . "/last-alerts-query.txt", (string) ($_SERVER["QUERY_STRING"] ?? ""));' . "\n"
        . 'file_put_contents(__DIR__ . "/last-alerts-ua.txt", (string) ($_SERVER["HTTP_USER_AGENT"] ?? ""));' . "\n"
        . '$n = (int) @file_get_contents(__DIR__ . "/alerts-hits.txt");' . "\n"
        . 'file_put_contents(__DIR__ . "/alerts-hits.txt", (string) ($n + 1));' . "\n"
        . 'if (is_file(__DIR__ . "/alerts-fail.flag")) { http_response_code(500); echo "down"; return; }' . "\n"
        . 'header("Content-Type: application/geo+json");' . "\n"
        . 'readfile(__DIR__ . "/alerts-body.json");' . "\n");
    copy($fx . 'weather-gov-alerts.json', $dir . '/alerts-body.json');

    // The forecast endpoint counts its hits, which is how the cache is proved,
    // and answers 500 while a flag file is present, which is how the stale-cache
    // fallback is proved: an upstream that starts failing keeps its address, so
    // the cache key must not change with it.
    file_put_contents($dir . '/forecast.php', '<?php' . "\n"
        . '$n = (int) @file_get_contents(__DIR__ . "/forecast-hits.txt");' . "\n"
        . 'file_put_contents(__DIR__ . "/forecast-hits.txt", (string) ($n + 1));' . "\n"
        . 'if (is_file(__DIR__ . "/forecast-fail.flag")) { http_response_code(500); echo "down"; return; }' . "\n"
        . 'header("Content-Type: application/json");' . "\n"
        . 'readfile(__DIR__ . "/forecast.json");' . "\n");

    file_put_contents($dir . '/meal.php', '<?php' . "\n"
        . 'if (is_file(__DIR__ . "/meal-fail.flag")) { http_response_code(500); echo "down"; return; }' . "\n"
        . 'header("Content-Type: application/json");' . "\n"
        . 'readfile(__DIR__ . "/meal.json");' . "\n");

    file_put_contents($dir . '/empty.php', '<?php' . "\n"
        . 'header("Content-Type: application/json");' . "\n");

    file_put_contents($dir . '/boom.php', '<?php' . "\n"
        . 'http_response_code(500);' . "\n"
        . 'header("Content-Type: text/plain");' . "\n"
        . 'echo "upstream is having a bad minute";' . "\n");

    file_put_contents($dir . '/nonsense.php', '<?php' . "\n"
        . 'header("Content-Type: text/html");' . "\n"
        . 'echo "<!doctype html><html><body><h1>Service temporarily unavailable</h1></body></html>";' . "\n");

    file_put_contents($dir . '/no-meals.php', '<?php' . "\n"
        . 'header("Content-Type: application/json");' . "\n"
        . 'echo "{\"meals\":null}";' . "\n");

    $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        teb_fail('could not open a probe socket to find a free port: ' . $errstr);
    }
    $name = (string) stream_socket_get_name($probe, false);
    $port = (int) substr($name, (int) strrpos($name, ':') + 1);
    fclose($probe);

    $cmd  = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($dir);
    $pipes = [];
    $proc  = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
    if (!is_resource($proc)) {
        teb_fail('could not start php -S on port ' . $port);
    }

    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $sock = @fsockopen('127.0.0.1', $port, $eno, $estr, 0.2);
        if (is_resource($sock)) {
            fclose($sock);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    if (!$ready) {
        teb_fail('php -S never came up on port ' . $port);
    }

    register_shutdown_function(static function () use ($proc, $pipes): void {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        @proc_terminate($proc);
        @proc_close($proc);
    });

    $server = ['base' => 'http://127.0.0.1:' . $port, 'dir' => $dir];

    return $server;
}

/** A port nothing is listening on, for the refused-connection paths. */
function twr_dead_port(): int
{
    static $port = null;
    if ($port !== null) {
        return $port;
    }
    $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        teb_fail('could not open a probe socket: ' . $errstr);
    }
    $name = (string) stream_socket_get_name($probe, false);
    $port = (int) substr($name, (int) strrpos($name, ':') + 1);
    fclose($probe);                     // closed again, so the port refuses

    return $port;
}

/**
 * A config with the upstreams pointed at the local server and data/ pointed at
 * a scratch directory, so nothing here touches the real cache.
 *
 * @param  array<string,mixed> $over
 * @return array<string,mixed>
 */
function twr_cfg(array $over = []): array
{
    $s   = twr_server();
    $cfg = [
        'root' => teb_root(),
        'site' => [
            'name'       => 'Test Paper',
            'short_name' => 'TP',
            'domain'     => 'example.org',
            'timezone'   => 'America/New_York',
            'locale'     => 'en_US',
        ],
        'db'      => ['driver' => 'sqlite', 'sqlite_path' => teb_tmp_dir('teb-wxdb') . '/news.sqlite'],
        'paths'   => ['data' => teb_tmp_dir('teb-wxdata')],
        'weather' => [
            'default_place' => 'new-york',
            'places'        => [],
            'endpoints'     => [
                'forecast' => $s['base'] . '/forecast.php',
                'alerts'   => $s['base'] . '/alerts.php',
            ],
        ],
        'recipes' => [
            'endpoints' => ['meal_random' => $s['base'] . '/meal.php'],
        ],
    ];

    foreach ($over as $path => $value) {
        $keys = explode('.', $path);
        $node = &$cfg;
        foreach ($keys as $k) {
            if (!isset($node[$k]) || !is_array($node[$k])) {
                $node[$k] = [];
            }
            $node = &$node[$k];
        }
        $node = $value;
        unset($node);
    }

    return $cfg;
}

function twr_hits(string $which): int
{
    $f = twr_server()['dir'] . '/' . $which . '-hits.txt';

    return is_file($f) ? (int) file_get_contents($f) : 0;
}

/** Make one upstream start failing at the SAME address it already had. */
function twr_fail(string $which, bool $on = true): void
{
    $flag = twr_server()['dir'] . '/' . $which . '-fail.flag';
    if ($on) {
        file_put_contents($flag, '1');
    } else {
        @unlink($flag);
    }
}

function twr_reset_hits(): void
{
    foreach (['forecast', 'alerts'] as $w) {
        @unlink(twr_server()['dir'] . '/' . $w . '-hits.txt');
    }
}

/** The raw text of one fixture. */
function twr_fixture(string $name): string
{
    $path = __DIR__ . '/fixtures/' . $name;
    assertFileExists($path, 'the recorded fixture must be in the repository');

    return (string) file_get_contents($path);
}

/**
 * One feed item as the parser sees it, plus the raw content:encoded the feed
 * carried — which is what a full recipe card lives in.
 *
 * @return array<string,mixed>
 */
function twr_feed_item(string $fixture, int $index): array
{
    $raw    = twr_fixture($fixture);
    $parsed = TEB\Xml::parseFeed($raw);
    assertTrue(isset($parsed['items'][$index]), $fixture . ' item ' . $index . ' must exist');

    $xml  = simplexml_load_string($raw);
    $item = $xml->channel->item[$index];
    $body = (string) $item->children('http://purl.org/rss/1.0/modules/content/')->encoded;
    if ($body === '') {
        $body = (string) $item->description;
    }

    return [
        'title'        => $parsed['items'][$index]['title'],
        'summary'      => $parsed['items'][$index]['summary'],
        'url'          => $parsed['items'][$index]['url'],
        'image_url'    => $parsed['items'][$index]['image_url'],
        'content_html' => $body,
    ];
}

/** Every item in a feed fixture, in the shape enrichRecipe() takes. */
function twr_feed_items(string $fixture): array
{
    $parsed = TEB\Xml::parseFeed(twr_fixture($fixture));
    $out    = [];
    foreach (array_keys($parsed['items']) as $i) {
        $out[] = twr_feed_item($fixture, (int) $i);
    }

    return $out;
}

/** A SQLite database with the schema on it. */
function twr_db(array $cfg): PDO
{
    $p = Db::connect($cfg);
    Db::migrate($p);

    return $p;
}

/** @param array<int,array<string,mixed>> $rows */
function twr_insert(PDO $p, array $rows): void
{
    $now  = (int) (microtime(true) * 1000);
    $made = [];
    foreach ($rows as $i => $r) {
        $made[] = [
            'source_slug'  => $r['source_slug'] ?? 'budget-bytes',
            'source_name'  => $r['source_name'] ?? 'Budget Bytes',
            'section'      => $r['section'] ?? 'recipes',
            'url'          => $r['url'] ?? ('https://example.com/recipe-' . $i),
            'title'        => $r['title'] ?? ('Recipe ' . $i),
            'summary'      => $r['summary'] ?? '',
            'image_url'    => $r['image_url'] ?? '',
            'published_at' => $r['published_at'] ?? ($now - ($i * 600000)),
            'fetched_at'   => $now,
            'guid'         => $r['url'] ?? ('https://example.com/recipe-' . $i),
        ];
    }
    Db::insertArticles($p, $made);
}

/**
 * Every point an SVG path visits, in absolute user units.
 *
 * Walks the command list rather than reading the numbers, because relative
 * commands carry deltas: 'a5.4 5.4 0 0 0 .5-10.8' moves 10.8 units UP from
 * wherever the pen is, and is not a coordinate at all.
 *
 * Curve control points are ignored on purpose; only the on-path endpoints are
 * returned, which is what a coordinate typo actually shows up in.
 *
 * @return array<int,array{0:float,1:float}>
 */
function twr_path_points(string $d): array
{
    $counts = ['m' => 2, 'l' => 2, 'h' => 1, 'v' => 1, 'c' => 6, 's' => 4, 'q' => 4, 't' => 2, 'a' => 7, 'z' => 0];

    preg_match_all('~[MmLlHhVvCcSsQqTtAaZz]|-?\d*\.?\d+(?:[eE][-+]?\d+)?~', $d, $m);
    $tokens = $m[0];

    $points  = [];
    $x = 0.0;
    $y = 0.0;
    $startX = 0.0;
    $startY = 0.0;
    $cmd = '';
    $i = 0;

    while ($i < count($tokens)) {
        $t = $tokens[$i];
        if (preg_match('~^[A-Za-z]$~', $t) === 1) {
            $cmd = $t;
            $i++;
            if (strtolower($cmd) === 'z') {
                $x = $startX;
                $y = $startY;
                $points[] = [$x, $y];
                continue;
            }
        }
        if ($cmd === '') {
            teb_fail('path data begins without a command: ' . $d);
        }

        $lower = strtolower($cmd);
        $need  = $counts[$lower] ?? 2;
        $args  = [];
        for ($k = 0; $k < $need; $k++) {
            if (!isset($tokens[$i])) {
                teb_fail('path data ran out of arguments for "' . $cmd . '": ' . $d);
            }
            $args[] = (float) $tokens[$i];
            $i++;
        }

        $relative = $cmd === $lower;
        if ($lower === 'h') {
            $x = $relative ? $x + $args[0] : $args[0];
        } elseif ($lower === 'v') {
            $y = $relative ? $y + $args[0] : $args[0];
        } else {
            $nx = $args[$need - 2];
            $ny = $args[$need - 1];
            $x  = $relative ? $x + $nx : $nx;
            $y  = $relative ? $y + $ny : $ny;
        }

        if ($lower === 'm') {
            $startX = $x;
            $startY = $y;
            // A second coordinate pair after a moveto is an implicit lineto.
            $cmd = $relative ? 'l' : 'L';
        }

        $points[] = [$x, $y];
    }

    return $points;
}

/** The WMO codes Open-Meteo documents. Frozen here on purpose. */
const TWR_DOCUMENTED_WMO = [
    0, 1, 2, 3, 45, 48, 51, 53, 55, 56, 57, 61, 63, 65, 66, 67,
    71, 73, 75, 77, 80, 81, 82, 85, 86, 95, 96, 99,
];

return [

    // =====================================================================
    //  WMO codes
    // =====================================================================

    'the WMO mapping is total over every documented code' => function (): void {
        $codes = Weather::codes();

        // Positive control: the two lists must be the same set, or "total over
        // the documented set" is only a statement about whatever we happened to
        // implement.
        sort($codes);
        $documented = TWR_DOCUMENTED_WMO;
        sort($documented);
        assertSame($documented, $codes, 'the implemented set must be exactly the documented set');

        foreach ($documented as $code) {
            $d = Weather::describe($code);

            assertTrue($d['known'], 'code ' . $code . ' must be known');
            assertNotSame('', $d['label'], 'code ' . $code . ' needs a label');
            assertNotSame('', $d['short'], 'code ' . $code . ' needs a short label');
            assertNotSame('', $d['icon_key'], 'code ' . $code . ' needs an icon');

            foreach (['undefined', 'null', 'array', 'NaN'] as $poison) {
                assertNotContains($poison, $d['label'], 'code ' . $code . ' label');
                assertNotContains($poison, $d['short'], 'code ' . $code . ' short label');
            }

            assertContains('<svg', Weather::icon($code), 'code ' . $code . ' icon');
            assertContains('<svg', Weather::icon($code, false), 'code ' . $code . ' night icon');
            assertSame($d['label'], Weather::label($code));
            assertSame($d['short'], Weather::shortLabel($code));
        }
    },

    'an undocumented code gets a neutral label, never the word undefined' => function (): void {
        foreach ([-1, 4, 7, 39, 100, 999, PHP_INT_MAX] as $code) {
            $d = Weather::describe($code);

            assertFalse($d['known'], 'code ' . $code . ' must not claim to be known');
            assertNotSame('', $d['label'], 'code ' . $code . ' still needs a label');
            assertNotSame('', $d['short'], 'code ' . $code . ' still needs a short label');
            assertNotContains('undefined', strtolower($d['label']));
            assertNotContains('undefined', strtolower($d['short']));
            assertContains('<svg', Weather::icon($code));

            // And it must not claim weather it has no evidence for.
            foreach (['sunny', 'rain', 'snow', 'clear'] as $claim) {
                assertNotContains($claim, strtolower($d['label']), 'code ' . $code . ' must not invent a condition');
            }
        }
    },

    'every icon is well-formed SVG that stays inside its own viewBox' => function (): void {
        $keys = [];
        foreach (array_merge(TWR_DOCUMENTED_WMO, [-1]) as $code) {
            $keys[Weather::describe($code)['icon_key']] = true;
        }
        assertGreaterThanOrEqual(9, count($keys), 'the icon set must actually differentiate conditions');

        foreach (array_keys($keys) as $key) {
            foreach ([true, false] as $day) {
                $svg = Weather::iconFor((string) $key, $day);

                $prev = libxml_use_internal_errors(true);
                $doc  = simplexml_load_string($svg);
                libxml_clear_errors();
                libxml_use_internal_errors($prev);
                assertTrue($doc !== false, 'icon ' . $key . ' must be well-formed XML: ' . $svg);

                assertContains('viewBox="0 0 32 32"', $svg, 'icon ' . $key);
                assertContains('aria-hidden="true"', $svg, 'icon ' . $key . ' is decorative');
                assertContains('currentColor', $svg, 'icon ' . $key . ' must inherit its colour');

                // A point outside the box is invisible on the page. This is not
                // hypothetical: a lightning bolt was drawn to y=34 first.
                //
                // The points have to be WALKED, not grepped. Half of these paths
                // move in relative steps, so the raw numbers in a 'd' attribute
                // are deltas — a naive sweep reads the cloud's "-10.8" as a
                // coordinate off the top of the icon, and would equally miss a
                // relative step that really did leave the box.
                $points = [];
                foreach ($doc->children() as $child) {
                    $name = $child->getName();
                    if ($name === 'path') {
                        foreach (twr_path_points((string) $child['d']) as $pt) {
                            $points[] = $pt;
                        }
                    } elseif ($name === 'circle') {
                        $cx = (float) $child['cx'];
                        $cy = (float) $child['cy'];
                        $r  = (float) $child['r'];
                        $points[] = [$cx - $r, $cy - $r];
                        $points[] = [$cx + $r, $cy + $r];
                    } else {
                        teb_fail('icon ' . $key . ' draws with an unhandled element: ' . $name);
                    }
                }
                assertGreaterThan(0, count($points), 'icon ' . $key . ' must actually draw something');

                foreach ($points as $pt) {
                    assertTrue(
                        $pt[0] >= -2.0 && $pt[0] <= 34.0 && $pt[1] >= -2.0 && $pt[1] <= 34.0,
                        'icon ' . $key . ' reaches ' . $pt[0] . ',' . $pt[1] . ' — outside the 0-32 box'
                    );
                }
            }
        }
    },

    'the icon bounds check can actually fail' => function (): void {
        // Positive control. Without this the check above proves only that the
        // walker returned something, not that it would object to anything.

        // The lightning bolt exactly as it was first written — it ran to y=34,
        // six units below the bottom of the icon, and rendered clipped.
        $bad = twr_path_points('M17.4 25.2 13.4 30.4h4L16.2 34');
        $out = array_filter($bad, static fn (array $p): bool => $p[1] > 32.0);
        assertGreaterThan(0, count($out), 'the walker must object to a point below the box');

        // The cloud, which is drawn almost entirely in RELATIVE steps and is
        // completely inside the box. Its 'd' contains "-10.8" and "-15.4", and
        // reading those as coordinates is exactly the mistake this walker avoids.
        $cloud = twr_path_points('M10.6 24.5h11.2a5.4 5.4 0 0 0 .5-10.8 8 8 0 0 0-15.4 2 4.6 4.6 0 0 0 3.7 8.8Z');
        assertGreaterThan(3, count($cloud));
        foreach ($cloud as $pt) {
            assertTrue(
                $pt[0] >= 0.0 && $pt[0] <= 32.0 && $pt[1] >= 0.0 && $pt[1] <= 32.0,
                'the cloud is inside the box: ' . $pt[0] . ',' . $pt[1]
            );
        }

        // Closing a path returns the pen to where the subpath started.
        $closed = twr_path_points('M4 4L8 8Z');
        assertSame([4.0, 4.0], $closed[count($closed) - 1]);
    },

    // =====================================================================
    //  the URLs we build
    // =====================================================================

    'the alerts URL repeats severity and never comma-joins it' => function (): void {
        $url = Weather::alertsUrl('NY');

        assertSame(2, substr_count($url, 'severity='), 'each severity gets its own parameter');
        assertContains('severity=Extreme', $url);
        assertContains('severity=Severe', $url);

        // The API rejects the comma form. Neither spelling of a comma may appear
        // anywhere in the query string.
        assertNotContains('Extreme,Severe', $url);
        assertNotContains('%2C', $url);
        assertNotContains('%2c', $url);
        assertFalse(strpos((string) parse_url($url, PHP_URL_QUERY), ',') !== false, 'no raw comma in the query');

        assertContains('area=NY', $url);
        assertContains('status=actual', $url, 'exercise and test messages must be excluded');
        assertContains('message_type=alert', $url);
    },

    'a severity value that contains a comma is refused, not smuggled through' => function (): void {
        $url = Weather::alertsUrl('TX', ['Extreme,Severe', 'Moderate']);

        assertNotContains('Extreme', $url, 'a comma-joined value is dropped whole');
        assertSame(1, substr_count($url, 'severity='));
        assertContains('severity=Moderate', $url);
    },

    'a hostile region cannot inject anything into the alerts URL' => function (): void {
        foreach (['NY&severity=Minor', "NY\r\nX: 1", '../../etc', 'ny', ''] as $region) {
            $url = Weather::alertsUrl($region);

            assertSame(2, substr_count($url, 'severity='), 'region ' . json_encode($region));
            assertNotContains('Minor', $url);
            assertNotContains("\r", $url);
            assertNotContains("\n", $url);
        }
        assertContains('area=NY', Weather::alertsUrl('ny'), 'a lower-case state is still a state');
        assertNotContains('area=', Weather::alertsUrl(''), 'no region means no area filter');
    },

    'the forecast URL asks for Fahrenheit, the place timezone and five days' => function (): void {
        $cfg   = twr_cfg();
        $place = Weather::resolvePlace($cfg, 'denver');
        $url   = Weather::forecastUrl($place, ['weather' => []]);

        assertContains('api.open-meteo.com', $url, 'the shipped default is the public endpoint');
        assertContains('temperature_unit=fahrenheit', $url);
        assertContains('wind_speed_unit=mph', $url);
        assertContains('forecast_days=5', $url);
        assertContains('latitude=39.7392', $url);
        assertContains('longitude=-104.9903', $url);
        assertContains('timezone=America%2FDenver', $url, 'the timezone comes from the place, not the server');
        assertContains('weather_code', $url);
        assertContains('sunset', $url);
    },

    // =====================================================================
    //  the forecast, from a recorded response
    // =====================================================================

    'a recorded Open-Meteo response becomes current conditions and five days' => function (): void {
        $w = Weather::get(twr_cfg(), 'new-york');

        assertFalse($w['degraded'], 'a good response is not degraded: ' . json_encode($w['notes']));
        assertFalse($w['stale']);
        assertSame([], $w['notes']);

        assertSame('new-york', $w['place']['slug']);
        assertSame('New York', $w['place']['name']);
        assertSame('New York, NY', $w['place']['label']);

        // The recorded response reads 71.4 °F, weather code 0, wind 3.7 mph
        // from 335°, humidity 74, is_day 1.
        $c = $w['current'];
        assertTrue($c['ok']);
        assertSame(71, $c['temp_f']);
        assertSame(0, $c['code']);
        assertSame('Clear sky', $c['label']);
        assertSame(74, $c['humidity']);
        assertSame(4, $c['wind_mph']);
        assertSame('NNW', $c['wind_compass']);
        assertSame(8, $c['gust_mph']);
        assertTrue($c['is_day']);
        assertSame('7:45 a.m.', $c['observed_label']);
        assertContains('<svg', $c['icon']);

        // Today's high and low come off the daily block: 87.7 / 69.2.
        assertSame(88, $c['high_f']);
        assertSame(69, $c['low_f']);
        assertSame('H 88° / L 69° · wind NNW 4', $c['hilo_line']);
        assertSame('Clear sky · New York', $c['summary_line']);

        assertCount(5, $w['days'], 'five days were asked for and five came back');

        $day = $w['days'][0];
        assertSame('2026-08-19', $day['iso']);
        assertSame('WED', $day['name']);
        assertSame('Wednesday', $day['name_long']);
        assertSame(88, $day['high_f']);
        assertSame(69, $day['low_f']);
        assertSame('88/69', $day['range_f']);
        assertSame(3, $day['code']);
        assertSame('Overcast', $day['short']);
        assertContains('<svg', $day['icon']);

        // Day two of the recording is code 81 — rain showers.
        assertSame(81, $w['days'][1]['code']);
        assertSame('Rain showers', $w['days'][1]['short']);

        foreach ($w['days'] as $d) {
            assertNotSame('', $d['name'], 'every day needs a label for the strip');
            assertNotSame('', $d['short']);
            assertTrue($d['high_f'] !== null && $d['low_f'] !== null);
        }
    },

    'Celsius is carried alongside Fahrenheit and converts exactly' => function (): void {
        $w = Weather::get(twr_cfg(), 'new-york');
        $c = $w['current'];

        assertSame(71.4, $c['temp_f_raw']);
        assertSame(21.9, $c['temp_c_raw'], '(71.4 - 32) * 5 / 9 = 21.888…');
        assertSame(22, $c['temp_c']);

        foreach ($w['days'] as $d) {
            assertNotNull($d['high_c'], 'every day carries Celsius too');
            assertNotNull($d['low_c']);
            assertTrue($d['high_c'] < $d['high_f'], 'a US summer day is a smaller number in Celsius');
        }
    },

    // =====================================================================
    //  the warnings, from a recorded response
    // =====================================================================

    'a recorded weather.gov response becomes readable warnings' => function (): void {
        $w = Weather::get(twr_cfg(), 'indianapolis');

        assertCount(2, $w['alerts'], 'the recording carries two active Flood Watches');

        $a = $w['alerts'][0];
        assertSame('Flood Watch', $a['event']);
        assertSame('Severe', $a['severity']);
        assertContains('Flood Watch issued August 19', $a['headline']);
        assertContains('NWS', $a['sender']);
        assertMatches('~^https://~', $a['url'], 'the link out is never plain http');

        // areaDesc is 24 semicolon-separated counties; the strip needs one line.
        assertSame('Carroll, Warren and 22 more', $a['area']);
        assertTrue(strlen($a['area']) < 40);

        assertNotSame('', $a['starts_label']);
        assertNotSame('', $a['ends_label']);

        // Long official prose is trimmed, not dumped whole into the page.
        assertTrue(mb_strlen($a['description']) <= 601, 'description is clamped');
        assertNotContains("\n", $a['description'], 'newlines are collapsed for the page');
    },

    'a state with nothing active yields no warnings and is not degraded' => function (): void {
        $s   = twr_server();
        $cfg = twr_cfg(['weather.endpoints.alerts' => $s['base'] . '/alerts-empty.json']);

        $w = Weather::get($cfg, 'new-york');

        assertSame([], $w['alerts']);
        assertFalse($w['degraded'], 'quiet weather is not a failure');
    },

    'a place with no US state never calls the warnings endpoint at all' => function (): void {
        $cfg = twr_cfg([
            'weather.places' => [
                'lisbon' => ['name' => 'Lisbon', 'region' => '', 'lat' => 38.7223, 'lon' => -9.1393, 'timezone' => 'Europe/Lisbon'],
            ],
        ]);

        twr_reset_hits();
        $w = Weather::get($cfg, 'lisbon');

        assertSame([], $w['alerts']);
        assertFalse($w['degraded'], 'a place outside the warning service is not a degraded place');
        assertSame(0, twr_hits('alerts'), 'no request should have been made');
        assertTrue($w['current']['ok'], 'the forecast still works everywhere');
    },

    'the request that reaches the warnings endpoint carries two severity params' => function (): void {
        $s = twr_server();
        @unlink($s['dir'] . '/last-alerts-query.txt');

        Weather::get(twr_cfg(['weather.cache_seconds' => 0]), 'indianapolis');

        $query = (string) @file_get_contents($s['dir'] . '/last-alerts-query.txt');
        assertNotSame('', $query, 'the endpoint must actually have been called');

        // What the builder returns is one thing; what cURL puts on the wire is
        // the thing the API rejects or accepts.
        assertSame(2, substr_count($query, 'severity='), 'on the wire: ' . $query);
        assertNotContains(',', $query, 'on the wire: ' . $query);
        assertNotContains('%2C', $query);

        $ua = (string) @file_get_contents($s['dir'] . '/last-alerts-ua.txt');
        assertNotSame('', $ua, 'the API answers 403 to a request with no User-Agent');
        assertContains('TP', $ua, 'the agent identifies the site from config');
        assertContains('example.org', $ua, 'and carries a contact');
    },

    // =====================================================================
    //  degraded, never fatal
    // =====================================================================

    'an upstream 500 yields a degraded model, not an exception' => function (): void {
        $s   = twr_server();
        $cfg = twr_cfg([
            'weather.endpoints.forecast' => $s['base'] . '/boom.php',
            'weather.endpoints.alerts'   => $s['base'] . '/boom.php',
        ]);

        $w = Weather::get($cfg, 'chicago');

        assertTrue($w['degraded'], 'a 500 is a degraded strip');
        assertTrue(count($w['notes']) >= 2, 'both failures are reported: ' . json_encode($w['notes']));
        assertContains('500', implode(' ', $w['notes']), 'the note says what happened');

        // The contract shape survives intact, which is what stops the page dying.
        foreach (['place', 'current', 'days', 'alerts', 'degraded'] as $key) {
            assertArrayHasKey($key, $w);
        }
        assertTrue(is_array($w['current']), 'current is an array even with no data');
        assertFalse($w['current']['ok']);
        assertNull($w['current']['temp_f']);
        assertSame('', $w['current']['hilo_line']);
        assertContains('<svg', $w['current']['icon'], 'even the empty state has an icon');
        assertSame([], $w['days']);
        assertSame([], $w['alerts']);
        assertSame('Chicago', $w['place']['name'], 'the place is still known');
    },

    'a 200 that is not JSON is degraded, not a fatal' => function (): void {
        $s   = twr_server();
        $cfg = twr_cfg([
            'weather.endpoints.forecast' => $s['base'] . '/nonsense.php',
            'weather.endpoints.alerts'   => $s['base'] . '/nonsense.php',
        ]);

        $w = Weather::get($cfg, 'miami');

        assertTrue($w['degraded']);
        assertFalse($w['current']['ok']);
        assertSame([], $w['days']);
        assertContains('shape', implode(' ', $w['notes']));
    },

    'a refused connection is degraded, not a fatal' => function (): void {
        $dead = 'http://127.0.0.1:' . twr_dead_port() . '/forecast';
        $cfg  = twr_cfg([
            'weather.endpoints.forecast' => $dead,
            'weather.endpoints.alerts'   => $dead,
        ]);

        $w = Weather::get($cfg, 'seattle');

        assertTrue($w['degraded']);
        assertFalse($w['current']['ok']);
        assertTrue(count($w['notes']) >= 1);
    },

    'one upstream down does not take the other with it' => function (): void {
        $s   = twr_server();
        $cfg = twr_cfg(['weather.endpoints.alerts' => $s['base'] . '/boom.php']);

        $w = Weather::get($cfg, 'indianapolis');

        assertTrue($w['current']['ok'], 'the forecast still rendered');
        assertCount(5, $w['days']);
        assertSame([], $w['alerts']);
        assertTrue($w['degraded'], 'but the model is honest that something is missing');
        assertCount(1, $w['notes']);
        assertContains('alerts', $w['notes'][0]);
    },

    // =====================================================================
    //  the fifteen-minute cache
    // =====================================================================

    'a response is cached in data/ under the rounded latitude and longitude' => function (): void {
        $cfg = twr_cfg();
        Weather::get($cfg, 'new-york');

        $files = glob($cfg['paths']['data'] . '/*.json') ?: [];
        $names = array_map('basename', $files);

        $forecast = array_values(array_filter($names, static fn (string $n): bool => strpos($n, 'weather-40.71_-74.01') === 0));
        assertCount(1, $forecast, 'expected a cache file keyed by the rounded coordinates, got ' . json_encode($names));

        $entry = json_decode((string) file_get_contents($cfg['paths']['data'] . '/' . $forecast[0]), true);
        assertTrue(is_array($entry));
        assertTrue((bool) $entry['ok']);
        assertContains('"temperature_2m"', (string) $entry['body']);
    },

    'a cached response is served without calling the upstream again' => function (): void {
        $cfg = twr_cfg();

        twr_reset_hits();
        $first = Weather::get($cfg, 'new-york');
        assertSame(1, twr_hits('forecast'), 'the first call goes out');

        $second = Weather::get($cfg, 'new-york');
        assertSame(1, twr_hits('forecast'), 'the second is served from data/');
        assertSame($first['current']['temp_f'], $second['current']['temp_f']);

        // With the window closed the request goes out again — which proves the
        // first result was the cache doing its job, not the class refusing to
        // fetch.
        $cfg['weather']['cache_seconds'] = 0;
        Weather::get($cfg, 'new-york');
        assertSame(2, twr_hits('forecast'));
    },

    'a stale cache is served when the upstream goes down, and the model says so' => function (): void {
        $cfg = twr_cfg();

        $good = Weather::get($cfg, 'new-york');
        assertTrue($good['current']['ok']);
        assertFalse($good['stale']);

        // Same data directory, same coordinates, SAME URL — the upstream itself
        // starts answering 500, which is what an outage actually looks like —
        // and the fifteen-minute window has closed.
        $cfg['weather']['cache_seconds'] = 0;
        twr_fail('forecast');

        try {
            $w = Weather::get($cfg, 'new-york');
        } finally {
            twr_fail('forecast', false);
        }

        assertTrue($w['current']['ok'], 'the last known weather is better than a blank strip');
        assertSame($good['current']['temp_f'], $w['current']['temp_f']);
        assertTrue($w['stale'], 'and it is flagged as stale');
        assertTrue($w['degraded']);
        assertContains('cached copy', implode(' ', $w['notes']));
    },

    'a failing upstream is not retried on every single page view' => function (): void {
        $s   = twr_server();
        $cfg = twr_cfg([
            'weather.endpoints.forecast' => $s['base'] . '/forecast.php',
            'weather.cache_seconds'      => 0,
        ]);

        // Prime a failure with no cache to fall back on.
        twr_fail('forecast');
        try {
            $first = Weather::get($cfg, 'boston');
            assertTrue($first['degraded']);

            twr_reset_hits();
            $second = Weather::get($cfg, 'boston');
            assertTrue($second['degraded']);
            assertSame(0, twr_hits('forecast'), 'the recent failure is remembered');
        } finally {
            twr_fail('forecast', false);
        }
    },

    // =====================================================================
    //  places
    // =====================================================================

    'the built-in metro table covers the country and every row is usable' => function (): void {
        $places = Weather::places(['weather' => ['places' => []]]);

        assertGreaterThanOrEqual(25, count($places), 'about twenty-five US metros were asked for');

        $zones = timezone_identifiers_list();
        foreach ($places as $slug => $p) {
            assertMatches('/^[a-z0-9][a-z0-9\-]*$/', $slug, 'slug ' . $slug);
            assertSame($slug, $p['slug']);
            assertNotSame('', $p['name'], $slug . ' needs a name');
            assertTrue($p['lat'] >= -90.0 && $p['lat'] <= 90.0, $slug . ' latitude');
            assertTrue($p['lon'] >= -180.0 && $p['lon'] <= 180.0, $slug . ' longitude');
            assertTrue($p['lat'] !== 0.0 || $p['lon'] !== 0.0, $slug . ' is not in the Atlantic');
            assertContains($p['timezone'], $zones, $slug . ' timezone');
            assertMatches('/^[A-Z]{2}$/', $p['region'], $slug . ' region');
            assertContains($p['region'], $p['label'], $slug . ' label');
        }

        // A handful of coordinates, spot-checked against the real cities.
        assertSame(34.0522, $places['los-angeles']['lat']);
        assertSame(-87.6298, $places['chicago']['lon']);
        assertSame('America/Phoenix', $places['phoenix']['timezone'], 'Arizona does not keep daylight time');
        assertSame('Pacific/Honolulu', $places['honolulu']['timezone']);
    },

    'a place in config overrides the built-in table of the same name' => function (): void {
        $cfg = twr_cfg([
            'weather.places' => [
                'denver' => ['name' => 'Denver Newsroom', 'region' => 'CO', 'lat' => 39.75, 'lon' => -105.0, 'timezone' => 'America/Denver'],
                'kolkata' => ['name' => 'Kolkata', 'region' => '', 'lat' => 22.5726, 'lon' => 88.3639, 'timezone' => 'Asia/Kolkata'],
            ],
        ]);

        $places = Weather::places($cfg);

        assertSame('Denver Newsroom', $places['denver']['name'], 'the client list wins');
        assertSame(39.75, $places['denver']['lat']);
        assertArrayHasKey('kolkata', $places, 'and can add places the table never had');
        assertArrayHasKey('new-york', $places, 'without removing the built-ins');
        assertSame('Kolkata', $places['kolkata']['label'], 'no state, no comma in the label');
    },

    'an unknown or hostile place falls back to the default rather than failing' => function (): void {
        $cfg = twr_cfg(['weather.default_place' => 'chicago']);

        foreach (['', 'atlantis', '../../etc/passwd', 'NEW YORK', '<script>', '0'] as $slug) {
            $p = Weather::resolvePlace($cfg, $slug);
            assertArrayHasKey('slug', $p, 'slug ' . json_encode($slug));
            assertMatches('/^[a-z0-9][a-z0-9\-]*$/', $p['slug'], 'slug ' . json_encode($slug));
        }

        assertSame('chicago', Weather::resolvePlace($cfg, 'atlantis')['slug'], 'unknown falls back to the default');
        assertSame('chicago', Weather::resolvePlace($cfg, null)['slug']);
        assertSame('new-york', Weather::resolvePlace($cfg, 'new-york')['slug'], 'a known slug is honoured');
        assertSame('new-york', Weather::resolvePlace($cfg, ' New-York ')['slug'], 'and tidied before matching');
    },

    'a default place that does not exist still yields a real place' => function (): void {
        $p = Weather::resolvePlace(['weather' => ['default_place' => 'nowhere', 'places' => []]], null);

        assertNotSame('', $p['slug']);
        assertNotSame('', $p['timezone']);
        assertTrue($p['lat'] !== 0.0 || $p['lon'] !== 0.0);
    },

    'the model always carries the keys the contract promises' => function (): void {
        foreach ([twr_cfg(), twr_cfg(['weather.endpoints.forecast' => 'not a url'])] as $i => $cfg) {
            $w = Weather::get($cfg, 'houston');

            foreach (['place', 'current', 'days', 'alerts', 'degraded'] as $key) {
                assertArrayHasKey($key, $w, 'case ' . $i . ' key ' . $key);
            }
            assertTrue(is_array($w['place']), 'case ' . $i);
            assertTrue(is_array($w['current']), 'case ' . $i);
            assertTrue(is_array($w['days']), 'case ' . $i);
            assertTrue(is_array($w['alerts']), 'case ' . $i);
            assertTrue(is_bool($w['degraded']), 'case ' . $i);
            assertTrue(is_array($w['places']) && $w['places'] !== [], 'case ' . $i . ': the picker is always populated');
        }
    },

    // =====================================================================
    //  recipes — enrichment reads, and never invents
    // =====================================================================

    'a real recipe card yields a total time, a yield and an ingredient count' => function (): void {
        // budgetbytes.xml item 1: "Sweet Potato and Ground Turkey Skillet".
        // Its card states Prep 15 / Cook 25 / Total 40 minutes, Servings 4.
        $row = Recipes::enrichRecipe(twr_feed_item('budgetbytes.xml', 1));

        assertSame(40, $row['total_minutes']);
        assertSame('40 min', $row['time_label']);
        assertSame(4, $row['servings']);
        assertSame('4 servings', $row['yield_label']);
        assertSame(15, $row['ingredient_count']);
        assertSame('15 ingredients', $row['ingredients_label']);
        assertSame('40 min · 4 servings · 15 ingredients', $row['meta_line']);
        assertSame(['40 min', '4 servings', '15 ingredients'], $row['meta']);
    },

    'hours and minutes in one card are summed, not read as the first number' => function (): void {
        // budgetbytes.xml item 2: "Slow Cooker Chicken and Vegetables Ramen",
        // whose card splits its total across a 3-hours span and a 15-minutes span.
        $row = Recipes::enrichRecipe(twr_feed_item('budgetbytes.xml', 2));

        assertSame(195, $row['total_minutes'], '3 hours + 15 minutes');
        assertSame('3 hr 15 min', $row['time_label']);
        assertSame(8, $row['servings']);
        assertSame(14, $row['ingredient_count']);
    },

    'a publisher who states no time is given none' => function (): void {
        // Smitten Kitchen ships prose. Love and Lemons ships a bare excerpt.
        // Neither states a time, a yield or a list, so neither gets one.
        foreach (['smittenkitchen.xml', 'loveandlemons.xml'] as $fixture) {
            $items = twr_feed_items($fixture);
            assertGreaterThanOrEqual(5, count($items), $fixture . ' should have several items');

            foreach ($items as $item) {
                $row = Recipes::enrichRecipe($item);

                foreach (['total_minutes', 'time_label', 'servings', 'yield_label',
                          'ingredient_count', 'ingredients_label', 'meta', 'meta_line'] as $key) {
                    assertArrayNotHasKey(
                        $key,
                        $row,
                        $fixture . ' / "' . $item['title'] . '" states no ' . $key . ' — the key must be absent, not empty'
                    );
                }
            }
        }
    },

    'a non-recipe post on a recipe feed is not given invented facts' => function (): void {
        // budgetbytes.xml item 0 is a grocery-prices explainer. It contains the
        // words "yield" and "ingredients" in prose and no recipe card at all.
        $row = Recipes::enrichRecipe(twr_feed_item('budgetbytes.xml', 0));

        assertArrayNotHasKey('total_minutes', $row);
        assertArrayNotHasKey('servings', $row);
        assertArrayNotHasKey('ingredient_count', $row);
        assertArrayNotHasKey('meta_line', $row);
    },

    'the summary the database stores is enough to be safe, and never enough to guess' => function (): void {
        // Production rows carry the trimmed plain-text summary and nothing else.
        // The enricher must survive that shape and stay silent on it.
        foreach (['budgetbytes.xml', 'smittenkitchen.xml', 'loveandlemons.xml'] as $fixture) {
            foreach (twr_feed_items($fixture) as $item) {
                $row = Recipes::enrichRecipe([
                    'title'   => $item['title'],
                    'summary' => $item['summary'],
                ]);

                assertArrayNotHasKey('meta_line', $row, $fixture . ' / ' . $item['title']);
                assertSame($item['title'], $row['title'], 'the row itself comes back intact');
            }
        }
    },

    'a cook time is never inferred from prose that merely mentions minutes' => function (): void {
        $cases = [
            'We spent 30 minutes arguing about whether pineapple belongs on pizza.',
            'The bakery is 20 minutes from my house and worth every step.',
            'It took me four hours to write this and about six seconds to eat it.',
        ];

        foreach ($cases as $text) {
            $row = Recipes::enrichRecipe(['title' => 'Prose', 'summary' => $text]);
            assertArrayNotHasKey('total_minutes', $row, $text);
        }

        // But a labelled statement is read.
        $row = Recipes::enrichRecipe(['title' => 'Labelled', 'summary' => 'Total time: 1 hour 10 minutes. Serves 6.']);
        assertSame(70, $row['total_minutes']);
        assertSame('1 hr 10 min', $row['time_label']);
        assertSame(6, $row['servings']);
    },

    'prep and cook times are added up when no total is stated' => function (): void {
        $row = Recipes::enrichRecipe([
            'title'   => 'Parts',
            'summary' => 'Prep time 15 minutes. Cook time 25 minutes. Makes 12 muffins.',
        ]);

        assertSame(40, $row['total_minutes']);
        assertSame(12, $row['servings']);
        assertSame('12 muffins', $row['yield_label'], 'the publisher\'s own unit is kept');
    },

    'schema.org durations and yields are read in either spelling' => function (): void {
        $microdata = Recipes::enrichRecipe(['title' => 'Micro', 'content_html' =>
            '<div><meta itemprop="totalTime" content="PT1H30M">'
            . '<span itemprop="recipeYield">8 slices</span>'
            . '<li itemprop="recipeIngredient">flour</li><li itemprop="recipeIngredient">water</li>'
            . '<li itemprop="recipeIngredient">salt</li></div>']);

        assertSame(90, $microdata['total_minutes']);
        assertSame('1 hr 30 min', $microdata['time_label']);
        assertSame('8 slices', $microdata['yield_label']);
        assertSame(3, $microdata['ingredient_count']);

        $jsonLd = Recipes::enrichRecipe(['title' => 'JSON-LD', 'content_html' =>
            '<script type="application/ld+json">{"@type":"Recipe","totalTime":"PT45M",'
            . '"recipeYield":"4 servings","recipeIngredient":["a","b","c","d"]}</script>']);

        assertSame(45, $jsonLd['total_minutes']);
        assertSame(4, $jsonLd['servings']);
        assertSame(4, $jsonLd['ingredient_count']);
    },

    'a nonsense duration is declined rather than published' => function (): void {
        foreach (['PT0M', 'P', 'tomorrow', 'PT9999H'] as $iso) {
            $row = Recipes::enrichRecipe([
                'title'        => 'Odd',
                'content_html' => '<meta itemprop="totalTime" content="' . $iso . '">',
            ]);
            assertArrayNotHasKey('total_minutes', $row, $iso . ' should not become a cook time');
        }
    },

    'an ingredient count is never taken across a roundup of many cards' => function (): void {
        // Two real recipe cards concatenated, which is what a "best 30 recipes"
        // post looks like. The count must come from the first card only, not
        // from the sum of every card on the page.
        $one = twr_feed_item('budgetbytes.xml', 1);
        $two = twr_feed_item('budgetbytes.xml', 2);

        $row = Recipes::enrichRecipe([
            'title'        => 'Roundup',
            'content_html' => $one['content_html'] . $two['content_html'],
        ]);

        assertSame(15, $row['ingredient_count'], 'the first card lists fifteen');
    },

    'an implausible count or yield is dropped rather than printed' => function (): void {
        $many = '<div class="wprm-recipe-ingredients-container">'
            . str_repeat('<li class="wprm-recipe-ingredient">x</li>', 90)
            . '</div><div class="wprm-recipe-instructions-container"></div>';
        $row  = Recipes::enrichRecipe(['title' => 'Too many', 'content_html' => $many]);
        assertArrayNotHasKey('ingredient_count', $row, 'ninety ingredients is a roundup, not a recipe');

        $one = '<div class="wprm-recipe-ingredients-container">'
            . '<li class="wprm-recipe-ingredient">x</li>'
            . '</div><div class="wprm-recipe-instructions-container"></div>';
        $row = Recipes::enrichRecipe(['title' => 'Too few', 'content_html' => $one]);
        assertArrayNotHasKey('ingredient_count', $row, 'one "ingredient" is a parse artefact');

        $row = Recipes::enrichRecipe(['title' => 'Huge yield', 'summary' => 'Serves 900 people.']);
        assertArrayNotHasKey('servings', $row);
    },

    'a yield keeps the publisher\'s own unit and drops everything that is not one' => function (): void {
        $cases = [
            'Makes 12 mini muffins.'               => '12 mini muffins',
            'Yield: 8 slices'                      => '8 slices',
            'Makes 24 cookies'                     => '24 cookies',
            'Serves 1 person'                      => '1 person',
            // The number is right in all of these; the words after it are not a
            // unit, so the label falls back rather than printing "4 to".
            'Serves 4 to 6 people.'                => '4 servings',
            'Serves 2 and takes no time'           => '2 servings',
            'Serves 4 million people, apparently.' => '4 servings',
        ];

        foreach ($cases as $text => $expected) {
            $row = Recipes::enrichRecipe(['title' => 'Yield', 'summary' => $text]);
            assertSame($expected, $row['yield_label'] ?? '(none)', $text);
        }
    },

    'timeLabel reads the way a person would say it' => function (): void {
        assertSame('40 min', Recipes::timeLabel(40));
        assertSame('1 hr', Recipes::timeLabel(60));
        assertSame('1 hr 30 min', Recipes::timeLabel(90));
        assertSame('3 hr', Recipes::timeLabel(180));
        assertSame('3 hr 15 min', Recipes::timeLabel(195));
        assertSame('1 day', Recipes::timeLabel(1440));
        assertSame('1 day 2 hr', Recipes::timeLabel(1560));
        assertSame('0 min', Recipes::timeLabel(0));
    },

    // =====================================================================
    //  recipes — the page
    // =====================================================================

    'recipeItems reads the recipe desk and nothing else' => function (): void {
        $cfg = twr_cfg();
        $p   = twr_db($cfg);

        twr_insert($p, [
            ['title' => 'Sheet-pan salmon', 'section' => 'recipes', 'summary' => 'Total time: 25 minutes. Serves 2.', 'image_url' => 'https://img.example.com/a.jpg'],
            ['title' => 'Senate passes bill', 'section' => 'us', 'source_slug' => 'npr-news', 'source_name' => 'NPR'],
            ['title' => 'Braised leeks', 'section' => 'recipes', 'source_slug' => 'smitten-kitchen', 'source_name' => 'Smitten Kitchen'],
            ['title' => 'Markets close lower', 'section' => 'business', 'source_slug' => 'abc-money', 'source_name' => 'ABC News'],
        ]);

        $items = Recipes::recipeItems($p, $cfg);

        assertCount(2, $items);
        foreach ($items as $row) {
            assertSame('recipes', $row['section']);
        }

        // Newest first, and the enrichment has already been applied.
        assertSame('Sheet-pan salmon', $items[0]['title']);
        assertSame(25, $items[0]['total_minutes']);
        assertSame(2, $items[0]['servings']);
        assertArrayNotHasKey('total_minutes', $items[1], 'the other one says nothing, so nothing is added');
    },

    'pageModel groups the desk into a lead and a grid' => function (): void {
        $cfg = twr_cfg();
        $p   = twr_db($cfg);

        $rows = [];
        for ($i = 0; $i < 7; $i++) {
            $rows[] = [
                'title'     => 'Recipe number ' . $i,
                'summary'   => 'Total time: ' . (10 + $i) . ' minutes. Serves 4.',
                'image_url' => $i === 3 ? '' : 'https://img.example.com/' . $i . '.jpg',
                'url'       => 'https://example.com/r/' . $i,
            ];
        }
        twr_insert($p, $rows);

        $model = Recipes::pageModel($p, $cfg);

        assertSame('recipes', $model['section']);
        assertSame('Recipes', $model['label']);
        assertSame(7, $model['count']);

        assertTrue(is_array($model['lead']));
        assertSame('Recipe number 0', $model['lead']['title'], 'the newest usable row leads');
        assertSame('lead', $model['lead']['size']);
        assertFalse($model['lead']['lazy'], 'the lead image is the page hero and loads eagerly');

        assertCount(6, $model['grid']);
        foreach ($model['grid'] as $row) {
            assertTrue($row['lazy'], 'every other image lazy-loads');
            assertContains($row['size'], ['medium', 'text']);
            assertNotSame($model['lead']['title'], $row['title'], 'no row appears twice');
        }

        // The row with no picture is offered as the designed text card, not as a
        // card with a hole in it.
        $textCards = array_values(array_filter($model['grid'], static fn (array $r): bool => $r['size'] === 'text'));
        assertCount(1, $textCards);
        assertSame('Recipe number 3', $textCards[0]['title']);

        assertSame(7, count($model['items']));
        assertCount(1, $model['sources']);
        assertSame('Budget Bytes', $model['sources'][0]['name']);
        assertSame(7, $model['sources'][0]['count']);
    },

    'the lead is the newest row that has both a picture and something to say' => function (): void {
        $cfg = twr_cfg();
        $p   = twr_db($cfg);

        twr_insert($p, [
            ['title' => 'Newest, no picture, no facts', 'image_url' => ''],
            ['title' => 'Second, picture but silent', 'image_url' => 'https://img.example.com/b.jpg'],
            ['title' => 'Third, picture and facts', 'image_url' => 'https://img.example.com/c.jpg', 'summary' => 'Total time: 35 minutes.'],
        ]);

        $model = Recipes::pageModel($p, $cfg);

        assertSame('Third, picture and facts', $model['lead']['title']);
        assertCount(2, $model['grid']);
    },

    'an empty desk renders as an honest empty desk, not a failure' => function (): void {
        $cfg = twr_cfg();
        $p   = twr_db($cfg);

        $model = Recipes::pageModel($p, $cfg);

        assertNull($model['lead']);
        assertSame([], $model['grid']);
        assertSame([], $model['items']);
        assertSame(0, $model['count']);
        assertSame([], $model['sources']);
        assertTrue($model['degraded']);
        assertNotSame('', $model['note']);
    },

    'the page never carries the method or the ingredient list' => function (): void {
        $cfg = twr_cfg();
        $p   = twr_db($cfg);

        $item = twr_feed_item('budgetbytes.xml', 1);
        twr_insert($p, [[
            'title'     => $item['title'],
            'summary'   => $item['summary'],
            'url'       => $item['url'],
            'image_url' => $item['image_url'],
        ]]);

        $model = Recipes::pageModel($p, $cfg);
        $json  = (string) json_encode($model);

        // A phrase from the real recipe method and one from its ingredient list.
        assertNotContains('cooking oil', $json, 'no ingredient list reaches the page');
        assertNotContains('wprm-recipe-instructions', $json);
        assertNotContains('<li', $json, 'no markup from the publisher is republished');

        foreach ($model['items'] as $row) {
            assertArrayNotHasKey('ingredients', $row);
            assertArrayNotHasKey('instructions', $row);
            assertArrayNotHasKey('content_html', $row);
            assertNotSame('', $row['url'], 'every card links back to the publisher');
        }
    },

    'the models hand the renderer raw text and one piece of trusted markup' => function (): void {
        $s   = twr_server();
        $cfg = twr_cfg();

        $w = Weather::get($cfg, 'indianapolis');

        // Text is NOT pre-escaped — escaping is the renderer's single job, and
        // doing it twice mangles an honest ampersand.
        assertNotContains('&amp;', (string) json_encode($w['alerts']), 'alert text arrives raw');
        assertNotContains('&lt;', (string) json_encode($w['place']));

        // The icon is the exception: finished markup, built here, from our own
        // tables only. It must survive to the page as markup.
        assertContains('<svg', $w['current']['icon']);
        assertContains('<path', $w['current']['icon']);
        foreach ($w['days'] as $d) {
            assertContains('<svg', $d['icon']);
        }

        // Nothing from an upstream is inside it, which is what makes that safe.
        assertNotContains('Flood', $w['current']['icon']);
        assertNotContains('Indiana', $w['current']['icon']);

        // And every link the model offers is a link a browser may safely follow.
        foreach ($w['alerts'] as $a) {
            assertMatches('~^https://~', $a['url']);
        }
    },

    // =====================================================================
    //  the dish of the day
    // =====================================================================

    'a recorded TheMealDB response becomes a dish, a count and a link out' => function (): void {
        $meal = Recipes::mealOfTheDay(twr_cfg());

        assertNotNull($meal);
        assertSame('Black Beans Hotpot', $meal['title']);
        assertSame('Vegetarian', $meal['category']);
        assertSame('Costa Rica', $meal['origin'], 'strArea is null in this recording, so strCountry is used');
        assertSame(11, $meal['ingredient_count']);
        assertContains('11 ingredients', $meal['meta_line']);
        assertMatches('~^https://~', $meal['url']);
        assertMatches('~^https://~', $meal['image_url']);
        assertSame(0, $meal['image_width'], 'an unknown pixel size is 0, the same as every ingested row');

        // The count is published. The list and the method are not.
        assertArrayNotHasKey('instructions', $meal);
        $json = (string) json_encode($meal);
        assertNotContains('Instant pot', $json, 'the method stays with the publisher');
        assertNotContains('Black Beans"', $json, 'and so does the ingredient list');
    },

    'a failing TheMealDB returns null rather than throwing' => function (): void {
        $s = twr_server();

        $cases = [
            'a 500'                => $s['base'] . '/boom.php',
            'HTML instead of JSON' => $s['base'] . '/nonsense.php',
            'JSON with no meals'   => $s['base'] . '/no-meals.php',
            'a refused connection' => 'http://127.0.0.1:' . twr_dead_port() . '/meal.json',
            'a 404'                => $s['base'] . '/there-is-no-such-file.json',
            'an empty 200'         => $s['base'] . '/empty.php',
        ];

        foreach ($cases as $what => $url) {
            $cfg = twr_cfg(['recipes.endpoints.meal_random' => $url]);
            assertNull(Recipes::mealOfTheDay($cfg), $what . ' must return null');
        }
    },

    'the dish is cached, and a dish already fetched survives the API going down' => function (): void {
        $cfg = twr_cfg();

        $first = Recipes::mealOfTheDay($cfg);
        assertNotNull($first);

        // Same data directory, SAME URL, upstream now broken: the cached dish
        // still shows.
        twr_fail('meal');
        try {
            $again = Recipes::mealOfTheDay($cfg);
        } finally {
            twr_fail('meal', false);
        }

        assertNotNull($again, 'a dish we already have beats no dish at all');
        assertSame($first['title'], $again['title']);

        $files = glob($cfg['paths']['data'] . '/recipe-meal-*.json') ?: [];
        assertCount(1, $files, 'exactly one cache file, keyed by the endpoint');
    },

    'a failing dish does not take the recipes page down with it' => function (): void {
        $s   = twr_server();
        $cfg = twr_cfg(['recipes.endpoints.meal_random' => $s['base'] . '/boom.php']);
        $p   = twr_db($cfg);

        twr_insert($p, [
            ['title' => 'Weeknight chili', 'summary' => 'Total time: 45 minutes. Serves 6.', 'image_url' => 'https://img.example.com/chili.jpg'],
        ]);

        $model = Recipes::pageModel($p, $cfg);

        assertNull($model['meal']);
        assertTrue($model['degraded']);
        assertSame(1, $model['count'], 'the desk itself is untouched');
        assertSame('Weeknight chili', $model['lead']['title']);
        assertSame(45, $model['lead']['total_minutes']);
    },

    'the dish can be switched off entirely, and then nothing is fetched' => function (): void {
        $cfg = twr_cfg(['recipes.meal_of_the_day' => false]);
        $p   = twr_db($cfg);

        twr_insert($p, [['title' => 'Congee', 'image_url' => 'https://img.example.com/c.jpg']]);

        $model = Recipes::pageModel($p, $cfg);

        assertNull($model['meal']);
        assertFalse($model['degraded'], 'switched off is not degraded');
        assertSame([], glob($cfg['paths']['data'] . '/recipe-meal-*.json') ?: []);
    },
];
