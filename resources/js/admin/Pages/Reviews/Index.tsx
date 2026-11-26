import { router, useForm } from '@inertiajs/react';
import Add from '@mui/icons-material/Add';
import Close from '@mui/icons-material/Close';
import FlagOutlined from '@mui/icons-material/FlagOutlined';
import PushPin from '@mui/icons-material/PushPin';
import SearchOutlined from '@mui/icons-material/SearchOutlined';
import SettingsOutlined from '@mui/icons-material/SettingsOutlined';
import UploadFileOutlined from '@mui/icons-material/UploadFileOutlined';
import VerifiedOutlined from '@mui/icons-material/VerifiedOutlined';
import Alert from '@mui/material/Alert';
import Autocomplete from '@mui/material/Autocomplete';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Checkbox from '@mui/material/Checkbox';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import Pagination from '@mui/material/Pagination';
import Rating from '@mui/material/Rating';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import AdminLayout from '../../components/AdminLayout';
import ConfirmDialog from '../../components/ConfirmDialog';
import RouterLink from '../../components/RouterLink';
import { useCan } from '../../lib/can';
import { formatDateTime } from '../../lib/format';
import type { Paginated } from '../../lib/types';

type Status = 'pending' | 'approved' | 'rejected' | 'spam';

interface Review {
    id: number;
    product: { id: number; name: string } | null;
    isStore: boolean;
    rating: number;
    title: string | null;
    body: string;
    name: string;
    email: string | null;
    country: string | null;
    countryName: string | null;
    status: Status;
    verified: boolean;
    order: { id: number; number: string } | null;
    source: string;
    sourceLabel: string | null;
    pinned: boolean;
    helpful: number;
    reply: string | null;
    photos: { id: number; url: string; full: string }[];
    reports: { id: number; reason: string; at: string | null }[];
    writtenAt: string | null;
}

interface Filters {
    status: string;
    rating: number | null;
    product: number | 'store' | null;
    country: string;
    from: string | null;
    to: string | null;
    search: string;
}

interface Props {
    reviews: Paginated<Review>;
    filters: Filters;
    counts: Record<'all' | Status | 'reported', number>;
    products: { id: number; name: string }[];
    countries: Record<string, string>;
    csvColumns: string[];
}

const STATUS: Record<Status, { label: string; color: 'warning' | 'success' | 'default' | 'error' }> = {
    pending: { label: 'Pending', color: 'warning' },
    approved: { label: 'Approved', color: 'success' },
    rejected: { label: 'Rejected', color: 'default' },
    spam: { label: 'Spam', color: 'error' },
};

/** The bundled SVG flag, with the country name as its text alternative. */
export function Flag({ code, name }: { code: string | null; name: string | null }) {
    if (!code || !name) return null;
    return <Box component="img" src={`/flags/${code.toLowerCase()}.svg`} alt={name} title={name} width={20} height={15} sx={{ borderRadius: '2px', boxShadow: '0 0 0 1px rgba(17,19,23,0.08)', flexShrink: 0 }} />;
}

