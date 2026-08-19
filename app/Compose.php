<?php
declare(strict_types=1);

namespace TEB;

/**
 * Front-page composition.
 *
 * Compose::home() is a PURE function of (rows, config, now). No time(), no rand(),
 * no I/O, no static mutable state — so the front page is reproducible and testable.
 * Everything visible is either passed in or read from config; there is no brand
 * literal and no absolute URL in this file. Block hrefs are ROUTE PATHS
 * ('/section/us'); the renderer turns them into URLs through TEB\Paths.
 *
 * Score = recency decay x source weight x section priority + image bonus,
 *         minus a penalty per article already taken from that source in the block.
 *
 * The finance quota is the client's commercial requirement, not a style note:
 * business / finance / markets / crypto are banned from the hero and from every
 * block named in compose.finance_blocked_blocks, and are capped at
 * compose.finance_max_on_home across the WHOLE front page, surfacing only in the
 * low markets strip. He is buying ads to build a general-news audience; a
 * markets-heavy front page fights that.
 *
 * Model shape (docs/CONTRACT.md):
 *   ['ticker'  => [item, ...],
 *    'hero'    => ['lead' => item|null, 'subs' => [item, ...]],
 *    'blocks'  => [['id','label','href','note','grid','items' => [item, ...]], ...],
 *    'markets' => [item, ...]]
 *
 * 'markets' is deliberately NOT one of 'blocks': the design renders it as the
 * .markets-strip band after the last block, and keeping it in one place is what
 * guarantees no article id is ever emitted twice.
 */
final class Compose
{
    /** Front-page block order. Markets is not a block — it is the strip that follows them. */
    public const BLOCK_ORDER = ['us', 'international', 'world', 'weather', 'recipes'];

    /** The strip that carries whatever finance survives the quota. */
    public const MARKETS_ID = 'markets';

    private const DEFAULTS = [
        'half_life_hours'          => 4.5,
        'image_bonus'              => 0.15,
        'repeat_source_penalty'    => 0.35,
        'finance_max_on_home'      => 2,
        'finance_blocked_blocks'   => ['hero', 'us', 'international'],
        'finance_sections'         => ['business', 'finance', 'markets', 'crypto', 'money',
                                       'economy', 'investing', 'stocks', 'personal-finance'],
        'hero_sub_count'           => 4,
        'per_source_cap_per_block' => 2,
        'ticker_count'             => 12,
        'ticker_source_cap'        => 2,
        'fresh_minutes'            => 45,
        'undated_age_hours'        => 12,
        'block_counts'             => ['us' => 6, 'international' => 6, 'world' => 8,
                                       'weather' => 3, 'recipes' => 4],
    ];

    /** Every key options() understands — also how a bare compose array is recognised. */
    private const OPTION_KEYS = [
        'half_life_hours', 'image_bonus', 'repeat_source_penalty', 'finance_max_on_home',
        'finance_blocked_blocks', 'finance_sections', 'hero_sub_count', 'per_source_cap_per_block',
        'ticker_count', 'ticker_source_cap', 'fresh_minutes', 'undated_age_hours', 'block_counts',
    ];

    /** Section -> front-page block. Anything absent is hero/ticker eligible but has no block. */
    private const SECTION_BLOCK = [
        'us' => 'us', 'nation' => 'us', 'national' => 'us', 'domestic' => 'us',
        'politics' => 'us', 'top' => 'us', 'topstories' => 'us', 'top-stories' => 'us',
        'headlines' => 'us', 'latest' => 'us', 'news' => 'us', 'general' => 'us',
        'international' => 'international', 'europe' => 'international', 'asia' => 'international',
        'africa' => 'international', 'americas' => 'international', 'middleeast' => 'international',
        'middle-east' => 'international', 'mideast' => 'international',
        'world' => 'world', 'global' => 'world', 'foreign' => 'world',
        'weather' => 'weather', 'alerts' => 'weather', 'forecast' => 'weather',
        'recipes' => 'recipes', 'recipe' => 'recipes', 'food' => 'recipes', 'cooking' => 'recipes',
    ];

