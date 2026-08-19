<?php
declare(strict_types=1);

namespace TEB;

// Render and Ingest both size publisher images through Images, and the test
// harness loads these files directly, so the dependency is declared here rather
// than relying on bootstrap having run.
require_once __DIR__ . '/Images.php';
require_once __DIR__ . '/Placeholder.php';

/**
 * Server-side HTML rendering.
 *
 * Everything the browser receives is built here, as strings, with no template
 * engine and no client-side hydration: the page is complete and correct before
 * a byte of JavaScript runs. assets/js/app.js only sharpens it (a ticking
 * clock, relative timestamps, a theme toggle) — remove it and the site is
 * unchanged in substance.
 *
 * THREE RULES THIS FILE IS BUILT AROUND
 * -------------------------------------
 * 1. NO BRAND, NO DOMAIN. Nothing in here names the site. Every visible piece
 *    of identity is read out of the config array handed in by the caller, so
 *    renaming the site in config.php renames the whole build.
 *
 * 2. NO ABSOLUTE PATHS. Every internal URL goes through TEB\Paths, which knows
 *    whether the app is at a web root or inside /some/sub/folder/ and whether
 *    mod_rewrite is available. There is not one href="/..." in this file.
 *
 * 3. EVERYTHING INTERPOLATED IS ESCAPED. esc() covers & < > " ' so a hostile
 *    headline is inert in text, in an attribute, and inside the onerror
 *    handler. Only markup this class generated itself is concatenated raw.
 *
 * IMAGES (SPEC §0.6)
 * ------------------
 * The single hero image on a page is loading="eager" fetchpriority="high"
 * decoding="async". Every other image on the site is loading="lazy"
 * decoding="async". All of them carry explicit width and height (the stored
 * publisher dimensions when we have them, otherwise the nominal box for that
 * card size — design.css sets img{height:auto} so they scale instead of
 * squashing), alt set to the headline, referrerpolicy="no-referrer" because
 * we hotlink publisher CDNs, and an onerror handler that removes the broken
 * image and promotes the card to the designed text-only state. A row with no
 * image never emits an <img> element at all.
 *
 * The class names come from docs/design/FINAL.md and must match
 * assets/css/site.css exactly — markup and stylesheet are one contract.
 */
final class Render
{
    /**
     * Nominal image box per card size, used when the publisher did not tell us
     * the real dimensions. These are the aspect ratios design.css crops to
     * (.card-media is 3/2, lead 2/1, recipe 4/3), so the reserved space is
     * right even before the stylesheet has loaded.
     */
    private const BOX = [
        'lead'   => [1200, 600],
        'large'  => [800, 533],
        'medium' => [640, 427],
        'small'  => [480, 320],
        'text'   => [640, 427],
        'recipe' => [640, 480],
    ];

    /**
     * The image fallback. It lives inside an HTML attribute, so it is written
     * with single quotes only and escaped on the way out (esc turns ' into
     * &#039;, which the parser hands back to the JS engine as a quote).
     *
     * It does three things, in this order: drop the broken image, promote the
     * card to .card--text — the designed no-photo state, which is what reveals
     * the text-only treatment of the same card — and collapse the media box.
     * The class swap alone would hide the box through CSS; the inline display
     * is belt and braces, because .card-media sets display:block and would
     * therefore beat a bare [hidden] attribute.
     */
    private const ONERROR =
        "this.onerror=null;this.style.display='none';"
        . "var c=this.closest('.card');if(c){c.classList.add('card--text');}"
        . "var m=this.closest('.card-media');if(m){m.style.display='none';}";

    /** Primary navigation. ONE array — the nav and the footer both read it. */
    private const NAV = [
        ['/', 'Front page'],
        ['/section/us', 'U.S.'],
        ['/section/international', 'International'],
        ['/section/world', 'World'],
        ['/weather', 'Weather'],
        ['/recipes', 'Recipes'],
    ];

    /** The right-aligned tail of the nav, and the footer's "About" column. */
    private const NAV_TAIL = [
        ['/sources', 'Sources'],
        ['/about', 'About'],
        ['/search', 'Search'],
    ];

    /** The line that keeps us honest about what this site republishes (SPEC §0.7). */
    private const STANDING =
        'We publish headlines, feed-provided summaries and source links only. '
        . 'Every full story remains with — and links to — its original publisher.';

    // =====================================================================
    //  escaping and small string helpers
    // =====================================================================

    /**
     * The only way text reaches the page. Escapes & < > " and ' so the same
     * call is safe in element content, in a double-quoted attribute, in a
     * single-quoted attribute and inside an inline event handler.
     *
     * ENT_SUBSTITUTE matters more than it looks: without it a single invalid
     * UTF-8 byte from a publisher's feed makes htmlspecialchars return an
     * empty string, and a headline silently vanishes instead of being repaired.
     */
    public static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Any scalar to a trimmed string; arrays, objects and null become ''. */
    private static function s($v): string
    {
        if (is_string($v)) {
            return trim($v);
        }
        if (is_int($v) || is_float($v)) {
            return trim((string) $v);
        }
        if (is_bool($v)) {
            return $v ? '1' : '';
        }

        return '';
    }

    /**
     * Build an attribute list. null / false / '' drop the attribute entirely,
     * true renders it bare (boolean attributes), everything else is escaped.
     */
    private static function attrs(array $pairs): string
    {
        $out = '';
        foreach ($pairs as $name => $value) {
            if ($value === null || $value === false || $value === '') {
                continue;
            }
            if ($value === true) {
                $out .= ' ' . $name;
                continue;
            }
            $out .= ' ' . $name . '="' . self::esc((string) $value) . '"';
        }

        return $out;
    }

    /** An internal link. Never bypass this — see rule 2 in the class docblock. */
    private static function url(string $routePath): string
    {
        return Paths::url($routePath);
    }

