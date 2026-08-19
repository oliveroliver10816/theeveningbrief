<?php

declare(strict_types=1);

namespace TEB;

/**
 * The forecast strip, the /weather page, and the National Weather Service
 * warnings that sit on top of both.
 *
 * Two upstreams, neither of which needs an account, a key or a signup:
 *
 *   Open-Meteo      api.open-meteo.com/v1/forecast     current conditions + 5 days
 *   Weather Service api.weather.gov/alerts/active      active US warnings
 *
 * Three rules govern everything below.
 *
 * 1. THE WEATHER STRIP MAY NEVER TAKE THE PAGE DOWN. Every call is wrapped, every
 *    upstream failure is caught, and get() always returns the same array shape —
 *    with 'degraded' => true and whatever data survived. There is no code path
 *    out of this class that throws.
 *
 * 2. 'current' IS ALWAYS AN ARRAY and 'days'/'alerts' are always lists. When the
 *    forecast is unavailable the values inside 'current' are null and
 *    current['ok'] is false. A renderer never has to guard against null before
 *    it can reach a key.
 *
 * 3. api.weather.gov WILL NOT ACCEPT A COMMA-JOINED severity LIST. The parameter
 *    has to be REPEATED — severity=Extreme&severity=Severe. Do not "tidy" that
 *    into one comma-separated value: it is the documented spelling, it is what
 *    alertsUrl() builds, and there is a test asserting the URL carries two
 *    separate severity= pairs and no comma. The same endpoint answers 403 to a
 *    request with no User-Agent, so every call here sends one built from config
 *    with a contact URL in it.
 *
 * Responses are cached in the data/ directory for fifteen minutes, keyed by the
 * rounded latitude and longitude, and a cache that has aged out is still used as
 * a last resort when the upstream is unreachable — a six-hour-old temperature
 * marked degraded beats an empty strip.
 *
 * No geocoding service is called and the browser is never asked for a location.
 * Places come from config.weather.places, extended by a built-in table of US
 * metros, and are chosen with ?place=<slug>.
 *
 * FOR WHOEVER RENDERS THIS
 * ------------------------
 * Every string in the model is RAW TEXT and must be escaped on the way out —
 * headlines and place names come from an upstream and are not pre-escaped here,
 * because escaping twice mangles a perfectly good "Q&A" or "Mother's Day".
 *
 * The one exception is 'icon', on current and on every day: that is finished
 * SVG markup built entirely from this file's own tables, with no upstream value
 * anywhere in it, and it must be printed UNESCAPED or it appears as angle
 * brackets on the page.
 */
final class Weather
{
    /** Current conditions and the daily forecast. Free, no key. */
    public const FORECAST_ENDPOINT = 'https://api.open-meteo.com/v1/forecast';

    /** Active US warnings, as GeoJSON. Needs a real User-Agent. */
    public const ALERTS_ENDPOINT = 'https://api.weather.gov/alerts/active';

    /** How long a fetched response is served from data/ before it is refetched. */
    public const CACHE_SECONDS = 900;

    /** How stale a cached response may be and still be served when upstream is down. */
    public const STALE_MAX_SECONDS = 21600;

    /** How long a failure is remembered, so an outage cannot slow every page view. */
    public const FAILURE_CACHE_SECONDS = 120;

    /** Severities worth interrupting a front page for. Sent as REPEATED params. */
    public const ALERT_SEVERITIES = ['Extreme', 'Severe'];

    /** Days of forecast requested and returned. */
    public const FORECAST_DAYS = 5;

    /** Most warnings carried in the model, newest first. */
    public const MAX_ALERTS = 6;

    private const TIMEOUT_DEFAULT = 8;
    private const TIMEOUT_MIN     = 2;
    private const TIMEOUT_MAX     = 30;

    /** A forecast response is a few KB; anything past this is not our payload. */
    private const MAX_BYTES = 2097152;

    /**
     * US metros, so ?place= works out of the box without a geocoding call.
     * config.weather.places is merged OVER this table, so the client's own list
     * always wins and can override any coordinate here.
     */
    private const METROS = [
        'new-york'      => ['name' => 'New York',      'region' => 'NY', 'lat' => 40.7128, 'lon' => -74.0060,  'timezone' => 'America/New_York'],
        'los-angeles'   => ['name' => 'Los Angeles',   'region' => 'CA', 'lat' => 34.0522, 'lon' => -118.2437, 'timezone' => 'America/Los_Angeles'],
        'chicago'       => ['name' => 'Chicago',       'region' => 'IL', 'lat' => 41.8781, 'lon' => -87.6298,  'timezone' => 'America/Chicago'],
        'houston'       => ['name' => 'Houston',       'region' => 'TX', 'lat' => 29.7604, 'lon' => -95.3698,  'timezone' => 'America/Chicago'],
        'phoenix'       => ['name' => 'Phoenix',       'region' => 'AZ', 'lat' => 33.4484, 'lon' => -112.0740, 'timezone' => 'America/Phoenix'],
        'philadelphia'  => ['name' => 'Philadelphia',  'region' => 'PA', 'lat' => 39.9526, 'lon' => -75.1652,  'timezone' => 'America/New_York'],
        'san-antonio'   => ['name' => 'San Antonio',   'region' => 'TX', 'lat' => 29.4241, 'lon' => -98.4936,  'timezone' => 'America/Chicago'],
        'san-diego'     => ['name' => 'San Diego',     'region' => 'CA', 'lat' => 32.7157, 'lon' => -117.1611, 'timezone' => 'America/Los_Angeles'],
        'dallas'        => ['name' => 'Dallas',        'region' => 'TX', 'lat' => 32.7767, 'lon' => -96.7970,  'timezone' => 'America/Chicago'],
        'austin'        => ['name' => 'Austin',        'region' => 'TX', 'lat' => 30.2672, 'lon' => -97.7431,  'timezone' => 'America/Chicago'],
        'jacksonville'  => ['name' => 'Jacksonville',  'region' => 'FL', 'lat' => 30.3322, 'lon' => -81.6557,  'timezone' => 'America/New_York'],
        'san-jose'      => ['name' => 'San Jose',      'region' => 'CA', 'lat' => 37.3382, 'lon' => -121.8863, 'timezone' => 'America/Los_Angeles'],
        'columbus'      => ['name' => 'Columbus',      'region' => 'OH', 'lat' => 39.9612, 'lon' => -82.9988,  'timezone' => 'America/New_York'],
        'charlotte'     => ['name' => 'Charlotte',     'region' => 'NC', 'lat' => 35.2271, 'lon' => -80.8431,  'timezone' => 'America/New_York'],
        'indianapolis'  => ['name' => 'Indianapolis',  'region' => 'IN', 'lat' => 39.7684, 'lon' => -86.1581,  'timezone' => 'America/Indiana/Indianapolis'],
        'seattle'       => ['name' => 'Seattle',       'region' => 'WA', 'lat' => 47.6062, 'lon' => -122.3321, 'timezone' => 'America/Los_Angeles'],
        'denver'        => ['name' => 'Denver',        'region' => 'CO', 'lat' => 39.7392, 'lon' => -104.9903, 'timezone' => 'America/Denver'],
        'washington'    => ['name' => 'Washington',    'region' => 'DC', 'lat' => 38.9072, 'lon' => -77.0369,  'timezone' => 'America/New_York'],
        'boston'        => ['name' => 'Boston',        'region' => 'MA', 'lat' => 42.3601, 'lon' => -71.0589,  'timezone' => 'America/New_York'],
        'nashville'     => ['name' => 'Nashville',     'region' => 'TN', 'lat' => 36.1627, 'lon' => -86.7816,  'timezone' => 'America/Chicago'],
        'detroit'       => ['name' => 'Detroit',       'region' => 'MI', 'lat' => 42.3314, 'lon' => -83.0458,  'timezone' => 'America/Detroit'],
        'portland'      => ['name' => 'Portland',      'region' => 'OR', 'lat' => 45.5152, 'lon' => -122.6784, 'timezone' => 'America/Los_Angeles'],
        'las-vegas'     => ['name' => 'Las Vegas',     'region' => 'NV', 'lat' => 36.1699, 'lon' => -115.1398, 'timezone' => 'America/Los_Angeles'],
        'atlanta'       => ['name' => 'Atlanta',       'region' => 'GA', 'lat' => 33.7490, 'lon' => -84.3880,  'timezone' => 'America/New_York'],
        'miami'         => ['name' => 'Miami',         'region' => 'FL', 'lat' => 25.7617, 'lon' => -80.1918,  'timezone' => 'America/New_York'],
        'minneapolis'   => ['name' => 'Minneapolis',   'region' => 'MN', 'lat' => 44.9778, 'lon' => -93.2650,  'timezone' => 'America/Chicago'],
        'san-francisco' => ['name' => 'San Francisco', 'region' => 'CA', 'lat' => 37.7749, 'lon' => -122.4194, 'timezone' => 'America/Los_Angeles'],
        'new-orleans'   => ['name' => 'New Orleans',   'region' => 'LA', 'lat' => 29.9511, 'lon' => -90.0715,  'timezone' => 'America/Chicago'],
        'st-louis'      => ['name' => 'St. Louis',     'region' => 'MO', 'lat' => 38.6270, 'lon' => -90.1994,  'timezone' => 'America/Chicago'],
        'salt-lake-city'=> ['name' => 'Salt Lake City','region' => 'UT', 'lat' => 40.7608, 'lon' => -111.8910, 'timezone' => 'America/Denver'],
        'honolulu'      => ['name' => 'Honolulu',      'region' => 'HI', 'lat' => 21.3069, 'lon' => -157.8583, 'timezone' => 'Pacific/Honolulu'],
        'anchorage'     => ['name' => 'Anchorage',     'region' => 'AK', 'lat' => 61.2181, 'lon' => -149.9003, 'timezone' => 'America/Anchorage'],
    ];