    /** Relative pull of a section. US highest, then international, then world. */
    private const SECTION_PRIORITY = [
        'us' => 1.30, 'nation' => 1.25, 'national' => 1.25, 'domestic' => 1.25,
        'politics' => 1.20, 'top' => 1.20, 'topstories' => 1.20, 'top-stories' => 1.20,
        'headlines' => 1.15, 'latest' => 1.10, 'news' => 1.10, 'general' => 1.05,
        'international' => 1.15, 'europe' => 1.10, 'asia' => 1.10, 'africa' => 1.10,
        'americas' => 1.10, 'middleeast' => 1.10, 'middle-east' => 1.10, 'mideast' => 1.10,
        'world' => 1.05, 'global' => 1.00, 'foreign' => 1.00,
        'health' => 0.95, 'technology' => 0.95, 'tech' => 0.95, 'science' => 0.90,
        'entertainment' => 0.85, 'sports' => 0.85, 'lifestyle' => 0.80,
        'weather' => 0.90, 'alerts' => 0.95, 'forecast' => 0.85,
        'recipes' => 0.85, 'recipe' => 0.85, 'food' => 0.85, 'cooking' => 0.85,
        'business' => 0.50, 'finance' => 0.50, 'markets' => 0.50, 'money' => 0.50,
        'economy' => 0.50, 'crypto' => 0.45, 'investing' => 0.45, 'stocks' => 0.45,
        'personal-finance' => 0.45,
    ];

    private const BLOCK_LABELS = [
        'us' => 'U.S.', 'international' => 'International', 'world' => 'World',
        'weather' => 'Weather', 'recipes' => 'Recipes', 'markets' => 'Markets',
    ];

    private const BLOCK_NOTES = [
        'us' => 'the national desk', 'international' => 'beyond the border',
        'world' => 'the world wire', 'weather' => 'conditions and alerts',
        'recipes' => 'something for tonight', 'markets' => 'after the close',
    ];

    /** Grid modifier classes from docs/design/FINAL.md. */
    private const BLOCK_GRID = [
        'us' => 'block-grid',
        'international' => 'block-grid block-grid--6up',
        'world' => 'block-grid block-grid--wire',
        'weather' => 'block-grid block-grid--3',
        'recipes' => 'block-grid',
    ];

    private const SECTION_LABELS = [
        'us' => 'U.S.', 'nation' => 'The Nation', 'national' => 'The Nation',
        'politics' => 'Politics', 'top' => 'Top story', 'topstories' => 'Top story',
        'headlines' => 'Headlines', 'international' => 'International', 'world' => 'World',
        'weather' => 'Weather', 'alerts' => 'Weather alert', 'recipes' => 'Recipes',
        'food' => 'Food', 'health' => 'Health', 'technology' => 'Technology', 'tech' => 'Technology',
        'science' => 'Science', 'entertainment' => 'Entertainment', 'sports' => 'Sports',
        'business' => 'Business', 'finance' => 'Finance', 'markets' => 'Markets',
        'money' => 'Money', 'economy' => 'Economy', 'crypto' => 'Crypto',
    ];

