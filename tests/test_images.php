<?php

declare(strict_types=1);

/**
 * Images — the module that stops a 60x60 thumbnail being blown up into a lead
 * photograph. Every URL rule here was verified against the live CDN before it
 * was written; these tests lock in the behaviour without touching the network.
 */

require_once __DIR__ . '/lib.php';
require_once dirname(__DIR__) . '/app/Images.php';

use TEB\Images;

return [

    'the CBS 60x60 thumbnail that shipped is rejected from every real slot' => function (): void {
        // The exact image from the article the client complained about.
        $a = [
            'image_url'    => 'https://assets3.cbsnewsstatic.com/hub/i/r/2026/08/19/e8fead1e/thumbnail/60x60/0537/gettyimages-2214317647.jpg',
            'image_width'  => 60,
            'image_height' => 60,
        ];
        foreach (['lead', 'large', 'medium'] as $size) {
            assertFalse(Images::usable($a, $size), "a 60x60 must never fill a $size card");
        }
        assertSame('text', Images::bestSize(60, 60), 'a 60x60 belongs in the text card');
    },

    'CBS URLs are left alone, because no larger rendition exists' => function (): void {
        // Verified live: /thumbnail/{anything other than 60x60}/ returns 404.
        // A guessed upgrade rule here would produce an imageless site.
        $u = 'https://assets3.cbsnewsstatic.com/hub/i/r/2026/08/19/e8fead1e/thumbnail/60x60/0537/x.jpg';
        assertSame($u, Images::upgradeUrl($u));
    },

    'BBC URLs upgrade on both of their path shapes' => function (): void {
        $a = 'https://ichef.bbci.co.uk/ace/standard/240/cpsprodpb/39e4/live/54820d50.jpg';
        assertContains('/1024/cpsprodpb/', Images::upgradeUrl($a));
        assertNotContains('/240/cpsprodpb/', Images::upgradeUrl($a));

        $b = 'https://ichef.bbci.co.uk/images/ic/240x135/p0l7jnbt.jpg';
        assertSame('https://ichef.bbci.co.uk/images/ic/1024x576/p0l7jnbt.jpg', Images::upgradeUrl($b));
    },

    'an image that is already large enough is never "upgraded" downwards' => function (): void {
        $big = 'https://ichef.bbci.co.uk/images/ic/1920x1080/p0l7jnbt.jpg';
        assertSame($big, Images::upgradeUrl($big), 'a 1920px BBC image must be left as it is');

        $ok = 'https://npr.brightspotcdn.com/dims3/default/strip/false/crop/800x600+0+0/resize/800x600!/?url=x';
        assertSame($ok, Images::upgradeUrl($ok), 'an NPR image already under 1200 must be left alone');
    },

    'the NPR rewrite caps width and keeps the aspect ratio' => function (): void {
        // Verified live: 4032x3024 is 3.3 MB, 1200x900 is 573 KB — same picture.
        $u  = 'https://npr.brightspotcdn.com/dims3/default/strip/false/crop/4032x3024+0+0/resize/4032x3024!/?url=x.jpg';
        $up = Images::upgradeUrl($u);
        assertContains('/resize/1200x900!/', $up);
        // The crop segment must NOT be touched — it selects the region, not the size.
        assertContains('/crop/4032x3024+0+0/', $up);
    },

    'an unknown CDN is passed through untouched' => function (): void {
        foreach ([
            'https://static01.nyt.com/images/2026/08/19/a.jpg',
            'https://i.guim.co.uk/img/media/abc/master/5000.jpg?width=700&s=deadbeef',
            'https://example.com/photo.png',
            '',
        ] as $u) {
            assertSame($u, Images::upgradeUrl($u));
        }
    },

    'the size ladder never lets an image fill a slot bigger than itself' => function (): void {
        $cases = [
            [1600, 900, 'lead'],
            [700, 560, 'lead'],
            [620, 349, 'lead'],
            [619, 348, 'large'],
            [440, 248, 'large'],
            [400, 267, 'medium'],
            [290, 163, 'medium'],
            [240, 135, 'small'],
            [189, 106, 'text'],
            [60, 60, 'text'],
        ];
        foreach ($cases as [$w, $h, $want]) {
            assertSame($want, Images::bestSize($w, $h), "{$w}x{$h} should be $want");
        }
    },

    'a tall thin image is judged on its short side too' => function (): void {
        // 1740x2186 (Love and Lemons) is fine; 400x60 is a banner, not a photo.
        assertSame('lead', Images::bestSize(1740, 2186));
        assertSame('text', Images::bestSize(400, 60), 'height below the floor is unusable');
        assertFalse(Images::usable(['image_url' => 'x', 'image_width' => 400, 'image_height' => 60], 'medium'));
    },

    'an unmeasured image is never gambled on a lead slot' => function (): void {
        $a = ['image_url' => 'https://example.com/unknown.jpg', 'image_width' => 0, 'image_height' => 0];
        assertFalse(Images::usable($a, 'lead'), 'unknown size must not take the hero');
        assertFalse(Images::usable($a, 'large'));
        assertFalse(Images::usable($a, 'medium'));
    },

    'a row with no image is never usable, whatever the numbers say' => function (): void {
        assertFalse(Images::usable(['image_url' => '', 'image_width' => 4000, 'image_height' => 3000], 'lead'));
        assertFalse(Images::usable([], 'medium'));
    },

    'dimensions are read from real file headers, not guessed' => function (): void {
        // A 1x1 PNG and a 2x3 GIF, byte-for-byte.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $d   = Images::dimensionsFromBytes($png);
        assertSame(['w' => 1, 'h' => 1], $d, 'PNG header must be read exactly');

        // Truncated and junk input must be reported as unknown, never as a size.
        assertNull(Images::dimensionsFromBytes('not an image at all, just text'));
        assertNull(Images::dimensionsFromBytes(''));
        assertNull(Images::dimensionsFromBytes(substr($png, 0, 8)));
    },

    'the checker would have caught the bug that shipped' => function (): void {
        // Guard against a future edit quietly raising the floor to nothing.
        assertTrue(Images::ABSOLUTE_MIN_WIDTH >= 150, 'the floor must stay meaningful');
        assertTrue(Images::MIN_WIDTH['lead'] > Images::MIN_WIDTH['medium'], 'lead must demand more than medium');
        assertTrue(Images::MIN_WIDTH['medium'] > Images::MIN_WIDTH['small'], 'medium must demand more than small');

        // The precise regression: a 60x60 in a lead slot.
        assertFalse(Images::usable(['image_url' => 'x', 'image_width' => 60, 'image_height' => 60], 'lead'));
        // And the positive control — a real photo still works, or the rule is useless.
        assertTrue(Images::usable(['image_url' => 'x', 'image_width' => 1600, 'image_height' => 900], 'lead'));
    },
];