    /**
     * Every WMO present-weather code Open-Meteo documents, mapped to a full
     * label, a short label for the five-day strip, and an icon key.
     *
     * The list is exhaustive over the documented set; describe() answers for a
     * code outside it with a neutral fallback, never with a blank and never
     * with the word "undefined".
     */
    private const WMO = [
        0  => ['Clear sky',                      'Clear',        'sun'],
        1  => ['Mainly clear',                   'Mostly clear', 'sun-cloud'],
        2  => ['Partly cloudy',                  'Partly cloudy', 'partly'],
        3  => ['Overcast',                       'Overcast',     'cloud'],
        45 => ['Fog',                            'Fog',          'fog'],
        48 => ['Depositing rime fog',            'Rime fog',     'fog'],
        51 => ['Light drizzle',                  'Light drizzle', 'drizzle'],
        53 => ['Drizzle',                        'Drizzle',      'drizzle'],
        55 => ['Dense drizzle',                  'Heavy drizzle', 'drizzle'],
        56 => ['Light freezing drizzle',         'Icy drizzle',  'sleet'],
        57 => ['Dense freezing drizzle',         'Icy drizzle',  'sleet'],
        61 => ['Slight rain',                    'Light rain',   'rain'],
        63 => ['Rain',                           'Rain',         'rain'],
        65 => ['Heavy rain',                     'Heavy rain',   'rain'],
        66 => ['Light freezing rain',            'Icy rain',     'sleet'],
        67 => ['Heavy freezing rain',            'Icy rain',     'sleet'],
        71 => ['Slight snow',                    'Light snow',   'snow'],
        73 => ['Snow',                           'Snow',         'snow'],
        75 => ['Heavy snow',                     'Heavy snow',   'snow'],
        77 => ['Snow grains',                    'Snow grains',  'snow'],
        80 => ['Slight rain showers',            'Rain showers', 'showers'],
        81 => ['Rain showers',                   'Rain showers', 'showers'],
        82 => ['Violent rain showers',           'Heavy showers', 'showers'],
        85 => ['Slight snow showers',            'Snow showers', 'snow'],
        86 => ['Heavy snow showers',             'Snow showers', 'snow'],
        95 => ['Thunderstorm',                   'Thunderstorm', 'storm'],
        96 => ['Thunderstorm with slight hail',  'Storm, hail',  'storm-hail'],
        99 => ['Thunderstorm with heavy hail',   'Storm, hail',  'storm-hail'],
    ];

    /** What describe() answers for a code nobody documented. Never blank. */
    private const WMO_FALLBACK = ['Conditions unavailable', 'Unavailable', 'unknown'];

    private const COMPASS = [
        'N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE',
        'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW',
    ];

    // =====================================================================
    //  the one public entry point
    // =====================================================================

    /**
     * The whole weather model for one place.
     *
     * @param  array<string,mixed> $cfg   the loaded config array
     * @param  string|null         $place a slug from ?place=, or null for the default
     * @return array{place:array,current:array,days:array,alerts:array,degraded:bool,
     *               stale:bool,notes:array,sources:array,places:array,fetched_at:int}
     */
    public static function get(array $cfg, ?string $place = null): array
    {
        $resolved = self::resolvePlace($cfg, $place);
        $model    = self::emptyModel($resolved, $cfg);

        try {
            $forecast = self::forecast($resolved, $cfg);
            $model['current'] = $forecast['current'];
            $model['days']    = $forecast['days'];

            if (!$forecast['ok']) {
                $model['degraded'] = true;
                $model['notes'][]  = 'forecast: ' . $forecast['note'];
            }
            if ($forecast['stale']) {
                $model['stale'] = true;
            }
            if ($forecast['fetched_at'] > 0) {
                $model['fetched_at'] = $forecast['fetched_at'];
            }

            $alerts = self::alerts($resolved, $cfg);
            $model['alerts'] = $alerts['items'];
            if (!$alerts['ok']) {
                $model['degraded'] = true;
                $model['notes'][]  = 'alerts: ' . $alerts['note'];
            }
            if ($alerts['stale']) {
                $model['stale'] = true;
            }
        } catch (\Throwable $e) {
            // Nothing in here is allowed to reach the page. If it does, the
            // strip renders empty and says so, and the rest of the site is
            // untouched.
            $model['degraded'] = true;
            $model['notes'][]  = 'weather: ' . self::short($e->getMessage());
        }

        return $model;
    }

