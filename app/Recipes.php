<?php

declare(strict_types=1);

namespace TEB;

use PDO;

/**
 * The recipes desk.
 *
 * The site this one is measured against advertises a recipes section that does
 * not exist: its section URL answers 200 and quietly serves the national news
 * feed. Ours is real — the recipe feeds are ingested like every other desk, and
 * this class turns those rows into a page.
 *
 * WHAT WE PUBLISH, AND WHAT WE DO NOT
 * -----------------------------------
 * Exactly what every other desk publishes: the headline, the summary the feed
 * itself provided, the publisher's name, and a prominent link to the original.
 * We do NOT reproduce the method, and we do NOT reproduce the ingredient list.
 * enrichRecipe() reads the total time, the yield and HOW MANY ingredients there
 * are — three facts, no instructions — and even those are only ever read, never
 * inferred: a recipe whose feed does not state a time simply has no time on it.
 *
 * PARSING IS DEFENSIVE BY DESIGN
 * ------------------------------
 * These are WordPress feeds and the shape varies per publisher. One ships a
 * fully marked-up recipe card in content:encoded; one ships a long prose excerpt
 * and nothing else; one ships a short excerpt with no markup at all. Every
 * extractor below therefore has several routes, each independently guarded, and
 * every one of them can decline. A field that was not found is ABSENT from the
 * returned row — never zero, never an empty string, never a guess. Rendering
 * code should use isset() / ?? and print nothing when a key is missing.
 *
 * mealOfTheDay() adds one keyless call to TheMealDB so the page still has
 * something to look at when the feeds are quiet. It is cached for ten minutes
 * and returns null — never an exception — on any failure at all.
 *
 * FOR WHOEVER RENDERS THIS
 * ------------------------
 * Every string in these models is RAW TEXT and must be escaped on the way out.
 * It is not pre-escaped here, because escaping twice turns "Mac & cheese" into
 * "Mac &amp;amp; cheese". What IS guaranteed is that any 'url' or 'image_url'
 * the models carry begins with http:// or https:// — a javascript: link from a
 * hostile upstream is dropped before it gets this far — and that no key ever
 * holds publisher markup.
 */
final class Recipes
{
    /** The desk these articles are filed under by the feed registry. */
    public const SECTION = 'recipes';

    /** One random dish, no key, no signup. */
    public const MEAL_ENDPOINT = 'https://www.themealdb.com/api/json/v1/1/random.php';

    /** How long a fetched dish is served from data/ before another is fetched. */
    public const MEAL_CACHE_SECONDS = 600;

    /** How long a failed call is remembered, so an outage cannot slow every page view. */
    public const MEAL_FAILURE_SECONDS = 120;

    /** How stale a cached dish may be and still be served when upstream is down. */
    public const MEAL_STALE_MAX_SECONDS = 86400;

    /** Recipes read for the page by default. */
    public const PAGE_LIMIT = 24;

    private const TIMEOUT_DEFAULT = 6;
    private const MAX_BYTES       = 524288;

    /** Nobody's total time is under a minute or over two days. Outside this, decline. */
    private const MIN_MINUTES = 1;
    private const MAX_MINUTES = 2880;

    /** A yield outside this range is a misread, not a serving count. */
    private const MIN_SERVINGS = 1;
    private const MAX_SERVINGS = 200;

    /** Fewer than two "ingredients" is a parse artefact; more than sixty is a roundup. */
    private const MIN_INGREDIENTS = 2;
    private const MAX_INGREDIENTS = 60;

    // =====================================================================
    //  the page
    // =====================================================================