export default function ReviewsIndex({ reviews, filters, counts, products, countries, csvColumns }: Props) {
    const can = useCan();
    const canEdit = can('reviews', 'edit');
    const canCreate = can('reviews', 'create');
    const canDelete = can('reviews', 'delete');

    const [search, setSearch] = useState(filters.search);
    const [selected, setSelected] = useState<number[]>([]);
    const [openId, setOpenId] = useState<number | null>(null);
    const [adding, setAdding] = useState(false);
    const [importing, setImporting] = useState(false);
    const [confirm, setConfirm] = useState<{ ids: number[] } | null>(null);
    const first = useRef(true);

    const go = (next: Partial<Record<keyof Filters | 'page', unknown>>) => {
        const query = Object.fromEntries(Object.entries({ ...filters, ...next }).filter(([, v]) => v !== '' && v !== null && v !== undefined));
        router.get('/admin/reviews', query as never, { preserveState: true, preserveScroll: true, replace: true });
    };

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const timer = window.setTimeout(() => go({ search }), 300);
        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    // A new page of results starts with nothing ticked.
    useEffect(() => setSelected([]), [reviews]);

    const bulk = (action: 'approve' | 'reject' | 'delete', ids: number[]) =>
        router.post('/admin/reviews/bulk', { action, ids }, { preserveScroll: true, onSuccess: () => setSelected([]) });

    const open = reviews.data.find((r) => r.id === openId) ?? null;
    const allTicked = reviews.data.length > 0 && selected.length === reviews.data.length;
    const productValue = filters.product === 'store' ? { id: 0, name: 'Store reviews' } : products.find((p) => p.id === filters.product) ?? null;
    const anyFilter = Boolean(filters.search || filters.rating || filters.product || filters.country || filters.from || filters.to);

    return (
        <AdminLayout
            title="Reviews"
            actions={
                <Stack direction="row" spacing={1} useFlexGap sx={{ flexWrap: 'wrap' }}>
                    <Button component={RouterLink} href="/admin/reviews/settings" startIcon={<SettingsOutlined />}>Settings</Button>
                    {canCreate && <Button variant="outlined" startIcon={<UploadFileOutlined />} onClick={() => setImporting(true)}>Import CSV</Button>}
                    {canCreate && <Button variant="contained" startIcon={<Add />} onClick={() => setAdding(true)}>Add review</Button>}
                </Stack>
            }
        >
            <Tabs value={filters.status} onChange={(_, status: string) => go({ status, page: undefined })} variant="scrollable" allowScrollButtonsMobile sx={{ mb: 2.5 }} aria-label="Review status">
                <Tab value="" label={`All (${counts.all})`} />
                <Tab value="pending" label={`Pending (${counts.pending})`} />
                <Tab value="approved" label={`Approved (${counts.approved})`} />
                <Tab value="rejected" label={`Rejected (${counts.rejected})`} />
                <Tab value="spam" label={`Spam (${counts.spam})`} />
                <Tab value="reported" label={`Reported (${counts.reported})`} />
            </Tabs>

            <Box sx={{ display: 'grid', gap: 2, mb: 2.5, gridTemplateColumns: { xs: '1fr', sm: 'repeat(2, minmax(0, 1fr))', lg: '2fr 2fr 1fr 1.3fr 1.2fr 1.2fr' } }}>
                <TextField label="Search" placeholder="Text, name or email" value={search} onChange={(e) => setSearch(e.target.value)} slotProps={{ input: { startAdornment: <InputAdornment position="start"><SearchOutlined /></InputAdornment> } }} />
                <Autocomplete
                    options={[{ id: 0, name: 'Store reviews' }, ...products]}
                    getOptionLabel={(o) => o.name}
                    isOptionEqualToValue={(a, b) => a.id === b.id}
                    value={productValue}
                    onChange={(_, value) => go({ product: value ? (value.id === 0 ? 'store' : value.id) : null, page: undefined })}
                    renderInput={(params) => <TextField {...params} label="Product" />}
                />
                <TextField select label="Rating" value={filters.rating ?? ''} onChange={(e) => go({ rating: e.target.value || null, page: undefined })}>
                    <MenuItem value="">Any</MenuItem>
                    {[5, 4, 3, 2, 1].map((n) => <MenuItem key={n} value={n}>{n} {n === 1 ? 'star' : 'stars'}</MenuItem>)}
                </TextField>
                <TextField select label="Country" value={filters.country} onChange={(e) => go({ country: e.target.value, page: undefined })}>
                    <MenuItem value="">Any</MenuItem>
                    {Object.entries(countries).map(([code, name]) => <MenuItem key={code} value={code}>{name}</MenuItem>)}
                </TextField>
                <TextField label="From" type="date" value={filters.from ?? ''} onChange={(e) => go({ from: e.target.value || null, page: undefined })} slotProps={{ inputLabel: { shrink: true } }} />
                <TextField label="To" type="date" value={filters.to ?? ''} onChange={(e) => go({ to: e.target.value || null, page: undefined })} slotProps={{ inputLabel: { shrink: true } }} />
            </Box>

            {reviews.data.length === 0 ? (
                <Card sx={{ p: 6, textAlign: 'center' }}>
                    <Typography variant="h4" component="p" sx={{ mb: 0.5 }}>No reviews here</Typography>
                    <Typography sx={{ color: 'text.secondary' }}>
                        {anyFilter || filters.status ? 'Try a different status or filter.' : 'Reviews from customers appear here. You can also add genuine reviews collected elsewhere, by hand or by CSV.'}
                    </Typography>
                </Card>
            ) : (
                <>
                    {canEdit && (
                        <Stack direction="row" spacing={1} useFlexGap sx={{ alignItems: 'center', mb: 1.5, flexWrap: 'wrap', minHeight: 44 }}>
                            <FormControlLabel
                                control={<Checkbox checked={allTicked} indeterminate={selected.length > 0 && !allTicked} onChange={(e) => setSelected(e.target.checked ? reviews.data.map((r) => r.id) : [])} />}
                                label={selected.length > 0 ? `${selected.length} selected` : 'Select all on this page'}
                            />
                            {selected.length > 0 && (
                                <>
                                    <Button size="small" variant="contained" onClick={() => bulk('approve', selected)}>Approve</Button>
                                    <Button size="small" variant="outlined" onClick={() => bulk('reject', selected)}>Reject</Button>
                                    {canDelete && <Button size="small" color="error" onClick={() => setConfirm({ ids: selected })}>Delete</Button>}
                                </>
                            )}
                        </Stack>
                    )}

                    <Stack spacing={1.5}>
                        {reviews.data.map((review) => (
                            <Card key={review.id} sx={{ p: 2.5, display: 'flex', gap: 1.5, alignItems: 'flex-start' }}>
                                {canEdit && (
                                    <Checkbox
                                        sx={{ mt: -0.75, ml: -1 }}
                                        checked={selected.includes(review.id)}
                                        onChange={(e) => setSelected(e.target.checked ? [...selected, review.id] : selected.filter((id) => id !== review.id))}
                                        slotProps={{ input: { 'aria-label': `Select review by ${review.name}` } }}
                                    />
                                )}
                                <Box sx={{ flex: 1, minWidth: 0 }}>
                                    <Stack direction="row" spacing={1} useFlexGap sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
                                        <Rating value={review.rating} readOnly size="small" />
                                        <Chip size="small" variant="outlined" color={STATUS[review.status].color} label={STATUS[review.status].label} />
                                        {review.verified && <Chip size="small" icon={<VerifiedOutlined />} label="Verified buyer" />}
                                        {review.pinned && <Chip size="small" icon={<PushPin />} label="Pinned" />}
                                        {review.sourceLabel && <Chip size="small" variant="outlined" label={review.sourceLabel} />}
                                        {review.reports.length > 0 && <Chip size="small" color="error" icon={<FlagOutlined />} label={`${review.reports.length} ${review.reports.length === 1 ? 'report' : 'reports'}`} />}
                                    </Stack>
                                    <Typography sx={{ fontSize: 13, color: 'text.secondary', mt: 0.75 }}>
                                        {review.isStore ? 'Store review' : review.product?.name ?? 'Product removed'} · {formatDateTime(review.writtenAt)}
                                    </Typography>
                                    {review.title && <Typography sx={{ fontWeight: 600, mt: 1 }}>{review.title}</Typography>}
                                    <Typography sx={{ mt: 0.5, display: '-webkit-box', WebkitLineClamp: 3, WebkitBoxOrient: 'vertical', overflow: 'hidden', whiteSpace: 'pre-line' }}>{review.body}</Typography>
                                    <Stack direction="row" spacing={1} sx={{ alignItems: 'center', mt: 1.25, fontSize: 14 }}>
                                        <Flag code={review.country} name={review.countryName} />
                                        <Typography sx={{ fontSize: 14, fontWeight: 500 }}>{review.name}</Typography>
                                        {review.email && <Typography sx={{ fontSize: 14, color: 'text.secondary' }} noWrap>{review.email}</Typography>}
                                        {review.photos.length > 0 && <Typography sx={{ fontSize: 14, color: 'text.secondary' }}>· {review.photos.length} {review.photos.length === 1 ? 'photo' : 'photos'}</Typography>}
                                    </Stack>
                                    {review.reports.length > 0 && <Typography sx={{ fontSize: 14, color: 'error.main', mt: 1 }}>Reported: {review.reports[0].reason}</Typography>}
                                </Box>
                                <Stack spacing={1} sx={{ flexShrink: 0, alignItems: 'stretch' }}>
                                    <Button size="small" variant="outlined" onClick={() => setOpenId(review.id)}>{canEdit ? 'Open' : 'View'}</Button>
                                    {canEdit && review.status !== 'approved' && <Button size="small" onClick={() => bulk('approve', [review.id])}>Approve</Button>}
                                    {canEdit && review.status === 'pending' && <Button size="small" color="inherit" onClick={() => bulk('reject', [review.id])}>Reject</Button>}
                                </Stack>
                            </Card>
                        ))}
                    </Stack>

                    {reviews.last_page > 1 && (
                        <Stack sx={{ alignItems: 'center', mt: 3 }}>
                            <Pagination count={reviews.last_page} page={reviews.current_page} color="primary" onChange={(_, page) => go({ page })} />
                        </Stack>
                    )}
                </>
            )}

            {open && <ReviewDialog key={open.id} review={open} countries={countries} canEdit={canEdit} canDelete={canDelete} onClose={() => setOpenId(null)} onDelete={() => setConfirm({ ids: [open.id] })} />}
            {adding && <AddDialog products={products} countries={countries} onClose={() => setAdding(false)} />}
            {importing && <ImportDialog columns={csvColumns} onClose={() => setImporting(false)} />}

            <ConfirmDialog
                open={confirm !== null}
                title={confirm && confirm.ids.length > 1 ? `Delete ${confirm.ids.length} reviews?` : 'Delete this review?'}
                text="Deleted reviews and their photos cannot be brought back. To hide a review and keep it, reject it instead."
                confirmLabel="Delete"
                danger
                onClose={() => setConfirm(null)}
                onConfirm={() => {
                    if (confirm) bulk('delete', confirm.ids);
                    setConfirm(null);
                    setOpenId(null);
                }}
            />
        </AdminLayout>
    );
}