    /**
     * Compose the front page.
     *
     * @param array $rows  article rows (Db::recentArticles shape); unknown keys are preserved
     * @param array $cfg   the whole config array (or just its 'compose' sub-array)
     * @param int   $nowMs epoch milliseconds — supplied by the caller so this stays pure
     */
    public static function home(array $rows, array $cfg, int $nowMs): array
    {
        $c    = self::options($cfg);
        $pool = self::prepare($rows, $c, $nowMs);

        /** @var array<int,bool> $used article ids already placed somewhere in the model */
        $used = [];
        /** @var array<string,bool> $leads sources that already lead a region — no source leads twice */
        $leads = [];

        $editorial = self::filterFinance($pool, false);
        $finance   = self::filterFinance($pool, true);

        // --- hero ------------------------------------------------------------
        // Finance is banned here unconditionally (SPEC 0.5), so it is picked from
        // the editorial pool only. The lead is the highest scorer that survives.
        $heroWant = 1 + max(0, $c['hero_sub_count']);
        $heroPick = self::takeScored(
            self::available($editorial, $used),
            $heroWant,
            $c['per_source_cap_per_block'],
            $c['repeat_source_penalty'],
            [],
            false
        );
        $lead = null;
        $subs = [];
        if ($heroPick) {
            $lead = self::stamp(array_shift($heroPick), 'hero', 'lead');
            $used[$lead['id']] = true;
            $leads[$lead['source']] = true;
            foreach ($heroPick as $row) {
                $row = self::stamp($row, 'hero', 'medium');
                $used[$row['id']] = true;
                $subs[] = $row;
            }
        }

        // --- blocks ----------------------------------------------------------
        $blocks = [];
        foreach (self::BLOCK_ORDER as $id) {
            $want = self::blockWant($c, $id);
            if ($want < 1) {
                continue;
            }
            $cands = [];
            foreach (self::available($editorial, $used) as $row) {
                if ($row['block'] === $id) {
                    $cands[] = $row;
                }
            }
            $picked = self::takeScored(
                $cands,
                $want,
                $c['per_source_cap_per_block'],
                $c['repeat_source_penalty'],
                $leads,
                true
            );
            if (!$picked) {
                continue;
            }
            $wire  = strpos(self::BLOCK_GRID[$id] ?? '', 'block-grid--wire') !== false;
            $items = [];
            foreach ($picked as $i => $row) {
                $size = $wire ? 'small' : ($i === 0 ? 'large' : 'medium');
                $row  = self::stamp($row, $id, $size);
                $used[$row['id']] = true;
                $items[] = $row;
            }
            $leads[$items[0]['source']] = true;
            $blocks[] = [
                'id'    => $id,
                'label' => self::BLOCK_LABELS[$id] ?? ucfirst($id),
                'href'  => self::blockHref($id),
                'note'  => self::BLOCK_NOTES[$id] ?? '',
                'grid'  => self::BLOCK_GRID[$id] ?? 'block-grid',
                'items' => $items,
            ];
        }

        // --- markets strip ---------------------------------------------------
        // The only place finance is allowed, and only up to the home-page cap.
        $markets = [];
        if (!in_array(self::MARKETS_ID, $c['finance_blocked_blocks'], true)) {
            $want = min($c['finance_max_on_home'], self::marketsWant($c));
            $picked = $want > 0
                ? self::takeScored(
                    self::available($finance, $used),
                    $want,
                    $c['per_source_cap_per_block'],
                    $c['repeat_source_penalty'],
                    $leads,
                    true
                )
                : [];
            foreach ($picked as $row) {
                $row = self::stamp($row, self::MARKETS_ID, 'small');
                $used[$row['id']] = true;
                $markets[] = $row;
            }
            if ($markets) {
                $leads[$markets[0]['source']] = true;
            }
        }

        // --- ticker ----------------------------------------------------------
        // Headlines, never markets (SPEC 7). Recency-ordered over whatever the
        // page has not already used, so no id is ever emitted twice.
        $ticker = [];
        if ($c['ticker_count'] > 0) {
            $rest = self::available($editorial, $used);
            usort($rest, [self::class, 'byRecency']);
            foreach (self::takeSequential($rest, $c['ticker_count'], $c['ticker_source_cap']) as $row) {
                $row = self::stamp($row, 'ticker', 'ticker');
                $used[$row['id']] = true;
                $ticker[] = $row;
            }
        }

        return self::enforce([
            'ticker'  => $ticker,
            'hero'    => ['lead' => $lead, 'subs' => $subs],
            'blocks'  => $blocks,
            'markets' => $markets,
        ], $c);
    }

    // -------------------------------------------------------------------------
    // config
    // -------------------------------------------------------------------------

