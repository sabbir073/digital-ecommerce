<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The theme tokens must match PRD section 8 in both the storefront CSS and the admin theme. */
class DesignTokensTest extends TestCase
{
    public static function tokens(): array
    {
        return [
            'ink' => ['--color-ink', 'ink', '#111317'],
            'muted' => ['--color-muted', 'muted', '#5B616E'],
            'accent' => ['--color-accent', 'accent', '#1D4ED8'],
            'accent tint' => ['--color-accent-tint', 'accentTint', '#E8EEFF'],
            'badge text' => ['--color-accent-tint-text', 'accentTintText', '#1D3FA8'],
            'surface' => ['--color-surface', 'surface', '#FFFFFF'],
            'soft surface' => ['--color-soft', 'softSurface', '#F6F7F9'],
            'border' => ['--color-line', 'border', '#E6E8EC'],
            'field border' => ['--color-field', 'fieldBorder', '#C9CDD4'],
        ];
    }

    #[DataProvider('tokens')]
    public function test_colour_tokens_match_the_prd(string $cssVariable, string $tsKey, string $hex): void
    {
        $css = file_get_contents(__DIR__.'/../../resources/css/app.css');
        $ts = file_get_contents(__DIR__.'/../../resources/js/tokens.ts');

        $this->assertMatchesRegularExpression('/'.preg_quote($cssVariable, '/').':\s*'.$hex.';/i', $css);
        $this->assertMatchesRegularExpression('/\b'.$tsKey.":\s*'".$hex."'/i", $ts);
    }

    public function test_fonts_radii_and_widths_match_the_prd(): void
    {
        $css = file_get_contents(__DIR__.'/../../resources/css/app.css');

        $this->assertStringContainsString("--font-heading: 'Space Grotesk'", $css);
        $this->assertStringContainsString("--font-body: 'Inter'", $css);
        $this->assertStringContainsString('--radius-input: 10px', $css);
        $this->assertStringContainsString('--radius-card: 16px', $css);
        $this->assertStringContainsString('--container-store: 1200px', $css);
        $this->assertStringContainsString('--container-selling: 1120px', $css);
        $this->assertStringContainsString('--text-body: 15px', $css);
    }
}