function ReviewDialog({ review, countries, canEdit, canDelete, onClose, onDelete }: { review: Review; countries: Record<string, string>; canEdit: boolean; canDelete: boolean; onClose: () => void; onDelete: () => void }) {
    const form = useForm({ status: review.status as string, reply: review.reply ?? '', pinned: review.pinned, country: review.country ?? '', resolve_reports: review.reports.length > 0 });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(`/admin/reviews/${review.id}`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onClose={onClose} fullWidth maxWidth="md">
            <form onSubmit={submit} noValidate>
                <DialogTitle sx={{ pr: 7 }}>
                    Review by {review.name}
                    <IconButton aria-label="Close" onClick={onClose} sx={{ position: 'absolute', right: 12, top: 12 }}><Close /></IconButton>
                </DialogTitle>
                <DialogContent dividers>
                    <Stack spacing={2.5}>
                        <Box>
                            <Stack direction="row" spacing={1} useFlexGap sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
                                <Rating value={review.rating} readOnly />
                                {review.verified && <Chip size="small" icon={<VerifiedOutlined />} label={review.order ? `Verified buyer · order ${review.order.number}` : 'Verified buyer'} />}
                                {review.sourceLabel && <Chip size="small" variant="outlined" label={review.sourceLabel} />}
                            </Stack>
                            <Typography sx={{ fontSize: 13, color: 'text.secondary', mt: 0.75 }}>
                                {review.isStore ? 'Store review' : review.product?.name ?? 'Product removed'} · {formatDateTime(review.writtenAt)} · {review.helpful} found it helpful{review.email ? ` · ${review.email}` : ''}
                            </Typography>
                            {review.title && <Typography sx={{ fontWeight: 600, mt: 1.5 }}>{review.title}</Typography>}
                            <Typography sx={{ mt: 0.5, whiteSpace: 'pre-line' }}>{review.body}</Typography>
                            <Typography sx={{ fontSize: 13, color: 'text.secondary', mt: 1 }}>The customer's words cannot be edited. You can reply, hide the review by rejecting it, or remove a photo.</Typography>
                        </Box>

                        {review.photos.length > 0 && (
                            <Stack direction="row" spacing={1.5} useFlexGap sx={{ flexWrap: 'wrap' }}>
                                {review.photos.map((photo, index) => (
                                    <Box key={photo.id} sx={{ position: 'relative' }}>
                                        <Box component="a" href={photo.full} target="_blank" rel="noopener">
                                            <Box component="img" src={photo.url} alt={`Photo ${index + 1} from ${review.name}`} sx={{ width: 96, height: 96, objectFit: 'cover', borderRadius: 2, display: 'block' }} />
                                        </Box>
                                        {canEdit && (
                                            <Tooltip title="Remove photo">
                                                <IconButton
                                                    size="small" aria-label={`Remove photo ${index + 1}`}
                                                    onClick={() => router.delete(`/admin/reviews/${review.id}/photos/${photo.id}`, { preserveScroll: true })}
                                                    sx={{ position: 'absolute', top: -8, right: -8, bgcolor: 'background.paper', boxShadow: 1, '&:hover': { bgcolor: 'background.paper' } }}
                                                >
                                                    <Close fontSize="small" />
                                                </IconButton>
                                            </Tooltip>
                                        )}
                                    </Box>
                                ))}
                            </Stack>
                        )}

                        {review.reports.length > 0 && (
                            <Alert severity="warning">
                                <Typography sx={{ fontWeight: 600, fontSize: 14 }}>Reported by visitors</Typography>
                                <Box component="ul" sx={{ m: 0, pl: 2.25, fontSize: 14 }}>
                                    {review.reports.map((report) => <li key={report.id}>{report.reason} ({formatDateTime(report.at)})</li>)}
                                </Box>
                                <FormControlLabel sx={{ mt: 0.5 }} control={<Checkbox checked={form.data.resolve_reports} disabled={!canEdit} onChange={(e) => form.setData('resolve_reports', e.target.checked)} />} label="Mark these reports as dealt with when I save" />
                            </Alert>
                        )}

                        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                            <TextField select label="Status" value={form.data.status} disabled={!canEdit} onChange={(e) => form.setData('status', e.target.value)} sx={{ flex: 1 }} helperText="Only approved reviews are public.">
                                {Object.entries(STATUS).map(([value, s]) => <MenuItem key={value} value={value}>{s.label}</MenuItem>)}
                            </TextField>
                            <TextField select label="Country" value={form.data.country} disabled={!canEdit} onChange={(e) => form.setData('country', e.target.value)} sx={{ flex: 1 }} helperText="Correct it if the flag is wrong.">
                                <MenuItem value="">Not given</MenuItem>
                                {Object.entries(countries).map(([code, name]) => <MenuItem key={code} value={code}>{name}</MenuItem>)}
                            </TextField>
                        </Stack>
                        <FormControlLabel control={<Switch checked={form.data.pinned} disabled={!canEdit} onChange={(e) => form.setData('pinned', e.target.checked)} />} label="Pin to the top of the list" />
                        <TextField
                            label="Public reply from the store" multiline minRows={3} value={form.data.reply} disabled={!canEdit}
                            onChange={(e) => form.setData('reply', e.target.value)} error={Boolean(form.errors.reply)}
                            helperText={form.errors.reply ?? 'Shown under the review. Leave empty for no reply.'} slotProps={{ htmlInput: { maxLength: 2000 } }}
                        />
                    </Stack>
                </DialogContent>
                <DialogActions sx={{ px: 3, py: 2 }}>
                    {canDelete && <Button color="error" onClick={onDelete} sx={{ mr: 'auto' }}>Delete</Button>}
                    <Button onClick={onClose}>{canEdit ? 'Cancel' : 'Close'}</Button>
                    {canEdit && <Button type="submit" variant="contained" disabled={form.processing}>Save</Button>}
                </DialogActions>
            </form>
        </Dialog>
    );
}