    /** Reads compose options off the full config array (or off a bare compose array). */
    private static function options(array $cfg): array
    {
        $raw = [];
        if (isset($cfg['compose']) && is_array($cfg['compose'])) {
            $raw = $cfg['compose'];
        } else {
            // The caller may hand us the compose sub-array on its own. Recognise it by
            // ANY option key, not by two of them: probing only 'finance_max_on_home' and
            // 'block_counts' meant a bare ['ticker_count' => 3] was silently ignored and
            // the page was composed with the defaults instead. No top-level config key
            // (site, db, ingest, compose, ads, weather, cache) collides with this list.
            foreach (self::OPTION_KEYS as $k) {
                if (array_key_exists($k, $cfg)) {
                    $raw = $cfg;
                    break;
                }
            }
        }

        $d = self::DEFAULTS;
        $o = [
            'half_life_hours'          => self::num($raw, 'half_life_hours', $d['half_life_hours']),
            'image_bonus'              => self::num($raw, 'image_bonus', $d['image_bonus']),
            'repeat_source_penalty'    => self::num($raw, 'repeat_source_penalty', $d['repeat_source_penalty']),
            'finance_max_on_home'      => self::int($raw, 'finance_max_on_home', $d['finance_max_on_home']),
            'hero_sub_count'           => self::int($raw, 'hero_sub_count', $d['hero_sub_count']),
            'per_source_cap_per_block' => self::int($raw, 'per_source_cap_per_block', $d['per_source_cap_per_block']),
            'ticker_count'             => self::int($raw, 'ticker_count', $d['ticker_count']),
            'ticker_source_cap'        => self::int($raw, 'ticker_source_cap', $d['ticker_source_cap']),
            'fresh_minutes'            => self::int($raw, 'fresh_minutes', $d['fresh_minutes']),
            'undated_age_hours'        => self::num($raw, 'undated_age_hours', $d['undated_age_hours']),
            'finance_blocked_blocks'   => self::slugList($raw, 'finance_blocked_blocks', $d['finance_blocked_blocks']),
            'finance_sections'         => self::slugList($raw, 'finance_sections', $d['finance_sections']),
            'block_counts'             => is_array($raw['block_counts'] ?? null) ? $raw['block_counts'] : [],
        ];

        if ($o['half_life_hours'] <= 0.0) {
            $o['half_life_hours'] = $d['half_life_hours'];
        }
        $o['finance_max_on_home']      = max(0, $o['finance_max_on_home']);
        $o['hero_sub_count']           = max(0, $o['hero_sub_count']);
        $o['per_source_cap_per_block'] = max(1, $o['per_source_cap_per_block']);
        $o['ticker_count']             = max(0, $o['ticker_count']);
        $o['ticker_source_cap']        = max(1, $o['ticker_source_cap']);
        $o['fresh_minutes']            = max(0, $o['fresh_minutes']);
        $o['undated_age_hours']        = max(0.0, $o['undated_age_hours']);
        $o['image_bonus']              = max(0.0, $o['image_bonus']);
        $o['repeat_source_penalty']    = max(0.0, $o['repeat_source_penalty']);

        return $o;
    }

    private static function blockWant(array $c, string $id): int
    {
        $v = $c['block_counts'][$id] ?? (self::DEFAULTS['block_counts'][$id] ?? 0);
        return is_numeric($v) ? max(0, (int)$v) : 0;
    }

    /** Markets defaults to exactly the finance cap, so raising the cap really does surface more. */
    private static function marketsWant(array $c): int
    {
        $v = $c['block_counts'][self::MARKETS_ID] ?? null;
        if ($v === null || !is_numeric($v)) {
            return $c['finance_max_on_home'];
        }
        return max(0, (int)$v);
    }

    private static function blockHref(string $id): string
    {
        // Route paths, not URLs — the renderer runs these through TEB\Paths::url().
        if ($id === 'weather' || $id === 'recipes') {
            return '/' . $id;
        }
        return '/section/' . $id;
    }

    private static function num(array $a, string $k, float $d): float
    {
        return isset($a[$k]) && is_numeric($a[$k]) ? (float)$a[$k] : $d;
    }

    private static function int(array $a, string $k, int $d): int
    {
        return isset($a[$k]) && is_numeric($a[$k]) ? (int)$a[$k] : $d;
    }