    // =====================================================================
    //  places
    // =====================================================================

    /**
     * Every place that can be asked for, slug => place. The built-in metros
     * first, then config.weather.places layered over them so the client's list
     * wins on both membership and coordinates.
     *
     * @param  array<string,mixed> $cfg
     * @return array<string,array{slug:string,name:string,region:string,lat:float,lon:float,timezone:string,label:string}>
     */
    public static function places(array $cfg): array
    {
        $out = [];
        foreach (self::METROS as $slug => $p) {
            $out[$slug] = self::normalisePlace($slug, $p, $cfg);
        }

        $configured = $cfg['weather']['places'] ?? null;
        if (is_array($configured)) {
            foreach ($configured as $slug => $p) {
                if (!is_array($p)) {
                    continue;
                }
                $slug = self::slug((string) $slug);
                if ($slug === '') {
                    continue;
                }
                $out[$slug] = self::normalisePlace($slug, $p, $cfg);
            }
        }

        uasort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * Turn ?place=denver into a place. An unknown, empty or hostile slug falls
     * back to config.weather.default_place, and then to the first place there
     * is — this never fails and never throws.
     *
     * @param  array<string,mixed> $cfg
     * @return array{slug:string,name:string,region:string,lat:float,lon:float,timezone:string,label:string}
     */
    public static function resolvePlace(array $cfg, ?string $slug = null): array
    {
        $places = self::places($cfg);
        $want   = self::slug((string) ($slug ?? ''));

        if ($want !== '' && isset($places[$want])) {
            return $places[$want];
        }

        $default = self::slug((string) ($cfg['weather']['default_place'] ?? ''));
        if ($default !== '' && isset($places[$default])) {
            return $places[$default];
        }

        $first = $places === [] ? null : reset($places);

        return is_array($first)
            ? $first
            : self::normalisePlace('new-york', self::METROS['new-york'], $cfg);
    }

    // =====================================================================
    //  WMO codes
    // =====================================================================

    /**
     * Every WMO code this build has a label for. The test asserts the mapping is
     * total over it, so it is public data rather than a private constant.
     *
     * @return int[]
     */
    public static function codes(): array
    {
        return array_keys(self::WMO);
    }

    /**
     * @return array{code:int,label:string,short:string,icon_key:string,known:bool}
     */
    public static function describe(int $code): array
    {
        $row   = self::WMO[$code] ?? null;
        $known = $row !== null;
        $row   = $row ?? self::WMO_FALLBACK;

        return [
            'code'     => $code,
            'label'    => $row[0],
            'short'    => $row[1],
            'icon_key' => $row[2],
            'known'    => $known,
        ];
    }

    /** The human label for a code. Never empty, never the string "undefined". */
    public static function label(int $code): string
    {
        return self::describe($code)['label'];
    }

    /** The short label used in the five-day strip. */
    public static function shortLabel(int $code): string
    {
        return self::describe($code)['short'];
    }

    /**
     * An inline SVG for a code. Self-contained: it inherits colour through
     * currentColor, carries its own size, and needs no stylesheet, so it is safe
     * to drop anywhere in the markup. Decorative, therefore aria-hidden.
     */
    public static function icon(int $code, bool $day = true, int $size = 32): string
    {
        $key  = self::describe($code)['icon_key'];
        $size = max(12, min(160, $size));

        return self::iconFor($key, $day, $size);
    }

    /** The same icon, chosen by key rather than by code. */
    public static function iconFor(string $key, bool $day = true, int $size = 32): string
    {
        $body = self::iconBody($key, $day);

        return '<svg class="wx-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"'
            . ' width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor"'
            . ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
            . ' aria-hidden="true" focusable="false">' . $body . '</svg>';
    }

    // =====================================================================
    //  URL building
    // =====================================================================

    /**
     * The forecast request. Fahrenheit is asked for because the site is US-first;
     * Celsius is carried alongside it, converted exactly, so a renderer can show
     * either without a second call.
     *
     * @param array{lat:float,lon:float,timezone:string} $place
     * @param array<string,mixed>                        $cfg
     */
    public static function forecastUrl(array $place, array $cfg = []): string
    {
        $params = [
            'latitude'          => self::coord((float) $place['lat']),
            'longitude'         => self::coord((float) $place['lon']),
            'current'           => 'temperature_2m,relative_humidity_2m,apparent_temperature,is_day,'
                                 . 'precipitation,weather_code,wind_speed_10m,wind_direction_10m,'
                                 . 'wind_gusts_10m,pressure_msl',
            'daily'             => 'weather_code,temperature_2m_max,temperature_2m_min,sunrise,sunset,'
                                 . 'precipitation_probability_max,wind_speed_10m_max',
            'timezone'          => (string) $place['timezone'],
            'temperature_unit'  => 'fahrenheit',
            'wind_speed_unit'   => 'mph',
            'precipitation_unit' => 'inch',
            'forecast_days'     => (string) self::FORECAST_DAYS,
        ];

        $q = [];
        foreach ($params as $k => $v) {
            $q[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }

        return self::endpoint($cfg, 'forecast', self::FORECAST_ENDPOINT) . '?' . implode('&', $q);
    }

    /**
     * The active-warnings request for one US state.
     *
     * ⚠ severity is REPEATED, once per value. api.weather.gov does not accept
     *   severity=Extreme,Severe — the comma form is not the documented spelling
     *   and has been observed to be rejected outright. Every value is
     *   rawurlencode()d individually, so a comma can never appear in the query
     *   string, not even percent-escaped. There is a test on exactly this.
     *
     * @param string[]            $severities
     * @param array<string,mixed> $cfg
     */
    public static function alertsUrl(string $region, array $severities = self::ALERT_SEVERITIES, array $cfg = []): string
    {
        $q = [];

        $region = strtoupper(preg_replace('/[^A-Za-z]/', '', $region) ?? '');
        if ($region !== '') {
            $q[] = 'area=' . rawurlencode(substr($region, 0, 2));
        }

        foreach ($severities as $s) {
            $s = trim((string) $s);
            if ($s === '' || strpos($s, ',') !== false) {
                continue;                       // never smuggle a list into one value
            }
            $q[] = 'severity=' . rawurlencode($s);
        }

        // Actual alerts only: excludes the exercise, test and system messages the
        // feed also carries, which would otherwise render as real warnings.
        $q[] = 'status=' . rawurlencode('actual');
        $q[] = 'message_type=' . rawurlencode('alert');

        return self::endpoint($cfg, 'alerts', self::ALERTS_ENDPOINT) . '?' . implode('&', $q);
    }

    // =====================================================================
    //  forecast
    // =====================================================================

    /**
     * @param  array<string,mixed> $place
     * @param  array<string,mixed> $cfg
     * @return array{ok:bool,note:string,stale:bool,fetched_at:int,current:array,days:array}
     */
    private static function forecast(array $place, array $cfg): array
    {
        $url  = self::forecastUrl($place, $cfg);
        $read = self::cached('weather-' . self::latLonKey($place) . '-' . self::fingerprint($url), $url, $cfg);

        $out = [
            'ok'         => $read['ok'],
            'note'       => $read['note'],
            'stale'      => $read['stale'],
            'fetched_at' => $read['ts'],
            'current'    => self::emptyCurrent($place),
            'days'       => [],
        ];

        if ($read['body'] === '') {
            return $out;
        }

        $json = json_decode($read['body'], true);
        if (!is_array($json) || !is_array($json['current'] ?? null)) {
            $out['ok']   = false;
            $out['note'] = 'the forecast response was not the shape we expect';

            return $out;
        }

        $tz      = self::timezone((string) $place['timezone']);
        $daily   = is_array($json['daily'] ?? null) ? $json['daily'] : [];
        $out['days'] = self::days($daily, $tz);
        $today   = $out['days'][0] ?? [];

        $out['current'] = self::current($json['current'], $place, $tz, $today);

        return $out;
    }

    /**
     * @param  array<string,mixed> $c    the API's "current" block
     * @param  array<string,mixed> $place
     * @param  array<string,mixed> $today
     * @return array<string,mixed>
     */
    private static function current(array $c, array $place, \DateTimeZone $tz, array $today): array
    {
        $code = self::intOrNull($c['weather_code'] ?? null);
        $desc = self::describe($code ?? -1);
        $isDay = ((int) ($c['is_day'] ?? 1)) === 1;

        $tempF  = self::floatOrNull($c['temperature_2m'] ?? null);
        $feelsF = self::floatOrNull($c['apparent_temperature'] ?? null);
        $windMph = self::floatOrNull($c['wind_speed_10m'] ?? null);
        $gustMph = self::floatOrNull($c['wind_gusts_10m'] ?? null);
        $dir     = self::intOrNull($c['wind_direction_10m'] ?? null);
        $compass = $dir === null ? '' : self::compass($dir);

        $observed = self::localTime(self::str($c['time'] ?? ''), $tz);

        $out = [
            'ok'          => $tempF !== null || $code !== null,
            'code'        => $code,
            'label'       => $desc['label'],
            'short'       => $desc['short'],
            'icon_key'    => $desc['icon_key'],
            'icon'        => self::iconFor($desc['icon_key'], $isDay),
            'is_day'      => $isDay,

            'temp_f'      => self::roundOrNull($tempF),
            'temp_c'      => self::roundOrNull(self::toCelsius($tempF)),
            'temp_f_raw'  => $tempF,
            'temp_c_raw'  => self::toCelsius($tempF),
            'feels_f'     => self::roundOrNull($feelsF),
            'feels_c'     => self::roundOrNull(self::toCelsius($feelsF)),

            'humidity'    => self::intOrNull($c['relative_humidity_2m'] ?? null),
            'wind_mph'    => self::roundOrNull($windMph),
            'wind_kph'    => self::roundOrNull($windMph === null ? null : $windMph * 1.609344),
            'wind_dir'    => $dir,
            'wind_compass' => $compass,
            'gust_mph'    => self::roundOrNull($gustMph),
            'precip_in'   => self::floatOrNull($c['precipitation'] ?? null),
            'pressure_hpa' => self::roundOrNull(self::floatOrNull($c['pressure_msl'] ?? null)),

            'high_f'      => $today['high_f'] ?? null,
            'low_f'       => $today['low_f'] ?? null,
            'high_c'      => $today['high_c'] ?? null,
            'low_c'       => $today['low_c'] ?? null,

            'sunrise'       => $today['sunrise'] ?? null,
            'sunset'        => $today['sunset'] ?? null,
            'sunrise_label' => (string) ($today['sunrise_label'] ?? ''),
            'sunset_label'  => (string) ($today['sunset_label'] ?? ''),

            'observed_at'    => $observed === null ? null : $observed->format(DATE_ATOM),
            'observed_ts'    => $observed === null ? null : $observed->getTimestamp() * 1000,
            'observed_label' => $observed === null ? '' : self::clockLabel($observed),

            'place'       => (string) $place['name'],
        ];

        // Two ready-made lines, because the design asks for exactly these two
        // and building them here keeps the same wording everywhere they appear.
        $out['summary_line'] = trim($desc['label'] . ' · ' . (string) $place['name'], ' ·');

        $bits = [];
        if ($out['high_f'] !== null && $out['low_f'] !== null) {
            $bits[] = 'H ' . $out['high_f'] . '° / L ' . $out['low_f'] . '°';
        }
        if ($out['wind_mph'] !== null && $out['wind_mph'] > 0) {
            $bits[] = trim('wind ' . $compass . ' ' . $out['wind_mph']);
        }
        $out['hilo_line'] = implode(' · ', $bits);

        return $out;
    }

    /**
     * @param  array<string,mixed> $daily
     * @return array<int,array<string,mixed>>
     */
    private static function days(array $daily, \DateTimeZone $tz): array
    {
        $dates = is_array($daily['time'] ?? null) ? array_values($daily['time']) : [];
        if ($dates === []) {
            return [];
        }

        $codes  = is_array($daily['weather_code'] ?? null) ? array_values($daily['weather_code']) : [];
        $highs  = is_array($daily['temperature_2m_max'] ?? null) ? array_values($daily['temperature_2m_max']) : [];
        $lows   = is_array($daily['temperature_2m_min'] ?? null) ? array_values($daily['temperature_2m_min']) : [];
        $rises  = is_array($daily['sunrise'] ?? null) ? array_values($daily['sunrise']) : [];
        $sets   = is_array($daily['sunset'] ?? null) ? array_values($daily['sunset']) : [];
        $pops   = is_array($daily['precipitation_probability_max'] ?? null) ? array_values($daily['precipitation_probability_max']) : [];
        $winds  = is_array($daily['wind_speed_10m_max'] ?? null) ? array_values($daily['wind_speed_10m_max']) : [];

        $todayIso = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $out      = [];

        foreach ($dates as $i => $iso) {
            $iso = self::str($iso);
            if ($iso === '') {
                continue;
            }
            $day  = self::localTime($iso, $tz);
            $code = self::intOrNull($codes[$i] ?? null);
            $desc = self::describe($code ?? -1);

            $highF = self::floatOrNull($highs[$i] ?? null);
            $lowF  = self::floatOrNull($lows[$i] ?? null);
            $rise  = self::localTime(self::str($rises[$i] ?? ''), $tz);
            $set   = self::localTime(self::str($sets[$i] ?? ''), $tz);

            $high = self::roundOrNull($highF);
            $low  = self::roundOrNull($lowF);

            $out[] = [
                'iso'           => $iso,
                'is_today'      => $iso === $todayIso,
                'name'          => $day === null ? '' : strtoupper($day->format('D')),
                'name_long'     => $day === null ? '' : $day->format('l'),
                'date_label'    => $day === null ? '' : $day->format('M j'),
                'code'          => $code,
                'label'         => $desc['label'],
                'short'         => $desc['short'],
                'icon_key'      => $desc['icon_key'],
                'icon'          => self::iconFor($desc['icon_key'], true),
                'high_f'        => $high,
                'low_f'         => $low,
                'high_c'        => self::roundOrNull(self::toCelsius($highF)),
                'low_c'         => self::roundOrNull(self::toCelsius($lowF)),
                'range_f'       => ($high === null || $low === null) ? '' : $high . '/' . $low,
                'precip_chance' => self::intOrNull($pops[$i] ?? null),
                'wind_mph'      => self::roundOrNull(self::floatOrNull($winds[$i] ?? null)),
                'sunrise'       => $rise === null ? null : $rise->format(DATE_ATOM),
                'sunset'        => $set === null ? null : $set->format(DATE_ATOM),
                'sunrise_label' => $rise === null ? '' : self::clockLabel($rise),
                'sunset_label'  => $set === null ? '' : self::clockLabel($set),
            ];
        }

        return $out;
    }

    // =====================================================================
    //  alerts
    // =====================================================================

    /**
     * @param  array<string,mixed> $place
     * @param  array<string,mixed> $cfg
     * @return array{ok:bool,note:string,stale:bool,items:array}
     */
    private static function alerts(array $place, array $cfg): array
    {
        $region = strtoupper(self::str($place['region'] ?? ''));
        if ($region === '') {
            // A place outside the United States has no NWS warnings to miss, so
            // there is nothing to call and nothing degraded about not calling it.
            return ['ok' => true, 'note' => '', 'stale' => false, 'items' => []];
        }

        $url  = self::alertsUrl($region, self::ALERT_SEVERITIES, $cfg);
        $read = self::cached('weather-alerts-' . strtolower($region) . '-' . self::fingerprint($url), $url, $cfg);

        $out = ['ok' => $read['ok'], 'note' => $read['note'], 'stale' => $read['stale'], 'items' => []];

        if ($read['body'] === '') {
            return $out;
        }

        $json = json_decode($read['body'], true);
        if (!is_array($json) || !is_array($json['features'] ?? null)) {
            $out['ok']   = false;
            $out['note'] = 'the warnings response was not the shape we expect';

            return $out;
        }

        $tz    = self::timezone((string) $place['timezone']);
        $items = [];
        foreach ($json['features'] as $feature) {
            if (!is_array($feature) || !is_array($feature['properties'] ?? null)) {
                continue;
            }
            $alert = self::alert($feature['properties'], $tz);
            if ($alert !== null) {
                $items[] = $alert;
            }
        }

        usort($items, static function (array $a, array $b): int {
            if ($a['rank'] !== $b['rank']) {
                return $a['rank'] <=> $b['rank'];
            }

            return $b['sent_ts'] <=> $a['sent_ts'];
        });

        $out['items'] = array_slice($items, 0, self::MAX_ALERTS);

        return $out;
    }

    /**
     * @param  array<string,mixed> $p
     * @return array<string,mixed>|null
     */
    private static function alert(array $p, \DateTimeZone $tz): ?array
    {
        $event = self::str($p['event'] ?? '');
        if ($event === '') {
            return null;
        }

        $severity = self::str($p['severity'] ?? '');
        $headline = self::str($p['headline'] ?? '');
        if ($headline === '') {
            $headline = $event;
        }

        $sent    = self::localTime(self::str($p['sent'] ?? ''), $tz);
        $onset   = self::localTime(self::str($p['onset'] ?? ($p['effective'] ?? '')), $tz);
        $ends    = self::localTime(self::str($p['ends'] ?? ($p['expires'] ?? '')), $tz);

        $web = self::str($p['web'] ?? '');
        $web = preg_replace('#^http://#i', 'https://', $web) ?? $web;
        if (!preg_match('#^https://#i', $web)) {
            $web = 'https://www.weather.gov/';
        }

        return [
            'id'          => self::str($p['id'] ?? ''),
            'event'       => $event,
            'severity'    => $severity,
            'urgency'     => self::str($p['urgency'] ?? ''),
            'certainty'   => self::str($p['certainty'] ?? ''),
            'headline'    => $headline,
            'area'        => self::areaLabel(self::str($p['areaDesc'] ?? '')),
            'area_full'   => self::str($p['areaDesc'] ?? ''),
            'sender'      => self::str($p['senderName'] ?? ''),
            'description' => self::clamp(self::whitespace(self::str($p['description'] ?? '')), 600),
            'instruction' => self::clamp(self::whitespace(self::str($p['instruction'] ?? '')), 400),
            'url'         => $web,
            'sent_label'  => $sent === null ? '' : self::clockLabel($sent),
            'sent_ts'     => $sent === null ? 0 : $sent->getTimestamp(),
            'starts_label' => $onset === null ? '' : self::stampLabel($onset),
            'ends_label'  => $ends === null ? '' : self::stampLabel($ends),
            'rank'        => strcasecmp($severity, 'Extreme') === 0 ? 0 : 1,
        ];
    }

    /** "Carroll; Warren; Tippecanoe; …" -> "Carroll, Warren and 22 more". */
    private static function areaLabel(string $areaDesc): string
    {
        $parts = array_values(array_filter(array_map('trim', explode(';', $areaDesc)), static fn (string $s): bool => $s !== ''));
        if ($parts === []) {
            return '';
        }
        if (count($parts) <= 2) {
            return implode(' and ', $parts);
        }

        return $parts[0] . ', ' . $parts[1] . ' and ' . (count($parts) - 2) . ' more';
    }

    // =====================================================================
    //  fetch + cache
    // =====================================================================

    /**
     * Read a URL through the fifteen-minute cache in data/.
     *
     * A fresh cache entry is returned without a request. A stale one triggers a
     * request, and is still handed back if that request fails — which is the
     * whole point: the strip keeps showing the last known weather instead of
     * going blank the moment an upstream has a bad minute.
     *
     * @param  array<string,mixed> $cfg
     * @return array{ok:bool,body:string,note:string,stale:bool,ts:int}
     */
    private static function cached(string $key, string $url, array $cfg): array
    {
        $ttl   = self::cacheSeconds($cfg);
        $entry = self::cacheRead($key, $cfg);
        $now   = time();

        if ($entry !== null && $entry['ok'] && ($now - $entry['ts']) < $ttl) {
            return ['ok' => true, 'body' => $entry['body'], 'note' => '', 'stale' => false, 'ts' => $entry['ts'] * 1000];
        }

        // A very recent failure is not retried on every page view.
        if ($entry !== null && !$entry['ok'] && ($now - $entry['ts']) < self::FAILURE_CACHE_SECONDS) {
            return ['ok' => false, 'body' => '', 'note' => $entry['note'] . ' (cached failure)', 'stale' => false, 'ts' => 0];
        }

        $res = self::http($url, $cfg);

        if ($res['ok'] && $res['body'] !== '') {
            self::cacheWrite($key, ['ok' => true, 'ts' => $now, 'body' => $res['body'], 'note' => '', 'url' => $url], $cfg);

            return ['ok' => true, 'body' => $res['body'], 'note' => '', 'stale' => false, 'ts' => $now * 1000];
        }

        $note = $res['error'] !== '' ? $res['error'] : ('HTTP ' . $res['status']);

        if ($entry !== null && $entry['ok'] && ($now - $entry['ts']) < self::STALE_MAX_SECONDS) {
            return [
                'ok'    => false,
                'body'  => $entry['body'],
                'note'  => $note . ' — serving the cached copy from ' . self::ago($now - $entry['ts']),
                'stale' => true,
                'ts'    => $entry['ts'] * 1000,
            ];
        }

        self::cacheWrite($key, ['ok' => false, 'ts' => $now, 'body' => '', 'note' => $note, 'url' => $url], $cfg);

        return ['ok' => false, 'body' => '', 'note' => $note, 'stale' => false, 'ts' => 0];
    }

    /**
     * One GET. Never throws, never warns, never follows a redirect off http(s).
     *
     * @param  array<string,mixed> $cfg
     * @return array{ok:bool,status:int,body:string,error:string}
     */
    private static function http(string $url, array $cfg): array
    {
        $out = ['ok' => false, 'status' => 0, 'body' => '', 'error' => ''];

        if (preg_match('#^https?://#i', $url) !== 1) {
            $out['error'] = 'not an http(s) url';

            return $out;
        }

        $timeout = self::timeoutSeconds($cfg);
        $headers = [
            'Accept: application/geo+json, application/json;q=0.9, */*;q=0.5',
            'Accept-Language: en-US,en;q=0.8',
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_CONNECTTIMEOUT => max(2, (int) ceil($timeout / 2)),
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_USERAGENT      => self::userAgent($cfg),
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_ENCODING       => '',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
                // A redirect must never take us to file:// or gopher://.
                @curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
                @curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            }
            $body = curl_exec($ch);
            $out['status'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($body === false) {
                $out['error'] = self::short((string) curl_error($ch));
            }
            curl_close($ch);

            $body = is_string($body) ? $body : '';
        } else {
            $context = stream_context_create(['http' => [
                'method'        => 'GET',
                'timeout'       => $timeout,
                'ignore_errors' => true,
                'user_agent'    => self::userAgent($cfg),
                'header'        => implode("\r\n", $headers),
                'max_redirects' => 4,
            ]]);
            $body = @file_get_contents($url, false, $context);
            $body = is_string($body) ? $body : '';
            foreach (($http_response_header ?? []) as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m) === 1) {
                    $out['status'] = (int) $m[1];
                }
            }
            if ($body === '' && $out['status'] === 0 && $out['error'] === '') {
                $out['error'] = 'the request could not be made';
            }
        }

        if (strlen($body) > self::MAX_BYTES) {
            $out['error'] = 'the response was larger than ' . self::MAX_BYTES . ' bytes';

            return $out;
        }

        $out['body'] = $body;
        $out['ok']   = $out['status'] >= 200 && $out['status'] < 300 && $body !== '';

        if (!$out['ok'] && $out['error'] === '') {
            $out['error'] = $body === '' && $out['status'] > 0
                ? 'HTTP ' . $out['status'] . ' with an empty body'
                : 'HTTP ' . $out['status'];
        }

        return $out;
    }

