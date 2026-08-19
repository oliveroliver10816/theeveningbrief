<?php

declare(strict_types=1);

namespace TEB;

/**
 * A newspaper-styled placeholder for stories whose feed carries no picture.
 *
 * Washington Post, Al Jazeera, Deutsche Welle and the National Weather Service
 * all publish without images, and CBS's are too small to use — so a real share
 * of the grid had no photograph. A designed masthead card keeps the page
 * looking like a newspaper instead of leaving holes in it.
 *
 * Drawn as SVG on our own domain: no download, no hotlink, scales to any card,
 * and it is cached hard because the same section always draws the same card.
 */
final class Placeholder
{
    /** Ink/paper pairs per section, all taken from the design tokens. */
    private const PALETTE = [
        'us'            => ['#12233d', '#EDE9DE'],
        'international' => ['#0E5A54', '#EDE9DE'],
        'world'         => ['#3A3730', '#EDE9DE'],
        'politics'      => ['#12233d', '#EDE9DE'],
        'business'      => ['#1E4636', '#EDE9DE'],
        'technology'    => ['#2A3550', '#EDE9DE'],
        'science'       => ['#1E4636', '#EDE9DE'],
        'health'        => ['#5A2733', '#EDE9DE'],
        'sports'        => ['#B0271E', '#EDE9DE'],
        'entertainment' => ['#4A2B52', '#EDE9DE'],
        'weather'       => ['#164A63', '#EDE9DE'],
        'recipes'       => ['#7A4318', '#EDE9DE'],
        'crime'         => ['#3A3730', '#EDE9DE'],
        'religion'      => ['#3F3663', '#EDE9DE'],
    ];

    public static function isPlaceholder(string $url): bool
    {
        return strpos($url, 'placeholder.svg') !== false;
    }

    /** Route + query for a story's placeholder. */
    public static function url(array $a): string
    {
        $section = strtolower((string) ($a['section'] ?? ''));
        // Deterministic per story, so the same card always draws the same way
        // and the browser cache is never churned.
        $seed = abs(crc32((string) ($a['id'] ?? ($a['title'] ?? '')))) % 6;

        return Paths::url('/placeholder.svg') . (strpos(Paths::url('/placeholder.svg'), '?') === false ? '?' : '&')
            . 's=' . rawurlencode($section) . '&v=' . $seed;
    }

    /** The SVG itself. 1200x630 so it drops into any card box cleanly. */
    public static function svg(string $section, int $variant, array $cfg): string
    {
        [$ink, $paper] = self::PALETTE[strtolower($section)] ?? ['#12233d', '#EDE9DE'];
        $variant = max(0, min(5, $variant));

        // The brand lives in config and nowhere else, including here.
        $name  = (string) ($cfg['site']['name'] ?? Config::get('site.name', ''));
        $label = strtoupper($section !== '' ? $section : (string) ($cfg['site']['short_name'] ?? ''));
        $e     = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');

        // Column rules, offset per variant so a grid of placeholders is not a
        // grid of identical tiles.
        $rules = '';
        $cols  = 3 + ($variant % 3);
        for ($i = 1; $i < $cols; $i++) {
            $x = (int) round(1200 * ($i / $cols));
            $rules .= '<line x1="' . $x . '" y1="300" x2="' . $x . '" y2="600" stroke="' . $ink . '" stroke-opacity=".16" stroke-width="2"/>';
        }

        // Text lines suggesting columns of type, varied by seed.
        $lines = '';
        for ($c = 0; $c < $cols; $c++) {
            $x0 = (int) round(1200 * ($c / $cols)) + 34;
            $w  = (int) round(1200 / $cols) - 68;
            for ($r = 0; $r < 7; $r++) {
                $y  = 330 + $r * 34;
                $ww = $r === 6 ? (int) round($w * (0.45 + 0.12 * (($variant + $c + $r) % 4))) : $w;
                $lines .= '<rect x="' . $x0 . '" y="' . $y . '" width="' . max(20, $ww) . '" height="10" rx="2" fill="' . $ink . '" fill-opacity=".13"/>';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 630" width="1200" height="630" role="img" aria-label="' . $e($name) . '">'
            . '<rect width="1200" height="630" fill="' . $paper . '"/>'
            . '<rect x="0" y="0" width="1200" height="8" fill="' . $ink . '"/>'
            . '<text x="600" y="150" text-anchor="middle" font-family="Georgia,&quot;Times New Roman&quot;,serif" font-size="76" font-weight="700" fill="' . $ink . '" letter-spacing="1">' . $e($name) . '</text>'
            . '<line x1="80" y1="196" x2="1120" y2="196" stroke="' . $ink . '" stroke-width="3"/>'
            . '<line x1="80" y1="206" x2="1120" y2="206" stroke="' . $ink . '" stroke-width="1"/>'
            . ($label !== ''
                ? '<text x="600" y="262" text-anchor="middle" font-family="Helvetica,Arial,sans-serif" font-size="30" font-weight="600" letter-spacing="8" fill="' . $ink . '" fill-opacity=".72">' . $e($label) . '</text>'
                : '')
            . $rules . $lines
            . '<line x1="80" y1="596" x2="1120" y2="596" stroke="' . $ink . '" stroke-width="3"/>'
            . '</svg>';
    }
}
