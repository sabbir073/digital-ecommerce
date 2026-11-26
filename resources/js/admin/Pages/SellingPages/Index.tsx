import { router, useForm } from '@inertiajs/react';
import Add from '@mui/icons-material/Add';
import ContentCopyOutlined from '@mui/icons-material/ContentCopyOutlined';
import DashboardCustomizeOutlined from '@mui/icons-material/DashboardCustomizeOutlined';
import DeleteOutlined from '@mui/icons-material/DeleteOutlined';
import EditOutlined from '@mui/icons-material/EditOutlined';
import OpenInNew from '@mui/icons-material/OpenInNew';
import Autocomplete from '@mui/material/Autocomplete';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import FormControl from '@mui/material/FormControl';
import FormControlLabel from '@mui/material/FormControlLabel';
import FormLabel from '@mui/material/FormLabel';
import IconButton from '@mui/material/IconButton';
import Radio from '@mui/material/Radio';
import RadioGroup from '@mui/material/RadioGroup';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { type FormEvent, useState } from 'react';
import AdminLayout from '../../components/AdminLayout';
import ConfirmDialog from '../../components/ConfirmDialog';
import RouterLink from '../../components/RouterLink';
import { useCan } from '../../lib/can';

interface PageRow {
    id: number;
    name: string;
    slug: string;
    status: 'draft' | 'published';
    product: string | null;
    views: number;
    orders: number;
    conversion: number | null;
    url: string;
    hasUnpublishedChanges: boolean;
}

export interface ProductOption {
    id: number;
    name: string;
    price: string;
    variants: { id: number; label: string }[];
}

