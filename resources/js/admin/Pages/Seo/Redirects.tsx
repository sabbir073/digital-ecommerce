import { router, useForm } from '@inertiajs/react';
import AddOutlined from '@mui/icons-material/AddOutlined';
import ArrowBackOutlined from '@mui/icons-material/ArrowBackOutlined';
import ArrowForwardOutlined from '@mui/icons-material/ArrowForwardOutlined';
import DeleteOutlined from '@mui/icons-material/DeleteOutlined';
import EditOutlined from '@mui/icons-material/EditOutlined';
import SearchOutlined from '@mui/icons-material/SearchOutlined';
import UploadFileOutlined from '@mui/icons-material/UploadFileOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import IconButton from '@mui/material/IconButton';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import Pagination from '@mui/material/Pagination';
import Stack from '@mui/material/Stack';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import AdminLayout from '../../components/AdminLayout';
import ConfirmDialog from '../../components/ConfirmDialog';
import RouterLink from '../../components/RouterLink';
import { useCan } from '../../lib/can';
import type { Paginated } from '../../lib/types';

interface RedirectRow {
    id: number;
    from: string;
    to: string;
    type: number;
    hits: number;
    createdAt: string | null;
}
interface MissingRow {
    id: number;
    path: string;
    hits: number;
    lastSeen: string | null;
}
interface Props {
    redirects: Paginated<RedirectRow>;
    notFound: Paginated<MissingRow>;
    search: string;
    tab: 'redirects' | '404';
    importMax: number;
    importProblems: string[];
}
type Editing = { id: number | null; from_path: string; to_path: string; type: number };

const row = { display: 'flex', gap: 2, alignItems: 'center', flexWrap: 'wrap', px: 2.5, py: 1.75, borderTop: 1, borderColor: 'divider' } as const;
const path = { fontFamily: 'ui-monospace, Menlo, monospace', fontSize: 14, wordBreak: 'break-all' } as const;