    /**
     * The User-Agent both upstreams see. api.weather.gov answers 403 to a
     * request without one and asks that it identify the application and carry a
     * contact, so it is built from config — nothing about the brand is compiled
     * in here.
     *
     * @param array<string,mixed> $cfg
     */
    public static function userAgent(array $cfg): string
    {
        $site = is_array($cfg['site'] ?? null) ? $cfg['site'] : [];

        $name  = self::str($site['short_name'] ?? '');
        $name  = $name !== '' ? $name : self::str($site['name'] ?? '');
        $token = (string) preg_replace('/[^A-Za-z0-9]+/', '', $name);
        $token = $token === '' ? 'NewsAggregator' : substr($token, 0, 40);

        $domain = self::str($site['domain'] ?? '');
        $domain = (string) preg_replace('#^https?://#i', '', $domain);
        $domain = (string) preg_replace('/[^A-Za-z0-9.\-]/', '', rtrim($domain, '/'));

        $contact = $domain !== '' ? '; +https://' . $domain . '/about' : '';

        return 'Mozilla/5.0 (compatible; ' . $token . 'Weather/1.0' . $contact . '; weather strip) PHP/'
            . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    /**
     * @param  array<string,mixed> $cfg
     * @return array{ok:bool,ts:int,body:string,note:string}|null
     */
    private static function cacheRead(string $key, array $cfg): ?array
    {
        $file = self::cacheFile($key, $cfg);
        if ($file === '' || !is_file($file) || !is_readable($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $json = json_decode($raw, true);
        if (!is_array($json) || !isset($json['ts'])) {
            return null;
        }

        return [
            'ok'   => (bool) ($json['ok'] ?? false),
            'ts'   => (int) $json['ts'],
            'body' => (string) ($json['body'] ?? ''),
            'note' => (string) ($json['note'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $cfg
     */
    private static function cacheWrite(string $key, array $entry, array $cfg): void
    {
        $file = self::cacheFile($key, $cfg);
        if ($file === '') {
            return;
        }

        $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return;
        }

        // Written to a neighbouring temp file and renamed, so a reader never sees
        // half a file and two writers cannot interleave.
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
        }
    }

    /** Absolute path of the cache file for a key, or '' when data/ is unusable. */
    private static function cacheFile(string $key, array $cfg): string
    {
        $dir = self::dataDir($cfg);
        if ($dir === '') {
            return '';
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return '';
        }
        if (!is_writable($dir)) {
            return '';
        }

        $key = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $key);

        return rtrim($dir, '/\\') . '/' . substr($key, 0, 120) . '.json';
    }

    /**
     * The writable data directory, resolved exactly the way the ingester does
     * it, so both land in the same folder on every host.
     *
     * @param array<string,mixed> $cfg
     */
    public static function dataDir(array $cfg): string
    {
        $root = self::str($cfg['root'] ?? '');
        $root = $root !== '' ? $root : dirname(__DIR__);

        $dir = self::str($cfg['paths']['data'] ?? '');
        if ($dir === '') {
            $sqlite = self::str($cfg['db']['sqlite_path'] ?? '');
            $dir    = $sqlite !== '' ? dirname($sqlite) : 'data';
        }
        if ($dir === '' || $dir === '.') {
            $dir = 'data';
        }
        if (preg_match('#^([A-Za-z]:[\\\\/]|/)#', $dir) !== 1) {
            $dir = rtrim($root, '/\\') . '/' . ltrim($dir, '/\\');
        }

        return rtrim(str_replace('\\', '/', $dir), '/');
    }

    // =====================================================================
    //  small helpers
    // =====================================================================

    /**
     * The base URL for one upstream. config.weather.endpoints.{forecast,alerts}
     * overrides it — that exists so the suite can point the class at a local
     * server, and so a host that has to proxy outbound calls can be pointed at
     * the proxy. Absent (which is the shipped state) it is the public endpoint.
     *
     * @param array<string,mixed> $cfg
     */
    private static function endpoint(array $cfg, string $which, string $fallback): string
    {
        $set = $cfg['weather']['endpoints'] ?? null;
        if (is_array($set)) {
            $url = self::str($set[$which] ?? '');
            if ($url !== '' && preg_match('#^https?://#i', $url) === 1) {
                return rtrim($url, '?&');
            }
        }

        return $fallback;
    }

    /** @param array<string,mixed> $cfg */
    private static function cacheSeconds(array $cfg): int
    {
        $v = $cfg['weather']['cache_seconds'] ?? null;

        return $v === null ? self::CACHE_SECONDS : max(0, min(86400, (int) $v));
    }

    /** @param array<string,mixed> $cfg */
    private static function timeoutSeconds(array $cfg): int
    {
        $v = $cfg['weather']['timeout_seconds'] ?? null;
        if ($v === null) {
            return self::TIMEOUT_DEFAULT;
        }

        return max(self::TIMEOUT_MIN, min(self::TIMEOUT_MAX, (int) $v));
    }

    /** The cache key component: latitude and longitude, rounded. */
    private static function latLonKey(array $place): string
    {
        return number_format((float) $place['lat'], 2, '.', '')
            . '_' . number_format((float) $place['lon'], 2, '.', '');
    }

    /** Short digest of the exact request, so changing units cannot serve a stale shape. */
    private static function fingerprint(string $url): string
    {
        return substr(hash('sha256', $url), 0, 8);
    }

    /**
     * @param  array<string,mixed> $place
     * @param  array<string,mixed> $cfg
     * @return array<string,mixed>
     */
    private static function emptyModel(array $place, array $cfg): array
    {
        $picker = [];
        foreach (self::places($cfg) as $slug => $p) {
            $picker[$slug] = $p['label'];
        }

        return [
            'place'      => $place,
            'current'    => self::emptyCurrent($place),
            'days'       => [],
            'alerts'     => [],
            'degraded'   => false,
            'stale'      => false,
            'notes'      => [],
            'places'     => $picker,
            'fetched_at' => 0,
            'units'      => ['temperature' => 'F', 'wind' => 'mph', 'precipitation' => 'in'],
            'sources'    => [
                ['name' => 'Open-Meteo', 'url' => 'https://open-meteo.com/'],
                ['name' => 'National Weather Service', 'url' => 'https://www.weather.gov/'],
            ],
        ];
    }

    /**
     * The shape 'current' always has. Every value a renderer might print is
     * present and null; nothing has to be guarded before it is read.
     *
     * @param  array<string,mixed> $place
     * @return array<string,mixed>
     */
    private static function emptyCurrent(array $place): array
    {
        $desc = self::describe(-1);

        return [
            'ok' => false,
            'code' => null, 'label' => $desc['label'], 'short' => $desc['short'],
            'icon_key' => $desc['icon_key'], 'icon' => self::iconFor($desc['icon_key'], true),
            'is_day' => true,
            'temp_f' => null, 'temp_c' => null, 'temp_f_raw' => null, 'temp_c_raw' => null,
            'feels_f' => null, 'feels_c' => null,
            'humidity' => null, 'wind_mph' => null, 'wind_kph' => null, 'wind_dir' => null,
            'wind_compass' => '', 'gust_mph' => null, 'precip_in' => null, 'pressure_hpa' => null,
            'high_f' => null, 'low_f' => null, 'high_c' => null, 'low_c' => null,
            'sunrise' => null, 'sunset' => null, 'sunrise_label' => '', 'sunset_label' => '',
            'observed_at' => null, 'observed_ts' => null, 'observed_label' => '',
            'place' => (string) ($place['name'] ?? ''),
            'summary_line' => (string) ($place['name'] ?? ''),
            'hilo_line' => '',
        ];
    }

    /**
     * @param  array<string,mixed> $p
     * @param  array<string,mixed> $cfg
     * @return array{slug:string,name:string,region:string,lat:float,lon:float,timezone:string,label:string}
     */
    private static function normalisePlace(string $slug, array $p, array $cfg): array
    {
        $name = self::str($p['name'] ?? '');
        if ($name === '') {
            $name = ucwords(str_replace('-', ' ', $slug));
        }

        $region = strtoupper(substr(self::str($p['region'] ?? ''), 0, 2));
        $region = (string) preg_replace('/[^A-Z]/', '', $region);

        $tz = self::str($p['timezone'] ?? '');
        if ($tz === '' || !in_array($tz, timezone_identifiers_list(), true)) {
            $tz = self::str($cfg['site']['timezone'] ?? '');
        }
        if ($tz === '' || !in_array($tz, timezone_identifiers_list(), true)) {
            $tz = 'UTC';
        }

        $lat = isset($p['lat']) ? (float) $p['lat'] : 0.0;
        $lon = isset($p['lon']) ? (float) $p['lon'] : 0.0;
        $lat = max(-90.0, min(90.0, $lat));
        $lon = max(-180.0, min(180.0, $lon));

        return [
            'slug'     => $slug,
            'name'     => $name,
            'region'   => $region,
            'lat'      => round($lat, 4),
            'lon'      => round($lon, 4),
            'timezone' => $tz,
            'label'    => $region === '' ? $name : $name . ', ' . $region,
        ];
    }

    private static function timezone(string $tz): \DateTimeZone
    {
        try {
            return new \DateTimeZone($tz === '' ? 'UTC' : $tz);
        } catch (\Throwable $e) {
            return new \DateTimeZone('UTC');
        }
    }

    /** Parse a local timestamp from either upstream. Null rather than a wrong time. */
    private static function localTime(string $raw, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            // Open-Meteo sends local wall-clock time with no offset, so the zone
            // has to be supplied; the alerts feed sends its own offset, which
            // DateTimeImmutable honours and the setTimezone then normalises.
            $d = new \DateTimeImmutable($raw, $tz);

            return $d->setTimezone($tz);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** "7:45 a.m." — the same clock wording the cards use. */
    private static function clockLabel(\DateTimeImmutable $d): string
    {
        return $d->format('g:i') . ' ' . ($d->format('a') === 'am' ? 'a.m.' : 'p.m.');
    }

    /** "Wed 2:00 p.m." for a warning's start and end. */
    private static function stampLabel(\DateTimeImmutable $d): string
    {
        return $d->format('D') . ' ' . self::clockLabel($d);
    }

    private static function compass(int $degrees): string
    {
        $degrees = (($degrees % 360) + 360) % 360;

        return self::COMPASS[(int) round($degrees / 22.5) % 16];
    }

    private static function toCelsius(?float $f): ?float
    {
        return $f === null ? null : round(($f - 32.0) * 5.0 / 9.0, 1);
    }

    /** @param mixed $v */
    private static function floatOrNull($v): ?float
    {
        if ($v === null || $v === '' || is_array($v)) {
            return null;
        }
        if (!is_numeric($v)) {
            return null;
        }

        return (float) $v;
    }

    /** @param mixed $v */
    private static function intOrNull($v): ?int
    {
        $f = self::floatOrNull($v);

        return $f === null ? null : (int) round($f);
    }

    private static function roundOrNull(?float $v): ?int
    {
        return $v === null ? null : (int) round($v);
    }

    /** @param mixed $v */
    private static function str($v): string
    {
        return is_string($v) ? trim($v) : (is_scalar($v) ? trim((string) $v) : '');
    }

    private static function slug(string $v): string
    {
        $v = strtolower(trim($v));
        $v = (string) preg_replace('/[^a-z0-9]+/', '-', $v);

        return trim($v, '-');
    }

    private static function whitespace(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    private static function clamp(string $s, int $max): string
    {
        if ($s === '' || mb_strlen($s) <= $max) {
            return $s;
        }
        $cut = mb_substr($s, 0, $max);
        $sp  = mb_strrpos($cut, ' ');
        if ($sp !== false && $sp > (int) ($max * 0.6)) {
            $cut = mb_substr($cut, 0, $sp);
        }

        return rtrim($cut, " \t\n\r,;:") . '…';
    }

    private static function short(string $s): string
    {
        $s = self::whitespace($s);

        return mb_strlen($s) > 160 ? mb_substr($s, 0, 157) . '…' : $s;
    }

    private static function ago(int $seconds): string
    {
        if ($seconds < 90) {
            return 'a moment ago';
        }
        $minutes = (int) round($seconds / 60);
        if ($minutes < 60) {
            return $minutes . ' minutes ago';
        }
        $hours = (int) round($minutes / 60);

        return $hours . ($hours === 1 ? ' hour ago' : ' hours ago');
    }

    private static function coord(float $v): string
    {
        return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') ?: '0';
    }

    // =====================================================================
    //  icons
    // =====================================================================

    private const CLOUD = '<path d="M10.6 24.5h11.2a5.4 5.4 0 0 0 .5-10.8 8 8 0 0 0-15.4 2 4.6 4.6 0 0 0 3.7 8.8Z"/>';

    /** A smaller cloud, tucked low and right, so the sun still dominates. */
    private const CLOUD_SMALL = '<path d="M17.4 27h9.2a4.1 4.1 0 0 0 .4-8.2 6.1 6.1 0 0 0-11.7 1.5 3.5 3.5 0 0 0 2.1 6.7Z"/>';

    private static function iconBody(string $key, bool $day): string
    {
        switch ($key) {
            case 'sun':
                return $day ? self::sun(16.0, 16.0, 6.0) : self::moon();

            case 'sun-cloud':
                // "Mainly clear" is mostly sky. It has to be visibly different
                // from "partly cloudy" or the two codes are a distinction the
                // page never makes.
                return ($day ? self::sun(11.6, 10.6, 4.4) : self::moonSmall()) . self::CLOUD_SMALL;

            case 'partly':
                return ($day ? self::sun(11.5, 11.5, 4.0) : self::moonSmall()) . self::CLOUD;

            case 'cloud':
                return self::CLOUD;

            case 'fog':
                return self::CLOUD
                    . '<path d="M6 27.5h13M23 27.5h3"/>';

            case 'drizzle':
                return self::CLOUD . self::drops([12.0, 16.0, 20.0], 27.0, 2.0);

            case 'rain':
                return self::CLOUD . self::drops([11.5, 16.0, 20.5], 27.0, 3.4);

            case 'showers':
                return self::CLOUD
                    . '<path d="M12.6 26.4 10.6 30M17.6 26.4 15.6 30M22.6 26.4 20.6 30"/>';

            case 'sleet':
                return self::CLOUD
                    . '<path d="M12 27v3.2"/>'
                    . self::flake(20.0, 28.4, 2.4);

            case 'snow':
                return self::CLOUD . self::flake(12.0, 28.2, 2.4) . self::flake(20.0, 28.2, 2.4);

            case 'storm':
                return self::CLOUD . self::bolt();

            case 'storm-hail':
                return self::CLOUD . self::bolt()
                    . '<circle cx="11" cy="29" r="1"/><circle cx="22" cy="29" r="1"/>';

            case 'unknown':
            default:
                // Neutral, and deliberately not a weather claim: a cloud with a
                // dash where the condition would be.
                return self::CLOUD . '<path d="M13 28.6h6"/>';
        }
    }

    private static function sun(float $cx, float $cy, float $r): string
    {
        $svg = '<circle cx="' . self::n($cx) . '" cy="' . self::n($cy) . '" r="' . self::n($r) . '"/>';
        $in  = $r + 2.4;
        $out = $r + 5.0;
        for ($i = 0; $i < 8; $i++) {
            $a  = deg2rad($i * 45.0);
            $x1 = $cx + cos($a) * $in;
            $y1 = $cy + sin($a) * $in;
            $x2 = $cx + cos($a) * $out;
            $y2 = $cy + sin($a) * $out;
            $svg .= '<path d="M' . self::n($x1) . ' ' . self::n($y1) . 'L' . self::n($x2) . ' ' . self::n($y2) . '"/>';
        }

        return $svg;
    }

    private static function moon(): string
    {
        return '<path d="M23.4 20.6A9.4 9.4 0 0 1 12.6 8.1a8.9 8.9 0 1 0 10.8 12.5Z"/>';
    }

    private static function moonSmall(): string
    {
        return '<path d="M15.6 12.7A6.2 6.2 0 0 1 8.5 4.5a5.9 5.9 0 1 0 7.1 8.2Z"/>';
    }

    /** @param float[] $xs */
    private static function drops(array $xs, float $y, float $length): string
    {
        $svg = '';
        foreach ($xs as $x) {
            $svg .= '<path d="M' . self::n($x) . ' ' . self::n($y)
                . 'v' . self::n($length) . '"/>';
        }

        return $svg;
    }

    private static function flake(float $cx, float $cy, float $r): string
    {
        $svg = '';
        for ($i = 0; $i < 3; $i++) {
            $a  = deg2rad(30.0 + $i * 60.0);
            $x1 = $cx - cos($a) * $r;
            $y1 = $cy - sin($a) * $r;
            $x2 = $cx + cos($a) * $r;
            $y2 = $cy + sin($a) * $r;
            $svg .= '<path d="M' . self::n($x1) . ' ' . self::n($y1) . 'L' . self::n($x2) . ' ' . self::n($y2) . '"/>';
        }

        return $svg;
    }

    private static function bolt(): string
    {
        return '<path d="M17.6 21.6 13.6 27.4h3.9L15.4 31.6"/>';
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') ?: '0';
    }
}
