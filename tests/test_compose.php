<?php
declare(strict_types=1);

/**
 * tests/test_compose.php — TEB\Compose::home()
 *
 * The finance quota is the client's commercial requirement (SPEC 0.5), so most of
 * this file is built to make it fail loudly if the quota is ever softened: every
 * fixture below hands Compose a front page that WANTS to be a markets page.
 */

teb_require_app('Compose');

use TEB\Compose;

const NOW_MS = 1787000000000;   // fixed clock — Compose is pure, so the tests are too

/** Build one article row. $minutesAgo drives recency decay. */
function art(
    int $id,
    string $section,
    string $source,
    int $minutesAgo,
    bool $image = true,
    float $weight = 1.0
): array {
    return [
        'id'           => $id,
        'title'        => ucfirst($source) . ' story ' . $id . ' on ' . $section,
        'url'          => 'https://example.test/' . $source . '/' . $id,
        'summary'      => 'Feed-provided summary for story ' . $id . '.',
        'image_url'    => $image ? 'https://cdn.example.test/' . $id . '.jpg' : null,
        'published_at' => NOW_MS - ($minutesAgo * 60000),
        'section'      => $section,
        'source'       => $source,
        'source_name'  => strtoupper($source),
        'weight'       => $weight,
        'slug'         => 'story-' . $id,
    ];
}

/** Base config in the shape config.php ships (docs/CONTRACT.md). */
function cfgFixture(array $composeOverrides = []): array
{
    return [
        'site'    => ['name' => 'Fixture Daily', 'timezone' => 'UTC'],
        'compose' => array_merge([
            'finance_max_on_home'      => 2,
            'finance_blocked_blocks'   => ['hero', 'us', 'international'],
            'hero_sub_count'           => 4,
            'per_source_cap_per_block' => 2,
            'ticker_count'             => 12,
        ], $composeOverrides),
    ];
}

/** A full, realistic newsroom: many sources per section, mixed ages, mixed images. */
function newsroomRows(array $opts = []): array
{
    $withRecipes = $opts['recipes'] ?? true;
    $rows = [];
    $id   = 100;

    $plan = [
        ['us',            ['abc', 'cbs', 'npr', 'nyt', 'upi'],          4, 1.15],
        ['international', ['bbc', 'guardian', 'aljazeera', 'dw'],       3, 1.05],
        ['world',         ['bbc', 'skynews', 'wapo', 'guardian'],       3, 1.00],
        ['weather',       ['nws'],                                      3, 1.00],
        ['health',        ['kff', 'npr'],                               2, 0.95],
        ['technology',    ['nyt', 'cbs'],                               2, 0.95],
        ['sports',        ['espn'],                                     2, 0.90],
    ];
    if ($withRecipes) {
        $plan[] = ['recipes', ['budgetbytes', 'smittenkitchen'], 3, 0.80];
    }

    $minutes = 40;
    foreach ($plan as [$section, $sources, $per, $weight]) {
        foreach ($sources as $source) {
            for ($i = 0; $i < $per; $i++) {
                $rows[] = art($id, $section, $source, $minutes, ($id % 3) !== 0, $weight);
                $minutes += 7;
                $id++;
            }
        }
    }
    return $rows;
}

/**
 * The adversarial input: finance is the freshest, the heaviest and always has a
 * picture, so on score alone it would own the hero and the top of the page.
 */
function financeHeavyRows(int $financeCount = 8): array
{
    $rows = [];
    $id   = 900;
    $sections = ['business', 'markets', 'crypto', 'finance'];
    for ($i = 0; $i < $financeCount; $i++) {
        $rows[] = art($id++, $sections[$i % 4], 'wire' . ($i % 3), 1 + $i, true, 3.0);
    }
    // ...and the general news is stale, unillustrated and lightly weighted.
    foreach (newsroomRows() as $row) {
        $row['published_at'] = $row['published_at'] - (600 * 60000);
        $row['image_url']    = null;
        $row['weight']       = 0.6;
        $rows[]              = $row;
    }
    return $rows;
}

/** Every item the model puts on the page, in every region. */
function allItems(array $m): array
{
    $out = [];
    if (is_array($m['hero']['lead'] ?? null)) {
        $out[] = $m['hero']['lead'];
    }
    foreach ($m['hero']['subs'] as $r) {
        $out[] = $r;
    }
    foreach ($m['blocks'] as $b) {
        foreach ($b['items'] as $r) {
            $out[] = $r;
        }
    }
    foreach ($m['markets'] as $r) {
        $out[] = $r;
    }
    foreach ($m['ticker'] as $r) {
        $out[] = $r;
    }
    return $out;
}

