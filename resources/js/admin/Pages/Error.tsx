import { Head } from '@inertiajs/react';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Typography from '@mui/material/Typography';
import RouterLink from '../components/RouterLink';

const MESSAGES: Record<number, [string, string]> = {
    403: ['You cannot open this', 'Your role does not include this area. Ask an administrator if you need access.'],
    404: ['Page not found', 'This page does not exist or was removed.'],
    419: ['This page expired', 'Reload the page and try again.'],
    500: ['Something went wrong', 'The error was recorded. Please try again in a moment.'],
    503: ['Down for maintenance', 'Please check back shortly.'],
};

export default function ErrorPage({ status }: { status: number }) {
    const [title, text] = MESSAGES[status] ?? MESSAGES[500];

    return (
        <Box component="main" sx={{ minHeight: '100vh', display: 'grid', placeItems: 'center', p: 3, textAlign: 'center' }}>
            <Head title={title} />
            <Box sx={{ maxWidth: 420 }}>
                <Typography sx={{ color: 'text.secondary', fontWeight: 600, mb: 1 }}>Error {status}</Typography>
                <Typography variant="h1" sx={{ mb: 1.5 }}>{title}</Typography>
                <Typography sx={{ color: 'text.secondary', mb: 3 }}>{text}</Typography>
                <Button component={RouterLink} href="/admin" variant="contained">Back to dashboard</Button>
            </Box>
        </Box>
    );
}
