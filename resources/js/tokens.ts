// PRD section 8 design tokens. resources/css/app.css holds the same values
// for Tailwind; tests/Unit/DesignTokensTest.php keeps the two files in step.
export const tokens = {
    ink: '#111317',
    muted: '#5B616E',
    accent: '#1D4ED8',
    accentTint: '#E8EEFF',
    accentTintText: '#1D3FA8',
    surface: '#FFFFFF',
    softSurface: '#F6F7F9',
    border: '#E6E8EC',
    fieldBorder: '#C9CDD4',
    fontHeading: '"Space Grotesk", system-ui, sans-serif',
    fontBody: '"Inter", system-ui, sans-serif',
    radiusCard: 16,
    radiusPanel: 20,
    radiusInput: 10,
} as const;
