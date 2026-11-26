import '@fontsource/inter/400.css';
import '@fontsource/inter/500.css';
import '@fontsource/inter/600.css';
import '@fontsource/space-grotesk/500.css';
import '@fontsource/space-grotesk/600.css';
import '@fontsource/space-grotesk/700.css';

import { createInertiaApp } from '@inertiajs/react';
import CssBaseline from '@mui/material/CssBaseline';
import { ThemeProvider } from '@mui/material/styles';
import type { ComponentType } from 'react';
import { createRoot } from 'react-dom/client';
import { theme } from './theme';

type PageModule = { default: ComponentType };

createInertiaApp({
    title: (title) => (title ? `${title} · Admin` : 'Admin'),
    resolve: async (name) => {
        const pages = import.meta.glob<PageModule>('./Pages/**/*.tsx');
        const load = pages[`./Pages/${name}.tsx`];
        if (!load) throw new Error(`Unknown admin page: ${name}`);
        return (await load()).default;
    },
    setup({ el, App, props }) {
        if (!el) return;
        createRoot(el).render(
            <ThemeProvider theme={theme}>
                <CssBaseline />
                <App {...props} />
            </ThemeProvider>,
        );
    },
    progress: { color: '#1D4ED8' },
});