function AddDialog({ products, countries, onClose }: { products: { id: number; name: string }[]; countries: Record<string, string>; onClose: () => void }) {
    const form = useForm({
        product_id: null as number | null, rating: 0, title: '', body: '', display_name: '', email: '', country: '', date: '', source_label: '', order_number: '', status: 'approved',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/admin/reviews', { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onClose={onClose} fullWidth maxWidth="sm">
            <form onSubmit={submit} noValidate>
                <DialogTitle>Add a review</DialogTitle>
                <DialogContent>
                    <Stack spacing={2.5} sx={{ pt: 1 }}>
                        <Alert severity="info">For genuine reviews a customer gave you somewhere else, such as by email. Enter the text exactly as they wrote it. It is shown with the source you name below.</Alert>
                        <Autocomplete
                            options={products} getOptionLabel={(o) => o.name}
                            value={products.find((p) => p.id === form.data.product_id) ?? null}
                            onChange={(_, value) => form.setData('product_id', value?.id ?? null)}
                            renderInput={(params) => <TextField {...params} label="Product" helperText="Leave empty for a review of the store as a whole." />}
                        />
                        <Box>
                            <Typography component="legend" sx={{ fontSize: 14, fontWeight: 500, mb: 0.5 }}>Rating *</Typography>
                            <Rating name="rating" value={form.data.rating} onChange={(_, value) => form.setData('rating', value ?? 0)} size="large" />
                            {form.errors.rating && <Typography sx={{ color: 'error.main', fontSize: 13 }}>Choose a rating.</Typography>}
                        </Box>
                        <TextField label="Title (optional)" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} slotProps={{ htmlInput: { maxLength: 120 } }} />
                        <TextField label="Review text" required multiline minRows={4} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} error={Boolean(form.errors.body)} helperText={form.errors.body} />
                        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                            <TextField label="Reviewer name" required fullWidth value={form.data.display_name} onChange={(e) => form.setData('display_name', e.target.value)} error={Boolean(form.errors.display_name)} helperText={form.errors.display_name} />
                            <TextField select label="Country" fullWidth value={form.data.country} onChange={(e) => form.setData('country', e.target.value)}>
                                <MenuItem value="">Not given</MenuItem>
                                {Object.entries(countries).map(([code, name]) => <MenuItem key={code} value={code}>{name}</MenuItem>)}
                            </TextField>
                        </Stack>
                        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                            <TextField label="Date written" type="date" fullWidth value={form.data.date} onChange={(e) => form.setData('date', e.target.value)} error={Boolean(form.errors.date)} helperText={form.errors.date ?? 'Leave empty for today.'} slotProps={{ inputLabel: { shrink: true } }} />
                            <TextField label="Reviewer email (optional)" type="email" fullWidth value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} error={Boolean(form.errors.email)} helperText={form.errors.email ?? 'Never shown publicly.'} />
                        </Stack>
                        <TextField label="Source shown with the review" required value={form.data.source_label} onChange={(e) => form.setData('source_label', e.target.value)} error={Boolean(form.errors.source_label)} helperText={form.errors.source_label ?? 'For example “Collected by email”.'} slotProps={{ htmlInput: { maxLength: 60 } }} />
                        <TextField label="Order number (optional)" value={form.data.order_number} onChange={(e) => form.setData('order_number', e.target.value)} error={Boolean(form.errors.order_number)} helperText={form.errors.order_number ?? 'Only a review matched to a real paid order gets the “Verified buyer” badge.'} />
                        <TextField select label="Status" value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                            <MenuItem value="approved">Approved: public right away</MenuItem>
                            <MenuItem value="pending">Pending: check it later</MenuItem>
                        </TextField>
                    </Stack>
                </DialogContent>
                <DialogActions sx={{ px: 3, pb: 2.5 }}>
                    <Button onClick={onClose}>Cancel</Button>
                    <Button type="submit" variant="contained" disabled={form.processing}>Add review</Button>
                </DialogActions>
            </form>
        </Dialog>
    );
}