function financeItems(array $m): array
{
    return array_values(array_filter(allItems($m), static fn(array $r): bool => !empty($r['is_finance'])));
}

function blockIds(array $m): array
{
    return array_map(static fn(array $b): string => (string)$b['id'], $m['blocks']);
}

function modelIsWellFormed(array $m): void
{
    assertTrue(array_key_exists('ticker', $m), 'model has ticker');
    assertTrue(array_key_exists('hero', $m), 'model has hero');
    assertTrue(array_key_exists('blocks', $m), 'model has blocks');
    assertTrue(array_key_exists('markets', $m), 'model has markets');
    assertTrue(is_array($m['ticker']), 'ticker is an array');
    assertTrue(is_array($m['blocks']), 'blocks is an array');
    assertTrue(is_array($m['markets']), 'markets is an array');
    assertTrue(array_key_exists('lead', $m['hero']), 'hero has lead');
    assertTrue(is_array($m['hero']['subs']), 'hero subs is an array');
    if ($m['hero']['lead'] !== null) {
        assertTrue(is_array($m['hero']['lead']), 'hero lead is a row or null');
    }
    foreach ($m['blocks'] as $b) {
        foreach (['id', 'label', 'href', 'items'] as $k) {
            assertTrue(array_key_exists($k, $b), "block carries $k");
        }
        assertTrue(count($b['items']) > 0, 'no empty block is emitted');
        assertTrue(strpos((string)$b['href'], '://') === false, 'href is a route path, not a URL');
    }
    foreach (allItems($m) as $r) {
        assertTrue(isset($r['id']) && is_int($r['id']) && $r['id'] > 0, 'every item has an int id');
        assertTrue(isset($r['title']) && $r['title'] !== '', 'every item has a title');
    }
}

