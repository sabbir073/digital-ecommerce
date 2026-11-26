import { router, useForm } from '@inertiajs/react';
import DeleteOutlined from '@mui/icons-material/DeleteOutlined';
import DownloadOutlined from '@mui/icons-material/DownloadOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import IconButton from '@mui/material/IconButton';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { useState } from 'react';
import type { FormEvent } from 'react';
import AdminLayout from '../../components/AdminLayout';
import ConfirmDialog from '../../components/ConfirmDialog';
import { formatBytes, formatDateTime } from '../../lib/format';

interface Backup {
    name: string;
    size: number;
    at: string;
}
interface Props {
    backups: Backup[];
    keep: number;
    last: { ok: boolean; at: string; error?: string } | null;
    database: string;
}

const mono = { fontFamily: 'ui-monospace, Menlo, monospace', fontSize: 14, wordBreak: 'break-all' } as const;

export default function Backups({ backups, keep, last, database }: Props) {
    const [making, setMaking] = useState(false);
    const [removing, setRemoving] = useState<Backup | null>(null);
    const form = useForm({ keep });
    const save = (event: FormEvent) => {
        event.preventDefault();
        form.put('/admin/backups', { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };

    return (
        <AdminLayout title="Backups" actions={<Button variant="contained" loading={making} onClick={() => router.post('/admin/backups', {}, { preserveScroll: true, onStart: () => setMaking(true), onFinish: () => setMaking(false) })}>Make a backup now</Button>}>
            <Stack spacing={3} sx={{ maxWidth: 860 }}>
                <Typography sx={{ color: 'text.secondary' }}>
                    A backup of the {database} database is made every night: products, orders, customers, pages and settings. Download one from time to time and keep it somewhere else, so a problem with the server does not take the backups with it.
                </Typography>
                {last && !last.ok && <Alert severity="error">The last backup, on {formatDateTime(last.at)}, failed: {last.error}</Alert>}
                <Alert severity="info">
                    Uploaded files (product downloads and images) are not inside these backups. Copy the folder <Box component="span" sx={mono}>storage/app</Box> with your hosting's file backup. Saved keys and passwords can only be read again with the same APP_KEY from the <Box component="span" sx={mono}>.env</Box> file, so keep a copy of that file too.
                </Alert>

                <Card>
                    {backups.length === 0 ? (
                        <Box sx={{ p: 5, textAlign: 'center' }}>
                            <Typography variant="h4" component="h2">No backups yet</Typography>
                            <Typography sx={{ color: 'text.secondary', mt: 0.5 }}>The first one is made tonight, or make one now.</Typography>
                        </Box>
                    ) : backups.map((backup, index) => (
                        <Box key={backup.name} sx={{ display: 'flex', gap: 1.5, alignItems: 'center', flexWrap: 'wrap', px: 2.5, py: 1.5, borderTop: index === 0 ? 0 : 1, borderColor: 'divider' }}>
                            <Box sx={{ flex: '1 1 220px', minWidth: 0 }}>
                                <Typography sx={{ fontWeight: 600 }}>{formatDateTime(backup.at)}</Typography>
                                <Typography sx={{ ...mono, fontSize: 13, color: 'text.secondary' }}>{backup.name} · {formatBytes(backup.size)}</Typography>
                            </Box>
                            <Button component="a" href={`/admin/backups/${backup.name}`} variant="outlined" size="small" startIcon={<DownloadOutlined />}>Download</Button>
                            <Tooltip title="Delete this backup"><IconButton aria-label={`Delete the backup of ${formatDateTime(backup.at)}`} onClick={() => setRemoving(backup)}><DeleteOutlined /></IconButton></Tooltip>
                        </Box>
                    ))}
                </Card>

                <Card component="form" onSubmit={save} noValidate sx={{ p: 3 }}>
                    <Typography variant="h4" component="h2" sx={{ mb: 2 }}>How many to keep</Typography>
                    <Box sx={{ display: 'flex', gap: 1.5, alignItems: 'flex-start', flexWrap: 'wrap' }}>
                        <TextField fullWidth={false} label="Backups kept" type="number" value={form.data.keep} onChange={(e) => form.setData('keep', Number(e.target.value))} error={!!form.errors.keep} helperText={form.errors.keep ?? 'Between 3 and 90. Older ones are deleted.'} slotProps={{ htmlInput: { min: 3, max: 90 } }} sx={{ width: 240 }} />
                        <Button type="submit" variant="contained" loading={form.processing} disabled={!form.isDirty} sx={{ mt: 1 }}>Save</Button>
                    </Box>
                </Card>

                <Card sx={{ p: 3 }}>
                    <Typography variant="h4" component="h2">Putting a backup back</Typography>
                    <Typography sx={{ color: 'text.secondary', mt: 0.5, mb: 1.5 }}>
                        Restoring replaces the whole database, so it is done on the server and not from this screen. Orders and changes made after the backup are lost.
                    </Typography>
                    <Typography sx={mono}>php artisan backup:restore {backups[0]?.name ?? 'backup-name.sql.gz'}</Typography>
                    <Typography sx={{ color: 'text.secondary', fontSize: 14, mt: 1.5 }}>Without command line access, unpack the file and import it with phpMyAdmin in your hosting panel.</Typography>
                </Card>
            </Stack>

            <ConfirmDialog open={!!removing} danger title="Delete this backup?" text={removing ? `The backup of ${formatDateTime(removing.at)} is removed from the server.` : ''} confirmLabel="Delete" onClose={() => setRemoving(null)}
                onConfirm={() => removing && router.delete(`/admin/backups/${removing.name}`, { preserveScroll: true, onFinish: () => setRemoving(null) })} />
        </AdminLayout>
    );
}