export default function SellingPagesIndex({ pages, products }: { pages: PageRow[]; products: ProductOption[] }) {
    const can = useCan();
    const [creating, setCreating] = useState(false);
    const [deleting, setDeleting] = useState<PageRow | null>(null);
    const [busy, setBusy] = useState(false);
    const form = useForm<{ name: string; product_id: number | null; start: 'template' | 'blank' }>({ name: '', product_id: null, start: 'template' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/admin/selling-pages');
    };

    const openCreate = () => {
        form.reset();
        form.clearErrors();
        setCreating(true);
    };

    return (
        <AdminLayout
            title="Selling pages"
            actions={can('selling_pages', 'create') && <Button variant="contained" startIcon={<Add />} onClick={openCreate}>New page</Button>}
        >
            {pages.length === 0 ? (
                <Card sx={{ p: 6, textAlign: 'center' }}>
                    <Typography variant="h4" component="p" sx={{ mb: 0.5 }}>No selling pages yet</Typography>
                    <Typography sx={{ color: 'text.secondary', maxWidth: 520, mx: 'auto' }}>
                        A selling page presents one product on its own address, and the customer pays without leaving it.
                    </Typography>
                </Card>
            ) : (
                <Card>
                    <TableContainer>
                        <Table>
                            <TableHead>
                                <TableRow>
                                    <TableCell>Page</TableCell>
                                    <TableCell>Product</TableCell>
                                    <TableCell>Status</TableCell>
                                    <TableCell align="right">Views</TableCell>
                                    <TableCell align="right">Orders</TableCell>
                                    <TableCell align="right">Conversion</TableCell>
                                    <TableCell align="right"><span style={{ position: 'absolute', left: -9999 }}>Actions</span></TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {pages.map((page) => (
                                    <TableRow key={page.id} hover>
                                        <TableCell>
                                            <Typography component={RouterLink} href={`/admin/selling-pages/${page.id}`} sx={{ fontWeight: 600, color: 'text.primary', textDecoration: 'none', '&:hover': { textDecoration: 'underline' } }}>
                                                {page.name}
                                            </Typography>
                                            <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>/p/{page.slug}</Typography>
                                        </TableCell>
                                        <TableCell>{page.product ?? 'Product removed'}</TableCell>
                                        <TableCell>
                                            <Stack direction="row" spacing={1} useFlexGap sx={{ flexWrap: 'wrap' }}>
                                                <Chip size="small" variant="outlined" color={page.status === 'published' ? 'success' : 'default'} label={page.status === 'published' ? 'Published' : 'Draft'} />
                                                {page.hasUnpublishedChanges && <Chip size="small" variant="outlined" color="warning" label="Unpublished changes" />}
                                            </Stack>
                                        </TableCell>
                                        <TableCell align="right">{page.views.toLocaleString()}</TableCell>
                                        <TableCell align="right">{page.orders.toLocaleString()}</TableCell>
                                        <TableCell align="right">{page.conversion === null ? 'No views yet' : `${page.conversion}%`}</TableCell>
                                        <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
                                            {page.status === 'published' && (
                                                <Tooltip title="Open the live page"><IconButton aria-label={`Open ${page.name}`} component="a" href={page.url} target="_blank" rel="noopener"><OpenInNew fontSize="small" /></IconButton></Tooltip>
                                            )}
                                            <Tooltip title="Open builder"><IconButton aria-label={`Open the builder for ${page.name}`} component={RouterLink} href={`/admin/selling-pages/${page.id}/builder`}><DashboardCustomizeOutlined fontSize="small" /></IconButton></Tooltip>
                                            <Tooltip title="Settings and packs"><IconButton aria-label={`Edit settings of ${page.name}`} component={RouterLink} href={`/admin/selling-pages/${page.id}`}><EditOutlined fontSize="small" /></IconButton></Tooltip>
                                            {can('selling_pages', 'create') && (
                                                <Tooltip title="Duplicate"><IconButton aria-label={`Duplicate ${page.name}`} onClick={() => router.post(`/admin/selling-pages/${page.id}/duplicate`)}><ContentCopyOutlined fontSize="small" /></IconButton></Tooltip>
                                            )}
                                            {can('selling_pages', 'delete') && (
                                                <Tooltip title="Delete"><IconButton aria-label={`Delete ${page.name}`} onClick={() => setDeleting(page)}><DeleteOutlined fontSize="small" /></IconButton></Tooltip>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </TableContainer>
                </Card>
            )}

            <Dialog open={creating} onClose={() => setCreating(false)} fullWidth maxWidth="sm">
                <form onSubmit={submit} noValidate>
                    <DialogTitle>New selling page</DialogTitle>
                    <DialogContent>
                        <Stack spacing={2.5} sx={{ pt: 1 }}>
                            <TextField
                                label="Page name" required autoFocus value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                error={Boolean(form.errors.name)} helperText={form.errors.name ?? 'Shown in admin and as the browser tab title.'}
                            />
                            <Autocomplete
                                options={products}
                                getOptionLabel={(option) => option.name}
                                value={products.find((p) => p.id === form.data.product_id) ?? null}
                                onChange={(_, value) => form.setData('product_id', value?.id ?? null)}
                                renderInput={(params) => <TextField {...params} label="Product this page sells" required error={Boolean(form.errors.product_id)} helperText={form.errors.product_id} />}
                            />
                            <FormControl>
                                <FormLabel id="start-label">Start from</FormLabel>
                                <RadioGroup aria-labelledby="start-label" value={form.data.start} onChange={(e) => form.setData('start', e.target.value as 'template' | 'blank')}>
                                    <FormControlLabel value="template" control={<Radio />} label="Starter template: hero, benefits, image and text, questions, order block" />
                                    <FormControlLabel value="blank" control={<Radio />} label="Blank page with only the order block" />
                                </RadioGroup>
                            </FormControl>
                        </Stack>
                    </DialogContent>
                    <DialogActions sx={{ px: 3, pb: 2.5 }}>
                        <Button onClick={() => setCreating(false)}>Cancel</Button>
                        <Button type="submit" variant="contained" disabled={form.processing}>Create draft</Button>
                    </DialogActions>
                </form>
            </Dialog>

            <ConfirmDialog
                open={deleting !== null}
                title={`Delete ${deleting?.name ?? ''}?`}
                text="The page address will show “not found”. Orders already placed on it are kept."
                confirmLabel="Delete page"
                danger
                busy={busy}
                onClose={() => setDeleting(null)}
                onConfirm={() => {
                    if (!deleting) return;
                    setBusy(true);
                    router.delete(`/admin/selling-pages/${deleting.id}`, { preserveScroll: true, onFinish: () => { setBusy(false); setDeleting(null); } });
                }}
            />
        </AdminLayout>
    );
}