    private static function slugList(array $a, string $k, array $d): array
    {
        if (!isset($a[$k]) || !is_array($a[$k])) {
            return $d;
        }
        $out = [];
        foreach ($a[$k] as $v) {
            if (is_string($v) || is_numeric($v)) {
                $s = self::slug((string)$v);
                if ($s !== '') {
                    $out[] = $s;
                }
            }
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // rows
    // -------------------------------------------------------------------------

    /** Normalise, score and rank the input rows. Malformed rows are skipped, never fatal. */
    private static function prepare(array $rows, array $c, int $nowMs): array
    {
        // Two rows may arrive carrying the SAME id but different content (a partial
        // re-ingest, a UNION in a hand-written query, a caller stitching two result
        // sets together). Keeping whichever happened to come first would make the
        // front page depend on database row order, which is exactly what this class
        // promises it does not do — so the winner is chosen by a total comparison
        // instead of by position.
        $byId = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $n = self::normalize($row, $c, $nowMs);
            if ($n === null) {
                continue;
            }
            $prev = $byId[$n['id']] ?? null;
            if ($prev === null || self::betterRow($n, $prev)) {
                $byId[$n['id']] = $n;
            }
        }
        $out = array_values($byId);
        usort($out, [self::class, 'byScore']);
        return $out;
    }

    /**
     * Total, position-independent ordering used only to settle an id collision:
     * score, then published time, then title, then destination URL, and — when even
     * those are identical, so the two rows rank and render alike — the serialised
     * row, so the answer is still the same whichever one the database returned first.
     */
    private static function betterRow(array $a, array $b): bool
    {
        if ($a['score'] !== $b['score']) {
            return $a['score'] > $b['score'];
        }
        $pa = $a['published_at'] ?? 0;
        $pb = $b['published_at'] ?? 0;
        if ($pa !== $pb) {
            return $pa > $pb;
        }
        if ($a['title'] !== $b['title']) {
            return strcmp($a['title'], $b['title']) < 0;
        }
        if ($a['url'] !== $b['url']) {
            return strcmp($a['url'], $b['url']) < 0;
        }
        try {
            return strcmp(serialize($a), serialize($b)) < 0;
        } catch (\Throwable $e) {
            // Only reachable if a caller put something unserialisable (a closure, a
            // resource) into a row. Both rows already agree on every field the page
            // ranks, renders or links with, so keeping the incumbent is correct.
            return false;
        }
    }

    private static function normalize(array $row, array $c, int $nowMs): ?array
    {
        $id = self::firstOf($row, ['id', 'article_id']);
        $id = is_numeric($id) ? (int)$id : 0;
        if ($id <= 0) {
            return null;                       // unlinkable and undedupable — drop it
        }

        $title = self::str(self::firstOf($row, ['title', 'headline']));
        if ($title === '') {
            return null;
        }

        $section = self::slug(self::str(self::firstOf($row, ['section', 'section_slug', 'category'])));
        $source  = self::slug(self::str(self::firstOf($row, ['source', 'source_slug', 'slug_source'])));
        $srcName = self::str(self::firstOf($row, ['source_name', 'source_title', 'publisher']));
        if ($source === '') {
            $source = $srcName !== '' ? self::slug($srcName) : self::hostSlug(self::str($row['url'] ?? ''));
        }
        if ($source === '') {
            $source = 'unknown';
        }
        if ($srcName === '') {
            $srcName = self::firstOf($row, ['source']) !== null ? self::str($row['source']) : $source;
        }

        $image = self::str(self::firstOf($row, ['image_url', 'image', 'thumbnail']));
        if (strcasecmp($image, 'null') === 0) {
            $image = '';
        }

        $pub = self::firstOf($row, ['published_at', 'published_ms', 'published', 'pubdate']);
        $pub = is_numeric($pub) ? (int)$pub : null;
        if ($pub !== null && $pub <= 0) {
            $pub = null;
        }
        if ($pub !== null && $pub < 100000000000) {
            $pub *= 1000;                      // caller handed us seconds, not milliseconds
        }

        $weight = self::firstOf($row, ['weight', 'source_weight', 'tier_weight']);
        $weight = is_numeric($weight) ? (float)$weight : 1.0;
        $weight = max(0.05, min(5.0, $weight));

        $isFinance = $section !== '' && in_array($section, $c['finance_sections'], true);

        $ageHours = $pub === null
            ? $c['undated_age_hours']
            : max(0.0, ($nowMs - $pub) / 3600000.0);

        $decay    = pow(0.5, $ageHours / $c['half_life_hours']);
        $priority = self::SECTION_PRIORITY[$section] ?? 0.80;
        $hasImage = $image !== '';
        $score    = $decay * $weight * $priority + ($hasImage ? $c['image_bonus'] : 0.0);

        $freshMs = $c['fresh_minutes'] * 60000;

        // Original keys survive so the renderer keeps author, slug, guid_hash, etc.
        return array_merge($row, [
            'id'            => $id,
            'title'         => $title,
            'url'           => self::str($row['url'] ?? ''),
            'summary'       => self::str(self::firstOf($row, ['summary', 'description', 'excerpt'])),
            'image_url'     => $image !== '' ? $image : null,
            'published_at'  => $pub,
            'section'       => $section,
            'section_label' => self::sectionLabel($section),
            'source'        => $source,
            'source_name'   => $srcName,
            'weight'        => $weight,
            'has_image'     => $hasImage,
            'is_finance'    => $isFinance,
            'block'         => $isFinance ? self::MARKETS_ID : (self::SECTION_BLOCK[$section] ?? ''),
            'age_hours'     => $ageHours,
            'fresh'         => $pub !== null && $freshMs > 0 && ($nowMs - $pub) <= $freshMs && ($nowMs - $pub) >= 0,
            'score'         => $score,
            'size'          => 'medium',
            'placement'     => '',
        ]);
    }

    private static function sectionLabel(string $section): string
    {
        if (isset(self::SECTION_LABELS[$section])) {
            return self::SECTION_LABELS[$section];
        }
        if ($section === '') {
            return 'News';
        }
        return ucwords(str_replace('-', ' ', $section));
    }

    private static function firstOf(array $row, array $keys)
    {
        foreach ($keys as $k) {
            if (isset($row[$k]) && $row[$k] !== '') {
                return $row[$k];
            }
        }
        return null;
    }

    private static function str($v): string
    {
        if (is_string($v)) {
            return trim($v);
        }
        if (is_numeric($v)) {
            return trim((string)$v);
        }
        return '';
    }

    private static function slug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim($s, '-');
    }