export default function Redirects({ redirects, notFound, search, tab, importMax, importProblems }: Props) {
    const can = useCan();
    const [query, setQuery] = useState(search);
    const [open, setOpen] = useState(false);
    const [importing, setImporting] = useState(false);
    const [removing, setRemoving] = useState<RedirectRow | null>(null);
    const [clearing, setClearing] = useState(false);
    const [busy, setBusy] = useState(false);
    const file = useRef<HTMLInputElement>(null);
    const form = useForm<Editing>({ id: null, from_path: '', to_path: '', type: 301 });

    const go = (params: Record<string, string | number>) => router.get('/admin/seo/redirects', { ...(search ? { q: search } : {}), ...(tab === '404' ? { tab } : {}), ...params }, { preserveScroll: true, preserveState: true });
    const edit = (values: Editing) => {
        form.setData(values);
        form.clearErrors();
        setOpen(true);
    };
    const save = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (form.data.id) form.put(`/admin/seo/redirects/${form.data.id}`, options);
        else form.post('/admin/seo/redirects', options);
    };
    const upload = (chosen: File | undefined) => {
        if (!chosen) return;
        setImporting(true);
        router.post('/admin/seo/redirects/import', { file: chosen }, { forceFormData: true, preserveScroll: true, onFinish: () => { setImporting(false); if (file.current) file.current.value = ''; } });
    };

    return (
        <AdminLayout title="Redirects and 404s">
            <Box sx={{ display: 'flex', gap: 1.5, flexWrap: 'wrap', mb: 2 }}>
                <Button component={RouterLink} href="/admin/seo" startIcon={<ArrowBackOutlined />} color="inherit">SEO dashboard</Button>
            </Box>

            <Tabs value={tab} onChange={(_, value: string) => router.get('/admin/seo/redirects', value === '404' ? { tab: '404' } : {}, { preserveScroll: true })} sx={{ mb: 3, borderBottom: 1, borderColor: 'divider' }}>
                <Tab value="redirects" label={`Redirects (${redirects.total})`} />
                <Tab value="404" label={`Not found log (${notFound.total})`} />
            </Tabs>

            {importProblems.length > 0 && (
                <Alert severity="warning" sx={{ mb: 3 }}>
                    <Typography sx={{ fontWeight: 600, mb: 0.5 }}>Some lines of the file were not imported</Typography>
                    {importProblems.map((problem) => <Typography key={problem} sx={{ fontSize: 14 }}>{problem}</Typography>)}
                </Alert>
            )}

            {tab === 'redirects' ? (
                <>
                    <Box sx={{ display: 'flex', gap: 1.5, flexWrap: 'wrap', alignItems: 'center', mb: 2 }}>
                        <Box component="form" onSubmit={(e: FormEvent) => { e.preventDefault(); router.get('/admin/seo/redirects', query.trim() ? { q: query.trim() } : {}, { preserveScroll: true, preserveState: true }); }} sx={{ flex: '1 1 260px', maxWidth: 420 }}>
                            <TextField size="small" fullWidth placeholder="Search addresses" value={query} onChange={(e) => setQuery(e.target.value)} slotProps={{ htmlInput: { 'aria-label': 'Search redirects' }, input: { startAdornment: <InputAdornment position="start"><SearchOutlined fontSize="small" /></InputAdornment> } }} />
                        </Box>
                        <Box sx={{ flex: 1 }} />
                        {can('seo', 'create') && (
                            <>
                                <input ref={file} type="file" accept=".csv,text/csv" hidden onChange={(e) => upload(e.target.files?.[0])} />
                                <Button variant="outlined" startIcon={<UploadFileOutlined />} loading={importing} onClick={() => file.current?.click()}>Import CSV</Button>
                                <Button variant="contained" startIcon={<AddOutlined />} onClick={() => edit({ id: null, from_path: '', to_path: '', type: 301 })}>New redirect</Button>
                            </>
                        )}
                    </Box>
                    <Typography sx={{ fontSize: 13, color: 'text.secondary', mb: 2 }}>
                        A CSV file has one redirect per line: the old address, the new address, and 301 or 302 (301 when left out). Up to {importMax} lines at once.
                    </Typography>

                    <Card>
                        {redirects.data.length === 0 ? (
                            <Box sx={{ p: 5, textAlign: 'center' }}>
                                <Typography variant="h4" component="h2">{search ? 'No redirect matches your search' : 'No redirects yet'}</Typography>
                                <Typography sx={{ color: 'text.secondary', mt: 0.5 }}>{search ? 'Try a shorter part of the address.' : 'Changing the address of a published page adds one here automatically.'}</Typography>
                            </Box>
                        ) : redirects.data.map((redirect, index) => (
                            <Box key={redirect.id} sx={{ ...row, borderTop: index === 0 ? 0 : 1 }}>
                                <Box sx={{ flex: '1 1 320px', minWidth: 0, display: 'flex', gap: 1, alignItems: 'center', flexWrap: 'wrap' }}>
                                    <Typography sx={path}>{redirect.from}</Typography>
                                    <ArrowForwardOutlined sx={{ fontSize: 16, color: 'text.secondary' }} />
                                    <Typography sx={path}>{redirect.to}</Typography>
                                </Box>
                                <Chip size="small" label={redirect.type === 301 ? '301 permanent' : '302 temporary'} variant={redirect.type === 301 ? 'filled' : 'outlined'} />
                                <Typography sx={{ fontSize: 13, color: 'text.secondary', minWidth: 72, textAlign: 'right' }}>{redirect.hits} {redirect.hits === 1 ? 'visit' : 'visits'}</Typography>
                                <Box sx={{ display: 'flex' }}>
                                    {can('seo', 'edit') && <IconButton aria-label={`Edit the redirect from ${redirect.from}`} onClick={() => edit({ id: redirect.id, from_path: redirect.from, to_path: redirect.to, type: redirect.type })}><EditOutlined /></IconButton>}
                                    {can('seo', 'delete') && <IconButton aria-label={`Delete the redirect from ${redirect.from}`} onClick={() => setRemoving(redirect)}><DeleteOutlined /></IconButton>}
                                </Box>
                            </Box>
                        ))}
                    </Card>
                    {redirects.last_page > 1 && <Box sx={{ display: 'flex', justifyContent: 'center', mt: 3 }}><Pagination count={redirects.last_page} page={redirects.current_page} color="primary" onChange={(_, page) => go({ page })} /></Box>}
                </>
            ) : (
                <>
                    <Box sx={{ display: 'flex', gap: 1.5, flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', mb: 2 }}>
                        <Typography sx={{ color: 'text.secondary', maxWidth: 640 }}>Addresses that visitors asked for and that do not exist, most asked first. Redirect the ones that should lead somewhere.</Typography>
                        {can('seo', 'delete') && notFound.total > 0 && <Button color="inherit" onClick={() => setClearing(true)}>Clear the log</Button>}
                    </Box>
                    <Card>
                        {notFound.data.length === 0 ? (
                            <Box sx={{ p: 5, textAlign: 'center' }}>
                                <Typography variant="h4" component="h2">No missing pages</Typography>
                                <Typography sx={{ color: 'text.secondary', mt: 0.5 }}>Nobody has asked for an address that does not exist.</Typography>
                            </Box>
                        ) : notFound.data.map((missing, index) => (
                            <Box key={missing.id} sx={{ ...row, borderTop: index === 0 ? 0 : 1 }}>
                                <Typography sx={{ ...path, flex: '1 1 280px', minWidth: 0 }}>{missing.path}</Typography>
                                <Typography sx={{ fontSize: 13, color: 'text.secondary' }}>{missing.hits} {missing.hits === 1 ? 'time' : 'times'}{missing.lastSeen ? `, last ${missing.lastSeen}` : ''}</Typography>
                                <Stack direction="row" spacing={0.5} sx={{ alignItems: 'center' }}>
                                    {can('seo', 'create') && <Button size="small" variant="outlined" onClick={() => edit({ id: null, from_path: missing.path, to_path: '', type: 301 })}>Create redirect</Button>}
                                    {can('seo', 'delete') && <IconButton aria-label={`Remove ${missing.path} from the log`} onClick={() => router.delete(`/admin/seo/not-found/${missing.id}`, { preserveScroll: true })}><DeleteOutlined /></IconButton>}
                                </Stack>
                            </Box>
                        ))}
                    </Card>
                    {notFound.last_page > 1 && <Box sx={{ display: 'flex', justifyContent: 'center', mt: 3 }}><Pagination count={notFound.last_page} page={notFound.current_page} color="primary" onChange={(_, page) => go({ missing: page })} /></Box>}
                </>
            )}

            <Dialog open={open} onClose={() => setOpen(false)} maxWidth="sm" fullWidth>
                <Box component="form" onSubmit={save} noValidate>
                    <DialogTitle>{form.data.id ? 'Edit redirect' : 'New redirect'}</DialogTitle>
                    <DialogContent>
                        <Stack spacing={2.5} sx={{ pt: 1 }}>
                            <TextField label="Old address" required autoFocus={!form.data.from_path} value={form.data.from_path} onChange={(e) => form.setData('from_path', e.target.value)} error={!!form.errors.from_path} helperText={form.errors.from_path ?? 'The part after the site name, for example /old-page.'} slotProps={{ htmlInput: { maxLength: 500, spellCheck: false } }} />
                            <TextField label="New address" required autoFocus={!!form.data.from_path} value={form.data.to_path} onChange={(e) => form.setData('to_path', e.target.value)} error={!!form.errors.to_path} helperText={form.errors.to_path ?? 'An address on this site, like /new-page, or a full address on another site.'} slotProps={{ htmlInput: { maxLength: 500, spellCheck: false } }} />
                            <TextField select label="Type" value={form.data.type} onChange={(e) => form.setData('type', Number(e.target.value))} error={!!form.errors.type} helperText={form.errors.type ?? 'Permanent tells search engines to forget the old address. Use temporary when the old address will come back.'}>
                                <MenuItem value={301}>301, permanent</MenuItem>
                                <MenuItem value={302}>302, temporary</MenuItem>
                            </TextField>
                        </Stack>
                    </DialogContent>
                    <DialogActions sx={{ px: 3, pb: 2.5 }}>
                        <Button color="inherit" onClick={() => setOpen(false)}>Cancel</Button>
                        <Button type="submit" variant="contained" loading={form.processing}>{form.data.id ? 'Save redirect' : 'Create redirect'}</Button>
                    </DialogActions>
                </Box>
            </Dialog>

            <ConfirmDialog open={!!removing} danger busy={busy} title="Delete this redirect?" text={removing ? `${removing.from} will stop leading to ${removing.to}.` : ''} confirmLabel="Delete" onClose={() => setRemoving(null)}
                onConfirm={() => removing && router.delete(`/admin/seo/redirects/${removing.id}`, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => { setBusy(false); setRemoving(null); } })} />
            <ConfirmDialog open={clearing} danger busy={busy} title="Clear the not found log?" text="Every address in the log is removed. New ones are added as visitors run into them." confirmLabel="Clear" onClose={() => setClearing(false)}
                onConfirm={() => router.delete('/admin/seo/not-found', { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => { setBusy(false); setClearing(false); } })} />
        </AdminLayout>
    );
}