    /**
     * The whole /recipes model: a lead, a grid, the publishers behind them, and
     * the dish of the day.
     *
     * Degrades all the way down. Zero recipes in the database still returns a
     * valid model — empty lists, count 0, a note saying so — because a desk with
     * nothing on it must render as an honest empty desk, not as a 500.
     *
     * @param  array<string,mixed> $cfg
     * @return array<string,mixed>
     */
    public static function pageModel(PDO $p, array $cfg): array
    {
        $limit = (int) ($cfg['recipes']['page_limit'] ?? self::PAGE_LIMIT);
        $limit = max(1, min(96, $limit));

        $items = self::recipeItems($p, $cfg, $limit);

        $lead = null;
        if ($items !== []) {
            $leadIndex = self::pickLead($items);
            $lead      = $items[$leadIndex];
            unset($items[$leadIndex]);
            $items = array_values($items);

            // The lead is the one image at the top of this page, so it is the
            // page's hero: eager, never lazy. Everything else lazy-loads.
            $lead['size'] = 'lead';
            $lead['lazy'] = false;
        }

        $grid = [];
        foreach ($items as $row) {
            $row['size'] = ($row['image_url'] ?? '') !== '' ? 'medium' : 'text';
            $row['lazy'] = true;
            $grid[]      = $row;
        }

        $all = $lead === null ? $grid : array_merge([$lead], $grid);

        $model = [
            'section'  => self::SECTION,
            'label'    => self::sectionLabel(),
            'blurb'    => self::sectionBlurb(),
            'lead'     => $lead,
            'grid'     => $grid,
            'items'    => $all,
            'count'    => count($all),
            'sources'  => self::sourcesOf($all),
            'meal'     => null,
            'degraded' => false,
            'note'     => '',
        ];

        if ($all === []) {
            $model['degraded'] = true;
            $model['note']     = 'No recipes have been fetched yet.';
        }

        if (self::mealEnabled($cfg)) {
            $model['meal'] = self::mealOfTheDay($cfg);
            if ($model['meal'] === null) {
                // Not a page failure — the dish is a garnish on top of the feeds.
                $model['degraded'] = true;
                if ($model['note'] === '') {
                    $model['note'] = 'The dish of the day could not be fetched.';
                }
            }
        }

        return $model;
    }

    /**
     * Recipe-desk articles, newest first, each one enriched.
     *
     * @param  array<string,mixed> $cfg
     * @return array<int,array<string,mixed>>
     */
    public static function recipeItems(PDO $p, array $cfg, int $limit = self::PAGE_LIMIT): array
    {
        $limit = max(1, min(200, $limit));

        try {
            $rows = Db::recentArticles($p, [
                'section' => self::SECTION,
                'limit'   => $limit,
            ]);
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = self::enrichRecipe($row);
        }

        return $out;
    }

    // =====================================================================
    //  enrichment
    // =====================================================================

