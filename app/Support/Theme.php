<?php

namespace App\Support;

/**
 * The parts of the storefront's look the owner can change: accent colour, fonts, grid density.
 * Everything else is the fixed layout from PRD section 8.
 */
class Theme
{
    public const DEFAULT_ACCENT = '#1D4ED8';

    /** Accent colours tested to keep white text on them, and them on white, above 4.5 to 1. */
    public const ACCENTS = [
        '#1D4ED8' => 'Blue',
        '#4338CA' => 'Indigo',
        '#7C3AED' => 'Violet',
        '#BE185D' => 'Pink',
        '#B91C1C' => 'Red',
        '#B45309' => 'Amber',
        '#15803D' => 'Green',
        '#0F766E' => 'Teal',
        '#111317' => 'Black',
    ];

    /** Self-hosted fonts. The file is null for the two that are always loaded. */
    public const FONTS = [
        'space-grotesk' => ['name' => 'Space Grotesk', 'stack' => "'Space Grotesk', ui-sans-serif, system-ui, sans-serif", 'file' => null],
        'inter' => ['name' => 'Inter', 'stack' => "'Inter', ui-sans-serif, system-ui, sans-serif", 'file' => null],
        'manrope' => ['name' => 'Manrope', 'stack' => "'Manrope', ui-sans-serif, system-ui, sans-serif", 'file' => 'manrope'],
        'dm-sans' => ['name' => 'DM Sans', 'stack' => "'DM Sans', ui-sans-serif, system-ui, sans-serif", 'file' => 'dm-sans'],
        'lora' => ['name' => 'Lora', 'stack' => "'Lora', ui-serif, Georgia, serif", 'file' => 'lora'],
        'playfair-display' => ['name' => 'Playfair Display', 'stack' => "'Playfair Display', ui-serif, Georgia, serif", 'file' => 'playfair-display'],
    ];

    public const DENSITIES = ['spacious' => 'Spacious (3 in a row)', 'standard' => 'Standard (4 in a row)', 'compact' => 'Compact (5 in a row)'];

    public const SOCIALS = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'x' => 'X', 'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn', 'pinterest' => 'Pinterest'];

    /** "#1d4ed8" or "1D4ED8" to "#1D4ED8"; null when it is not a colour. */
    public static function hex(mixed $value): ?string
    {
        $value = strtoupper(ltrim(trim((string) $value), '#'));
        if (preg_match('/^[0-9A-F]{3}$/', $value)) {
            $value = $value[0].$value[0].$value[1].$value[1].$value[2].$value[2];
        }

        return preg_match('/^[0-9A-F]{6}$/', $value) ? '#'.$value : null;
    }

    /** WCAG contrast ratio between two colours, from 1 to 21. */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** An accent works when white text on it, and it as text on white, both reach 4.5 to 1. */
    public static function accentPasses(string $hex): bool
    {
        return self::contrast($hex, '#FFFFFF') >= 4.5;
    }

    /**
     * The colours derived from the accent: a pale tint for chips and a text colour that stays readable on it.
     *
     * @return array{accent:string, tint:string, tintText:string}
     */
    public static function palette(string $accent): array
    {
        $tint = self::mix($accent, '#FFFFFF', 0.9);
        $text = $accent;
        for ($i = 0; $i < 12 && self::contrast($text, $tint) < 4.5; $i++) {
            $text = self::mix($text, '#000000', 0.12);
        }

        return ['accent' => $accent, 'tint' => $tint, 'tintText' => $text];
    }

    private static function mix(string $from, string $to, float $amount): string
    {
        [$r1, $g1, $b1] = self::rgb($from);
        [$r2, $g2, $b2] = self::rgb($to);
        $c = fn (int $x, int $y) => (int) round($x + ($y - $x) * $amount);

        return sprintf('#%02X%02X%02X', $c($r1, $r2), $c($g1, $g2), $c($b1, $b2));
    }

    /** @return array{0:int, 1:int, 2:int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private static function luminance(string $hex): float
    {
        $channel = function (int $value): float {
            $v = $value / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = self::rgb($hex);

        return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    }
}
