import { Head, usePage } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Paper from '@mui/material/Paper';
import Typography from '@mui/material/Typography';
import type { ReactNode } from 'react';
import type { SharedProps } from '../lib/types';

export default function AuthLayout({ title, intro, children }: { title: string; intro?: string; children: ReactNode }) {
    const { store, flash } = usePage<SharedProps>().props;

    return (
        <Box component="main" sx={{ minHeight: '100vh', display: 'grid', placeItems: 'center', p: 2 }}>
            <Head title={title} />
            <Box sx={{ width: '100%', maxWidth: 440 }}>
                <Typography variant="h4" component="p" sx={{ textAlign: 'center', mb: 3 }}>
                    {store.name}
                </Typography>
                <Paper sx={{ p: { xs: 3, sm: 4 }, border: 1, borderColor: 'divider', borderRadius: 5 }}>
                    <Typography variant="h2" component="h1" sx={{ mb: intro ? 1 : 3 }}>
                        {title}
                    </Typography>
                    {intro && <Typography sx={{ color: 'text.secondary', mb: 3 }}>{intro}</Typography>}
                    {flash.success && <Alert severity="success" sx={{ mb: 2 }}>{flash.success}</Alert>}
                    {flash.error && <Alert severity="error" sx={{ mb: 2 }}>{flash.error}</Alert>}
                    {children}
                </Paper>
            </Box>
        </Box>
    );
}