function ImportDialog({ columns, onClose }: { columns: string[]; onClose: () => void }) {
    const form = useForm<{ file: File | null; source_label: string; status: string }>({ file: null, source_label: '', status: 'approved' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/admin/reviews/import', { preserveScroll: true, forceFormData: true, onSuccess: onClose });
    };

    return (
        <Dialog open onClose={onClose} fullWidth maxWidth="sm">
            <form onSubmit={submit} noValidate>
                <DialogTitle>Import reviews from a CSV file</DialogTitle>
                <DialogContent>
                    <Stack spacing={2.5} sx={{ pt: 1 }}>
                        <Typography sx={{ color: 'text.secondary' }}>
                            For genuine reviews collected on another platform. Columns: {columns.join(', ')}. Only rating, body and display_name are required. Leave product_slug empty for a store review.{' '}
                            <Typography component="a" href="/admin/reviews/import-format" sx={{ color: 'primary.main' }}>Download a file with the right columns</Typography>.
                        </Typography>
                        <Box>
                            <Button variant="outlined" component="label" startIcon={<UploadFileOutlined />}>
                                {form.data.file ? form.data.file.name : 'Choose CSV file'}
                                <input type="file" accept=".csv,text/csv" hidden onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} />
                            </Button>
                            {form.errors.file && <Typography sx={{ color: 'error.main', fontSize: 13, mt: 0.5 }}>{form.errors.file}</Typography>}
                        </Box>
                        <TextField label="Source shown with every imported review" required value={form.data.source_label} onChange={(e) => form.setData('source_label', e.target.value)} error={Boolean(form.errors.source_label)} helperText={form.errors.source_label ?? 'For example “Imported from Etsy”.'} slotProps={{ htmlInput: { maxLength: 60 } }} />
                        <TextField select label="Status after import" value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                            <MenuItem value="approved">Approved: public right away</MenuItem>
                            <MenuItem value="pending">Pending: check each one first</MenuItem>
                        </TextField>
                    </Stack>
                </DialogContent>
                <DialogActions sx={{ px: 3, pb: 2.5 }}>
                    <Button onClick={onClose}>Cancel</Button>
                    <Button type="submit" variant="contained" disabled={form.processing || !form.data.file}>Import</Button>
                </DialogActions>
            </form>
        </Dialog>
    );
}