    private static function hostSlug(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }
        return self::slug(preg_replace('/^www\./', '', $host) ?? $host);
    }

    // -------------------------------------------------------------------------
    // selection
    // -------------------------------------------------------------------------

    /** @param array<int,bool> $used */
    private static function available(array $pool, array $used): array
    {
        $out = [];
        foreach ($pool as $row) {
            if (!isset($used[$row['id']])) {
                $out[] = $row;
            }
        }
        return $out;
    }

    private static function filterFinance(array $pool, bool $wantFinance): array
    {
        $out = [];
        foreach ($pool as $row) {
            if ($row['is_finance'] === $wantFinance) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Greedy pick with the repeat-source penalty applied live, which is what makes
     * "- penalty for a source already used in the same block" change the ORDER and
     * not just the score. Candidates arrive in canonical rank order, and the winner
     * is chosen by a full scan on a TOTAL key (effective score, then published time,
     * then lowest id) — so an exact score tie, which is what a feed that publishes no
     * dates produces for every one of its rows, always resolves the same way and the
     * front page cannot reshuffle with the database's row order.
     *
     * The per-source cap is HARD. It is never widened to fill a block: a block that
     * runs short because one publisher is all we have is the correct outcome (that
     * is the whole point of the cap), and a cap that quietly relaxes is a cap that
     * cannot be tested. The only rule that gives way is "no source leads twice",
     * and only when there is literally no other source left to lead with.
     *
     * @param array<string,bool> $leadSources sources that already lead somewhere
     */
    private static function takeScored(
        array $cands,
        int $want,
        int $cap,
        float $penalty,
        array $leadSources,
        bool $applyLeadRule
    ): array {
        if ($want < 1 || !$cands) {
            return [];
        }

        $effCap = max(1, $cap);

        $picked    = [];
        $takenIds  = [];
        $bySource  = [];
        $leadRule  = $applyLeadRule;

        while (count($picked) < $want) {
            $best     = null;
            $bestKey  = null;
            foreach ($cands as $row) {
                if (isset($takenIds[$row['id']])) {
                    continue;
                }
                $usedFrom = $bySource[$row['source']] ?? 0;
                if ($usedFrom >= $effCap) {
                    continue;
                }
                if (!$picked && $leadRule && isset($leadSources[$row['source']])) {
                    continue;                  // no source leads twice
                }
                $eff = $row['score'] - $penalty * $usedFrom;
                $key = [$eff, $row['published_at'] ?? 0, -$row['id']];
                if ($bestKey === null || self::keyGreater($key, $bestKey)) {
                    $best    = $row;
                    $bestKey = $key;
                }
            }

            if ($best === null) {
                if (!$picked && $leadRule) {
                    $leadRule = false;         // nothing else can lead here — relax, never blank
                    continue;
                }
                break;
            }

            $picked[] = $best;
            $takenIds[$best['id']] = true;
            $bySource[$best['source']] = ($bySource[$best['source']] ?? 0) + 1;
        }

        return $picked;
    }

    /** Walk a pre-ordered list taking what the per-source cap allows. Used by the ticker. */
    private static function takeSequential(array $cands, int $want, int $cap): array
    {
        $picked   = [];
        $bySource = [];
        foreach ($cands as $row) {
            if (count($picked) >= $want) {
                break;
            }
            $usedFrom = $bySource[$row['source']] ?? 0;
            if ($usedFrom >= max(1, $cap)) {
                continue;
            }
            $picked[] = $row;
            $bySource[$row['source']] = $usedFrom + 1;
        }
        return $picked;
    }

    /** @param array{0:float,1:int,2:int} $a @param array{0:float,1:int,2:int} $b */
    private static function keyGreater(array $a, array $b): bool
    {
        if ($a[0] !== $b[0]) {
            return $a[0] > $b[0];
        }
        if ($a[1] !== $b[1]) {
            return $a[1] > $b[1];
        }
        return $a[2] > $b[2];
    }

    private static function byScore(array $a, array $b): int
    {
        $c = $b['score'] <=> $a['score'];
        if ($c !== 0) {
            return $c;
        }
        $c = ($b['published_at'] ?? 0) <=> ($a['published_at'] ?? 0);
        if ($c !== 0) {
            return $c;
        }
        return $a['id'] <=> $b['id'];
    }

    private static function byRecency(array $a, array $b): int
    {
        $c = ($b['published_at'] ?? 0) <=> ($a['published_at'] ?? 0);
        if ($c !== 0) {
            return $c;
        }
        $c = $b['score'] <=> $a['score'];
        if ($c !== 0) {
            return $c;
        }
        return $a['id'] <=> $b['id'];
    }

    private static function stamp(array $row, string $placement, string $size): array
    {
        $row['placement'] = $placement;
        $row['size']      = $size;
        return $row;
    }

    // -------------------------------------------------------------------------
    // invariants
    // -------------------------------------------------------------------------

    /**
     * Last line of defence, run on the finished model: the finance ban, the
     * home-page finance cap and the no-duplicate-id rule hold no matter what the
     * selection path above did. Cheap, and it means a future change to scoring
     * cannot quietly put a markets story in the hero.
     */
    private static function enforce(array $model, array $c): array
    {
        $blocked = $c['finance_blocked_blocks'];
        $seen    = [];

        // A row may be kept only if its id is unplaced AND, when it is finance, the
        // region is the markets strip and the strip is not itself blocked by config.
        $keep = static function (array $row, string $region) use (&$seen, $blocked): bool {
            if (isset($seen[$row['id']])) {
                return false;                                     // never twice on one page
            }
            if (!empty($row['is_finance'])
                && ($region !== Compose::MARKETS_ID || in_array($region, $blocked, true))) {
                return false;                                     // finance lives in the strip only
            }
            $seen[$row['id']] = true;
            return true;
        };

        // hero first: the lead is the most valuable slot, so it wins any collision.
        $lead = $model['hero']['lead'] ?? null;
        if (is_array($lead) && !$keep($lead, 'hero')) {
            $lead = null;
        }
        $subs = [];
        foreach ($model['hero']['subs'] ?? [] as $row) {
            if (is_array($row) && $keep($row, 'hero')) {
                $subs[] = $row;
            }
        }
        $model['hero'] = ['lead' => $lead, 'subs' => $subs];

        $blocks = [];
        foreach ($model['blocks'] ?? [] as $block) {
            $items = [];
            foreach ($block['items'] ?? [] as $row) {
                if (is_array($row) && $keep($row, (string)($block['id'] ?? ''))) {
                    $items[] = $row;
                }
            }
            if ($items) {
                $block['items'] = $items;
                $blocks[] = $block;
            }
        }
        $model['blocks'] = $blocks;

        $markets = [];
        $budget  = $c['finance_max_on_home'];
        foreach ($model['markets'] ?? [] as $row) {
            if (!is_array($row) || !$keep($row, self::MARKETS_ID)) {
                continue;
            }
            if (!empty($row['is_finance'])) {
                if ($budget <= 0) {
                    unset($seen[$row['id']]);   // not placed after all
                    continue;
                }
                $budget--;
            }
            $markets[] = $row;
        }
        $model['markets'] = $markets;

        $ticker = [];
        foreach ($model['ticker'] ?? [] as $row) {
            if (is_array($row) && $keep($row, 'ticker')) {
                $ticker[] = $row;
            }
        }
        $model['ticker'] = $ticker;

        return [
            'ticker'  => $model['ticker'],
            'hero'    => $model['hero'],
            'blocks'  => $model['blocks'],
            'markets' => $model['markets'],
        ];
    }
}
