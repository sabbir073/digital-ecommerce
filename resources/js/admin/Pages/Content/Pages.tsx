import { router, useForm } from '@inertiajs/react';
import Add from '@mui/icons-material/Add';
import DashboardCustomizeOutlined from '@mui/icons-material/DashboardCustomizeOutlined';
import DeleteOutlined from '@mui/icons-material/DeleteOutlined';
import EditOutlined from '@mui/icons-material/EditOutlined';
import OpenInNew from '@mui/icons-material/OpenInNew';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import IconButton from '@mui/material/IconButton';
import InputAdornment from '@mui/material/InputAdornment';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import TravelExploreOutlined from '@mui/icons-material/TravelExploreOutlined';
import Typography from '@mui/material/Typography';
import { type FormEvent, useState } from 'react';
import AdminLayout from '../../components/AdminLayout';
import ConfirmDialog from '../../components/ConfirmDialog';
import RouterLink from '../../components/RouterLink';
import { useCan } from '../../lib/can';
import { formatDateTime } from '../../lib/format';

interface PageRow {
    id: number;
    title: string;
    slug: string;
    status: 'draft' | 'published';
    url: string;
    updatedAt: string | null;
    hasUnpublishedChanges: boolean;
}

function Details({ page, onClose }: { page: PageRow | null; onClose: () => void }) {
    const form = useForm({ title: page?.title ?? '', slug: page?.slug ?? '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        if (page) form.put(`/admin/pages/${page.id}`, { preserveScroll: true, onSuccess: onClose });
        else form.post('/admin/pages');
    }

    return (
        <Dialog open onClose={onClose} maxWidth="xs" fullWidth>
            <form onSubmit={submit}>
                <DialogTitle>{page ? 'Page name and address' : 'New page'}</DialogTitle>
                <DialogContent>
                    <Stack sx={{ gap: 2.5, pt: 1 }}>
                        <TextField label="Title" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} error={!!form.errors.title} helperText={form.errors.title ?? (page ? undefined : 'For example About us, Contact or Privacy policy.')} autoFocus slotProps={{ htmlInput: { maxLength: 120 } }} />
                        {page && (
                            <TextField
                                label="Address" value={form.data.slug} onChange={(e) => form.setData('slug', e.target.value)} error={!!form.errors.slug}
                                helperText={form.errors.slug ?? 'Changing the address breaks links people have saved.'}
                                slotProps={{ input: { startAdornment: <InputAdornment position="start">/pages/</InputAdornment> }, htmlInput: { maxLength: 120 } }}
                            />
                        )}
                    </Stack>
                </DialogContent>
                <DialogActions sx={{ px: 3, pb: 3 }}>
                    <Button variant="outlined" onClick={onClose}>Cancel</Button>
                    <Button type="submit" variant="contained" loading={form.processing}>{page ? 'Save' : 'Create and open builder'}</Button>
                </DialogActions>
            </form>
        </Dialog>
    );
}

export default function ContentPages({ pages }: { pages: PageRow[] }) {
    const can = useCan();
    const [editing, setEditing] = useState<PageRow | 'new' | null>(null);
    const [deleting, setDeleting] = useState<PageRow | null>(null);

    return (
        <AdminLayout title="Pages" actions={can('content', 'create') ? <Button variant="contained" startIcon={<Add />} onClick={() => setEditing('new')}>New page</Button> : undefined}>
            {pages.length === 0 ? (
                <Card sx={{ p: 6, textAlign: 'center' }}>
                    <Typography variant="h4" component="p" sx={{ mb: 0.5 }}>No pages yet</Typography>
                    <Typography sx={{ color: 'text.secondary' }}>Create pages such as About, Contact, Returns, Privacy and Terms, then link them from the menus under Header and footer.</Typography>
                </Card>
            ) : (
                <Card>
                    <TableContainer>
                        <Table>
                            <TableHead>
                                <TableRow>
                                    <TableCell>Page</TableCell>
                                    <TableCell>Status</TableCell>
                                    <TableCell>Last change</TableCell>
                                    <TableCell align="right">Actions</TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {pages.map((page) => (
                                    <TableRow key={page.id} hover>
                                        <TableCell>
                                            <Typography component={RouterLink} href={`/admin/pages/${page.id}/builder`} sx={{ fontWeight: 600, color: 'text.primary', textDecoration: 'none', '&:hover': { textDecoration: 'underline' } }}>{page.title}</Typography>
                                            <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>/pages/{page.slug}</Typography>
                                        </TableCell>
                                        <TableCell>
                                            <Stack direction="row" sx={{ gap: 1, flexWrap: 'wrap' }}>
                                                <Chip size="small" variant="outlined" color={page.status === 'published' ? 'success' : 'default'} label={page.status === 'published' ? 'Published' : 'Draft'} />
                                                {page.hasUnpublishedChanges && <Chip size="small" variant="outlined" color="warning" label="Changes not live" />}
                                            </Stack>
                                        </TableCell>
                                        <TableCell sx={{ whiteSpace: 'nowrap' }}>{formatDateTime(page.updatedAt)}</TableCell>
                                        <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
                                            <Tooltip title="Edit content"><IconButton aria-label={`Edit content of ${page.title}`} component={RouterLink} href={`/admin/pages/${page.id}/builder`}><DashboardCustomizeOutlined /></IconButton></Tooltip>
                                            {can('content', 'edit') && <Tooltip title="Name and address"><IconButton aria-label={`Name and address of ${page.title}`} onClick={() => setEditing(page)}><EditOutlined /></IconButton></Tooltip>}
                                            {can('seo') && <Tooltip title="SEO"><IconButton aria-label={`SEO of ${page.title}`} component={RouterLink} href={`/admin/seo/page/${page.id}`}><TravelExploreOutlined /></IconButton></Tooltip>}
                                            {page.status === 'published' && <Tooltip title="Open page"><IconButton aria-label={`Open ${page.title}`} component="a" href={page.url} target="_blank" rel="noopener"><OpenInNew /></IconButton></Tooltip>}
                                            {can('content', 'publish') && page.status === 'published' && <Button size="small" onClick={() => router.post(`/admin/pages/${page.id}/unpublish`, {}, { preserveScroll: true })}>Unpublish</Button>}
                                            {can('content', 'delete') && <Tooltip title="Delete"><IconButton aria-label={`Delete ${page.title}`} onClick={() => setDeleting(page)}><DeleteOutlined /></IconButton></Tooltip>}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </TableContainer>
                </Card>
            )}

            {editing && <Details key={editing === 'new' ? 'new' : editing.id} page={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
            <ConfirmDialog
                open={deleting !== null}
                title={`Delete ${deleting?.title ?? ''}?`}
                text="The page is removed for good and menu links to it disappear."
                confirmLabel="Delete"
                danger
                onClose={() => setDeleting(null)}
                onConfirm={() => deleting && router.delete(`/admin/pages/${deleting.id}`, { preserveScroll: true, onFinish: () => setDeleting(null) })}
            />
        </AdminLayout>
    );
}
