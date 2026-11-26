import { router } from '@inertiajs/react';
import ArrowBackOutlined from '@mui/icons-material/ArrowBackOutlined';
import ExpandMoreOutlined from '@mui/icons-material/ExpandMoreOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import Collapse from '@mui/material/Collapse';
import IconButton from '@mui/material/IconButton';
import MenuItem from '@mui/material/MenuItem';
import Pagination from '@mui/material/Pagination';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useState } from 'react';
import AdminLayout from '../../components/AdminLayout';
import ConfirmDialog from '../../components/ConfirmDialog';
import RouterLink from '../../components/RouterLink';
import { useCan } from '../../lib/can';
import { formatDateTime } from '../../lib/format';
import type { Paginated } from '../../lib/types';

interface Row {
    id: number;
    event: string;
    service: string;
    serviceName: string;
    ok: boolean;
    payload: unknown;
    response: string | null;
    at: string | null;
}
interface Props {
    events: Paginated<Row>;
    filters: { service: string; result: string };
    services: Record<string, string>;
    keep: number;
}

const code = { m: 0, p: 1.5, bgcolor: 'action.hover', borderRadius: 1.5, fontFamily: 'ui-monospace, Menlo, monospace', fontSize: 12.5, whiteSpace: 'pre-wrap', wordBreak: 'break-word', maxHeight: 320, overflow: 'auto' } as const;

export default function EventLog({ events, filters, services, keep }: Props) {
    const can = useCan();
    const [open, setOpen] = useState<number | null>(null);
    const [clearing, setClearing] = useState(false);
    const go = (params: Record<string, string | number>) => {
        const query: Record<string, string | number> = { ...filters, ...params };
        Object.keys(query).forEach((key) => query[key] === '' && delete query[key]);
        router.get('/admin/integrations/log', query, { preserveScroll: true, preserveState: true });
    };

    return (
        <AdminLayout title="Event log">
            <Box sx={{ display: 'flex', gap: 1.5, flexWrap: 'wrap', alignItems: 'center', mb: 2 }}>
                <Button component={RouterLink} href="/admin/integrations" startIcon={<ArrowBackOutlined />} color="inherit">Integrations</Button>
                <Box sx={{ flex: 1 }} />
                <TextField select fullWidth={false} size="small" label="Sent by" value={filters.service} onChange={(e) => go({ service: e.target.value, page: 1 })} sx={{ minWidth: 200 }}>
                    <MenuItem value="">Everything</MenuItem>
                    {Object.entries(services).map(([key, name]) => <MenuItem key={key} value={key}>{name}</MenuItem>)}
                </TextField>
                <TextField select fullWidth={false} size="small" label="Result" value={filters.result} onChange={(e) => go({ result: e.target.value, page: 1 })} sx={{ minWidth: 160 }}>
                    <MenuItem value="">All</MenuItem>
                    <MenuItem value="failed">Failed only</MenuItem>
                </TextField>
                {can('integrations', 'delete') && events.total > 0 && <Button color="inherit" onClick={() => setClearing(true)}>Clear the log</Button>}
            </Box>
            <Typography sx={{ color: 'text.secondary', fontSize: 14, mb: 2 }}>
                The newest {keep} events are kept. "Browser" lines are events a visitor's browser sent to its tags. The other lines are events this server sent, with the service's answer.
            </Typography>

            <Card>
                {events.data.length === 0 ? (
                    <Box sx={{ p: 5, textAlign: 'center' }}>
                        <Typography variant="h4" component="h2">No events yet</Typography>
                        <Typography sx={{ color: 'text.secondary', mt: 0.5 }}>Switch a service on and open the store. Events appear here within a few seconds.</Typography>
                    </Box>
                ) : events.data.map((row, index) => (
                    <Box key={row.id} sx={{ borderTop: index === 0 ? 0 : 1, borderColor: 'divider' }}>
                        <Box sx={{ display: 'flex', gap: 1.5, alignItems: 'center', flexWrap: 'wrap', px: 2.5, py: 1.25 }}>
                            <Typography sx={{ fontFamily: 'ui-monospace, Menlo, monospace', fontSize: 14, fontWeight: 600, minWidth: 150 }}>{row.event}</Typography>
                            <Chip size="small" variant="outlined" label={row.serviceName} />
                            <Chip size="small" color={row.ok ? 'success' : 'error'} label={row.ok ? 'OK' : 'Failed'} />
                            <Typography sx={{ fontSize: 13, color: 'text.secondary', ml: 'auto' }}>{row.at ? formatDateTime(row.at) : ''}</Typography>
                            <IconButton size="small" aria-label={`${open === row.id ? 'Hide' : 'Show'} the details of ${row.event}`} aria-expanded={open === row.id} onClick={() => setOpen(open === row.id ? null : row.id)}>
                                <ExpandMoreOutlined sx={{ transform: open === row.id ? 'rotate(180deg)' : 'none', transition: 'transform 0.15s' }} />
                            </IconButton>
                        </Box>
                        <Collapse in={open === row.id} unmountOnExit>
                            <Box sx={{ px: 2.5, pb: 2, display: 'grid', gap: 1.5, gridTemplateColumns: { xs: 'minmax(0, 1fr)', md: 'minmax(0, 1fr) minmax(0, 1fr)' } }}>
                                <Box>
                                    <Typography sx={{ fontSize: 13, fontWeight: 600, mb: 0.5 }}>What was sent</Typography>
                                    <Box component="pre" sx={code}>{JSON.stringify(row.payload, null, 2)}</Box>
                                </Box>
                                <Box>
                                    <Typography sx={{ fontSize: 13, fontWeight: 600, mb: 0.5 }}>The answer</Typography>
                                    <Box component="pre" sx={code}>{row.response || 'No answer.'}</Box>
                                </Box>
                            </Box>
                        </Collapse>
                    </Box>
                ))}
            </Card>
            {events.last_page > 1 && <Box sx={{ display: 'flex', justifyContent: 'center', mt: 3 }}><Pagination count={events.last_page} page={events.current_page} color="primary" onChange={(_, page) => go({ page })} /></Box>}

            <ConfirmDialog open={clearing} danger title="Clear the event log?" text="Every line is removed. New events are added as they happen." confirmLabel="Clear" onClose={() => setClearing(false)}
                onConfirm={() => router.delete('/admin/integrations/log', { preserveScroll: true, onFinish: () => setClearing(false) })} />
        </AdminLayout>
    );
}