    /**
     * A publisher's link, from a feed, i.e. hostile input. Only http and https
     * survive: a feed that hands us `javascript:` or a `data:` URL gets an
     * empty string and the link is not rendered at all.
     */
    public static function outbound(string $url): string
    {
        $url = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $url));
        if ($url === '') {
            return '';
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }
        if ((string) parse_url($url, PHP_URL_HOST) === '') {
            return '';
        }

        return $url;
    }

    /** URL-safe slug. Byte-wise on purpose: it can never return null on bad UTF-8. */
    public static function slug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = str_replace(['&', '@'], [' and ', ' at '], $s);
        $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim($s, '-');
        if (strlen($s) > 72) {
            $s = substr($s, 0, 72);
            $cut = strrpos($s, '-');
            if ($cut !== false && $cut > 24) {
                $s = substr($s, 0, $cut);
            }
            $s = trim($s, '-');
        }

        return $s;
    }

    /**
     * The route path for one article: /article/{slug}-{id}. The id is the
     * authority; the slug is decoration the router is free to correct.
     */
    public static function articleHref(array $a): string
    {
        $id = (int) ($a['id'] ?? 0);
        if ($id < 1) {
            return '/';
        }
        $slug = self::s($a['slug'] ?? '');
        if ($slug === '') {
            $slug = self::s($a['title'] ?? '');
        }
        $slug = self::slug($slug);

        return '/article/' . ($slug !== '' ? $slug . '-' : '') . $id;
    }

    // =====================================================================
    //  time
    // =====================================================================

    private static function tz(array $cfg): \DateTimeZone
    {
        $name = self::s($cfg['site']['timezone'] ?? '');
        if ($name !== '') {
            try {
                return new \DateTimeZone($name);
            } catch (\Throwable $e) {
                // A timezone typed from memory must not take the site down.
            }
        }

        return new \DateTimeZone('UTC');
    }

    /** '5:48 p.m.' — the newspaper form the design sets in the mono face. */
    private static function clockLabel(\DateTimeImmutable $dt): string
    {
        return $dt->format('g:i') . ($dt->format('A') === 'AM' ? ' a.m.' : ' p.m.');
    }

    /**
     * <time datetime="…">5:48 p.m.</time>.
     *
     * The ABSOLUTE time is rendered on the server, so a reader with no
     * JavaScript sees a correct timestamp. app.js later rewrites recent ones
     * to "12m ago" and puts the absolute value in the title attribute.
     */
    public static function timeTag(?int $ms, array $cfg, string $class = 't'): string
    {
        if ($ms === null || $ms <= 0) {
            return '';
        }
        $dt  = (new \DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone(self::tz($cfg));
        $age = time() - intdiv($ms, 1000);
        $abs = $age > 86400 || $age < -3600
            ? $dt->format('M j') . ', ' . self::clockLabel($dt)
            : self::clockLabel($dt);

        return '<time' . self::attrs(['class' => $class, 'datetime' => $dt->format('c')]) . '>'
            . self::esc($abs) . '</time>';
    }

    // =====================================================================
    //  images
    // =====================================================================

    /** Stored publisher dimensions when we have them, else the card's nominal box. */
    private static function dims(array $a, string $size): array
    {
        $w = (int) ($a['image_width'] ?? 0);
        $h = (int) ($a['image_height'] ?? 0);
        if ($w > 0 && $h > 0 && $w <= 10000 && $h <= 10000) {
            return [$w, $h];
        }

        return self::BOX[$size] ?? self::BOX['medium'];
    }

    /**
     * One <img>, or '' when the row has no usable image — in which case the
     * caller emits the text-only card and no media element at all.
     *
     * $eager is true for the ONE hero image on the page and false everywhere
     * else. There is no third state.
     */
    private static function imgTag(array $a, string $size, bool $eager): string
    {
        $raw = self::s($a['image_url'] ?? '');

        // Our own placeholder is a site-relative path, and outbound() rejects
        // anything without a scheme and host — which is right for a publisher
        // URL and wrong for ours. Let ours through untouched.
        $isOwn = $raw !== '' && Placeholder::isPlaceholder($raw);
        $src   = $isOwn ? $raw : self::outbound($raw);
        if ($src === '') {
            return '';
        }

        // Refuse a picture that cannot fill this slot. Several publishers put a
        // sidebar thumbnail in their feed — CBS News ships every image at 60x60
        // — and stretching one into a lead photo produces unreadable mush. The
        // caller falls back to the designed text-only card, which is better.
        if (!$isOwn && !Images::usable($a, $size)) {
            return '';
        }

        [$w, $h] = self::dims($a, $size);

        $alt = self::s($a['image_alt'] ?? '');
        if ($alt === '') {
            $alt = self::s($a['title'] ?? '');
        }
        if ($alt === '') {
            $alt = self::s($a['source_name'] ?? '') . ' photograph';
            $alt = trim($alt) === 'photograph' ? 'News photograph' : $alt;
        }

        $attrs = [
            'src'            => $src,
            'alt'            => $alt,
            'width'          => (string) $w,
            'height'         => (string) $h,
            'decoding'       => 'async',
            'referrerpolicy' => 'no-referrer',
        ];
        if ($eager) {
            $attrs['loading']       = 'eager';
            $attrs['fetchpriority'] = 'high';
        } else {
            $attrs['loading'] = 'lazy';
        }
        $attrs['onerror'] = self::ONERROR;

        return '<img' . self::attrs($attrs) . '>';
    }

    // =====================================================================
    //  card — one component, five sizes (docs/design/FINAL.md)
    // =====================================================================

    /**
     * $o keys:
     *   size    'lead'|'large'|'medium'|'small'|'text'   default 'medium'
     *   lazy    bool   default true — false makes this the page's hero image
     *   cfg     array  the config, for the timezone on the timestamp
     *   hed     'h1'|'h2'|'h3'                            default h2 for lead
     *   link    bool   default true — false on the article page's own headline
     *   out     bool   show the outbound "Read the full story" link
     *   kline   bool   show the chip/kicker line above a lead headline
     *   recipe  bool   render the recipe variant (4:3 media, .recipe-meta)
     *   class   string extra classes
     */
    public static function card(array $a, array $o = []): string
    {
        $cfg  = is_array($o['cfg'] ?? null) ? $o['cfg'] : [];
        $size = self::s($o['size'] ?? 'medium');
        if (!isset(self::BOX[$size])) {
            $size = 'medium';
        }
        $recipe = !empty($o['recipe']);
        $lazy   = array_key_exists('lazy', $o) ? (bool) $o['lazy'] : true;
        $link   = array_key_exists('link', $o) ? (bool) $o['link'] : true;

        $title = self::s($a['title'] ?? '');
        if ($title === '') {
            return '';                       // a card with no headline is not a card
        }

        $href    = self::url(self::articleHref($a));
        $out     = self::outbound(self::s($a['url'] ?? ''));
        $srcName = self::s($a['source_name'] ?? '');
        if ($srcName === '') {
            $srcName = self::s($a['source'] ?? '');
        }
        $kicker  = self::s($a['section_label'] ?? '');
        $summary = self::s($a['summary'] ?? '');

        // .card--small and .card--text never carry media: the design hides it,
        // and emitting it anyway would download an image nobody can see.
        $mediaBox = $recipe ? 'recipe' : $size;
        $img      = ($size === 'small' || $size === 'text')
            ? ''
            : self::imgTag($a, $mediaBox, !$lazy);

        // Several publishers ship no picture at all — Washington Post, Al Jazeera,
        // Deutsche Welle and the National Weather Service among them — and CBS's
        // are too small to use. Rather than leave holes in the grid, draw the
        // masthead card so every story still looks like a newspaper item.
        if ($img === '' && $size !== 'small' && $size !== 'text') {
            $ph = $a;
            $ph['image_url']    = Placeholder::url($a);
            $ph['image_width']  = 1200;
            $ph['image_height'] = 630;
            $ph['image_alt']    = self::s($a['title'] ?? '');
            $img = self::imgTag($ph, $mediaBox, !$lazy);
        }

        // The recipe variant is its own skeleton in FINAL.md — `card recipe-card`,
        // no size modifier — so its 4:3 media box is never fighting a size rule.
        $classes = ['card'];
        if ($recipe) {
            $classes[] = 'recipe-card';
        } else {
            $classes[] = 'card--' . $size;
        }
        if ($img === '' && $size !== 'small' && $size !== 'text') {
            // No photograph AND no placeholder drawn: the designed no-photo state.
            $classes[] = 'card--text';
        }
        $extra = self::s($o['class'] ?? '');
        if ($extra !== '') {
            $classes[] = $extra;
        }

        $hedTag = self::s($o['hed'] ?? '');
        if ($hedTag === '' || !in_array($hedTag, ['h1', 'h2', 'h3', 'h4'], true)) {
            $hedTag = $size === 'lead' ? 'h2' : 'h3';
        }

        // ---- pieces -----------------------------------------------------
        $media = '';
        if ($img !== '') {
            $credit = '';
            if ($srcName !== '' && ($size === 'lead' || $size === 'large')) {
                $credit = '<span class="credit">PHOTO · ' . self::esc(mb_strtoupper($srcName, 'UTF-8')) . '</span>';
            }
            // aria-hidden + tabindex="-1": the headline link below is the real
            // one, so this must not become a second tab stop or a second
            // announcement. The alt text still carries the headline for any
            // client that ignores ARIA.
            $media = '<a' . self::attrs([
                'class'       => 'card-media',
                'href'        => $href,
                'tabindex'    => '-1',
                'aria-hidden' => 'true',
            ]) . '>' . $img . $credit . '</a>';
        }

        $hed = '<' . $hedTag . ' class="card-hed">'
            . ($link ? '<a' . self::attrs(['href' => $href]) . '>' . self::esc($title) . '</a>' : self::esc($title))
            . '</' . $hedTag . '>';

        $sum = ($summary !== '' && $size !== 'small' && !$recipe)
            ? '<p class="card-sum">' . self::esc($summary) . '</p>'
            : '';

        $time = self::timeTag(isset($a['published_at']) ? (int) $a['published_at'] : null, $cfg);
        $src  = '';
        if ($srcName !== '' || $time !== '') {
            $src = '<p class="card-src">' . self::esc($srcName)
                . ($srcName !== '' && $time !== '' ? ' · ' : '') . $time . '</p>';
        }

        // No card ever links off the site. Every route into a story goes through
        // our own article page first; the link to the publisher lives there, at
        // the end of the piece. A lead card that jumped straight to abcnews.com
        // was handing our traffic away on the front page.
        $outLink = '';
        $wantOut = array_key_exists('out', $o) ? (bool) $o['out'] : false;
        if ($wantOut && $out !== '') {
            $outLink = '<a' . self::attrs([
                'class' => 'card-out',
                'href'  => $out,
                'rel'   => 'noopener nofollow',
            ]) . '>' . self::esc($srcName !== '' ? 'Read the full story at ' . $srcName . ' →' : 'Read the full story →')
                . '</a>';
        }

        $kickerEl = $kicker !== '' ? '<p class="kicker">' . self::esc($kicker) . '</p>' : '';

        $open = '<article class="' . self::esc(implode(' ', $classes)) . '">';

        // ---- recipe variant ---------------------------------------------
        if ($recipe) {
            $meta = self::s($a['recipe_meta'] ?? '');
            $metaEl = '';
            if ($meta !== '') {
                $minutes = (int) ($a['minutes'] ?? 0);
                $metaEl = '<p class="recipe-meta">'
                    . ($minutes > 0 ? '<b>' . self::esc($minutes . ' min') . '</b> · ' : '')
                    . self::esc($meta) . '</p>';
            }

            return $open . $media . $hed . $metaEl . $src . '</article>';
        }

        // ---- lead: kicker line, headline, then the picture ----------------
        if ($size === 'lead') {
            $kline = '';
            if (array_key_exists('kline', $o) ? (bool) $o['kline'] : true) {
                $chip = !empty($a['fresh']) ? '<span class="chip">New</span>' : '';
                if ($chip !== '' || $kicker !== '') {
                    $kline = '<div class="kline">' . $chip
                        . ($kicker !== '' ? '<span class="kicker">' . self::esc($kicker) . '</span>' : '')
                        . '</div>';
                }
            }

            return $open . $kline . $hed . $media . $sum . $src . $outLink . '</article>';
        }

        // ---- every other size: picture, kicker, headline -------------------
        return $open . $media . $kickerEl . $hed . $sum . $src . $outLink . '</article>';
    }

    // =====================================================================
    //  ticker — headlines, never markets (SPEC §7)
    // =====================================================================

    /**
     * The duplicate <ul> is not an accident: the CSS keyframe translates the
     * track by -50%, so the second copy is what makes the loop seamless. It is
     * aria-hidden and every link inside it is taken out of the tab order, so
     * the duplication is invisible to assistive tech and to the keyboard.
     *
     * Pausing on hover and on focus-within is pure CSS; app.js only adds the
     * pause when the browser tab is hidden.
     */
    public static function ticker(array $items, array $cfg): string
    {
        $clean = [];
        foreach ($items as $item) {
            if (is_array($item) && self::s($item['title'] ?? '') !== '') {
                $clean[] = $item;
            }
        }
        if (!$clean) {
            return '';
        }

        $live = '';
        $copy = '';
        foreach ($clean as $item) {
            $live .= self::tickerItem($item, false);
            $copy .= self::tickerItem($item, true);
        }

        return '<div class="ticker" aria-label="Latest headlines">'
            . '<div class="ticker-bug"><span class="dot" aria-hidden="true"></span>LATEST</div>'
            . '<div class="ticker-vp"><div class="ticker-track">'
            . '<ul>' . $live . '</ul>'
            . '<ul aria-hidden="true">' . $copy . '</ul>'
            . '</div></div></div>';
    }

    private static function tickerItem(array $item, bool $mirror): string
    {
        $title = self::s($item['title'] ?? '');
        $src   = self::s($item['source_name'] ?? '');
        if ($src === '') {
            $src = self::s($item['source'] ?? '');
        }
        $chip = !empty($item['fresh']) ? '<span class="chip">New</span>' : '';

        return '<li><a' . self::attrs([
            'href'     => self::url(self::articleHref($item)),
            'tabindex' => $mirror ? '-1' : null,
        ]) . '>' . $chip . self::esc($title)
            . ($src !== '' ? '<span class="s">' . self::esc($src) . '</span>' : '')
            . '</a></li>';
    }

    // =====================================================================
    //  hero
    // =====================================================================

    /**
     * rail (2fr) | subs (4fr) | lead (6fr), with the lead placed top-right by
     * the grid. DOM order is rail → subs → lead so the markup still reads in
     * a sensible order with no stylesheet.
     *
     * Degrades on purpose: with no rail items and no secondary stories the
     * lead is rendered in a plain block instead, because an empty 2fr column
     * would otherwise sit there as a hole.
     */
    private static function hero(array $model, array $cfg): string
    {
        $hero = is_array($model['hero'] ?? null) ? $model['hero'] : [];
        $lead = is_array($hero['lead'] ?? null) ? $hero['lead'] : null;
        $subs = is_array($hero['subs'] ?? null) ? $hero['subs'] : [];

        $rail = is_array($model['rail'] ?? null) ? $model['rail'] : [];
        if (!$rail && is_array($model['ticker'] ?? null)) {
            $rail = array_slice($model['ticker'], 0, 6);
        }

        // card() refuses to build a card with no headline, so a lead row that
        // has lost its title would return an empty string here and leave the
        // hero grid holding a rail and a 6fr hole — the mirror image of the
        // empty-rail case this function already guards against. Test the same
        // condition card() tests, and take the same exit.
        if ($lead === null || self::s($lead['title'] ?? '') === '') {
            return '';
        }

        $railHtml = '';
        if ($rail) {
            $li = '';
            $n  = 0;
            foreach ($rail as $item) {
                if (!is_array($item) || self::s($item['title'] ?? '') === '') {
                    continue;
                }
                $n++;
                $src = self::s($item['source_name'] ?? '');
                if ($src === '') {
                    $src = self::s($item['source'] ?? '');
                }
                $li .= '<li><a' . self::attrs(['href' => self::url(self::articleHref($item))]) . '>'
                    . '<span class="n">' . self::esc((string) $n) . '</span>'
                    . '<span><span class="h">' . self::esc(self::s($item['title'])) . '</span>'
                    . ($src !== '' ? '<span class="s">' . self::esc($src) . '</span>' : '')
                    . '</span></a></li>';
                if ($n >= 6) {
                    break;
                }
            }
            if ($li !== '') {
                $more = self::NAV[1];
                $railHtml = '<div class="hero-rail">'
                    . '<div class="rail-head"><p class="kicker">Also on the wire</p>'
                    . '<p class="rail-note">Stories moving elsewhere while the top of the page holds.</p></div>'
                    . '<ol class="rail-list">' . $li . '</ol>'
                    . '<div class="rail-foot"><a' . self::attrs(['href' => self::url($more[0])])
                    . '>More headlines →</a></div>'
                    . '</div>';
            }
        }

        $subCards = '';
        foreach ($subs as $sub) {
            if (is_array($sub)) {
                $subCards .= self::card($sub, ['size' => 'medium', 'cfg' => $cfg]);
            }
        }

        // Without a rail the 2fr column of the hero grid would sit empty — a
        // 400px hole at 2560px. So a thin front page degrades to a plain block
        // instead: the lead, then whatever seconds exist, in the normal grid.
        // Same classes, no special case in the stylesheet.
        if ($railHtml === '') {
            return '<section class="block wrap" aria-label="Top stories">'
                . self::card($lead, ['size' => 'lead', 'lazy' => false, 'cfg' => $cfg])
                . ($subCards !== '' ? '<div class="block-grid">' . $subCards . '</div>' : '')
                . '</section>';
        }

        return '<section class="hero wrap" aria-label="Top stories">'
            . $railHtml
            . ($subCards !== '' ? '<div class="hero-subs">' . $subCards . '</div>' : '')
            // THE page hero: the only eager image anywhere in the document.
            . self::card($lead, ['size' => 'lead', 'lazy' => false, 'cfg' => $cfg, 'class' => 'hero-lead'])
            . '</section>';
    }

    // =====================================================================
    //  block
    // =====================================================================

    /**
     * A section band: a ruled header and a FLAT grid of cards. Nothing here
     * depends on how many cards there are or which column they land in — the
     * hairline is drawn by each card, so any count resolves cleanly.
     *
     * $b = ['id','label','href','note','grid','items'].
     */
    public static function block(array $b, array $cfg, array $o = []): string
    {
        $items = is_array($b['items'] ?? null) ? $b['items'] : [];
        $label = self::s($b['label'] ?? '');
        $note  = self::s($b['note'] ?? '');
        $grid  = self::s($b['grid'] ?? '');
        if ($grid === '' || strpos($grid, 'block-grid') !== 0) {
            $grid = 'block-grid';
        }
        $recipe = self::s($b['id'] ?? '') === 'recipes';
        $lazy   = array_key_exists('lazy', $o) ? (bool) $o['lazy'] : true;

        $cards = '';
        $first = true;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $size = self::s($item['size'] ?? '');
            if (!isset(self::BOX[$size])) {
                $size = $first ? 'large' : 'medium';
            }
            if (strpos($grid, 'block-grid--wire') !== false) {
                $size = 'small';          // the wire desk is text rows by design
            }
            $cards .= self::card($item, [
                'size'   => $size,
                'cfg'    => $cfg,
                'recipe' => $recipe,
                'lazy'   => $first ? $lazy : true,
            ]);
            $first = false;
        }

        if ($cards === '' && empty($o['keep_empty'])) {
            return '';
        }

        $head = '<div class="block-head"><p>'
            . ($label !== '' ? '<span class="block-label">' . self::esc($label) . '</span>' : '')
            . ($note !== '' ? ' <span class="block-note">— ' . self::esc($note) . '</span>' : '')
            . '</p>';
        $href = self::s($b['href'] ?? '');
        if ($href !== '' && empty($o['no_more'])) {
            $head .= '<a' . self::attrs(['class' => 'block-more', 'href' => self::url($href)]) . '>'
                . self::esc('More ' . ($label !== '' ? $label : 'stories') . ' →') . '</a>';
        }
        $head .= '</div>';

        return '<section class="block wrap"' . self::attrs(['aria-label' => $label !== '' ? $label : null]) . '>'
            . $head . '<div class="' . self::esc($grid) . '">' . $cards . '</div></section>';
    }

    // =====================================================================
    //  weather strip
    // =====================================================================

    /**
     * Everything in the model is optional. A degraded forecast (the upstream
     * service was slow, or we are offline on first upload) renders the band
     * with whatever survived rather than disappearing or printing zeros.
     */
    public static function weatherStrip(array $w, array $cfg): string
    {
        $place = '';
        if (is_array($w['place'] ?? null)) {
            $place = self::s($w['place']['name'] ?? '');
        } else {
            $place = self::s($w['place'] ?? '');
        }
        $cur   = is_array($w['current'] ?? null) ? $w['current'] : [];
        $days  = is_array($w['days'] ?? null) ? $w['days'] : [];
        $alerts = is_array($w['alerts'] ?? null) ? $w['alerts'] : [];

        $temp = self::s($cur['temp'] ?? '');
        $cond = self::s($cur['cond'] ?? '');
        $high = self::s($cur['high'] ?? '');
        $low  = self::s($cur['low'] ?? '');
        $wind = self::s($cur['wind'] ?? '');

        if ($temp === '' && !$days && !$alerts) {
            return '';
        }

        $now = '';
        if ($temp !== '' || $cond !== '' || $place !== '') {
            $hilo = [];
            if ($high !== '' && $low !== '') {
                $hilo[] = 'H ' . $high . '° / L ' . $low . '°';
            }
            if ($wind !== '') {
                $hilo[] = 'wind ' . $wind;
            }
            $now = '<div class="wx-now">'
                . ($temp !== '' ? '<span class="wx-temp">' . self::esc($temp . '°') . '</span>' : '')
                . '<div>'
                . '<span class="wx-cond">' . self::esc(trim($cond . ($cond !== '' && $place !== '' ? ' · ' : '') . $place)) . '</span>'
                . ($hilo ? '<span class="wx-hilo">' . self::esc(implode(' · ', $hilo)) . '</span>' : '')
                . '</div></div>';
        }

        $alertEl = '';
        foreach ($alerts as $alert) {
            $headline = is_array($alert) ? self::s($alert['headline'] ?? '') : self::s($alert);
            if ($headline === '') {
                continue;
            }
            $alertEl = '<p class="wx-alert"><span class="chip">Alert</span> '
                . '<a' . self::attrs(['href' => self::url('/weather')]) . '>' . self::esc($headline) . '</a></p>';
            break;                     // the strip carries one; /weather has them all
        }

        $daysEl = '';
        if ($days) {
            $li = '';
            $shown = 0;
            foreach ($days as $day) {
                if (!is_array($day) || $shown >= 5) {
                    continue;
                }
                $name = mb_strtoupper(self::s($day['name'] ?? ''), 'UTF-8');
                $dh   = self::s($day['high'] ?? '');
                $dl   = self::s($day['low'] ?? '');
                $dc   = self::s($day['cond'] ?? '');
                if ($name === '' && $dh === '') {
                    continue;
                }
                $li .= '<li>'
                    . ($name !== '' ? '<span class="d">' . self::esc($name) . '</span>' : '')
                    . ($dh !== '' ? '<span class="n2">' . self::esc($dh . ($dl !== '' ? '/' . $dl : '')) . '</span>' : '')
                    . ($dc !== '' ? '<span class="c">' . self::esc($dc) . '</span>' : '')
                    . '</li>';
                $shown++;
            }
            if ($li !== '') {
                $daysEl = '<ul class="wx-days">' . $li . '</ul>';
            }
        }

        return '<section class="weather-strip" aria-label="Weather"><div class="wrap weather-row">'
            . $now . $alertEl . $daysEl
            . '<a' . self::attrs(['class' => 'block-more', 'href' => self::url('/weather')]) . '>Full forecast →</a>'
            . '</div></section>';
    }

    // =====================================================================
    //  markets strip — once, low on the page, quiet (SPEC §0.5)
    // =====================================================================

    /**
     * Quotes are only printed when a caller actually supplies them. We have no
     * market data feed, and inventing an index level would be worse than
     * leaving the row bare.
     */
    public static function marketsStrip(array $items, array $cfg, array $quotes = []): string
    {
        $cards = '';
        $n = 0;
        foreach ($items as $item) {
            if (!is_array($item) || $n >= 2) {
                continue;
            }
            $cards .= self::card($item, ['size' => 'small', 'cfg' => $cfg]);
            $n++;
        }

        $quoteEl = '';
        if ($quotes) {
            $li = '';
            foreach ($quotes as $q) {
                if (!is_array($q)) {
                    continue;
                }
                $name  = self::s($q['name'] ?? '');
                $value = self::s($q['value'] ?? '');
                $move  = self::s($q['change'] ?? '');
                $dir   = self::s($q['direction'] ?? '');
                if ($name === '' || $value === '') {
                    continue;
                }
                $li .= '<li><span class="nm">' . self::esc($name) . '</span>' . self::esc($value)
                    . ($move !== ''
                        ? ' <span class="' . ($dir === 'down' ? 'down' : 'up') . '">'
                          . self::esc(($dir === 'down' ? '▼ ' : '▲ ') . $move) . '</span>'
                        : '')
                    . '</li>';
            }
            if ($li !== '') {
                $quoteEl = '<ul class="mk-quotes">' . $li . '</ul>';
            }
        }

        if ($cards === '' && $quoteEl === '') {
            return '';
        }

        return '<section class="markets-strip" aria-label="Markets"><div class="wrap">'
            . '<div class="mk-row"><p class="mk-label">Markets · after the close</p>'
            . $quoteEl
            . ($quoteEl !== '' ? '<span class="mk-fine">delayed at least 15 min</span>' : '')
            . '</div>'
            . ($cards !== '' ? '<div class="mk-cards">' . $cards . '</div>' : '')
            . '</div></section>';
    }

    // =====================================================================
    //  ad slot — height reserved, zero layout shift (SPEC §8)
    // =====================================================================

    /**
     * The box is drawn at the configured size whether ads are on or off, so
     * switching them on never moves the page. While they are off this emits
     * the reserved frame and nothing else: no script, no iframe, no request to
     * anybody. When they are on, the same reserved frame carries an empty
     * mount for the client's tag to fill, so the ad lands inside space that
     * was already there.
     */
    public static function adSlot(string $name, array $cfg): string
    {
        $slots = (is_array($cfg['ads'] ?? null) && is_array($cfg['ads']['slots'] ?? null))
            ? $cfg['ads']['slots'] : [];
        if (!isset($slots[$name]) || !is_array($slots[$name])) {
            return '';
        }
        $w = (int) ($slots[$name][0] ?? 0);
        $h = (int) ($slots[$name][1] ?? 0);
        if ($w < 1 || $h < 1) {
            return '';
        }
        $w = min($w, 2000);
        $h = min($h, 2000);

        $enabled = !empty($cfg['ads']['enabled']);
        $classes = 'adslot' . ($w <= 320 ? ' adslot--box' : '');

        $inner = $enabled
            ? '<div' . self::attrs([
                'class'        => 'adslot-mount',
                'id'           => 'ad-' . self::slug($name),
                'data-ad-slot' => $name,
              ]) . '></div>'
            : '<span class="adslot-label">Advertisement</span>'
              . '<span class="adslot-dim">' . self::esc($w . ' × ' . $h) . '</span>';

        return '<div' . self::attrs([
            'class'        => $classes,
            'style'        => '--ad-w:' . $w . 'px;--ad-h:' . $h . 'px',
            'aria-label'   => 'Advertisement slot',
            'data-ad-slot' => $name,
        ]) . '><div class="adslot-frame">' . $inner . '</div></div>';
    }

    // =====================================================================
    //  search bar and pagination
    // =====================================================================

    public static function searchbar(string $q, array $cfg): string
    {
        return '<form' . self::attrs([
            'class'  => 'searchbar',
            'action' => self::url('/search'),
            'method' => 'get',
            'role'   => 'search',
        ]) . '>'
            . self::rewriteCarrier('/search')
            . '<input' . self::attrs([
                'type'        => 'search',
                'name'        => 'q',
                'value'       => $q,
                'placeholder' => 'Search the headlines…',
                'aria-label'  => 'Search',
            ]) . '>'
            . '<button type="submit">Search</button></form>';
    }

    /**
     * When mod_rewrite is unavailable our links carry ?r=/search. A GET form
     * throws every existing query parameter away, so the route has to be
     * re-stated as a hidden field or the search button lands on the front page.
     */
    private static function rewriteCarrier(string $routePath): string
    {
        if (Paths::hasRewrite()) {
            return '';
        }

        return '<input' . self::attrs(['type' => 'hidden', 'name' => 'r', 'value' => $routePath]) . '>';
    }

    /**
     * $p = ['page'=>int, 'pages'=>int, 'template'=>string] where the template
     * carries a {page} placeholder, e.g. '/section/us?page={page}'.
     * Disabled links are not rendered — an unreachable link is a defect.
     */
    public static function pagination(array $p, array $cfg): string
    {
        $page  = max(1, (int) ($p['page'] ?? 1));
        $pages = max(1, (int) ($p['pages'] ?? 1));
        if ($pages < 2) {
            return '';
        }
        $template = self::s($p['template'] ?? '');
        if ($template === '' || strpos($template, '{page}') === false) {
            return '';
        }

        $link = static function (int $n, string $label, string $class) use ($template): string {
            return '<a' . self::attrs([
                'class' => $class,
                'href'  => self::url(str_replace('{page}', (string) $n, $template)),
            ]) . '>' . self::esc($label) . '</a>';
        };

        $out = '';
        if ($page > 1) {
            $out .= $link($page - 1, '← Newer', 'pg pg-prev');
        }

        $window = [];
        foreach ([1, $page - 1, $page, $page + 1, $pages] as $n) {
            if ($n >= 1 && $n <= $pages) {
                $window[$n] = true;
            }
        }
        ksort($window);
        $last = 0;
        foreach (array_keys($window) as $n) {
            if ($last > 0 && $n > $last + 1) {
                $out .= '<span class="pg pg-gap">…</span>';
            }
            $out .= $n === $page
                ? '<span class="pg pg-now" aria-current="page">' . self::esc((string) $n) . '</span>'
                : $link($n, (string) $n, 'pg');
            $last = $n;
        }

        if ($page < $pages) {
            $out .= $link($page + 1, 'Older →', 'pg pg-next');
        }

        return '<div class="wrap"><nav class="pagination" aria-label="Pages">' . $out . '</nav></div>';
    }

    // =====================================================================
    //  page chrome
    // =====================================================================

    /**
     * The masthead. Every word of identity in here comes out of $cfg — there
     * is no brand string in this file to find.
     *
     * The clock is rendered by the server so it is right with JavaScript off;
     * app.js finds it by id, reads data-tz, and makes it tick.
     */
    private static function masthead(array $cfg, ?array $weather): string
    {
        $site = is_array($cfg['site'] ?? null) ? $cfg['site'] : [];
        $name = self::s($site['name'] ?? '');
        $tag  = self::s($site['tagline'] ?? '');
        $tzName = self::s($site['timezone'] ?? '') !== '' ? self::s($site['timezone']) : 'UTC';
        $now  = (new \DateTimeImmutable('now'))->setTimezone(self::tz($cfg));

        // left plate: date, place, conditions
        $place = '';
        $cond  = '';
        if (is_array($weather)) {
            $place = is_array($weather['place'] ?? null)
                ? self::s($weather['place']['name'] ?? '')
                : self::s($weather['place'] ?? '');
            $cur   = is_array($weather['current'] ?? null) ? $weather['current'] : [];
            $temp  = self::s($cur['temp'] ?? '');
            $cText = self::s($cur['cond'] ?? '');
            $cond  = trim(($temp !== '' ? $temp . '° ' : '') . $cText);
        }
        if ($place === '') {
            $place = self::defaultPlace($cfg);
        }

        $sideLines = '<strong>' . self::esc($now->format('l, F j, Y')) . '</strong>';
        if ($place !== '' || $cond !== '') {
            $sideLines .= '<br>' . self::esc(trim($place . ($place !== '' && $cond !== '' ? ' · ' : '') . $cond));
        }
        $sunset = is_array($weather) && is_array($weather['current'] ?? null)
            ? self::s($weather['current']['sunset'] ?? '') : '';
        $sideLines .= '<br><span class="m">'
            . self::esc(($sunset !== '' ? 'sunset ' . $sunset . ' · ' : '') . 'edition ' . $now->format('Y-m-d'))
            . '</span>';

        return '<header class="masthead wrap"><div class="masthead-grid">'
            . '<div class="masthead-side">' . $sideLines . '</div>'
            . '<div class="masthead-brand">'
            . '<p class="wordmark"><a' . self::attrs(['href' => self::url('/')]) . '>' . self::esc($name) . '</a></p>'
            . ($tag !== '' ? '<p class="tag">' . self::esc($tag) . '</p>' : '')
            . '</div>'
            . '<div class="masthead-plate" aria-label="Edition">'
            . '<p class="ed">Latest edition</p>'
            . '<p class="stars" aria-hidden="true">★ ★ ★</p>'
            . '<p' . self::attrs([
                'class'       => 'clock',
                'id'          => 'clock',
                'data-tz'     => $tzName,
                'data-locale' => str_replace('_', '-', self::s($site['locale'] ?? '') !== '' ? self::s($site['locale']) : 'en_US'),
            ]) . '>'
            . self::esc($now->format('g:i:s A')) . '</p>'
            . '<p class="no">' . self::esc('Vol. I · No. ' . (int) $now->format('z') . ' · ' . $now->format('T')) . '</p>'
            . '</div></div></header><div class="oxford" aria-hidden="true"></div>';
    }

    private static function defaultPlace(array $cfg): string
    {
        $w = is_array($cfg['weather'] ?? null) ? $cfg['weather'] : [];
        $key = self::s($w['default_place'] ?? '');
        $places = is_array($w['places'] ?? null) ? $w['places'] : [];
        if ($key !== '' && is_array($places[$key] ?? null)) {
            return self::s($places[$key]['name'] ?? '');
        }
        foreach ($places as $place) {
            if (is_array($place) && self::s($place['name'] ?? '') !== '') {
                return self::s($place['name']);
            }
        }

        return '';
    }

    /** One nav, built from one array, so a name can never differ between two places. */
    private static function nav(string $route): string
    {
        $li = '';
        foreach (self::NAV as $entry) {
            $li .= self::navItem($entry[0], $entry[1], $route, '');
        }
        $first = true;
        foreach (self::NAV_TAIL as $entry) {
            $li .= self::navItem($entry[0], $entry[1], $route, $first ? 'push' : '');
            $first = false;
        }
        $li .= '<li class="tt">'
            . '<button' . self::attrs([
                'type'         => 'button',
                'class'        => 'theme-toggle',
                'data-theme-toggle' => true,
                'aria-label'   => 'Colour theme: following your system setting',
            ]) . '><span class="tt-icon" aria-hidden="true">◐</span><span class="tt-text">System</span></button></li>';

        return '<nav class="nav" aria-label="Sections"><ul>' . $li . '</ul></nav>';
    }

    private static function navItem(string $path, string $label, string $route, string $liClass): string
    {
        $on = self::routeMatches($path, $route);

        return '<li' . self::attrs(['class' => $liClass !== '' ? $liClass : null]) . '>'
            . '<a' . self::attrs([
                'class'        => $on ? 'on' : null,
                'href'         => self::url($path),
                'aria-current' => $on ? 'page' : null,
            ]) . '>' . self::esc($label) . '</a></li>';
    }

    /**
     * An empty route means "no nav item is current" — which is what an error
     * page wants. Without that, '' would fall through to '/' and a 404 would
     * light up "Front page" as though the reader were on it.
     */
    private static function routeMatches(string $path, string $route): bool
    {
        if ($route === '') {
            return false;
        }
        if ($path === '/') {
            return $route === '/';
        }

        return $route === $path || strpos($route, $path . '/') === 0;
    }

    /**
     * The footer carries the standing line about what this site republishes.
     * It is not decoration: it is the only defensible position for a site that
     * shows a publisher's headline and summary (SPEC §0.7).
     */
    private static function footer(array $cfg, array $sources): string
    {
        $site = is_array($cfg['site'] ?? null) ? $cfg['site'] : [];
        $name = self::s($site['name'] ?? '');
        $now  = (new \DateTimeImmutable('now'))->setTimezone(self::tz($cfg));
        $place = self::defaultPlace($cfg);

        $sectionLinks = '';
        foreach (self::NAV as $entry) {
            if ($entry[0] === '/') {
                continue;
            }
            $sectionLinks .= '<li><a' . self::attrs(['href' => self::url($entry[0])]) . '>'
                . self::esc($entry[1]) . '</a></li>';
        }

        $aboutLinks = '';
        foreach (self::NAV_TAIL as $entry) {
            $aboutLinks .= '<li><a' . self::attrs(['href' => self::url($entry[0])]) . '>'
                . self::esc($entry[1]) . '</a></li>';
        }

        $sourceLinks = '';
        $shown = 0;
        foreach ($sources as $source) {
            if ($shown >= 12) {
                break;
            }
            $label = is_array($source) ? self::s($source['name'] ?? ($source['slug'] ?? '')) : self::s($source);
            $slug  = is_array($source) ? self::s($source['slug'] ?? '') : '';
            if ($label === '') {
                continue;
            }
            $sourceLinks .= '<li><a' . self::attrs([
                'href' => self::url($slug !== '' ? '/sources#' . self::slug($slug) : '/sources'),
            ]) . '>' . self::esc($label) . '</a></li>';
            $shown++;
        }
        if ($sourceLinks === '') {
            $sourceLinks = '<li><a' . self::attrs(['href' => self::url('/sources')])
                . '>Every source we read</a></li>';
        }

        $followLinks = '<li><a' . self::attrs(['href' => self::url('/feed.xml')]) . '>RSS feed</a></li>'
            . '<li><a' . self::attrs(['href' => self::url('/sitemap.xml')]) . '>Sitemap</a></li>'
            . '<li><a' . self::attrs(['href' => self::url('/sitemap-news.xml')]) . '>News sitemap</a></li>';

        return '<footer class="footer"><div class="oxford" aria-hidden="true"></div><div class="wrap">'
            . '<div class="footer-grid">'
            . '<div class="footer-brand">'
            . '<p class="fbrand">' . self::esc($name) . '</p>'
            . '<p class="standing">' . self::esc(self::STANDING) . '</p>'
            . '<p class="m">' . self::esc(($place !== '' ? $place . ' · ' : '') . 'Vol. I, No. ' . (int) $now->format('z')) . '</p>'
            . '</div>'
            . '<div><h5>Sections</h5><ul>' . $sectionLinks . '</ul></div>'
            . '<div><h5>Our sources</h5><ul class="two">' . $sourceLinks . '</ul></div>'
            . '<div><h5>About</h5><ul>' . $aboutLinks . '</ul></div>'
            . '<div><h5>Follow</h5><ul>' . $followLinks . '</ul></div>'
            . '</div>'
            . '<div class="footer-bar"><span>' . self::esc('© ' . $now->format('Y') . ' ' . $name) . '</span>'
            . '<span class="m">' . self::esc('Updated ' . self::clockLabel($now) . ' ' . $now->format('T')) . '</span>'
            . '</div></div></footer>';
    }

    // =====================================================================
    //  layout
    // =====================================================================

    /**
     * The whole document.
     *
     * $o keys: title, description, canonical (route path), body (the inner
     * HTML of <main>), jsonld (string|array), cfg, route, ticker (array or
     * pre-rendered string), weather, sources, ogType, ogImage, noindex.
     *
     * Head order matters: the stylesheet is LINKED, not inlined, and app.js is
     * DEFERRED, so nothing in the head blocks the render except the four-line
     * theme snippet — which exists precisely so the page does not flash white
     * before a reader's saved dark theme is applied.
     */
    public static function layout(array $o): string
    {
        $cfg  = is_array($o['cfg'] ?? null) ? $o['cfg'] : [];
        $site = is_array($cfg['site'] ?? null) ? $cfg['site'] : [];
        $name = self::s($site['name'] ?? '');

        $title = self::s($o['title'] ?? '');
        if ($title === '') {
            $title = $name;
        } elseif ($name !== '' && stripos($title, $name) === false) {
            $title .= ' · ' . $name;
        }

        $description = self::s($o['description'] ?? '');
        if ($description === '') {
            $description = self::s($site['description'] ?? '');
        }

        $locale = self::s($site['locale'] ?? '');
        $locale = $locale !== '' ? $locale : 'en_US';
        $lang   = str_replace('_', '-', $locale);

        $canonicalPath = self::s($o['canonical'] ?? '/');
        $canonical     = Paths::absolute($canonicalPath === '' ? '/' : $canonicalPath);

        $route = array_key_exists('route', $o) ? self::s($o['route']) : Paths::currentRoute();

        $head = '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="color-scheme" content="light dark">'
            // Pre-paint: a saved theme is applied before the first pixel, so
            // choosing dark does not mean a white flash on every page load.
            . '<script>(function(){try{var t=localStorage.getItem("theme");'
            . 'if(t==="dark"||t==="light"){document.documentElement.setAttribute("data-theme",t);}}catch(e){}})();</script>'
            . '<title>' . self::esc($title) . '</title>';

        if ($description !== '') {
            $head .= '<meta' . self::attrs(['name' => 'description', 'content' => $description]) . '>';
        }
        $themeColor = self::s($site['theme_color'] ?? '');
        if ($themeColor !== '') {
            $head .= '<meta' . self::attrs(['name' => 'theme-color', 'content' => $themeColor]) . '>';
        }
        if (!empty($o['noindex'])) {
            $head .= '<meta name="robots" content="noindex,follow">';
        }

        $head .= '<link' . self::attrs(['rel' => 'canonical', 'href' => $canonical]) . '>';

        $ogImage = self::outbound(self::s($o['ogImage'] ?? ''));
        $ogType  = self::s($o['ogType'] ?? '');
        $head .= '<meta' . self::attrs(['property' => 'og:type', 'content' => $ogType !== '' ? $ogType : 'website']) . '>'
            . '<meta' . self::attrs(['property' => 'og:title', 'content' => $title]) . '>'
            . ($description !== '' ? '<meta' . self::attrs(['property' => 'og:description', 'content' => $description]) . '>' : '')
            . '<meta' . self::attrs(['property' => 'og:url', 'content' => $canonical]) . '>'
            . ($name !== '' ? '<meta' . self::attrs(['property' => 'og:site_name', 'content' => $name]) . '>' : '')
            . '<meta' . self::attrs(['property' => 'og:locale', 'content' => $locale]) . '>'
            . ($ogImage !== '' ? '<meta' . self::attrs(['property' => 'og:image', 'content' => $ogImage]) . '>' : '')
            . '<meta' . self::attrs(['name' => 'twitter:card', 'content' => $ogImage !== '' ? 'summary_large_image' : 'summary']) . '>'
            . '<meta' . self::attrs(['name' => 'twitter:title', 'content' => $title]) . '>'
            . ($description !== '' ? '<meta' . self::attrs(['name' => 'twitter:description', 'content' => $description]) . '>' : '')
            . ($ogImage !== '' ? '<meta' . self::attrs(['name' => 'twitter:image', 'content' => $ogImage]) . '>' : '');

        // The stylesheet pulls one Google Fonts sheet; warming both hosts saves
        // a full connection setup on the critical path. gstatic needs the
        // crossorigin flag because font files are fetched anonymously.
        $head .= '<link rel="preconnect" href="https://fonts.googleapis.com">'
            . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            . '<link' . self::attrs([
                'rel'   => 'alternate',
                'type'  => 'application/rss+xml',
                'title' => $name !== '' ? $name : 'Feed',
                'href'  => self::url('/feed.xml'),
            ]) . '>'
            . '<link' . self::attrs(['rel' => 'stylesheet', 'href' => Paths::asset('css/site.css')]) . '>'
            . '<script' . self::attrs(['src' => Paths::asset('js/app.js'), 'defer' => true]) . '></script>';

        $jsonld = self::jsonLd($o['jsonld'] ?? null);
        if ($jsonld !== '') {
            $head .= '<script type="application/ld+json">' . $jsonld . '</script>';
        }

        $tickerHtml = '';
        if (isset($o['ticker'])) {
            $tickerHtml = is_array($o['ticker']) ? self::ticker($o['ticker'], $cfg) : (string) $o['ticker'];
        }

        $body = '<a class="skip" href="#top-stories">Skip to the news</a>'
            . $tickerHtml
            . self::masthead($cfg, is_array($o['weather'] ?? null) ? $o['weather'] : null)
            . self::nav($route)
            . '<main' . self::attrs(['id' => 'top-stories', 'tabindex' => '-1']) . '>'
            . (string) ($o['body'] ?? '')
            . '</main>'
            . self::footer($cfg, is_array($o['sources'] ?? null) ? $o['sources'] : []);

        return '<!doctype html><html' . self::attrs(['lang' => $lang]) . '><head>' . $head . '</head>'
            . '<body>' . $body . '</body></html>';
    }

    /**
     * JSON-LD, from an array or a pre-built string.
     *
     * EVERY '<' becomes its \u003C escape, and that is not belt and braces —
     * neutralising only '</' is not enough, and the gap blanks the whole page.
     * HTML5 tokenises the contents of a <script> element as script data, and
     * the sequence '<!--<script' switches it into the script-data DOUBLE
     * escaped state, in which a later '</script>' is text rather than an end
     * tag. A headline carrying '<!--<script>' therefore swallows the rest of
     * the document and the browser paints an empty <body>. Verified against
     * Chrome and against a spec-compliant HTML5 parser: 112 body elements
     * became 1.
     *
     * The substitution is lossless. JSON has no '<' or '>' outside a string
     * literal, and inside one \u003C is exactly '<', so what a consumer parses
     * is unchanged — the block simply can no longer reach any script-data
     * state. JSON_HEX_TAG does the same job for the array form; the string
     * form needs it here, because a module that built its own JSON did not
     * necessarily set that flag.
     *
     * JSON_INVALID_UTF8_SUBSTITUTE is the same call esc() makes with
     * ENT_SUBSTITUTE: without it one bad byte out of a publisher's feed makes
     * json_encode return false and the whole structured-data block silently
     * disappears.
     */
    private static function jsonLd($value): string
    {
        if (is_array($value) && $value) {
            $json = json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE
            );
            $value = $json === false ? '' : $json;
        }
        if (!is_string($value) || trim($value) === '') {
            return '';
        }

        return str_replace(['<', '>'], ['\u003C', '\u003E'], $value);
    }

    // =====================================================================
    //  pages
    // =====================================================================

    /**
     * The front page, from the model TEB\Compose::home() returns:
     *   ['ticker', 'hero' => ['lead','subs'], 'blocks' => [...], 'markets']
     * plus, optionally, 'weather' (TEB\Weather::get), 'rail', 'quotes',
     * 'sources'.
     *
     * Band order follows the design: hero, billboard, the section blocks, the
     * weather strip, recipes, and the markets strip last and quiet — which is
     * also SPEC §0.5's requirement that money never leads this page.
     */
    public static function home(array $model, array $cfg): string
    {
        $site   = is_array($cfg['site'] ?? null) ? $cfg['site'] : [];
        $name   = self::s($site['name'] ?? '');
        $tag    = self::s($site['tagline'] ?? '');
        $blocks = is_array($model['blocks'] ?? null) ? $model['blocks'] : [];
        $weather = is_array($model['weather'] ?? null) ? $model['weather'] : null;

        $body  = self::hero($model, $cfg);
        $body .= self::adSlot('leaderboard', $cfg);

        $weatherDone = false;
        $rendered    = 0;
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (!$weatherDone && self::s($block['id'] ?? '') === 'recipes' && $weather !== null) {
                $body .= self::weatherStrip($weather, $cfg);
                $weatherDone = true;
            }
            $html = self::block($block, $cfg);
            if ($html === '') {
                continue;
            }
            $body .= $html;
            $rendered++;
            if ($rendered === 2) {
                $body .= self::adSlot('inline', $cfg);
            }
        }
        if (!$weatherDone && $weather !== null) {
            $body .= self::weatherStrip($weather, $cfg);
        }

        $body .= self::marketsStrip(
            is_array($model['markets'] ?? null) ? $model['markets'] : [],
            $cfg,
            is_array($model['quotes'] ?? null) ? $model['quotes'] : []
        );

        $title = $name;
        if ($name !== '' && $tag !== '') {
            $title = $name . ' — ' . $tag;
        }

        $jsonld = [
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'name'     => $name,
            'url'      => Paths::absolute('/'),
        ];
        $searchTarget = Paths::absolute('/search') . (strpos(Paths::url('/search'), '?') !== false ? '&' : '?') . 'q={search_term_string}';
        $jsonld['potentialAction'] = [
            '@type'       => 'SearchAction',
            'target'      => $searchTarget,
            'query-input' => 'required name=search_term_string',
        ];

        return self::layout([
            'cfg'         => $cfg,
            'title'       => $title,
            'description' => self::s($site['description'] ?? ''),
            'canonical'   => '/',
            'route'       => '/',
            'ticker'      => is_array($model['ticker'] ?? null) ? $model['ticker'] : [],
            'weather'     => $weather,
            'sources'     => is_array($model['sources'] ?? null) ? $model['sources'] : [],
            'ogImage'     => self::s($model['hero']['lead']['image_url'] ?? ''),
            'body'        => $body,
            'jsonld'      => $jsonld,
        ]);
    }

    /**
     * A section index, a search result page, or any other list of stories.
     *
     * $model: label, note, slug, href, items, grid, page, pages, template,
     *         search (bool), q, total, ticker, weather, sources, description,
     *         canonical, noindex.
     *
     * The FIRST card on the page is the one eager image — a section index has
     * its own hero, and it is that card.
     */
    public static function section(array $model, array $cfg): string
    {
        $label = self::s($model['label'] ?? '');
        $items = is_array($model['items'] ?? null) ? $model['items'] : [];
        $isSearch = !empty($model['search']);
        $q = self::s($model['q'] ?? '');

        $body = '';

        if ($isSearch) {
            $note = $q === ''
                ? 'Type a word or a name to search every story we hold.'
                : ($items
                    ? 'Showing ' . (int) ($model['total'] ?? count($items)) . ' result'
                      . (((int) ($model['total'] ?? count($items))) === 1 ? '' : 's') . ' for “' . $q . '”.'
                    : 'Nothing matched “' . $q . '”.');
            $body .= '<section class="block wrap" aria-label="Search">'
                . '<div class="block-head"><p><span class="block-label">Search</span></p></div>'
                . self::searchbar($q, $cfg)
                . '<p class="result-note">' . self::esc($note) . '</p>'
                . '</section>';
        }

        $blockModel = [
            'id'    => self::s($model['slug'] ?? ''),
            'label' => $label,
            'note'  => self::s($model['note'] ?? ''),
            'grid'  => self::s($model['grid'] ?? 'block-grid'),
            'href'  => '',
            'items' => $items,
        ];
        if ($isSearch) {
            $blockModel['label'] = $label !== '' ? $label : 'Results';
        }

        // lazy=false on the first card: this page's single hero image.
        $listHtml = self::block($blockModel, $cfg, ['lazy' => false, 'no_more' => true]);
        if ($listHtml === '' && !$isSearch) {
            $listHtml = '<section class="block wrap"><div class="block-head"><p>'
                . '<span class="block-label">' . self::esc($label !== '' ? $label : 'Section') . '</span></p></div>'
                . '<p class="result-note">Nothing here yet. The next fetch will fill it.</p></section>';
        }
        $body .= $listHtml;

        $body .= self::pagination([
            'page'     => (int) ($model['page'] ?? 1),
            'pages'    => (int) ($model['pages'] ?? 1),
            'template' => self::s($model['template'] ?? ''),
        ], $cfg);

        $canonical = self::s($model['canonical'] ?? '');
        if ($canonical === '') {
            $canonical = self::s($model['href'] ?? '');
        }
        if ($canonical === '') {
            $canonical = '/';
        }

        $title = $isSearch
            ? ($q !== '' ? 'Search: ' . $q : 'Search')
            : ($label !== '' ? $label : 'Section');

        return self::layout([
            'cfg'         => $cfg,
            'title'       => $title,
            'description' => self::s($model['description'] ?? ''),
            'canonical'   => $canonical,
            'route'       => self::s($model['route'] ?? $canonical),
            'ticker'      => is_array($model['ticker'] ?? null) ? $model['ticker'] : [],
            'weather'     => is_array($model['weather'] ?? null) ? $model['weather'] : null,
            'sources'     => is_array($model['sources'] ?? null) ? $model['sources'] : [],
            'noindex'     => $isSearch ? true : !empty($model['noindex']),
            'body'        => $body,
        ]);
    }

    /**
     * One story: our headline, the feed's own summary, the source, and a
     * prominent link out. Never the publisher's article text (SPEC §0.7) —
     * there is no field here that could carry it.
     *
     * $model: article (required), related, ticker, weather, sources, jsonld.
     */
    public static function article(array $model, array $cfg): string
    {
        $a = is_array($model['article'] ?? null) ? $model['article'] : $model;
        $title = self::s($a['title'] ?? '');
        if ($title === '') {
            return self::error(404, 'That story is no longer here.', $cfg);
        }

        $srcName = self::s($a['source_name'] ?? '');
        if ($srcName === '') {
            $srcName = self::s($a['source'] ?? '');
        }
        $sectionLabel = self::s($a['section_label'] ?? '');
        $sectionSlug  = self::slug(self::s($a['section'] ?? ''));

        $head = '<div class="block-head"><p>'
            . ($sectionLabel !== '' ? '<span class="block-label">' . self::esc($sectionLabel) . '</span>' : '')
            . ($srcName !== '' ? ' <span class="block-note">— reported by ' . self::esc($srcName) . '</span>' : '')
            . '</p>';
        if ($sectionSlug !== '') {
            $head .= '<a' . self::attrs(['class' => 'block-more', 'href' => self::url('/section/' . $sectionSlug)])
                . '>' . self::esc('More ' . ($sectionLabel !== '' ? $sectionLabel : 'stories') . ' →') . '</a>';
        }
        $head .= '</div>';

        // The story's own image is this page's hero, and its headline is the
        // <h1>, so it does not link to itself.
        $card = self::card($a, [
            'size' => 'lead',
            'lazy' => false,
            'cfg'  => $cfg,
            'hed'  => 'h1',
            'link' => false,
            'out'  => false,
        ]);

        // The story text. Most newsrooms put only a paragraph or two in their
        // feed and hold the rest back deliberately; a few (recipe sites) publish
        // the whole piece. We render everything the feed actually gives us and
        // link out for the remainder. We never fetch and re-publish their page.
        $prose    = self::s($a['body'] ?? '');
        $summary2 = self::s($a['summary'] ?? '');
        if ($prose === '' || mb_strlen($prose) < mb_strlen($summary2)) {
            $prose = $summary2;
        }

        $paras = '';
        foreach (preg_split('/\n{2,}/', $prose) ?: [] as $chunk) {
            $chunk = trim((string) $chunk);
            if ($chunk !== '') {
                $paras .= '<p>' . self::esc($chunk) . '</p>';
            }
        }
        if ($paras === '') {
            $paras = '<p>' . self::esc($summary2) . '</p>';
        }

        $byline = '';
        $author = self::s($a['author'] ?? '');
        if ($author !== '' || $srcName !== '') {
            $byline = '<p class="article-byline">'
                . ($author !== '' ? 'By ' . self::esc($author) . ' · ' : '')
                . self::esc($srcName)
                . '</p>';
        }

        $outUrl  = self::outbound(self::s($a['url'] ?? ''));
        $continue = $outUrl !== ''
            ? '<p class="article-continue"><a' . self::attrs([
                'class'  => 'card-out',
                'href'   => $outUrl,
                'rel'    => 'noopener nofollow',
                'target' => '_blank',
              ]) . '>' . self::esc('Read more at ' . ($srcName !== '' ? $srcName : 'the publisher') . ' →') . '</a></p>'
            : '';

        $article = '<div class="article-body">' . $byline . $paras . $continue . '</div>';

        $body = '<section class="block wrap" aria-label="Story">' . $head . $card . $article . '</section>';

        $related = is_array($model['related'] ?? null) ? $model['related'] : [];
        if ($related) {
            $body .= self::block([
                'id'    => 'related',
                'label' => 'Related',
                'note'  => 'from the same desk',
                'grid'  => 'block-grid block-grid--3',
                'href'  => $sectionSlug !== '' ? '/section/' . $sectionSlug : '',
                'items' => $related,
            ], $cfg);
        }

        $body .= self::adSlot('inline', $cfg);

        $summary = self::s($a['summary'] ?? '');

        return self::layout([
            'cfg'         => $cfg,
            'title'       => $title,
            'description' => $summary,
            'canonical'   => self::articleHref($a),
            'route'       => $sectionSlug !== '' ? '/section/' . $sectionSlug : '/',
            'ticker'      => is_array($model['ticker'] ?? null) ? $model['ticker'] : [],
            'weather'     => is_array($model['weather'] ?? null) ? $model['weather'] : null,
            'sources'     => is_array($model['sources'] ?? null) ? $model['sources'] : [],
            'ogType'      => 'article',
            'ogImage'     => self::s($a['image_url'] ?? ''),
            'body'        => $body,
            'jsonld'      => $model['jsonld'] ?? null,
        ]);
    }

    /** 404, 500 and everything else — a real page, with the nav and a way out. */
    public static function error(int $status, string $msg, array $cfg): string
    {
        $status = ($status >= 400 && $status <= 599) ? $status : 500;
        $msg    = self::s($msg);
        if ($msg === '') {
            $msg = $status === 404
                ? 'That page is not here.'
                : 'Something went wrong at our end.';
        }

        $body = '<section class="block wrap" aria-label="Error">'
            . '<div class="block-head"><p><span class="block-label">' . self::esc((string) $status) . '</span>'
            . ' <span class="block-note">— ' . self::esc($status === 404 ? 'not found' : 'error') . '</span></p>'
            . '<a' . self::attrs(['class' => 'block-more', 'href' => self::url('/')]) . '>Front page →</a></div>'
            . '<p class="result-note">' . self::esc($msg) . '</p>'
            . self::searchbar('', $cfg)
            . '</section>';

        return self::layout([
            'cfg'       => $cfg,
            'title'     => $status . ' · ' . ($status === 404 ? 'Not found' : 'Error'),
            'canonical' => '/',
            'route'     => '',
            'noindex'   => true,
            'body'      => $body,
        ]);
    }
}