return [

    // ---------------------------------------------------------------- quota --

    'finance-heavy input still yields a finance-free hero' => function (): void {
        $m = Compose::home(financeHeavyRows(), cfgFixture(), NOW_MS);
        assertTrue($m['hero']['lead'] !== null, 'a finance-heavy page still has a lead');
        assertFalse((bool)$m['hero']['lead']['is_finance'], 'hero lead is not finance');
        foreach ($m['hero']['subs'] as $r) {
            assertFalse((bool)$r['is_finance'], 'hero sub ' . $r['id'] . ' is not finance');
        }
        assertTrue(count($m['hero']['subs']) > 0, 'hero subs are populated from general news');
    },

    'finance-heavy input still yields finance-free first two blocks' => function (): void {
        $m = Compose::home(financeHeavyRows(), cfgFixture(), NOW_MS);
        assertSame(['us', 'international'], array_slice(blockIds($m), 0, 2), 'the top two blocks still lead the page');
        foreach (array_slice($m['blocks'], 0, 2) as $b) {
            foreach ($b['items'] as $r) {
                assertFalse((bool)$r['is_finance'], 'block ' . $b['id'] . ' item ' . $r['id'] . ' is not finance');
            }
            // Not merely finance-free: still FULL. Finance must not be able to eat a
            // block's slots and leave a hole where the general news should be.
            assertGreaterThanOrEqual(4, count($b['items']), "block {$b['id']} is still filled with general news");
        }
    },

    'finance never appears in any block, only in the markets strip' => function (): void {
        $m = Compose::home(financeHeavyRows(), cfgFixture(), NOW_MS);
        foreach ($m['blocks'] as $b) {
            foreach ($b['items'] as $r) {
                assertFalse((bool)$r['is_finance'], 'no finance in block ' . $b['id']);
            }
        }
        foreach ($m['ticker'] as $r) {
            assertFalse((bool)$r['is_finance'], 'the ticker is headlines, never markets');
        }
        assertTrue(count($m['markets']) > 0, 'the markets strip is where finance surfaces');
        foreach ($m['markets'] as $r) {
            assertTrue((bool)$r['is_finance'], 'markets strip carries the finance rows');
        }
    },

    'total finance on the whole front page respects the cap' => function (): void {
        $m = Compose::home(financeHeavyRows(20), cfgFixture(['finance_max_on_home' => 2]), NOW_MS);
        assertCount(2, financeItems($m), 'exactly the cap, with 20 finance stories on offer');
    },

    'raising finance_max_on_home really changes the output' => function (): void {
        $rows = financeHeavyRows(12);
        $two  = Compose::home($rows, cfgFixture(['finance_max_on_home' => 2]), NOW_MS);
        $five = Compose::home($rows, cfgFixture(['finance_max_on_home' => 5]), NOW_MS);
        assertCount(2, financeItems($two), 'cap 2 puts 2 finance items on the page');
        assertCount(5, financeItems($five), 'cap 5 puts 5 finance items on the page');
        assertCount(2, $two['markets'], 'markets strip follows the cap');
        assertCount(5, $five['markets'], 'markets strip follows the raised cap');
        assertNotSame($two, $five, 'the config value changes the model');
        // ...and it must not have leaked upward into the protected regions.
        assertFalse((bool)$five['hero']['lead']['is_finance'], 'a raised cap still cannot touch the hero');
    },

    'a zero cap removes finance from the page entirely' => function (): void {
        $m = Compose::home(financeHeavyRows(12), cfgFixture(['finance_max_on_home' => 0]), NOW_MS);
        assertCount(0, financeItems($m), 'cap 0 means no finance anywhere');
        assertCount(0, $m['markets'], 'markets strip is empty');
        assertTrue($m['hero']['lead'] !== null, 'the rest of the page still renders');
    },

    'finance_blocked_blocks is read, not decorative' => function (): void {
        $rows = financeHeavyRows(12);
        $open = Compose::home($rows, cfgFixture(), NOW_MS);
        $shut = Compose::home(
            $rows,
            cfgFixture(['finance_blocked_blocks' => ['hero', 'us', 'international', 'markets']]),
            NOW_MS
        );
        assertTrue(count($open['markets']) > 0, 'markets strip fills by default');
        assertCount(0, $shut['markets'], 'blocking the strip in config empties it');
        assertCount(0, financeItems($shut), 'and finance then has nowhere left to go');
    },

    'reads a row in the exact shape Db::recentArticles returns' => function (): void {
        // Db hands us source_slug / source_name / source_weight (not 'source' /
        // 'weight'), casts published_at to int — so a NULL date arrives as 0, not
        // null — and casts a NULL image_url to ''. If Compose missed those key
        // names every story would silently collapse to weight 1.0 and one unknown
        // source, which would quietly disable the per-source caps.
        $dbRow = static function (int $id, string $slug, string $section, float $weight, int $ms, string $img): array {
            return [
                'id' => $id, 'source_id' => 3,
                'source_slug' => $slug, 'source_name' => strtoupper($slug),
                'source_weight' => $weight, 'source_tier' => 1, 'source_homepage' => 'https://' . $slug . '.test/',
                'section' => $section, 'url' => 'https://' . $slug . '.test/story/' . $id,
                'title' => 'Story ' . $id, 'title_key' => 'story-' . $id, 'summary' => 'Summary.',
                'image_url' => $img, 'image_width' => 800, 'image_height' => 533,
                'author' => 'A Reporter', 'published_at' => $ms, 'fetched_at' => NOW_MS,
                'guid_hash' => str_repeat((string)$id, 8),
            ];
        };
        $rows = [
            $dbRow(1, 'heavywire', 'us', 2.4, NOW_MS - 1800000, 'https://cdn.test/1.jpg'),
            $dbRow(2, 'lightwire', 'us', 0.3, NOW_MS - 1800000, 'https://cdn.test/2.jpg'),
            $dbRow(3, 'lightwire', 'world', 0.3, 0, ''),           // NULL date arrived as 0
        ];
        $m = Compose::home($rows, cfgFixture(), NOW_MS);

        assertSame(1, $m['hero']['lead']['id'], 'source_weight was read off the joined row');
        assertSame('heavywire', $m['hero']['lead']['source'], 'source_slug was read');
        assertSame('HEAVYWIRE', $m['hero']['lead']['source_name'], 'source_name survives');
        assertSame(2.4, $m['hero']['lead']['weight'], 'the weight is the source weight, not the 1.0 default');

        // Rule 4 of the build: every image ships explicit width and height. The
        // renderer can only do that if Compose passes the columns through.
        assertSame(800, $m['hero']['lead']['image_width'], 'image_width survives composition');
        assertSame(533, $m['hero']['lead']['image_height'], 'image_height survives composition');
        assertSame('A Reporter', $m['hero']['lead']['author'], 'unknown columns are preserved for the renderer');

        // Identity is the SLUG. Db COALESCEs s.name over a.source_name, so one
        // publisher can arrive under two display names in the same batch; if the
        // cap keyed off the name they would count as two sources and both get in.
        $twoNames = [
            array_merge($dbRow(10, 'nyt', 'us', 1.3, NOW_MS - 600000, 'https://cdn.test/a.jpg'),
                ['source_name' => 'The New York Times']),
            array_merge($dbRow(11, 'nyt', 'us', 1.3, NOW_MS - 900000, 'https://cdn.test/b.jpg'),
                ['source_name' => 'NYT']),
            $dbRow(12, 'upi', 'us', 1.0, NOW_MS - 1200000, 'https://cdn.test/c.jpg'),
        ];
        $capped = Compose::home($twoNames, cfgFixture(['per_source_cap_per_block' => 1, 'hero_sub_count' => 2]), NOW_MS);
        $heroSources = array_map(static fn(array $r): string => $r['source'], $capped['hero']['subs']);
        array_unshift($heroSources, $capped['hero']['lead']['source']);
        assertSame(['nyt', 'upi'], $heroSources, 'two display names for one slug are still ONE source');

        $undated = null;
        foreach (allItems($m) as $r) {
            if ($r['id'] === 3) {
                $undated = $r;
            }
        }
        assertNotNull($undated, 'the undated row is still placed');
        assertNull($undated['published_at'], 'published_at 0 is normalised to null, never to 1970');
        assertFalse($undated['has_image'], "an empty image_url is not an image");
        assertNull($undated['image_url'], 'and it is normalised to null for the renderer');
    },

    // ------------------------------------------------------------ selection --

    'per-source cap per block holds' => function (): void {
        $m   = Compose::home(newsroomRows(), cfgFixture(['per_source_cap_per_block' => 2]), NOW_MS);
        $cap = 2;
        foreach ($m['blocks'] as $b) {
            $bySource = [];
            foreach ($b['items'] as $r) {
                $bySource[$r['source']] = ($bySource[$r['source']] ?? 0) + 1;
            }
            foreach ($bySource as $source => $n) {
                assertTrue($n <= $cap, "block {$b['id']} took $n from $source (cap $cap)");
            }
        }
        $heroBySource = [];
        foreach (allItems(['hero' => $m['hero'], 'blocks' => [], 'markets' => [], 'ticker' => []]) as $r) {
            $heroBySource[$r['source']] = ($heroBySource[$r['source']] ?? 0) + 1;
        }
        foreach ($heroBySource as $source => $n) {
            assertTrue($n <= $cap, "hero took $n from $source (cap $cap)");
        }
    },

    'tightening the per-source cap changes the output' => function (): void {
        $rows = newsroomRows();
        $two  = Compose::home($rows, cfgFixture(['per_source_cap_per_block' => 2]), NOW_MS);
        $one  = Compose::home($rows, cfgFixture(['per_source_cap_per_block' => 1]), NOW_MS);
        assertNotSame($two, $one, 'per_source_cap_per_block is read');
        foreach ($one['blocks'] as $b) {
            $seen = [];
            foreach ($b['items'] as $r) {
                assertFalse(isset($seen[$r['source']]), "cap 1: {$b['id']} took {$r['source']} twice");
                $seen[$r['source']] = true;
            }
        }
    },

    'no source leads twice' => function (): void {
        $m     = Compose::home(newsroomRows(), cfgFixture(), NOW_MS);
        $leads = [];
        $leads[] = $m['hero']['lead']['source'];
        foreach ($m['blocks'] as $b) {
            $leads[] = $b['items'][0]['source'];
        }
        if ($m['markets']) {
            $leads[] = $m['markets'][0]['source'];
        }
        assertSame(count($leads), count(array_unique($leads)), 'lead sources are unique: ' . implode(',', $leads));
        assertTrue(count($leads) >= 5, 'the fixture really does exercise several lead slots');
    },

    'the repeat-source penalty reorders, it does not merely rescore' => function (): void {
        // alpha owns the three best stories; bravo's is clearly weaker. With the
        // penalty switched off this is [alpha, alpha, alpha]; with it, bravo is lifted
        // into second place. The cap is set wide so it cannot be doing the work.
        $rows = [
            art(1, 'us', 'alpha', 0, false, 1.0),
            art(2, 'us', 'alpha', 10, false, 1.0),
            art(3, 'us', 'alpha', 20, false, 1.0),
            art(4, 'us', 'bravo', 60, false, 1.0),
        ];
        $m = Compose::home($rows, cfgFixture([
            'hero_sub_count'           => 2,
            'per_source_cap_per_block' => 3,
        ]), NOW_MS);
        assertSame('alpha', $m['hero']['lead']['source'], 'the best story still leads');
        assertSame(['bravo', 'alpha'], array_map(
            static fn(array $r): string => $r['source'],
            $m['hero']['subs']
        ), 'the second alpha is pushed below bravo by the repeat-source penalty');
    },

    'recency, source weight, section priority and the image bonus all score' => function (): void {
        // Fresh-but-plain beats old-but-illustrated: with the decay removed the image
        // bonus would win this, so this asserts the decay itself, not a tiebreak.
        $m = Compose::home([
            art(1, 'us', 'alpha', 600, true, 1.0),
            art(2, 'us', 'alpha', 5, false, 1.0),
        ], cfgFixture(), NOW_MS);
        assertSame(2, $m['hero']['lead']['id'], 'recency decay puts the fresh story on top');
        assertGreaterThan(
            $m['hero']['subs'][0]['score'],
            $m['hero']['lead']['score'],
            'ten hours of decay outweighs the image bonus'
        );

        // heavier source wins at equal age
        $m = Compose::home([
            art(3, 'us', 'alpha', 30, false, 0.6),
            art(4, 'us', 'bravo', 30, false, 1.6),
        ], cfgFixture(), NOW_MS);
        assertSame(4, $m['hero']['lead']['id'], 'source weight is applied');

        // us outranks world at equal age
        $m = Compose::home([
            art(5, 'world', 'alpha', 30, false, 1.0),
            art(6, 'us', 'alpha', 30, false, 1.0),
        ], cfgFixture(), NOW_MS);
        assertSame(6, $m['hero']['lead']['id'], 'section priority puts U.S. above world');

        // international outranks world at equal age
        $m = Compose::home([
            art(7, 'world', 'alpha', 30, false, 1.0),
            art(8, 'international', 'alpha', 30, false, 1.0),
        ], cfgFixture(), NOW_MS);
        assertSame(8, $m['hero']['lead']['id'], 'section priority puts international above world');

        // The half-life is what balances "fresh" against "authoritative", so it must
        // be able to flip that decision — a hardcoded constant would pass everything
        // above this line.
        $freshLight = art(11, 'us', 'alpha', 30, false, 1.0);
        $staleHeavy = art(12, 'us', 'bravo', 600, false, 3.0);
        $short = Compose::home([$freshLight, $staleHeavy], cfgFixture(['half_life_hours' => 1]), NOW_MS);
        $long  = Compose::home([$freshLight, $staleHeavy], cfgFixture(['half_life_hours' => 48]), NOW_MS);
        assertSame(11, $short['hero']['lead']['id'], 'a short half-life makes freshness decisive');
        assertSame(12, $long['hero']['lead']['id'], 'a long half-life lets the heavier source win');

        // the picture breaks an otherwise exact tie
        $m = Compose::home([
            art(9, 'us', 'alpha', 30, false, 1.0),
            art(10, 'us', 'alpha', 30, true, 1.0),
        ], cfgFixture(), NOW_MS);
        assertSame(10, $m['hero']['lead']['id'], 'image bonus breaks the tie');
    },

    'the ticker is latest-first, capped, and its count is configurable' => function (): void {
        // One publisher is given a run of stories in a section that has no block, so
        // they all fall through to the ticker: without the cap it would be a
        // single-source list, which is what the cap exists to prevent.
        $rows = newsroomRows();
        for ($i = 0; $i < 9; $i++) {
            $rows[] = art(700 + $i, 'sports', 'espn', 3 + $i, true, 1.0);
        }
        $m = Compose::home($rows, cfgFixture(['ticker_count' => 12]), NOW_MS);
        assertCount(12, $m['ticker'], 'the ticker fills to its configured length');

        $prev = PHP_INT_MAX;
        foreach ($m['ticker'] as $r) {
            $at = (int)$r['published_at'];
            assertLessThanOrEqual($prev, $at, 'the ticker runs latest-first');
            $prev = $at;
        }

        $bySource = [];
        foreach ($m['ticker'] as $r) {
            $bySource[$r['source']] = ($bySource[$r['source']] ?? 0) + 1;
        }
        foreach ($bySource as $source => $n) {
            assertLessThanOrEqual(2, $n, "the ticker took $n from $source");
        }

        assertCount(4, Compose::home($rows, cfgFixture(['ticker_count' => 4]), NOW_MS)['ticker'], 'ticker_count is read');
        assertCount(0, Compose::home($rows, cfgFixture(['ticker_count' => 0]), NOW_MS)['ticker'], 'zero means no ticker');
    },

    // ------------------------------------------------------------ structure --

    'no article id appears twice anywhere in the model, ticker included' => function (): void {
        foreach ([newsroomRows(), financeHeavyRows(12)] as $i => $rows) {
            $m    = Compose::home($rows, cfgFixture(), NOW_MS);
            $ids  = array_map(static fn(array $r): int => $r['id'], allItems($m));
            assertTrue(count($ids) > 20, "fixture $i places a real page (" . count($ids) . ' items)');
            assertSame(count($ids), count(array_unique($ids)), "fixture $i emitted a duplicate id");
            assertTrue(count($m['ticker']) > 0, "fixture $i fills the ticker");
        }
    },

    'duplicate input rows are collapsed, not emitted twice' => function (): void {
        $rows = newsroomRows();
        $dupe = array_merge($rows, array_slice($rows, 0, 6));
        $m    = Compose::home($dupe, cfgFixture(), NOW_MS);
        $ids  = array_map(static fn(array $r): int => $r['id'], allItems($m));
        assertSame(count($ids), count(array_unique($ids)), 'a repeated input row is placed once');
    },

    'block order is exactly us, international, world, weather, recipes' => function (): void {
        $m = Compose::home(newsroomRows(), cfgFixture(), NOW_MS);
        assertSame(['us', 'international', 'world', 'weather', 'recipes'], blockIds($m), 'canonical block order');
        assertSame(Compose::BLOCK_ORDER, blockIds($m), 'and it matches the published constant');
        assertNotContains('markets', blockIds($m), 'markets is the strip after the blocks, not a block');
        assertSame('/section/us', $m['blocks'][0]['href'], 'block href is a route path');
        assertSame('/recipes', $m['blocks'][4]['href'], 'recipes has its own route');
    },

    'block order survives a reordered and finance-heavy input' => function (): void {
        $rows = financeHeavyRows(12);
        shuffleDeterministically($rows);
        $m = Compose::home($rows, cfgFixture(), NOW_MS);
        $ids = blockIds($m);
        assertSame(array_values(array_intersect(Compose::BLOCK_ORDER, $ids)), $ids, 'order is canonical');
        assertTrue(count($m['markets']) > 0, 'and markets still comes out as the trailing strip');
    },

    'exact score ties resolve deterministically, not by database row order' => function (): void {
        // A feed that supplies no dates (CONTRACT: published_at is ms or null) gives
        // every one of its rows an IDENTICAL score. If ties fell through to input
        // order the front page would reshuffle on every request, because the row
        // order is only as stable as the database's ORDER BY on equal keys.
        $rows = [];
        for ($i = 1; $i <= 14; $i++) {
            $rows[] = [
                'id'           => $i,
                'title'        => 'Undated story ' . $i,
                'url'          => 'https://example.test/u/' . $i,
                'summary'      => 'No date on this feed.',
                'image_url'    => null,
                'published_at' => null,
                'section'      => $i <= 7 ? 'us' : 'world',
                'source'       => 'undated',
                'source_name'  => 'UNDATED',
                'weight'       => 1.0,
            ];
        }
        $scores = array_unique(array_map(
            static fn(array $r): float => $r['score'],
            array_filter(allItems(Compose::home($rows, cfgFixture(), NOW_MS)), static fn(array $r): bool => $r['section'] === 'us')
        ));
        assertCount(1, $scores, 'the fixture really does produce an exact tie');

        $forward  = Compose::home($rows, cfgFixture(), NOW_MS);
        $backward = Compose::home(array_reverse($rows), cfgFixture(), NOW_MS);
        $rotated  = $rows;
        shuffleDeterministically($rotated);
        $shuffled = Compose::home($rotated, cfgFixture(), NOW_MS);
        assertSame($forward, $backward, 'a tie must not be settled by input order');
        assertSame($forward, $shuffled, 'nor by any other input order');
        assertTrue($forward['hero']['lead'] !== null, 'and the tied page still composes');
    },

    'ranking does not depend on the order rows arrive from the database' => function (): void {
        $rows = newsroomRows();
        $a = Compose::home($rows, cfgFixture(), NOW_MS);
        $b = Compose::home(array_reverse($rows), cfgFixture(), NOW_MS);
        assertSame($a, $b, 'a total ordering means DB row order cannot change the page');
    },

    'deterministic: the same input twice gives a deeply identical model' => function (): void {
        $rows = financeHeavyRows(12);
        $cfg  = cfgFixture();
        $a = Compose::home($rows, $cfg, NOW_MS);
        $b = Compose::home($rows, $cfg, NOW_MS);
        assertSame($a, $b, 'Compose::home is not deterministic');
        // ...and a different clock must produce a DIFFERENT page, or the recency decay
        // is dead. assertTrue(is_array(...)) used to stand here, which can never fail:
        // home() is declared ': array', so PHP guarantees it before the test runs.
        $c = Compose::home($rows, $cfg, NOW_MS + (36 * 3600 * 1000));
        assertNotSame($a, $c, 'moving the clock 36 hours changed nothing — the decay is not being applied');
        assertTrue($c['hero']['lead'] !== null, 'and a later clock still composes a page');
    },

    // -------------------------------------------------------------- degrade --

    'degrades: three articles in total' => function (): void {
        $rows = [
            art(1, 'us', 'abc', 10),
            art(2, 'world', 'bbc', 20),
            art(3, 'recipes', 'budgetbytes', 30),
        ];
        $m = Compose::home($rows, cfgFixture(), NOW_MS);
        modelIsWellFormed($m);
        assertTrue($m['hero']['lead'] !== null, 'three stories still produce a lead');
        $ids = array_map(static fn(array $r): int => $r['id'], allItems($m));
        assertSame(count($ids), count(array_unique($ids)), 'no duplication when starved');
        assertTrue(count($ids) <= 3, 'it cannot invent articles it does not have');
    },

    'degrades: zero recipes' => function (): void {
        $m = Compose::home(newsroomRows(['recipes' => false]), cfgFixture(), NOW_MS);
        modelIsWellFormed($m);
        assertSame(['us', 'international', 'world', 'weather'], blockIds($m), 'the recipes block is dropped, order intact');
        assertNotContains('recipes', blockIds($m), 'no empty recipes block');
    },

    'degrades: every article from one source' => function (): void {
        $rows = [];
        $id = 1;
        foreach (['us', 'us', 'us', 'us', 'international', 'international', 'world', 'world', 'recipes', 'weather'] as $i => $s) {
            $rows[] = art($id++, $s, 'onlysource', 5 + ($i * 11));
        }
        $m = Compose::home($rows, cfgFixture(), NOW_MS);
        modelIsWellFormed($m);
        assertTrue($m['hero']['lead'] !== null, 'one source still leads the page');
        assertTrue(count($m['blocks']) >= 3, 'the cap must not blank the page when there is no diversity to have');
        $ids = array_map(static fn(array $r): int => $r['id'], allItems($m));
        assertSame(count($ids), count(array_unique($ids)), 'no duplication in the single-source case');
    },

    'degrades: every article is finance' => function (): void {
        $rows = [];
        for ($i = 0; $i < 15; $i++) {
            $rows[] = art(500 + $i, ['business', 'markets', 'crypto', 'finance'][$i % 4], 'wire' . ($i % 3), 2 + $i);
        }
        $m = Compose::home($rows, cfgFixture(), NOW_MS);
        modelIsWellFormed($m);
        assertSame(null, $m['hero']['lead'], 'an all-finance day does not get a finance hero');
        assertCount(0, $m['blocks'], 'and no blocks');
        assertCount(0, $m['ticker'], 'and no finance in the ticker');
        assertCount(2, $m['markets'], 'only the capped markets strip survives');
    },

    'degrades: zero articles' => function (): void {
        $m = Compose::home([], cfgFixture(), NOW_MS);
        modelIsWellFormed($m);
        assertSame(null, $m['hero']['lead'], 'no lead');
        assertCount(0, $m['hero']['subs'], 'no subs');
        assertCount(0, $m['blocks'], 'no blocks');
        assertCount(0, $m['markets'], 'no markets');
        assertCount(0, $m['ticker'], 'no ticker');
    },

    'degrades: junk rows never throw' => function (): void {
        // The runner fails any test that throws, so calling straight through IS
        // the assertion that Compose survives garbage from the database.
        $m = Compose::home([
                ['id' => 0, 'title' => 'no id'],
                ['id' => 7],                                   // no title
                ['title' => 'no id at all'],
                'not an array',
                ['id' => '11', 'title' => 'string id', 'published_at' => '1787000000', 'section' => 'US ',
                 'source_name' => 'The Wire', 'image_url' => 'null', 'weight' => 'heavy'],
                ['id' => 12, 'title' => 'future dated', 'published_at' => NOW_MS + 90000000, 'section' => 'us',
                 'url' => 'https://news.example.test/x'],
                ['id' => 13, 'title' => 'no section', 'published_at' => null],
        ], cfgFixture(), NOW_MS);
        modelIsWellFormed($m);
        assertTrue($m['hero']['lead'] !== null, 'the usable rows still compose');
        $ids = array_map(static fn(array $r): int => $r['id'], allItems($m));
        assertNotContains(0, $ids, 'the id-less row is dropped');
        assertNotContains(7, $ids, 'the title-less row is dropped');
        assertContains(11, $ids, 'a numeric-string id is accepted');
        assertContains(12, $ids, 'a future-dated row is accepted');
    },

    'degrades: an empty config falls back to the shipped defaults' => function (): void {
        $m = Compose::home(financeHeavyRows(12), [], NOW_MS);
        modelIsWellFormed($m);
        assertFalse((bool)$m['hero']['lead']['is_finance'], 'the quota holds with no config at all');
        assertTrue(count(financeItems($m)) <= 2, 'and defaults to the documented cap of 2');

        // Garbage values on the right keys must not produce a broken page either.
        $m = Compose::home(newsroomRows(), cfgFixture([
            'finance_max_on_home'      => 'lots',
            'hero_sub_count'           => -5,
            'per_source_cap_per_block' => 0,
            'ticker_count'             => 'many',
            'half_life_hours'          => 0,
        ]), NOW_MS);
        modelIsWellFormed($m);
    },

    // ------------------------------------------------------------ hardening --

    'the finance ban is absolute — emptying finance_blocked_blocks cannot open the hero' => function (): void {
        // finance_blocked_blocks names the regions the client may shut; it is not a
        // list the client may SHORTEN to let markets back into the news. SPEC 0.5 is
        // unconditional, so an empty list must change nothing except that the strip
        // itself stays open.
        $rows = financeHeavyRows(12);
        $m    = Compose::home($rows, cfgFixture(['finance_blocked_blocks' => []]), NOW_MS);
        modelIsWellFormed($m);
        assertTrue($m['hero']['lead'] !== null, 'the page still has a lead');
        assertFalse((bool)$m['hero']['lead']['is_finance'], 'an empty blocked list still cannot put finance in the hero');
        foreach ($m['hero']['subs'] as $r) {
            assertFalse((bool)$r['is_finance'], 'nor in a hero sub');
        }
        foreach ($m['blocks'] as $b) {
            foreach ($b['items'] as $r) {
                assertFalse((bool)$r['is_finance'], 'nor in block ' . $b['id']);
            }
        }
        foreach ($m['ticker'] as $r) {
            assertFalse((bool)$r['is_finance'], 'nor in the ticker');
        }
        assertCount(2, financeItems($m), 'finance still surfaces, still only in the capped strip');
        assertCount(2, $m['markets'], 'and the strip is the place it surfaces');
    },

    'two rows sharing an id resolve the same way whichever order they arrive in' => function (): void {
        // A partial re-ingest, a UNION, or two result sets stitched together can hand
        // Compose the same id twice with different content. Taking whichever came
        // first would make the front page depend on database row order — the thing
        // this class exists to be free of.
        $strong = art(1, 'us', 'alpha', 5, true, 1.0);
        $strong['title'] = 'The better copy of story 1';
        $weak = art(1, 'us', 'alpha', 600, false, 0.2);
        $weak['title'] = 'The worse copy of story 1';

        $rows = [$strong, $weak, art(2, 'us', 'bravo', 30), art(3, 'world', 'charlie', 40)];
        $forward  = Compose::home($rows, cfgFixture(), NOW_MS);
        $backward = Compose::home([$weak, $strong, $rows[2], $rows[3]], cfgFixture(), NOW_MS);

        assertSame($forward, $backward, 'a duplicated id was settled by input order');
        assertSame('The better copy of story 1', $forward['hero']['lead']['title'], 'the stronger of the two rows is the one kept');

        $ids = array_map(static fn(array $r): int => $r['id'], allItems($forward));
        assertSame(count($ids), count(array_unique($ids)), 'and it is still placed only once');
    },

    'the compose sub-array may be passed on its own, whichever keys it carries' => function (): void {
        // Compose documents that $cfg may be "the whole config array (or just its
        // 'compose' sub-array)". A bare array used to be recognised only when it
        // happened to carry finance_max_on_home or block_counts, so
        // Compose::home($rows, Config::get('compose'), $now) silently composed with
        // the defaults instead of the client's settings.
        $rows = newsroomRows();
        $opts = ['hero_sub_count' => 1, 'ticker_count' => 3, 'per_source_cap_per_block' => 1];

        $bare     = Compose::home($rows, $opts, NOW_MS);
        $wrapped  = Compose::home($rows, ['compose' => $opts], NOW_MS);
        $defaults = Compose::home($rows, [], NOW_MS);

        assertSame($wrapped, $bare, 'a bare compose array must be read exactly like a wrapped one');
        assertNotSame($defaults, $bare, 'and it must not fall through to the shipped defaults');
        assertCount(1, $bare['hero']['subs'], 'hero_sub_count was read off the bare array');
        assertCount(3, $bare['ticker'], 'ticker_count was read off the bare array');

        // A full config that has no compose section at all still gets the defaults.
        $noCompose = Compose::home($rows, ['site' => ['name' => 'Fixture Daily'], 'db' => ['driver' => 'sqlite']], NOW_MS);
        assertSame($defaults, $noCompose, 'a config with no compose section composes with the defaults');
    },

];

/** A fixed, seedless permutation — determinism matters more than randomness here. */
function shuffleDeterministically(array &$rows): void
{
    $out = [];
    $n   = count($rows);
    for ($i = 0; $i < $n; $i++) {
        $out[] = $rows[($i * 7) % $n];
    }
    $seen = [];
    $uniq = [];
    foreach ($out as $r) {
        if (!isset($seen[$r['id']])) {
            $seen[$r['id']] = true;
            $uniq[] = $r;
        }
    }
    foreach ($rows as $r) {
        if (!isset($seen[$r['id']])) {
            $seen[$r['id']] = true;
            $uniq[] = $r;
        }
    }
    $rows = $uniq;
}
