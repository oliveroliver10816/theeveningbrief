<?php

declare(strict_types=1);

namespace TEB;

/**
 * Feed parser — RSS 2.0, Atom 1.0 and RDF (RSS 1.0).
 *
 * Contract:
 *   parseFeed(string $xml): array   ['title' => string, 'items' => [ item, ... ]]
 *   item = ['guid','url','title','summary','image_url','published_at','author']
 *
 * Rules this file is held to:
 *   - A malformed feed returns an empty item list. Never a warning, never an exception.
 *   - published_at is an INTEGER ms epoch or null. Never 0, never false.
 *   - XXE is off: LIBXML_NONET on every load and the external entity loader is
 *     neutered for the duration of the parse; a document that declares its own
 *     entities is refused outright (that is the billion-laughs shape).
 *   - No brand or domain literal lives here. The parser knows nothing about the site.
 */
final class Xml
{
    public const NS_MEDIA   = 'http://search.yahoo.com/mrss/';
    public const NS_CONTENT = 'http://purl.org/rss/1.0/modules/content/';
    public const NS_DC      = 'http://purl.org/dc/elements/1.1/';
    public const NS_ATOM    = 'http://www.w3.org/2005/Atom';
    public const NS_ITUNES  = 'http://www.itunes.com/dtds/podcast-1.0.dtd';
    public const NS_RSS1    = 'http://purl.org/rss/1.0/';
    public const NS_RDF     = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';

    /** Feed summaries are capped at parse time: we never store, and so can never republish, a full article. */
    public const SUMMARY_MAX = 500;