    /**
     * Read the three publishable facts out of whatever the feed gave us.
     *
     * Looks at 'content_html', 'content', 'summary' and 'description' — the
     * first is where a full WordPress recipe card lives when the ingester has
     * one; the rest is the trimmed summary every article row carries. Both the
     * markup and the plain text are searched, markup first, because markup is
     * unambiguous and prose is not.
     *
     * Adds ONLY the keys it could actually justify:
     *
     *   total_minutes, time_label            — 'Total time 40 minutes'
     *   servings, yield_label                — 'Servings 4', 'Makes 12 bars'
     *   ingredient_count, ingredients_label  — counted from a real list
     *   meta, meta_line                      — the above, ready to print
     *
     * Nothing is invented. A publisher who states no cook time gets no cook
     * time, and the keys are simply not there.
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function enrichRecipe(array $row): array
    {
        $html = '';
        foreach (['content_html', 'content', 'description', 'summary'] as $k) {
            $v = self::str($row[$k] ?? '');
            if ($v !== '' && strpos($v, '<') !== false) {
                $html = $v;
                break;
            }
        }

        $text = self::plain(self::str($row['summary'] ?? ''));
        if ($html !== '') {
            $text = trim($text . ' ' . self::plain($html));
        }

        $minutes = self::totalMinutes($html, $text);
        if ($minutes !== null) {
            $row['total_minutes'] = $minutes;
            $row['time_label']    = self::timeLabel($minutes);
        }

        $yield = self::yieldOf($html, $text);
        if ($yield !== null) {
            if ($yield['servings'] !== null) {
                $row['servings'] = $yield['servings'];
            }
            $row['yield_label'] = $yield['label'];
        }

        $count = self::ingredientCount($html);
        if ($count !== null) {
            $row['ingredient_count']  = $count;
            $row['ingredients_label'] = $count . ' ingredients';
        }

        $meta = [];
        foreach (['time_label', 'yield_label', 'ingredients_label'] as $k) {
            if (isset($row[$k]) && $row[$k] !== '') {
                $meta[] = (string) $row[$k];
            }
        }
        if ($meta !== []) {
            $row['meta']      = $meta;
            $row['meta_line'] = implode(' · ', $meta);
        }

        return $row;
    }

    // ------------------------------------------------------------------ time

    /** Total time in minutes, or null when the publisher did not state one. */
    private static function totalMinutes(string $html, string $text): ?int
    {
        foreach ([
            self::timeFromRecipeCard($html),
            self::timeFromMicrodata($html),
            self::timeFromLabelledText($text),
            self::timeFromPartsText($text),
        ] as $candidate) {
            if ($candidate !== null && $candidate >= self::MIN_MINUTES && $candidate <= self::MAX_MINUTES) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The WP Recipe Maker card, which is what the big WordPress food sites ship
     * inside content:encoded. It splits an hours-and-minutes total across two
     * spans, and repeats each unit inside a screen-reader span, so every
     * hours/minutes/days part is collected and summed rather than taking the
     * first number found.
     */
    private static function timeFromRecipeCard(string $html): ?int
    {
        if ($html === '' || stripos($html, 'total_time') === false) {
            return null;
        }

        $pattern = '~wprm-recipe-total_time-(minutes|hours|days)"[^>]*>\s*(\d{1,4})~i';
        if (preg_match_all($pattern, $html, $m, PREG_SET_ORDER) < 1) {
            return null;
        }

        $total = 0;
        $seen  = [];
        foreach ($m as $hit) {
            $unit = strtolower($hit[1]);
            if (isset($seen[$unit])) {
                continue;               // the same figure repeated for screen readers
            }
            $seen[$unit] = true;
            $total      += self::toMinutes((int) $hit[2], $unit);
        }

        return $total > 0 ? $total : null;
    }

    /**
     * schema.org, in either spelling: an itemprop with an ISO-8601 duration, or
     * a JSON-LD recipe block carrying the same.
     */
    private static function timeFromMicrodata(string $html): ?int
    {
        if ($html === '' || stripos($html, 'totalTime') === false) {
            return null;
        }

        if (preg_match('~itemprop=["\']totalTime["\'][^>]*content=["\']([^"\']+)["\']~i', $html, $m) === 1) {
            $v = self::isoDuration($m[1]);
            if ($v !== null) {
                return $v;
            }
        }
        if (preg_match('~["\']totalTime["\']\s*:\s*["\']([^"\']+)["\']~i', $html, $m) === 1) {
            $v = self::isoDuration($m[1]);
            if ($v !== null) {
                return $v;
            }
        }

        return null;
    }

    /**
     * Plain prose, but only where a label makes the number unambiguous. "Ready
     * in 25 minutes" is a time; a bare "25 minutes" in the middle of a paragraph
     * could be anything, and is deliberately not accepted.
     */
    private static function timeFromLabelledText(string $text): ?int
    {
        if ($text === '') {
            return null;
        }

        $labels = '(?:total\s*time|ready\s*in|total|time\s*required)';
        if (preg_match('~\b' . $labels . '\b\s*[:\-–—]?\s*(' . self::DURATION . ')~iu', $text, $m) === 1) {
            return self::durationToMinutes($m[1]);
        }

        return null;
    }

    /** No total stated, but a prep time and a cook time that can be added up. */
    private static function timeFromPartsText(string $text): ?int
    {
        if ($text === '') {
            return null;
        }

        $sum = 0;
        foreach (['prep\s*time', 'cook\s*time', 'bake\s*time', 'rest\s*time', 'chill\s*time'] as $label) {
            if (preg_match('~\b' . $label . '\b\s*[:\-–—]?\s*(' . self::DURATION . ')~iu', $text, $m) === 1) {
                $v = self::durationToMinutes($m[1]);
                if ($v !== null) {
                    $sum += $v;
                }
            }
        }

        return $sum > 0 ? $sum : null;
    }

    /** "1 hour 10 minutes", "40 mins", "1 hr", "90 minutes". */
    private const DURATION = '\d{1,4}\s*(?:minutes?|mins?|m|hours?|hrs?|h|days?|d)(?:\s*\d{1,3}\s*(?:minutes?|mins?|m|seconds?|secs?))?';

    private static function durationToMinutes(string $raw): ?int
    {
        $total = 0;
        $found = false;

        if (preg_match_all('~(\d{1,4})\s*(minutes?|mins?|m|hours?|hrs?|h|days?|d)\b~i', $raw, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $hit) {
                $unit = strtolower($hit[2]);
                $unit = ($unit === 'm' || strpos($unit, 'min') === 0) ? 'minutes'
                    : (($unit === 'h' || strpos($unit, 'h') === 0) ? 'hours' : 'days');
                $total += self::toMinutes((int) $hit[1], $unit);
                $found  = true;
            }
        }

        return $found && $total > 0 ? $total : null;
    }

    private static function toMinutes(int $value, string $unit): int
    {
        if (strpos($unit, 'hour') === 0 || $unit === 'h' || strpos($unit, 'hr') === 0) {
            return $value * 60;
        }
        if (strpos($unit, 'day') === 0 || $unit === 'd') {
            return $value * 1440;
        }

        return $value;
    }

    /** PT1H10M -> 70. Anything that is not a duration -> null. */
    private static function isoDuration(string $raw): ?int
    {
        $raw = trim($raw);
        if (preg_match('~^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:\d+S)?)?$~i', $raw, $m) !== 1) {
            return null;
        }

        $minutes = ((int) ($m[1] ?? 0)) * 1440 + ((int) ($m[2] ?? 0)) * 60 + ((int) ($m[3] ?? 0));

        return $minutes > 0 ? $minutes : null;
    }

    /** 40 -> '40 min'; 90 -> '1 hr 30 min'; 180 -> '3 hr'; 1560 -> '1 day 2 hr'. */
    public static function timeLabel(int $minutes): string
    {
        $minutes = max(0, $minutes);

        $days    = intdiv($minutes, 1440);
        $hours   = intdiv($minutes % 1440, 60);
        $rest    = $minutes % 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = $days . ($days === 1 ? ' day' : ' days');
        }
        if ($hours > 0) {
            $parts[] = $hours . ' hr';
        }
        if ($rest > 0 && $days === 0) {
            $parts[] = $rest . ' min';
        }

        return $parts === [] ? '0 min' : implode(' ', $parts);
    }

    // ----------------------------------------------------------------- yield

    /**
     * @return array{servings:int|null,label:string}|null
     */
    private static function yieldOf(string $html, string $text): ?array
    {
        foreach ([
            self::yieldFromRecipeCard($html),
            self::yieldFromMicrodata($html),
            self::yieldFromText($text),
        ] as $candidate) {
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return array{servings:int|null,label:string}|null */
    private static function yieldFromRecipeCard(string $html): ?array
    {
        if ($html === '' || stripos($html, 'wprm-recipe-servings') === false) {
            return null;
        }

        if (preg_match('~class="[^"]*wprm-recipe-servings\b[^"]*"[^>]*>\s*([\d/.,\s]{1,12})<~i', $html, $m) !== 1) {
            return null;
        }
        $servings = self::servingsNumber($m[1]);
        if ($servings === null) {
            return null;
        }

        $unit = '';
        if (preg_match('~class="[^"]*wprm-recipe-servings-unit\b[^"]*"[^>]*>\s*([^<]{1,24})<~i', $html, $u) === 1) {
            $unit = self::plain($u[1]);
        }

        return self::yieldResult($servings, $unit);
    }

    /** @return array{servings:int|null,label:string}|null */
    private static function yieldFromMicrodata(string $html): ?array
    {
        if ($html === '' || stripos($html, 'recipeYield') === false) {
            return null;
        }

        $raw = '';
        if (preg_match('~itemprop=["\']recipeYield["\'][^>]*content=["\']([^"\']{1,40})["\']~i', $html, $m) === 1) {
            $raw = $m[1];
        } elseif (preg_match('~itemprop=["\']recipeYield["\'][^>]*>\s*([^<]{1,40})<~i', $html, $m) === 1) {
            $raw = $m[1];
        } elseif (preg_match('~["\']recipeYield["\']\s*:\s*["\']([^"\']{1,40})["\']~i', $html, $m) === 1) {
            $raw = $m[1];
        }

        $raw = self::plain($raw);
        if ($raw === '') {
            return null;
        }

        $servings = self::servingsNumber($raw);
        $unit     = trim((string) preg_replace('~^[\d/.,\s]+~', '', $raw));

        if ($servings === null) {
            return null;
        }

        return self::yieldResult($servings, $unit);
    }

    /** @return array{servings:int|null,label:string}|null */
    private static function yieldFromText(string $text): ?array
    {
        if ($text === '') {
            return null;
        }

        $pattern = '~\b(?:serves|servings|yield|yields|makes)\b\s*[:\-–—]?\s*(\d{1,3})\s*([A-Za-z][A-Za-z\- ]{0,18})?~iu';
        if (preg_match($pattern, $text, $m) !== 1) {
            return null;
        }

        $servings = self::servingsNumber($m[1]);
        if ($servings === null) {
            return null;
        }

        return self::yieldResult($servings, self::plain($m[2] ?? ''));
    }

    /**
     * Words that are grammar, scale or time rather than a unit of yield.
     *
     * Without this, "Serves 4 to 6 people" prints as "4 to", "Serves 2 and takes
     * no time" prints as "2 and takes no time", and "serves 4 million people"
     * prints as "4 million people". The number is still right in all three, so
     * the sentence is kept and only the unit is dropped back to the default.
     */
    private const NOT_A_UNIT = [
        'to', 'or', 'and', 'of', 'a', 'an', 'the', 'in', 'for', 'with', 'from', 'per',
        'plus', 'by', 'at', 'on', 'that', 'which', 'but', 'so', 'if', 'as', 'is', 'are',
        'was', 'were', 'about', 'over', 'under', 'up', 'down', 'more', 'less', 'each',
        'every', 'my', 'our', 'your', 'this', 'these', 'it', 'we', 'you', 'i',
        'million', 'billion', 'thousand', 'hundred', 'percent', 'dozen',
        'second', 'seconds', 'minute', 'minutes', 'hour', 'hours', 'day', 'days',
        'week', 'weeks', 'month', 'months', 'year', 'years',
    ];

    /** @return array{servings:int|null,label:string}|null */
    private static function yieldResult(int $servings, string $unit): ?array
    {
        $unit = trim((string) preg_replace('~[^A-Za-z\- ].*$~u', '', $unit));
        $unit = trim(preg_replace('~\s+~', ' ', $unit) ?? '');

        // Keep the publisher's own unit — 'muffins', 'slices', 'mini muffins' —
        // but stop at the first word that is not one, and take at most three.
        $kept = [];
        foreach (explode(' ', $unit) as $word) {
            if ($word === '' || in_array(strtolower($word), self::NOT_A_UNIT, true)) {
                break;
            }
            $kept[] = $word;
            if (count($kept) === 3) {
                break;
            }
        }
        $unit = implode(' ', $kept);

        if ($unit === '' || strlen($unit) > 24) {
            $unit = $servings === 1 ? 'serving' : 'servings';
        }

        return ['servings' => $servings, 'label' => $servings . ' ' . $unit];
    }

    private static function servingsNumber(string $raw): ?int
    {
        if (preg_match('~(\d{1,3})~', $raw, $m) !== 1) {
            return null;
        }
        $n = (int) $m[1];

        return ($n >= self::MIN_SERVINGS && $n <= self::MAX_SERVINGS) ? $n : null;
    }

    // ----------------------------------------------------------- ingredients

    /**
     * How many ingredients the recipe lists — a number, never the list itself.
     *
     * Only ever counted from real markup, and only inside the FIRST ingredients
     * block: a "best 30 recipes" roundup contains dozens of cards, and counting
     * across all of them would report a nonsense figure with total confidence.
     * A count outside a plausible range is discarded rather than published.
     */
    private static function ingredientCount(string $html): ?int
    {
        if ($html === '') {
            return null;
        }

        foreach ([
            self::countRecipeCardIngredients($html),
            self::countMicrodataIngredients($html),
            self::countListAfterHeading($html),
        ] as $n) {
            if ($n !== null && $n >= self::MIN_INGREDIENTS && $n <= self::MAX_INGREDIENTS) {
                return $n;
            }
        }

        return null;
    }

    private static function countRecipeCardIngredients(string $html): ?int
    {
        $start = stripos($html, 'wprm-recipe-ingredients-container');
        if ($start === false) {
            return null;
        }

        $end    = stripos($html, 'wprm-recipe-instructions-container', $start);
        $window = $end !== false && $end > $start
            ? substr($html, $start, $end - $start)
            : substr($html, $start, 40000);

        // The lookahead matters: without it this also matches
        // wprm-recipe-ingredient-amount / -unit / -name / -notes and reports
        // five times the real number.
        $n = preg_match_all('~class="wprm-recipe-ingredient(?=["\s])~i', $window);

        return $n > 0 ? $n : null;
    }

    private static function countMicrodataIngredients(string $html): ?int
    {
        $n = preg_match_all('~itemprop=["\']recipeIngredient["\']~i', $html);
        if ($n > 0) {
            return $n;
        }

        if (preg_match('~["\']recipeIngredient["\']\s*:\s*\[(.*?)\]~s', $html, $m) === 1) {
            $n = preg_match_all('~"(?:[^"\\\\]|\\\\.)*"~', $m[1]);

            return $n > 0 ? $n : null;
        }

        return null;
    }

    /** An "Ingredients" heading followed by a list, which is how a plain post does it. */
    private static function countListAfterHeading(string $html): ?int
    {
        if (preg_match('~<(h[1-6]|strong|b|p)[^>]*>\s*ingredients\s*:?\s*</\1>~i', $html, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $from = (int) $m[0][1] + strlen($m[0][0]);
        $tail = substr($html, $from, 6000);

        $open = stripos($tail, '<ul');
        if ($open === false || $open > 400) {
            return null;                        // the list has to follow the heading closely
        }
        $close = stripos($tail, '</ul>', $open);
        if ($close === false) {
            return null;
        }

        $n = preg_match_all('~<li\b~i', substr($tail, $open, $close - $open));

        return $n > 0 ? $n : null;
    }

    // =====================================================================
    //  dish of the day
    // =====================================================================

    /**
     * One dish from TheMealDB, cached for ten minutes.
     *
     * Returns null on ANY failure — a refused connection, a timeout, a 500, a
     * body that is not JSON, JSON with no meal in it — because this sits on a
     * page that has to render either way. It never throws.
     *
     * The model carries the dish, its picture, where it came from and HOW MANY
     * ingredients it has. It deliberately does not carry the instructions.
     *
     * @param  array<string,mixed> $cfg
     * @return array<string,mixed>|null
     */
    public static function mealOfTheDay(array $cfg): ?array
    {
        try {
            $url = self::endpoint($cfg);
            $key = 'recipe-meal-' . substr(hash('sha256', $url), 0, 8);
            $now = time();

            $entry = self::cacheRead($key, $cfg);
            if ($entry !== null && $entry['ok'] && ($now - $entry['ts']) < self::MEAL_CACHE_SECONDS) {
                return is_array($entry['meal']) ? $entry['meal'] : null;
            }
            if ($entry !== null && !$entry['ok'] && ($now - $entry['ts']) < self::MEAL_FAILURE_SECONDS) {
                return null;
            }

            $res  = self::http($url, $cfg);
            $meal = $res['ok'] ? self::mealFrom($res['body']) : null;

            if ($meal !== null) {
                self::cacheWrite($key, ['ok' => true, 'ts' => $now, 'meal' => $meal], $cfg);

                return $meal;
            }

            // Upstream is unhappy. A dish we already have beats no dish at all.
            if ($entry !== null && $entry['ok'] && is_array($entry['meal'])
                && ($now - $entry['ts']) < self::MEAL_STALE_MAX_SECONDS) {
                return $entry['meal'];
            }

            self::cacheWrite($key, ['ok' => false, 'ts' => $now, 'meal' => null], $cfg);

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function mealFrom(string $body): ?array
    {
        if ($body === '') {
            return null;
        }

        $json = json_decode($body, true);
        if (!is_array($json) || !is_array($json['meals'] ?? null) || !is_array($json['meals'][0] ?? null)) {
            return null;
        }

        $m     = $json['meals'][0];
        $title = self::str($m['strMeal'] ?? '');
        if ($title === '') {
            return null;
        }

        $count = 0;
        for ($i = 1; $i <= 20; $i++) {
            if (self::str($m['strIngredient' . $i] ?? '') !== '') {
                $count++;
            }
        }

        $id     = self::str($m['idMeal'] ?? '');
        $source = self::str($m['strSource'] ?? '');
        if (preg_match('#^https?://#i', $source) !== 1) {
            $source = $id !== '' ? 'https://www.themealdb.com/meal/' . rawurlencode($id) : 'https://www.themealdb.com/';
        }

        $image = self::str($m['strMealThumb'] ?? '');
        if (preg_match('#^https?://#i', $image) !== 1) {
            $image = '';
        }

        $tags = [];
        foreach (explode(',', self::str($m['strTags'] ?? '')) as $tag) {
            $tag = trim($tag);
            if ($tag !== '' && count($tags) < 4) {
                $tags[] = $tag;
            }
        }

        $meta = [];
        if (self::str($m['strCategory'] ?? '') !== '') {
            $meta[] = self::str($m['strCategory']);
        }
        $origin = self::str($m['strArea'] ?? '');
        if ($origin === '') {
            $origin = self::str($m['strCountry'] ?? '');
        }
        if ($origin !== '') {
            $meta[] = $origin;
        }
        if ($count >= self::MIN_INGREDIENTS) {
            $meta[] = $count . ' ingredients';
        }

        return [
            'id'          => $id,
            'title'       => $title,
            'category'    => self::str($m['strCategory'] ?? ''),
            'origin'      => $origin,
            'tags'        => $tags,
            // 0 means "we do not know the pixel size" — the same thing every
            // ingested article row says, so the renderer's own default applies.
            'image_url'    => $image,
            'image_width'  => 0,
            'image_height' => 0,
            'ingredient_count' => $count >= self::MIN_INGREDIENTS ? $count : null,
            'url'         => $source,
            'source_name' => 'TheMealDB',
            'source_url'  => 'https://www.themealdb.com/',
            'link_host'   => self::host($source),
            'meta'        => $meta,
            'meta_line'   => implode(' · ', $meta),
        ];
    }

    // =====================================================================
    //  page helpers
    // =====================================================================

    /**
     * Which row leads the page: the newest one that has both a picture and
     * something to say about it, then the newest with a picture, then simply the
     * newest. Rows arrive newest-first, so the first match is the newest match.
     *
     * @param array<int,array<string,mixed>> $items
     */
    private static function pickLead(array $items): int
    {
        foreach ($items as $i => $row) {
            if (($row['image_url'] ?? '') !== '' && isset($row['meta_line'])) {
                return $i;
            }
        }
        foreach ($items as $i => $row) {
            if (($row['image_url'] ?? '') !== '') {
                return $i;
            }
        }

        return 0;
    }

    /**
     * The publishers behind the rows on this page, so the desk can credit them.
     *
     * Read off the rows themselves rather than the feed registry: a row whose
     * source has since been removed from the registry still names its publisher.
     *
     * @param  array<int,array<string,mixed>> $rows
     * @return array<int,array{slug:string,name:string,homepage:string,count:int}>
     */
    private static function sourcesOf(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $name = self::str($row['source_name'] ?? '');
            if ($name === '') {
                continue;
            }
            $slug = self::str($row['source_slug'] ?? '');
            $key  = $slug !== '' ? $slug : strtolower($name);

            if (!isset($out[$key])) {
                $out[$key] = [
                    'slug'     => $slug,
                    'name'     => $name,
                    'homepage' => self::str($row['source_homepage'] ?? ''),
                    'count'    => 0,
                ];
            }
            $out[$key]['count']++;
            if ($out[$key]['homepage'] === '') {
                $out[$key]['homepage'] = self::str($row['source_homepage'] ?? '');
            }
        }

        uasort($out, static function (array $a, array $b): int {
            return $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']);
        });

        return array_values($out);
    }

    /** The desk's label, from the feed registry when it is loaded. */
    private static function sectionLabel(): string
    {
        if (class_exists(Feeds::class)) {
            $s = Feeds::section(self::SECTION);
            if (is_array($s) && self::str($s['label'] ?? '') !== '') {
                return self::str($s['label']);
            }
        }

        return 'Recipes';
    }

    private static function sectionBlurb(): string
    {
        if (class_exists(Feeds::class)) {
            $s = Feeds::section(self::SECTION);
            if (is_array($s) && self::str($s['blurb'] ?? '') !== '') {
                return self::str($s['blurb']);
            }
        }

        return 'One dish at a time, from kitchens that publish their own recipes.';
    }

    // =====================================================================
    //  fetch + cache
    // =====================================================================

    /**
     * config.recipes.endpoints.meal_random overrides the public endpoint. That
     * exists so the suite can point this at a local server, and so a host that
     * must proxy outbound calls can be pointed at the proxy. Absent — which is
     * the shipped state — it is TheMealDB.
     *
     * @param array<string,mixed> $cfg
     */
    private static function endpoint(array $cfg): string
    {
        $set = $cfg['recipes']['endpoints'] ?? null;
        if (is_array($set)) {
            $url = self::str($set['meal_random'] ?? '');
            if ($url !== '' && preg_match('#^https?://#i', $url) === 1) {
                return $url;
            }
        }

        return self::MEAL_ENDPOINT;
    }

    /** @param array<string,mixed> $cfg */
    private static function mealEnabled(array $cfg): bool
    {
        $v = $cfg['recipes']['meal_of_the_day'] ?? true;

        return !($v === false || $v === 0 || $v === '0' || $v === '');
    }

    /**
     * One GET. Never throws.
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

        $timeout = (int) ($cfg['recipes']['timeout_seconds'] ?? self::TIMEOUT_DEFAULT);
        $timeout = max(2, min(30, $timeout));
        $headers = ['Accept: application/json, */*;q=0.5', 'Accept-Language: en-US,en;q=0.8'];

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
            $body          = curl_exec($ch);
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
            $out['error'] = 'HTTP ' . $out['status'];
        }

        return $out;
    }

    /**
     * Identifies the site and carries a contact URL, both read from config —
     * nothing about the brand is compiled in here.
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

        return 'Mozilla/5.0 (compatible; ' . $token . 'Recipes/1.0' . $contact . '; recipes desk) PHP/'
            . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    /**
     * @param  array<string,mixed> $cfg
     * @return array{ok:bool,ts:int,meal:array<string,mixed>|null}|null
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
            'meal' => is_array($json['meal'] ?? null) ? $json['meal'] : null,
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

        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
        }
    }

    /** @param array<string,mixed> $cfg */
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

    /** Markup and entities out, one line of readable text back. */
    private static function plain(string $s): string
    {
        if ($s === '') {
            return '';
        }

        // Block boundaries become spaces first, so "40 minutes</td><td>Servings"
        // does not read as one word.
        $s = (string) preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', ' ', $s);
        $s = (string) preg_replace('~<[^>]+>~', ' ', $s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = str_replace(["\xC2\xA0", "\xE2\x80\x8B"], ' ', $s);

        return trim((string) preg_replace('~\s+~u', ' ', $s));
    }

    /** @param mixed $v */
    private static function str($v): string
    {
        return is_string($v) ? trim($v) : (is_scalar($v) ? trim((string) $v) : '');
    }

    private static function host(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return (string) preg_replace('~^www\.~i', '', $host);
    }

    private static function short(string $s): string
    {
        $s = trim((string) preg_replace('~\s+~u', ' ', $s));

        return mb_strlen($s) > 160 ? mb_substr($s, 0, 157) . '…' : $s;
    }
}
