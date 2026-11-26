import { createTheme } from '@mui/material/styles';
import { tokens } from '../tokens';

const heading = { fontFamily: tokens.fontHeading, fontWeight: 600, letterSpacing: '-0.02em' };

/** Material UI themed with the PRD section 8 tokens, so admin matches the storefront. */
export const theme = createTheme({
    palette: {
        mode: 'light',
        primary: { main: tokens.accent, contrastText: '#FFFFFF' },
        secondary: { main: tokens.ink, contrastText: '#FFFFFF' },
        text: { primary: tokens.ink, secondary: tokens.muted },
        background: { default: tokens.softSurface, paper: tokens.surface },
        divider: tokens.border,
    },
    shape: { borderRadius: tokens.radiusInput },
    typography: {
        fontFamily: tokens.fontBody,
        fontSize: 14,
        body1: { fontSize: 15, lineHeight: 1.5 },
        body2: { fontSize: 14, lineHeight: 1.5 },
        caption: { fontSize: 13 },
        h1: { ...heading, fontSize: 32 },
        h2: { ...heading, fontSize: 28 },
        h3: { ...heading, fontSize: 22 },
        h4: { ...heading, fontSize: 18 },
        h5: { ...heading, fontSize: 16 },
        h6: { ...heading, fontSize: 15 },
        button: { fontWeight: 600, textTransform: 'none', fontSize: 15 },
    },
    components: {
        MuiButton: {
            defaultProps: { disableElevation: true },
            styleOverrides: {
                root: {
                    borderRadius: 999,
                    minHeight: 44,
                    paddingInline: 22,
                    transition: 'transform 160ms ease, background-color 160ms ease, box-shadow 160ms ease',
                    '&:hover': { transform: 'translateY(-1px)' },
                    '&:active': { transform: 'translateY(0) scale(0.98)' },
                    '@media (prefers-reduced-motion: reduce)': { transition: 'none', '&:hover, &:active': { transform: 'none' } },
                },
                sizeLarge: { minHeight: 52, paddingInline: 28 },
                outlined: { borderColor: tokens.fieldBorder, color: tokens.ink, backgroundColor: tokens.surface },
            },
        },
        MuiPaper: {
            defaultProps: { elevation: 0 },
            styleOverrides: { root: { backgroundImage: 'none' }, rounded: { borderRadius: tokens.radiusCard } },
        },
        MuiCard: { styleOverrides: { root: { border: `1px solid ${tokens.border}`, borderRadius: tokens.radiusCard } } },
        MuiDialog: { styleOverrides: { paper: { borderRadius: tokens.radiusPanel } } },
        MuiTextField: { defaultProps: { fullWidth: true, size: 'medium' } },
        MuiOutlinedInput: {
            styleOverrides: {
                root: { borderRadius: tokens.radiusInput, backgroundColor: tokens.surface },
                notchedOutline: { borderColor: tokens.fieldBorder },
            },
        },
        MuiChip: { styleOverrides: { root: { fontWeight: 500 } } },
        MuiTableCell: {
            styleOverrides: {
                root: { borderColor: tokens.border },
                head: { color: tokens.muted, fontSize: 13, fontWeight: 600, whiteSpace: 'nowrap' },
            },
        },
        MuiListItemButton: {
            styleOverrides: {
                root: {
                    borderRadius: 10,
                    minHeight: 44,
                    '&.Mui-selected': { backgroundColor: tokens.accentTint, color: tokens.accentTintText },
                    '&.Mui-selected:hover': { backgroundColor: tokens.accentTint },
                },
            },
        },
        MuiTooltip: { styleOverrides: { tooltip: { backgroundColor: tokens.ink, fontSize: 13 } } },
    },
});