    /** Hard ceiling on items taken from one document, so a 5,000-entry feed cannot blow memory. */
    public const MAX_ITEMS = 120;

    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'jfif'];

    /**
     * Substrings that mark a "picture" which is really an analytics beacon. Deliberately narrow:
     * NPR ships <img src='undefined'> followed by a real tracking pixel, so a naive
     * "first <img>" would card every NPR story with an invisible 1x1.
     */
    private const IMAGE_DENY = [
        '/tracking/', 'rss-pixel', 'feedburner', 'feedsportal', 'doubleclick',
        'scorecardresearch', '/beacon', 'pixel.gif', '/1x1.', 'blank.gif', 'spacer.gif',
        'googletagmanager', 'google-analytics',
    ];

    /**
     * @return array{title:string,items:array<int,array{guid:string,url:string,title:string,summary:string,image_url:string,published_at:?int,author:string}>}
     */
    public static function parseFeed(string $xml): array
    {
        $empty = ['title' => '', 'items' => []];

        if ($xml === '') {
            return $empty;
        }
        $xml = self::stripBom($xml);
        if (trim($xml) === '') {
            return $empty;
        }
        // A feed that declares entities is either broken or hostile (billion laughs).
        if (self::declaresEntities($xml)) {
            return $empty;
        }

        $root = self::load($xml);
        if ($root === null) {
            return $empty;
        }

        try {
            $ns   = self::namespaceMap($root);
            $kind = self::kind($root);

            if ($kind === 'atom') {
                $title = self::text($root->children($ns['atom'])->title ?? null);
                $nodes = $root->children($ns['atom'])->entry;
                if (!self::hasNodes($nodes)) {
                    $nodes = $root->entry;
                }
            } elseif ($kind === 'rdf') {
                $rss1    = $root->children(self::NS_RSS1);
                $channel = $rss1->channel ?? null;
                $title   = self::text($channel->title ?? null);
                $nodes   = $rss1->item;
                if (!self::hasNodes($nodes)) {
                    $nodes = $root->item;
                }
            } else {
                $channel = $root->channel ?? null;
                $title   = self::text($channel->title ?? null);
                $nodes   = ($channel !== null) ? $channel->item : null;
                if (!self::hasNodes($nodes)) {
                    $nodes = $root->item;
                }
            }

            $items = [];
            if (self::hasNodes($nodes)) {
                foreach ($nodes as $node) {
                    if (count($items) >= self::MAX_ITEMS) {
                        break;
                    }
                    $item = self::parseItem($node, $ns, $kind);
                    if ($item !== null) {
                        $items[] = $item;
                    }
                }
            }

            return ['title' => self::stripHtml($title), 'items' => $items];
        } catch (\Throwable $e) {
            // A structurally surprising document is a bad feed, not a fatal error.
            return $empty;
        }
    }

    /** HTML (or HTML-in-a-feed) to plain text: tags out, entities decoded, whitespace collapsed. */
    public static function stripHtml(string $s): string
    {
        if ($s === '') {
            return '';
        }
        // Script/style bodies are not prose.
        $s = self::rx('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $s);
        // Structural tags become a space so words either side do not fuse.
        $s = self::rx('#<br\s*/?>|</?(p|div|li|ul|ol|h[1-6]|tr|td|th|blockquote|section|figure|figcaption)\b[^>]*>#i', ' ', $s);

        // Some publishers double-encode ("&lt;p&gt;"), so strip-then-decode until it settles.
        $prev   = null;
        $rounds = 0;
        while ($s !== $prev && $rounds < 3) {
            $prev = $s;
            $s    = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $rounds++;
        }

        $s = str_replace(["\xC2\xA0", "\xE2\x80\x8B", "\xEF\xBB\xBF"], [' ', '', ''], $s);
        $s = self::rx('/\s+/u', ' ', $s);

        return trim($s);
    }

    /**
     * Cut to $max characters on a word boundary. Never leaves half an HTML entity or a
     * dangling "<", and only appends an ellipsis when it actually cut something.
     * The returned string, ellipsis included, is never longer than $max.
     */
    public static function trimSummary(string $s, int $max): string
    {
        $s = trim(self::rx('/\s+/u', ' ', $s));
        if ($max <= 0 || $s === '') {
            return '';
        }
        if (self::len($s) <= $max) {
            return $s;
        }

        $budget = $max - 1;                 // one character reserved for the ellipsis
        $cut    = self::sub($s, 0, $budget);

        $space = self::lastSpace($cut);
        if ($space !== null && $space >= (int) floor($budget * 0.5)) {
            $cut = self::sub($cut, 0, $space);
        }

        $cut = self::dropDangling($cut);
        $cut = rtrim($cut, " \t\n\r\0\x0B,;:!?-([{\"'");
        $cut = self::rx('/(\s|\x{2013}|\x{2014}|\x{2026}|\.)+$/u', '', $cut);

        if ($cut === '') {
            return '';
        }

        return $cut . "\u{2026}";
    }

    // ---------------------------------------------------------------- loading

    private static function stripBom(string $s): string
    {
        if (strncmp($s, "\xEF\xBB\xBF", 3) === 0) {
            $s = substr($s, 3);
        }
        return ltrim($s, " \t\n\r\0\x0B");
    }

    /** True when the document carries a DOCTYPE with entity declarations. */
    private static function declaresEntities(string $xml): bool
    {
        $head = substr($xml, 0, 8192);
        if (stripos($head, '<!DOCTYPE') === false) {
            return false;
        }
        return stripos($xml, '<!ENTITY') !== false;
    }

    private static function load(string $xml): ?\SimpleXMLElement
    {
        $prevInternal = libxml_use_internal_errors(true);
        $loaderSet    = false;
        if (function_exists('libxml_set_external_entity_loader')) {
            /** @psalm-suppress InvalidArgument */
            libxml_set_external_entity_loader(static function ($publicId, $systemId, $context) {
                return null; // no network, no filesystem — ever
            });
            $loaderSet = true;
        }

        // LIBXML_NOENT is deliberately absent: substituting entities is the XXE foot-gun.
        $flags = LIBXML_NOCDATA | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING;

        try {
            $sx = simplexml_load_string($xml, \SimpleXMLElement::class, $flags);
            if (!($sx instanceof \SimpleXMLElement)) {
                $retry = self::reencode($xml);
                if ($retry !== null) {
                    $sx = simplexml_load_string($retry, \SimpleXMLElement::class, $flags);
                }
            }
        } catch (\Throwable $e) {
            $sx = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prevInternal);
            if ($loaderSet) {
                libxml_set_external_entity_loader(null);
            }
        }

        return ($sx instanceof \SimpleXMLElement) ? $sx : null;
    }

    /** Last resort for a feed whose declared charset libxml cannot open: convert to UTF-8 and retry once. */
    private static function reencode(string $xml): ?string
    {
        if (!preg_match('/<\?xml[^>]*encoding=["\']([A-Za-z0-9_\-]+)["\']/', substr($xml, 0, 200), $m)) {
            return null;
        }
        $from = strtoupper($m[1]);
        if ($from === 'UTF-8' || !function_exists('mb_convert_encoding')) {
            return null;
        }
        $converted = @mb_convert_encoding($xml, 'UTF-8', $from);
        if (!is_string($converted) || $converted === '') {
            return null;
        }
        return (string) preg_replace('/(<\?xml[^>]*encoding=["\'])[A-Za-z0-9_\-]+(["\'])/', '${1}UTF-8${2}', $converted, 1);
    }

    private static function kind(\SimpleXMLElement $root): string
    {
        $name = strtolower($root->getName());
        if ($name === 'feed') {
            return 'atom';
        }
        if ($name === 'rdf') {
            return 'rdf';
        }
        return 'rss';
    }

    /**
     * Resolve prefixes to the URIs THIS document actually declares — mrss appears in the
     * wild both with and without its trailing slash, and itunes with two different cases.
     *
     * @return array<string,string>
     */
    private static function namespaceMap(\SimpleXMLElement $root): array
    {
        $declared = [];
        try {
            $declared = $root->getDocNamespaces(true, true);
        } catch (\Throwable $e) {
            $declared = [];
        }
        if (!is_array($declared)) {
            $declared = [];
        }

        $pick = static function (string $prefix, string $default) use ($declared): string {
            foreach ($declared as $p => $uri) {
                if (strcasecmp((string) $p, $prefix) === 0 && (string) $uri !== '') {
                    return (string) $uri;
                }
            }
            return $default;
        };

        return [
            'media'   => $pick('media', self::NS_MEDIA),
            'content' => $pick('content', self::NS_CONTENT),
            'dc'      => $pick('dc', self::NS_DC),
            'itunes'  => $pick('itunes', self::NS_ITUNES),
            'atom'    => $pick('atom', self::NS_ATOM),
        ];
    }

    // ----------------------------------------------------------------- items

    /**
     * @param array<string,string> $ns
     * @return array{guid:string,url:string,title:string,summary:string,image_url:string,published_at:?int,author:string}|null
     */
    private static function parseItem(\SimpleXMLElement $it, array $ns, string $kind): ?array
    {
        $title = self::stripHtml(self::text($it->title ?? null));
        $url   = self::itemUrl($it, $ns, $kind);
        $guid  = self::itemGuid($it, $ns, $kind, $url);

        if ($url === '' && self::isHttpUrl($guid)) {
            $url = self::normalizeUrl($guid);
        }
        if ($title === '' || $url === '') {
            return null;   // an entry with no headline or no destination is not a story
        }
        if ($guid === '') {
            $guid = $url;
        }

        return [
            'guid'         => $guid,
            'url'          => $url,
            'title'        => self::trimSummary($title, 300),
            'summary'      => self::itemSummary($it, $ns, $kind),
            'body'         => self::itemBody($it, $ns, $kind),
            'image_url'    => self::itemImage($it, $ns),
            'published_at' => self::itemDate($it, $ns, $kind),
            'author'       => self::itemAuthor($it, $ns, $kind),
        ];
    }

    /** @param array<string,string> $ns */
    private static function itemUrl(\SimpleXMLElement $it, array $ns, string $kind): string
    {
        if ($kind === 'atom') {
            $best     = '';
            $fallback = '';
            foreach ($it->children($ns['atom'])->link as $link) {
                $a    = self::attrs($link);
                $href = trim((string) ($a['href'] ?? ''));
                if ($href === '') {
                    continue;
                }
                $rel  = strtolower((string) ($a['rel'] ?? 'alternate'));
                $type = strtolower((string) ($a['type'] ?? ''));
                if ($rel === 'alternate' && ($type === '' || strpos($type, 'html') !== false || strpos($type, 'xml') !== false)) {
                    if ($best === '') {
                        $best = $href;
                    }
                } elseif ($rel !== 'self' && $rel !== 'enclosure' && $rel !== 'edit' && $fallback === '') {
                    $fallback = $href;
                }
            }
            $href = $best !== '' ? $best : $fallback;
            return $href !== '' ? self::normalizeUrl($href) : '';
        }

        $link = trim(self::text($it->link ?? null));
        if ($link === '') {
            // RDF items carry their address on rdf:about.
            $about = self::attr($it, 'about', self::NS_RDF);
            if ($about !== '') {
                $link = $about;
            }
        }
        return $link !== '' ? self::normalizeUrl($link) : '';
    }

    /** @param array<string,string> $ns */
    private static function itemGuid(\SimpleXMLElement $it, array $ns, string $kind, string $url): string
    {
        if ($kind === 'atom') {
            $id = trim(self::text($it->children($ns['atom'])->id ?? null));
            return $id !== '' ? $id : $url;
        }
        $guid = trim(self::text($it->guid ?? null));
        if ($guid !== '') {
            return $guid;
        }
        $about = self::attr($it, 'about', self::NS_RDF);
        return $about !== '' ? $about : $url;
    }

    /**
     * The fullest article text the feed carries, untrimmed.
     *
     * Separate from itemSummary(), which is deliberately short for cards. Most
     * news feeds carry only 150-800 characters here because publishers withhold
     * the body on purpose; WordPress feeds (recipes) carry the whole piece. We
     * render whatever is really there and link to the publisher for the rest —
     * we never fetch and republish the full text from their page.
     *
     * @param array<string,string> $ns
     */
    private static function itemBody(\SimpleXMLElement $it, array $ns, string $kind): string
    {
        $candidates = [];
        $candidates[] = self::text($it->children($ns['content'])->encoded ?? null);
        if ($kind === 'atom') {
            $candidates[] = self::text($it->children($ns['atom'])->content ?? null);
        }
        $candidates[] = self::text($it->description ?? null);
        if ($kind === 'atom') {
            $candidates[] = self::text($it->children($ns['atom'])->summary ?? null);
        }

        $best = '';
        foreach ($candidates as $raw) {
            // Keep paragraph boundaries: stripHtml flattens, so mark them first.
            $marked = preg_replace('#</(p|div|li|h[1-6]|blockquote)\s*>#i', "$0\n\n", (string) $raw);
            $marked = preg_replace('#<br\s*/?>#i', "\n", (string) $marked);
            $clean  = self::stripHtml((string) $marked);
            $clean  = trim((string) preg_replace("/\n{3,}/", "\n\n", $clean));
            if (mb_strlen($clean) > mb_strlen($best)) {
                $best = $clean;
            }
        }

        return mb_substr($best, 0, 20000);
    }

    /** @param array<string,string> $ns */
    private static function itemSummary(\SimpleXMLElement $it, array $ns, string $kind): string
    {
        $candidates = [];

        if ($kind === 'atom') {
            $candidates[] = self::text($it->children($ns['atom'])->summary ?? null);
            $candidates[] = self::text($it->children($ns['atom'])->content ?? null);
        }
        $candidates[] = self::text($it->description ?? null);
        $candidates[] = self::text($it->children($ns['content'])->encoded ?? null);
        $candidates[] = self::text($it->summary ?? null);
        $candidates[] = self::text($it->children($ns['media'])->description ?? null);
        $candidates[] = self::text($it->children($ns['itunes'])->summary ?? null);

        foreach ($candidates as $raw) {
            $clean = self::stripHtml((string) $raw);
            if ($clean !== '') {
                return self::trimSummary($clean, self::SUMMARY_MAX);
            }
        }
        return '';
    }

    /** @param array<string,string> $ns */
    private static function itemAuthor(\SimpleXMLElement $it, array $ns, string $kind): string
    {
        if ($kind === 'atom') {
            $name = self::text($it->children($ns['atom'])->author->name ?? null);
            if (trim($name) !== '') {
                return self::stripHtml($name);
            }
        }

        $raw = trim(self::text($it->children($ns['dc'])->creator ?? null));
        if ($raw === '') {
            $raw = trim(self::text($it->author ?? null));
        }
        if ($raw === '') {
            $raw = trim(self::text($it->children($ns['itunes'])->author ?? null));
        }
        if ($raw === '') {
            $name = self::text($it->author->name ?? null);
            $raw  = trim($name);
        }
        if ($raw === '') {
            return '';
        }

        // RSS <author> is "someone@example.com (Real Name)". Keep the name, drop the address.
        if (preg_match('/^\S+@\S+\s*\((.+)\)\s*$/', $raw, $m) === 1) {
            $raw = $m[1];
        } elseif (preg_match('/^\S+@\S+$/', $raw) === 1) {
            return '';
        }

        return self::trimSummary(self::stripHtml($raw), 120);
    }

    /** @param array<string,string> $ns */
    private static function itemDate(\SimpleXMLElement $it, array $ns, string $kind): ?int
    {
        $raw = [];
        if ($kind === 'atom') {
            $raw[] = self::text($it->children($ns['atom'])->published ?? null);
            $raw[] = self::text($it->children($ns['atom'])->updated ?? null);
        }
        $raw[] = self::text($it->pubDate ?? null);
        $raw[] = self::text($it->children($ns['dc'])->date ?? null);
        $raw[] = self::text($it->published ?? null);
        $raw[] = self::text($it->updated ?? null);
        $raw[] = self::text($it->children($ns['dc'])->{'created'} ?? null);

        foreach ($raw as $value) {
            $ms = self::toMs((string) $value);
            if ($ms !== null) {
                return $ms;
            }
        }
        return null;
    }

    /** RFC 822 / ISO 8601 / dc:date to ms epoch. null — never 0, never false — when unknown or absurd. */
    private static function toMs(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > 64) {
            return null;
        }
        // A bare number is not a date we are willing to guess at.
        if (preg_match('/[A-Za-z0-9]/', $raw) !== 1 || preg_match('/^\d+$/', $raw) === 1) {
            return null;
        }

        $ts = false;
        try {
            // The UTC zone applies only when the string carries none of its own.
            $d  = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
            $ts = $d->getTimestamp();
        } catch (\Throwable $e) {
            $ts = false;
        }
        if ($ts === false) {
            $ts = strtotime($raw);
        }
        if (!is_int($ts)) {
            return null;
        }
        if ($ts < 315532800) {                 // before 1980 — a broken or placeholder date
            return null;
        }
        if ($ts > time() + 172800) {           // more than two days ahead — a broken clock
            return null;
        }
        return $ts * 1000;
    }

    // ---------------------------------------------------------------- images

    /** @param array<string,string> $ns */
    private static function itemImage(\SimpleXMLElement $it, array $ns): string
    {
        $candidates = [];
        self::collectMedia($it, $ns['media'], $candidates, 0);

        if ($candidates !== []) {
            usort($candidates, static function (array $a, array $b): int {
                if ($a['rank'] !== $b['rank']) {
                    return $b['rank'] <=> $a['rank'];
                }
                return $b['w'] <=> $a['w'];
            });
            foreach ($candidates as $c) {
                $u = self::acceptImage($c['url']);
                if ($u !== '') {
                    return $u;
                }
            }
        }

        // <enclosure url type length>
        foreach ($it->enclosure as $enc) {
            $a    = self::attrs($enc);
            $u    = (string) ($a['url'] ?? '');
            $type = strtolower((string) ($a['type'] ?? ''));
            if ($u === '') {
                continue;
            }
            if ($type !== '' && strpos($type, 'image/') !== 0) {
                continue;
            }
            if ($type === '' && !self::looksLikeImage($u)) {
                continue;
            }
            $u = self::acceptImage($u);
            if ($u !== '') {
                return $u;
            }
        }

        // itunes:image href="..."
        $itunesImage = $it->children($ns['itunes'])->image ?? null;
        if ($itunesImage instanceof \SimpleXMLElement) {
            $href = self::attrs($itunesImage)['href'] ?? '';
            $u    = self::acceptImage((string) $href);
            if ($u !== '') {
                return $u;
            }
        }

        // Some publishers (CBS) hang a bare <image> on the item itself.
        if (isset($it->image)) {
            $node = $it->image;
            $u    = trim(self::text($node->url ?? null));
            if ($u === '') {
                $u = trim(self::text($node));
            }
            $u = self::acceptImage($u);
            if ($u !== '') {
                return $u;
            }
        }

        // Last: the first real <img> inside content:encoded, then inside description.
        foreach ([
            self::text($it->children($ns['content'])->encoded ?? null),
            self::text($it->description ?? null),
            self::text($it->children($ns['atom'])->content ?? null),
            self::text($it->children($ns['atom'])->summary ?? null),
        ] as $html) {
            $u = self::firstImgSrc((string) $html);
            if ($u !== '') {
                return $u;
            }
        }

        return '';
    }

    /**
     * media:content / media:thumbnail, including inside media:group.
     *
     * @param array<int,array{url:string,w:int,rank:int}> $out
     */
    private static function collectMedia(\SimpleXMLElement $node, string $mediaNs, array &$out, int $depth): void
    {
        if ($depth > 2) {
            return;
        }
        $m = $node->children($mediaNs);
        if (!($m instanceof \SimpleXMLElement)) {
            return;
        }

        foreach (['content' => 2, 'thumbnail' => 1] as $tag => $rank) {
            foreach ($m->{$tag} as $el) {
                $a   = self::attrs($el);
                $url = trim((string) ($a['url'] ?? ''));
                if ($url === '') {
                    continue;
                }
                $type   = strtolower((string) ($a['type'] ?? ''));
                $medium = strtolower((string) ($a['medium'] ?? ''));
                if ($type !== '' && strpos($type, 'image/') !== 0) {
                    continue;                       // a video or audio rendition
                }
                if ($medium !== '' && $medium !== 'image') {
                    continue;
                }
                if ($type === '' && $medium === '' && !self::looksLikeImage($url)) {
                    continue;
                }
                $out[] = ['url' => $url, 'w' => (int) ($a['width'] ?? 0), 'rank' => $rank];
            }
        }

        foreach ($m->group as $group) {
            self::collectMedia($group, $mediaNs, $out, $depth + 1);
        }
    }

    private static function firstImgSrc(string $html): string
    {
        if ($html === '' || stripos($html, '<img') === false) {
            return '';
        }
        if (!preg_match_all('/<img\b[^>]*>/i', $html, $tags) || empty($tags[0])) {
            return '';
        }
        foreach ($tags[0] as $tag) {
            // A declared 1x1 is a beacon, not a photograph.
            if (preg_match('/\bwidth\s*=\s*["\']?1["\']?/i', $tag) === 1
                && preg_match('/\bheight\s*=\s*["\']?1["\']?/i', $tag) === 1) {
                continue;
            }
            $src = '';
            foreach (['/\bsrc\s*=\s*"([^"]*)"/i', "/\\bsrc\\s*=\\s*'([^']*)'/i", '/\bsrc\s*=\s*([^\s>"\']+)/i'] as $srcRx) {
                if (preg_match($srcRx, $tag, $m) === 1) {
                    $src = $m[1];
                    break;
                }
            }
            if ($src === '') {
                continue;
            }
            $u = self::acceptImage(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($u !== '') {
                return $u;
            }
        }
        return '';
    }

    /** Normalise and vet a candidate image URL. Returns '' when it is not usable. */
    private static function acceptImage(string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || strlen($url) > 1000) {
            return '';
        }
        $lower = strtolower($url);
        // NPR ships src='undefined' in content:encoded; treat those literals as absent.
        if ($lower === 'undefined' || $lower === 'null' || $lower === 'none' || $lower === 'false') {
            return '';
        }
        if (strncmp($lower, 'data:', 5) === 0 || strncmp($lower, 'javascript:', 11) === 0) {
            return '';
        }
        $url = self::normalizeUrl($url);
        if (!self::isHttpUrl($url)) {
            return '';
        }
        $lower = strtolower($url);
        foreach (self::IMAGE_DENY as $deny) {
            if (strpos($lower, $deny) !== false) {
                return '';
            }
        }
        return $url;
    }

    private static function looksLikeImage(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '') {
            $path = $url;
        }
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return $ext !== '' && in_array($ext, self::IMAGE_EXT, true);
    }

    // ------------------------------------------------------------- utilities

    private static function normalizeUrl(string $url): string
    {
        $url = trim(preg_replace('/\s+/', '', $url) ?? $url);
        if ($url === '') {
            return '';
        }
        if (strncmp($url, '//', 2) === 0) {
            return 'https:' . $url;                 // protocol-relative
        }
        if (preg_match('#^https?://#i', $url) !== 1) {
            return '';
        }
        return $url;
    }

    private static function isHttpUrl(string $url): bool
    {
        return preg_match('#^https?://[^\s/@]+#i', $url) === 1;
    }

    private static function hasNodes($nodes): bool
    {
        return ($nodes instanceof \SimpleXMLElement) && $nodes->count() > 0;
    }

    /** @param \SimpleXMLElement|null $node */
    private static function text($node): string
    {
        if ($node === null) {
            return '';
        }
        if ($node instanceof \SimpleXMLElement) {
            return (string) $node;
        }
        return (string) $node;
    }

    /** @return array<string,string> */
    private static function attrs(\SimpleXMLElement $el): array
    {
        $out = [];
        $a   = $el->attributes();
        if ($a instanceof \SimpleXMLElement) {
            foreach ($a as $k => $v) {
                $out[(string) $k] = (string) $v;
            }
        }
        return $out;
    }

    private static function attr(\SimpleXMLElement $el, string $name, string $ns): string
    {
        $a = $el->attributes($ns);
        if ($a instanceof \SimpleXMLElement && isset($a[$name])) {
            return (string) $a[$name];
        }
        return '';
    }

    private static function rx(string $pattern, string $replacement, string $subject): string
    {
        $out = preg_replace($pattern, $replacement, $subject);
        return is_string($out) ? $out : $subject;
    }

    private static function len(string $s): int
    {
        return function_exists('mb_strlen') ? (int) mb_strlen($s, 'UTF-8') : strlen($s);
    }

    private static function sub(string $s, int $start, int $length): string
    {
        if (function_exists('mb_substr')) {
            return (string) mb_substr($s, $start, $length, 'UTF-8');
        }
        $cut = substr($s, $start, $length);
        // Do not leave a split multi-byte sequence behind.
        while ($cut !== '' && (ord($cut[strlen($cut) - 1]) & 0xC0) === 0x80) {
            $cut = substr($cut, 0, -1);
        }
        return $cut;
    }

    private static function lastSpace(string $s): ?int
    {
        $pos = function_exists('mb_strrpos') ? mb_strrpos($s, ' ', 0, 'UTF-8') : strrpos($s, ' ');
        return ($pos === false) ? null : (int) $pos;
    }

    /** Remove a trailing half-written entity ("&amp" / "&#82") or an unclosed "<" fragment. */
    private static function dropDangling(string $s): string
    {
        $amp = strrpos($s, '&');
        if ($amp !== false) {
            $tail = substr($s, $amp);
            if (strpos($tail, ';') === false && strlen($tail) <= 12) {
                $s = substr($s, 0, $amp);
            }
        }
        $lt = strrpos($s, '<');
        if ($lt !== false && strpos(substr($s, $lt), '>') === false) {
            $s = substr($s, 0, $lt);
        }
        return rtrim($s);
    }
}
