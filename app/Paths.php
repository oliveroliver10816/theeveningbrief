<?php
declare(strict_types=1);

namespace TEB;

/**
 * Every URL the site emits is built here.
 *
 * THE PROBLEM THIS SOLVES
 * -----------------------
 * The same ZIP has to work when it is unzipped into a web root
 * (https://example.com/) and when it is unzipped into a subfolder
 * (https://example.com/teb/, https://example.com/a/b/c/) with nobody editing
 * anything in between — and it has to work whether or not the host honours
 * .htaccess. Four things follow from that, and they are the whole job of this
 * class:
 *
 *  1. Nothing in the codebase may write href="/section/us". Every link goes
 *     through url(), which puts the detected base path in front of it.
 *  2. The base path is derived from $_SERVER['SCRIPT_NAME'] — where the
 *     running PHP file actually lives — and never from REQUEST_URI, which is
 *     the pretty URL the visitor typed and tells you nothing about where the
 *     application is installed.
 *  3. Pretty URLs (/section/us) are only emitted once we have evidence that
 *     the rewrite rule is really running. Otherwise we emit
 *     index.php?r=/section/us, which needs no Apache module and works on
 *     every host in the world. A host that ignores .htaccess must produce a
 *     working site, not a site of 404s.
 *  4. Canonical, Open Graph and sitemap URLs take their hostname from the
 *     request, so a test upload on a staging subdomain, a CDN hostname or a
 *     raw IP describes itself correctly instead of pointing at a domain that
 *     is not serving yet. config.site.domain is only the fallback for when
 *     there is no request at all — the cron job and the command line.
 *
 * Everything here is static, cheap and side-effect free apart from one small
 * cache file, and no method on it can throw.
 */
final class Paths
{
    /** Marker header the rewrite probe sends so its own request never probes. */
    private const PROBE_HEADER = 'HTTP_X_TEB_PROBE';

    /** Route the probe asks for. Only the front controller can answer it. */
    private const PROBE_ROUTE = '/healthz';

    /** How long a "no rewrite here" answer is trusted before we look again. */
    private const NEGATIVE_TTL = 900;

    /** @var array<string,mixed> */
    private static array $server = [];

    private static string $rootDir = '';

    private static ?string $base = null;

    private static ?string $route = null;

    private static ?bool $rewrite = null;

    private static ?bool $forced = null;

    private static bool $probeAllowed = true;

    /** @var array<string,int> filemtime cache so one page render stats each asset once */
    private static array $mtimes = [];

    private static bool $cacheDirResolved = false;

    private static ?string $cacheDir = null;

    /**
     * Point the class at a request. Call it once per request from the front
     * controller, before anything renders:
     *
     *     TEB\Paths::init($_SERVER);
     *
     * Calling it again resets everything, which is what the tests rely on when
     * they walk through a table of $_SERVER fixtures.
     *
     * @param array<string,mixed> $server usually $_SERVER
     * @param string|null         $rootDir the folder holding config.php; the
     *                                     real project root by default
     */
    public static function init(array $server, ?string $rootDir = null): void
    {
        self::$server       = $server;
        self::$rootDir      = rtrim(str_replace('\\', '/', $rootDir ?? dirname(__DIR__)), '/');
        self::$base         = null;
        self::$route        = null;
        self::$rewrite      = null;
        self::$forced       = null;
        self::$probeAllowed = true;
        self::$mtimes       = [];
        self::$cacheDirResolved = false;
        self::$cacheDir         = null;
    }

    /**
     * The URL path the application is installed at: '' at a web root, '/teb'
     * in a subfolder, '/a/b/c' further down. Never has a trailing slash, so
     * base() . '/section/us' is always right.
     */
    public static function base(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }

        return self::$base = self::deriveBase();
    }

    /**
     * Turn an application path into a link.
     *
     *     url('/')             ->  /            or  /teb/
     *     url('/section/us')   ->  /section/us  or  /teb/index.php?r=/section/us
     *     url('/search?q=iran')->  /search?q=iran
     *
     * An absolute URL, a fragment, mailto: or tel: is handed straight back —
     * a publisher's link must never be prefixed with our base path.
     */
    public static function url(string $path): string
    {
        $path = trim($path);

        if ($path === '' || $path === '/') {
            return self::base() . '/';
        }
        if (self::isExternal($path)) {
            return $path;
        }

        // Split off ?query and #fragment before touching the path.
        $fragment = '';
        if (($hash = strpos($path, '#')) !== false) {
            $fragment = substr($path, $hash);
            $path     = substr($path, 0, $hash);
        }
        $query = '';
        if (($qm = strpos($path, '?')) !== false) {
            $query = substr($path, $qm + 1);
            $path  = substr($path, 0, $qm);
        }

        $path = self::normaliseRoute($path);

        if ($path === '/' && $query === '') {
            return self::base() . '/' . $fragment;
        }

        if (self::hasRewrite()) {
            return self::base() . self::encodePath($path)
                . ($query !== '' ? '?' . $query : '') . $fragment;
        }

        // The universal form: no Apache module required, works everywhere.
        return self::base() . '/index.php?r=' . self::encodeRouteValue($path)
            . ($query !== '' ? '&' . $query : '') . $fragment;
    }

    /**
     * A link to a static file under assets/, with ?v=<file modification time>
     * appended so a changed stylesheet is picked up immediately and an
     * unchanged one stays in the browser cache for as long as .htaccess says.
     *
     *     asset('css/site.css')         ->  /teb/assets/css/site.css?v=1755...
     *     asset('assets/js/app.js')     ->  same thing
     */
    public static function asset(string $rel): string
    {
        $rel = ltrim(trim($rel), '/');
        if ($rel === '') {
            return self::base() . '/assets/';
        }
        if (self::isExternal($rel)) {
            return $rel;
        }
        if (strpos($rel, 'assets/') !== 0) {
            $rel = 'assets/' . $rel;
        }
        $rel = self::collapseSlashes($rel);

        $version = self::mtime(self::$rootDir . '/' . $rel);

        return self::base() . '/' . self::encodePath('/' . $rel, false)
            . ($version > 0 ? '?v=' . $version : '');
    }

    /**
     * A fully qualified URL for canonical tags, Open Graph, the RSS feed and
     * the sitemaps: scheme + host + base + path.
     *
     * The host comes from the request and is validated hard, because
     * $_SERVER['HTTP_HOST'] is attacker-controlled: a header carrying CRLF
     * would otherwise be echoed into a Location or a <link rel=canonical> and
     * turn into header injection or an open redirect. Anything that is not a
     * plain hostname (optionally with a port, optionally an IPv6 literal in
     * brackets) is thrown away, and we fall back to SERVER_NAME, then to
     * config.site.domain, then to localhost.
     */
    public static function absolute(string $path): string
    {
        // Anything that already addresses somewhere else is returned as it
        // stands. The '//' test alone let mailto: and tel: through to the line
        // below, which glued our origin onto the front of them and produced
        // 'https://example.commailto:desk@example.com' — a broken link, and a
        // broken link is worst of all in a canonical tag or a sitemap. A bare
        // '#fragment' is still resolved against this page, which is right.
        if (self::isExternal($path) && $path[0] !== '#') {
            return $path;
        }

        return self::origin() . self::url($path);
    }

    /** 'https://example.com' — scheme and host with no trailing slash. */
    public static function origin(): string
    {
        return self::scheme() . '://' . self::host();
    }

    /** 'https' or 'http', honouring a proxy/CDN that terminated TLS for us. */
    public static function scheme(): string
    {
        $https = (string) self::sv('HTTPS');
        if ($https !== '' && strtolower($https) !== 'off') {
            return 'https';
        }
        if ((string) self::sv('SERVER_PORT') === '443') {
            return 'https';
        }

        // Cloudflare and every other CDN in front of a plain-HTTP origin.
        $fwd = strtolower(trim((string) self::sv('HTTP_X_FORWARDED_PROTO')));
        if ($fwd !== '') {
            $first = trim(explode(',', $fwd)[0]);
            if ($first === 'https' || $first === 'http') {
                return $first;
            }
        }
        if (strtolower((string) self::sv('HTTP_X_FORWARDED_SSL')) === 'on') {
            return 'https';
        }
        if (strtolower((string) self::sv('HTTP_CF_VISITOR')) !== '' && strpos((string) self::sv('HTTP_CF_VISITOR'), 'https') !== false) {
            return 'https';
        }

        $scheme = strtolower((string) self::sv('REQUEST_SCHEME'));
        if ($scheme === 'https' || $scheme === 'http') {
            return $scheme;
        }

        // No request to read: the cron job and the command line, where the
        // sitemap is built. A public news site is served over TLS, so https
        // is the useful assumption — and it is only ever used to print, never
        // to connect.
        return self::sv('REQUEST_URI') === null && self::sv('HTTP_HOST') === null ? 'https' : 'http';
    }

    /** Validated request host, with the port dropped when it is the default. */
    public static function host(): string
    {
        $candidates = [
            (string) self::sv('HTTP_HOST'),
            (string) self::sv('SERVER_NAME'),
            (string) Config::get('site.domain', ''),
        ];

        foreach ($candidates as $candidate) {
            $host = self::validHost($candidate);
            if ($host !== '') {
                return self::stripDefaultPort($host);
            }
        }

        return 'localhost';
    }

    /**
     * The route being requested, normalised, with the base path removed —
     * whatever shape the request arrived in:
     *
     *   GET /teb/section/us              (rewrite on)  -> /section/us
     *   GET /teb/index.php?r=/section/us (rewrite off) -> /section/us
     *   GET /teb/                                      -> /
     *   php cron/ingest.php                            -> /
     */
    public static function currentRoute(): string
    {
        if (self::$route !== null) {
            return self::$route;
        }

        // 1. The explicit route parameter always wins: it is what our own
        //    links carry when mod_rewrite is not available.
        $r = self::queryParam('r');
        if ($r !== null && trim($r) !== '') {
            return self::$route = self::normaliseRoute(self::stripBase(trim($r)));
        }

        // 2. Otherwise the path of the request, minus the base path.
        $uri = (string) self::sv('REQUEST_URI');
        if ($uri === '') {
            return self::$route = '/';
        }
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $uri) === 1) {
            $uri = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');   // some proxies send the absolute form
        }
        if (($qm = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $qm);
        }
        if (($hash = strpos($uri, '#')) !== false) {
            $uri = substr($uri, 0, $hash);
        }

        $path = self::stripBase(rawurldecode($uri));

        // A direct hit on the front controller, e.g. /teb/index.php — that is
        // the front page, not a route called "index.php".
        if (preg_match('#^/(index|install)\.php$#i', $path) === 1) {
            $path = '/';
        }

        return self::$route = self::normaliseRoute($path);
    }

    /**
     * Should we emit pretty URLs?
     *
     * Only when we can show the rewrite really works. The answer is cached so
     * the check happens once, not once per request, and it can never throw:
     * every branch that fails for any reason lands on false, which is the
     * form of URL that works with no Apache modules at all.
     *
     * Evidence, in order:
     *   · an answer already cached from an earlier request;
     *   · this very request arrived at index.php on a URL that is not
     *     index.php and carries no ?r= — the only thing that does that is the
     *     internal rewrite, or PHP's own built-in server, and both mean
     *     pretty URLs work;
     *   · a one-off loopback request for /healthz. The front controller
     *     answers that with JSON; a host with no rewrite answers with its own
     *     404 page. Two seconds, once, then cached.
     */
    public static function hasRewrite(): bool
    {
        if (self::$forced !== null) {
            return self::$forced;
        }
        if (self::$rewrite !== null) {
            return self::$rewrite;
        }

        try {
            $cached = self::cacheRead();
            if ($cached === true) {
                return self::$rewrite = true;
            }

            if (self::evidenceFromRequest()) {
                self::cacheWrite(true, 'request');    // only ever written once

                return self::$rewrite = true;
            }

            if ($cached === false) {
                return self::$rewrite = false;        // a recent "no", still trusted
            }

            $probed = self::probe();
            if ($probed !== null) {
                self::cacheWrite($probed, 'probe');

                return self::$rewrite = $probed;
            }

            // The probe could not tell us — no loopback, a firewall, a
            // single-worker server that cannot answer its own request. Record
            // that as a negative so we do NOT pay the probe timeout again on
            // every single page view; NEGATIVE_TTL re-opens the question later.
            // This is safe: evidenceFromRequest() is consulted before the
            // negative cache is trusted, so the first request that actually
            // arrives through the rewrite still upgrades this to a yes.
            self::cacheWrite(false, 'probe-inconclusive');
        } catch (\Throwable $e) {
            // Deliberately swallowed. A link that works is worth more than an
            // exception page, and ?r= links always work.
        }

        return self::$rewrite = false;
    }

    /**
     * Override the rewrite decision. true/false pin it; null returns to
     * detection. Used by the tests, and available to the front controller if
     * a host ever needs it pinned by hand.
     */
    public static function forceRewrite(?bool $on): void
    {
        self::$forced = $on;
    }

    /** Switch the loopback probe off — the tests never make network calls. */
    public static function allowProbe(bool $allowed): void
    {
        self::$probeAllowed = $allowed;
    }

    /** The folder holding config.php. */
    public static function root(): string
    {
        return self::$rootDir !== '' ? self::$rootDir : dirname(__DIR__);
    }

    /** The writable folder for the database, caches and lock files. */
    public static function dataDir(): string
    {
        return self::root() . '/data';
    }

    /** True when $path is the route currently being served — for nav highlighting. */
    public static function isCurrent(string $path): bool
    {
        return self::normaliseRoute($path) === self::currentRoute();
    }

    /**
     * One value out of the request's query string, read from QUERY_STRING so
     * it is driven purely by the $_SERVER array handed to init().
     */
    public static function queryParam(string $name): ?string
    {
        $qs = (string) self::sv('QUERY_STRING');
        if ($qs === '') {
            $uri = (string) self::sv('REQUEST_URI');
            $at  = strpos($uri, '?');
            $qs  = $at === false ? '' : substr($uri, $at + 1);
        }
        if ($qs === '') {
            return null;
        }

        $parsed = [];
        parse_str($qs, $parsed);
        $value = $parsed[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    // ------------------------------------------------------------------ base --

    private static function deriveBase(): string
    {
        // An explicit override always wins. A reverse proxy that mounts the
        // site under a path it strips, or a php -S router emulating a
        // subfolder, can set this and everything downstream follows.
        $explicit = (string) (self::sv('TEB_BASE') ?? (getenv('TEB_BASE') ?: ''));
        if ($explicit !== '') {
            return self::tidyBase($explicit);
        }

        $script = '';
        foreach (['SCRIPT_NAME', 'PHP_SELF', 'ORIG_SCRIPT_NAME'] as $key) {
            $candidate = self::normSlashes((string) (self::sv($key) ?? ''));
            if (($qm = strpos($candidate, '?')) !== false) {
                $candidate = substr($candidate, 0, $qm);
            }
            if ($candidate !== '') {
                $script = $candidate;
                break;
            }
        }

        // 1. The precise method: the running file's path relative to the
        //    project root, chopped off the end of its URL path. This is the
        //    only method that is right for a second entry point such as
        //    cron/ingest.php reached over the web, where a plain dirname()
        //    would answer '/teb/cron'.
        $file = self::normSlashes((string) (self::sv('SCRIPT_FILENAME') ?? ''));
        $root = self::$rootDir !== '' ? self::$rootDir : dirname(__DIR__);
        foreach ([$file, (string) (realpath($file) ?: '')] as $candidate) {
            if ($candidate === '' || $script === '') {
                continue;
            }
            foreach ([$root, (string) (realpath($root) ?: '')] as $rootCandidate) {
                if ($rootCandidate === '') {
                    continue;
                }
                $rootCandidate = rtrim(self::normSlashes($rootCandidate), '/');
                if (strpos($candidate, $rootCandidate . '/') !== 0) {
                    continue;
                }
                $rel = '/' . ltrim(substr($candidate, strlen($rootCandidate)), '/');
                if ($rel === '/') {
                    continue;
                }
                if ($script === $rel) {
                    return '';
                }
                if (substr($script, -strlen($rel)) === $rel) {
                    return self::tidyBase(substr($script, 0, -strlen($rel)));
                }
            }
        }

        // 2. SCRIPT_NAME is a .php path: its directory is the base.
        if (substr($script, -4) === '.php') {
            return self::tidyBase(dirname($script));
        }

        // 3. SCRIPT_NAME is not a script path at all — PHP's built-in server
        //    in router mode reports the requested URL there. Fall back to
        //    where the script sits inside the document root.
        $docRoot = rtrim(self::normSlashes((string) (self::sv('DOCUMENT_ROOT') ?? '')), '/');
        if ($file !== '' && $docRoot !== '' && strpos($file, $docRoot . '/') === 0) {
            return self::tidyBase(dirname(substr($file, strlen($docRoot))));
        }

        return '';
    }

    /** '/teb/' -> '/teb'; '/' -> ''; '.' -> ''; 'teb' -> '/teb' */
    private static function tidyBase(string $base): string
    {
        $base = self::normSlashes($base);
        if ($base === '' || $base === '.' || $base === '/') {
            return '';
        }
        if ($base[0] !== '/') {
            $base = '/' . $base;
        }
        $base = rtrim($base, '/');

        return $base === '/' ? '' : $base;
    }

    /** Remove the base path from the front of a request path. */
    private static function stripBase(string $path): string
    {
        $base = self::base();
        if ($base === '') {
            return $path;
        }
        $path = self::collapseSlashes('/' . ltrim($path, '/'));

        if ($path === $base) {
            return '/';
        }
        if (strpos($path, $base . '/') === 0) {
            return substr($path, strlen($base));
        }

        return $path;
    }

    // ----------------------------------------------------------------- routes --

    /**
     * One canonical spelling for any route: leading slash, no trailing slash,
     * no doubled slashes, no ".." segments, no control characters, bounded
     * length. Everything that compares or dispatches routes agrees because
     * everything goes through here.
     */
    public static function normaliseRoute(string $path): string
    {
        $path = str_replace("\0", '', $path);
        $path = preg_replace('/[\x00-\x1F\x7F]/', '', $path) ?? $path;
        $path = trim($path);

        if ($path === '' || $path === '/') {
            return '/';
        }
        if (strlen($path) > 2048) {
            $path = substr($path, 0, 2048);
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        $path = self::collapseSlashes($path);

        if (strpos($path, '..') !== false || strpos($path, './') !== false) {
            $out = [];
            foreach (explode('/', $path) as $segment) {
                if ($segment === '' || $segment === '.') {
                    continue;
                }
                if ($segment === '..') {
                    array_pop($out);
                    continue;
                }
                $out[] = $segment;
            }
            $path = '/' . implode('/', $out);
        }

        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    private static function collapseSlashes(string $s): string
    {
        return (string) preg_replace('#/{2,}#', '/', str_replace('\\', '/', $s));
    }

    private static function normSlashes(string $s): string
    {
        return self::collapseSlashes(trim($s));
    }

    private static function isExternal(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if ($path[0] === '#') {
            return true;
        }
        if (strpos($path, '//') === 0) {
            return true;
        }

        return preg_match('#^(?:https?|mailto|tel|data|ftp):#i', $path) === 1;
    }

    /** Percent-encode the unusual characters in a path, segment by segment. */
    private static function encodePath(string $path, bool $leadingSlash = true): string
    {
        $out = [];
        foreach (explode('/', ltrim($path, '/')) as $segment) {
            $out[] = preg_match('/^[A-Za-z0-9\-._~!$&\'()*+,;=:@%]*$/', $segment) === 1
                ? $segment
                : rawurlencode($segment);
        }
        $joined = implode('/', $out);

        return ($leadingSlash ? '/' : '') . $joined;
    }

    /** Encode a route for ?r=, keeping the slashes readable. */
    private static function encodeRouteValue(string $path): string
    {
        return str_replace(['%2F', '%7E'], ['/', '~'], rawurlencode($path));
    }

    // ---------------------------------------------------------------- rewrite --

    /**
     * Did THIS request arrive through the rewrite? True only when the front
     * controller is running on a URL that is not the front controller and
     * that carries no ?r= — which nothing but an internal rewrite produces.
     */
    private static function evidenceFromRequest(): bool
    {
        if (self::sv(self::PROBE_HEADER) !== null) {
            return false;                        // our own probe, proves nothing
        }
        if (self::queryParam('r') !== null) {
            return false;                        // arrived the safe way
        }

        $uri = (string) self::sv('REQUEST_URI');
        if ($uri === '') {
            return false;                        // CLI
        }
        if (($qm = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $qm);
        }

        $path = self::normaliseRoute(self::stripBase(rawurldecode($uri)));

        // '/' is served by DirectoryIndex, not by the rewrite, so it proves
        // nothing. A path that names a .php file reached PHP on its own. And
        // '/index.php/section/us' is PATH_INFO, which also needs no rewrite.
        if ($path === '/' || preg_match('#\.php(/|$)#i', $path) === 1) {
            return false;
        }

        // We are executing PHP, on a URL that is not a PHP file, that carries
        // no ?r=. On a host where the rewrite is not running, Apache would
        // have answered that request itself with a 404 and this code would
        // never have run.
        return true;
    }

    /**
     * One loopback HTTP request for the base path + /healthz. Returns
     * true/false when it learns something, null when it could not tell.
     */
    private static function probe(): ?bool
    {
        if (!self::$probeAllowed || self::sv(self::PROBE_HEADER) !== null) {
            return null;
        }

        $host = self::validHost((string) (self::sv('HTTP_HOST') ?? ''))
            ?: self::validHost((string) (self::sv('SERVER_NAME') ?? ''));
        if ($host === '') {
            return null;                          // no request to loop back to
        }

        // One process at a time, and only if the run is not already recorded.
        $lock = self::openProbeLock();
        if ($lock === null) {
            return null;
        }

        try {
            $url = self::scheme() . '://' . self::stripDefaultPort($host)
                . self::base() . self::PROBE_ROUTE;

            $body   = null;
            $status = 0;

            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                if ($ch !== false) {
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_CONNECTTIMEOUT => 2,
                        CURLOPT_TIMEOUT        => 3,
                        // We are talking to ourselves through the public
                        // hostname; a staging box with a self-signed or
                        // not-yet-issued certificate must not read as "no
                        // rewrite here". Nothing sensitive is sent or trusted.
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_SSL_VERIFYHOST => 0,
                        CURLOPT_HTTPHEADER     => ['X-TEB-Probe: 1', 'Accept: application/json'],
                        CURLOPT_USERAGENT      => 'TEB-rewrite-probe/1.0',
                    ]);
                    $result = curl_exec($ch);
                    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                    $body   = is_string($result) ? $result : null;
                    curl_close($ch);
                }
            } elseif (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
                $ctx = stream_context_create([
                    'http' => [
                        'timeout'       => 3,
                        'ignore_errors' => true,
                        'header'        => "X-TEB-Probe: 1\r\nAccept: application/json\r\n",
                    ],
                    'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
                ]);
                $result = @file_get_contents($url, false, $ctx);
                $body   = is_string($result) ? $result : null;
                foreach ($http_response_header ?? [] as $line) {
                    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                        $status = (int) $m[1];
                    }
                }
            } else {
                return null;                      // no way to make the request
            }

            if ($body === null || $status === 0) {
                return null;                      // network refused us; do not conclude
            }

            // The front controller answers /healthz with JSON. Apache's own
            // 404 page cannot.
            return $status === 200 && strpos(ltrim($body), '{') === 0;
        } finally {
            if (is_resource($lock)) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
    }

    /** @return resource|null */
    private static function openProbeLock()
    {
        $dir = self::writableCacheDir();
        if ($dir === null) {
            return null;
        }
        $fh = @fopen($dir . '/rewrite-probe.lock', 'c');
        if ($fh === false) {
            return null;
        }
        if (!@flock($fh, LOCK_EX | LOCK_NB)) {
            @fclose($fh);

            return null;                          // another request is probing
        }

        return $fh;
    }

    /** @return bool|null */
    private static function cacheRead(): ?bool
    {
        foreach (self::cacheCandidates() as $file) {
            if (!is_file($file)) {
                continue;
            }
            $raw = @file_get_contents($file);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }
            $entry = $data[self::cacheKey()] ?? null;
            if (!is_array($entry) || !isset($entry['rewrite'])) {
                continue;
            }
            $value = (bool) $entry['rewrite'];
            if ($value) {
                return true;                      // a yes never expires
            }
            $at = (int) ($entry['at'] ?? 0);
            if ($at > 0 && (time() - $at) < self::NEGATIVE_TTL) {
                return false;                     // a no is re-checked later
            }
        }

        return null;
    }

    private static function cacheWrite(bool $value, string $reason): void
    {
        $file = self::writableCacheDir();
        if ($file === null) {
            return;
        }
        $path = $file . '/rewrite.json';

        $data = [];
        if (is_file($path)) {
            $decoded = json_decode((string) @file_get_contents($path), true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        $data[self::cacheKey()] = ['rewrite' => $value, 'at' => time(), 'why' => $reason];

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            return;
        }
        // Written through a temporary file so a half-written cache can never
        // be read by a request that lands mid-write.
        $tmp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
            }
        }
    }

    /** The answer depends on where the app is mounted, so key the cache by it. */
    private static function cacheKey(): string
    {
        return self::base() === '' ? '/' : self::base();
    }

    /** @return string[] */
    private static function cacheCandidates(): array
    {
        return [
            self::dataDir() . '/cache/rewrite.json',
            sys_get_temp_dir() . '/teb-' . substr(sha1(self::root()), 0, 12) . '/rewrite.json',
        ];
    }

    /**
     * data/cache when it is writable; otherwise a per-install folder in the
     * system temp directory, so a read-only document root still gets a cached
     * answer instead of probing on every single page view.
     */
    private static function writableCacheDir(): ?string
    {
        if (self::$cacheDirResolved) {
            return self::$cacheDir;
        }
        self::$cacheDirResolved = true;

        foreach ([self::dataDir() . '/cache', sys_get_temp_dir() . '/teb-' . substr(sha1(self::root()), 0, 12)] as $candidate) {
            if (!is_dir($candidate)) {
                @mkdir($candidate, 0775, true);
            }
            if (is_dir($candidate) && is_writable($candidate)) {
                return self::$cacheDir = $candidate;
            }
        }

        return self::$cacheDir = null;
    }

    // ------------------------------------------------------------------ hosts --

    /**
     * A hostname we are willing to print. Rejects CRLF (header injection),
     * spaces, credentials, paths and anything that is not a hostname or an
     * IPv6 literal. Returns '' when the candidate is not usable.
     */
    private static function validHost(string $candidate): string
    {
        $candidate = trim($candidate);
        if ($candidate === '' || strlen($candidate) > 255) {
            return '';
        }
        // Any control character at all — a \r or \n here is an attack, not a typo.
        if (preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
            return '';
        }
        if (preg_match('/^\[[0-9A-Fa-f:.]{2,45}\](:\d{1,5})?$/', $candidate) === 1) {
            return $candidate;                    // [::1]:8000
        }
        if (preg_match('/^[A-Za-z0-9]([A-Za-z0-9.\-]{0,252}[A-Za-z0-9])?(:\d{1,5})?$/', $candidate) !== 1) {
            return '';
        }
        if (strpos($candidate, '..') !== false) {
            return '';
        }

        return strtolower($candidate);
    }

    private static function stripDefaultPort(string $host): string
    {
        $scheme = self::scheme();
        if ($scheme === 'https' && substr($host, -4) === ':443') {
            return substr($host, 0, -4);
        }
        if ($scheme === 'http' && substr($host, -3) === ':80') {
            return substr($host, 0, -3);
        }

        return $host;
    }

    // ------------------------------------------------------------------ misc --

    /** @return mixed|null */
    private static function sv(string $key)
    {
        $value = self::$server[$key] ?? null;

        return is_scalar($value) ? $value : null;
    }

    private static function mtime(string $file): int
    {
        if (array_key_exists($file, self::$mtimes)) {
            return self::$mtimes[$file];
        }
        $t = is_file($file) ? @filemtime($file) : false;

        return self::$mtimes[$file] = $t === false ? 0 : (int) $t;
    }
}
