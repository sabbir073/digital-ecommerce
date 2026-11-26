import { router, useForm } from '@inertiajs/react';
import Add from '@mui/icons-material/Add';
import ArrowDownward from '@mui/icons-material/ArrowDownward';
import ArrowUpward from '@mui/icons-material/ArrowUpward';
import DeleteOutline from '@mui/icons-material/DeleteOutlined';
import EditOutlined from '@mui/icons-material/EditOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import Divider from '@mui/material/Divider';
import IconButton from '@mui/material/IconButton';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import TravelExploreOutlined from '@mui/icons-material/TravelExploreOutlined';
import Typography from '@mui/material/Typography';
import { type FormEvent, useState } from 'react';
import AdminLayout from '../../components/AdminLayout';
import ConfirmDialog from '../../components/ConfirmDialog';
import MediaPicker from '../../components/MediaPicker';
import { useCan } from '../../lib/can';
import RouterLink from '../../components/RouterLink';
import { slugify } from '../../lib/format';
import type { MediaItem } from '../../lib/types';

interface Category {
    id: number;
    name: string;
    slug: string;
    parentId: number | null;
    position: number;
    products: number;
    image: MediaItem | null;
    children?: Category[];
}

type FormData = { name: string; slug: string; parent_id: number | ''; media_id: number | null };

export default function CategoriesIndex({ categories }: { categories: Category[] }) {
    const can = useCan();
    const [open, setOpen] = useState<Category | 'new' | null>(null);
    const [image, setImage] = useState<MediaItem | null>(null);
    const [slugTouched, setSlugTouched] = useState(false);
    const [pickerOpen, setPickerOpen] = useState(false);
    const [deleting, setDeleting] = useState<Category | null>(null);
    const [busy, setBusy] = useState(false);
    const form = useForm<FormData>({ name: '', slug: '', parent_id: '', media_id: null });
    const errors = form.errors as Record<string, string>;

    const openForm = (category: Category | 'new', parentId: number | '' = '') => {
        form.clearErrors();
        if (category === 'new') {
            form.setData({ name: '', slug: '', parent_id: parentId, media_id: null });
            setImage(null);
            setSlugTouched(false);
        } else {
            form.setData({ name: category.name, slug: category.slug, parent_id: category.parentId ?? '', media_id: category.image?.id ?? null });
            setImage(category.image);
            setSlugTouched(true);
        }
        setOpen(category);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, parent_id: data.parent_id === '' ? null : data.parent_id }));
        const options = { preserveScroll: true, onSuccess: () => setOpen(null) };
        if (open === 'new') form.post('/admin/categories', options);
        else if (open) form.put(`/admin/categories/${open.id}`, options);
    };

    const move = (siblings: Category[], index: number, direction: -1 | 1) => {
        const ids = siblings.map((c) => c.id);
        const target = index + direction;
        if (target < 0 || target >= ids.length) return;
        [ids[index], ids[target]] = [ids[target], ids[index]];
        router.post('/admin/categories/reorder', { ids }, { preserveScroll: true });
    };

    const row = (category: Category, siblings: Category[], index: number, child = false) => (
        <Box key={category.id} sx={{ display: 'flex', alignItems: 'center', gap: 1.5, px: 2, py: 1.25, pl: child ? 6 : 2 }}>
            <Box sx={{ width: 44, height: 44, borderRadius: 2, overflow: 'hidden', bgcolor: 'background.default', flexShrink: 0 }}>
                {category.image?.thumb && <img src={category.image.thumb} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />}
            </Box>
            <Box sx={{ flex: 1, minWidth: 0 }}>
                <Typography noWrap sx={{ fontWeight: 600 }}>{category.name}</Typography>
                <Typography noWrap sx={{ color: 'text.secondary', fontSize: 13 }}>
                    /c/{category.slug} · {category.products} {category.products === 1 ? 'product' : 'products'}
                </Typography>
            </Box>
            {can('seo') && <Tooltip title="SEO"><IconButton aria-label={`SEO of ${category.name}`} component={RouterLink} href={`/admin/seo/category/${category.id}`}><TravelExploreOutlined fontSize="small" /></IconButton></Tooltip>}
            {can('categories', 'edit') && (
                <>
                    <Tooltip title="Move up"><span><IconButton aria-label={`Move ${category.name} up`} disabled={index === 0} onClick={() => move(siblings, index, -1)}><ArrowUpward fontSize="small" /></IconButton></span></Tooltip>
                    <Tooltip title="Move down"><span><IconButton aria-label={`Move ${category.name} down`} disabled={index === siblings.length - 1} onClick={() => move(siblings, index, 1)}><ArrowDownward fontSize="small" /></IconButton></span></Tooltip>
                    <Tooltip title="Edit"><IconButton aria-label={`Edit ${category.name}`} onClick={() => openForm(category)}><EditOutlined fontSize="small" /></IconButton></Tooltip>
                </>
            )}
            {can('categories', 'delete') && (
                <Tooltip title="Delete"><IconButton aria-label={`Delete ${category.name}`} onClick={() => setDeleting(category)}><DeleteOutline fontSize="small" /></IconButton></Tooltip>
            )}
        </Box>
    );

    return (
        <AdminLayout
            title="Categories"
            actions={can('categories', 'create') && <Button variant="contained" startIcon={<Add />} onClick={() => openForm('new')}>New category</Button>}
        >
            {categories.length === 0 ? (
                <Card sx={{ p: 6, textAlign: 'center' }}>
                    <Typography variant="h4" component="p" sx={{ mb: 0.5 }}>No categories yet</Typography>
                    <Typography sx={{ color: 'text.secondary' }}>Categories appear in the store navigation and on the home page.</Typography>
                </Card>
            ) : (
                <Stack spacing={2}>
                    {categories.map((category, index) => (
                        <Card key={category.id}>
                            {row(category, categories, index)}
                            {(category.children ?? []).map((child, childIndex) => (
                                <Box key={child.id}>
                                    <Divider />
                                    {row(child, category.children ?? [], childIndex, true)}
                                </Box>
                            ))}
                            {can('categories', 'create') && (
                                <>
                                    <Divider />
                                    <Box sx={{ px: 2, py: 1, pl: 6 }}>
                                        <Button size="small" variant="text" startIcon={<Add />} onClick={() => openForm('new', category.id)}>Add sub-category</Button>
                                    </Box>
                                </>
                            )}
                        </Card>
                    ))}
                </Stack>
            )}

            <Dialog open={!!open} onClose={() => setOpen(null)} maxWidth="xs" fullWidth>
                <Box component="form" onSubmit={submit} noValidate>
                    <DialogTitle>{open === 'new' ? 'New category' : 'Edit category'}</DialogTitle>
                    <DialogContent>
                        <Stack spacing={2.5} sx={{ pt: 1 }}>
                            <TextField
                                label="Name"
                                required
                                autoFocus
                                value={form.data.name}
                                onChange={(e) => form.setData((data) => ({ ...data, name: e.target.value, slug: slugTouched ? data.slug : slugify(e.target.value) }))}
                                error={!!errors.name}
                                helperText={errors.name}
                            />
                            <TextField
                                label="URL slug"
                                required
                                value={form.data.slug}
                                onChange={(e) => { setSlugTouched(true); form.setData('slug', e.target.value); }}
                                error={!!errors.slug}
                                helperText={errors.slug ?? `Page address: /c/${form.data.slug || 'your-slug'}`}
                            />
                            <TextField select label="Parent category" value={form.data.parent_id} onChange={(e) => form.setData('parent_id', e.target.value === '' ? '' : Number(e.target.value))} error={!!errors.parent_id} helperText={errors.parent_id ?? 'Categories can be two levels deep.'} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}>
                                <MenuItem value="">None (top level)</MenuItem>
                                {categories.filter((c) => open === 'new' || c.id !== (open as Category | null)?.id).map((c) => <MenuItem key={c.id} value={c.id}>{c.name}</MenuItem>)}
                            </TextField>
                            <Box sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
                                <Box sx={{ width: 72, height: 72, borderRadius: 3, overflow: 'hidden', bgcolor: 'background.default', border: 1, borderColor: 'divider', flexShrink: 0 }}>
                                    {image?.thumb && <img src={image.thumb} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />}
                                </Box>
                                <Stack direction="row" spacing={1}>
                                    <Button variant="outlined" size="small" onClick={() => setPickerOpen(true)}>{image ? 'Change image' : 'Choose image'}</Button>
                                    {image && <Button variant="text" size="small" onClick={() => { setImage(null); form.setData('media_id', null); }}>Remove</Button>}
                                </Stack>
                            </Box>
                        </Stack>
                    </DialogContent>
                    <DialogActions sx={{ px: 3, pb: 3 }}>
                        <Button variant="outlined" onClick={() => setOpen(null)}>Cancel</Button>
                        <Button type="submit" variant="contained" loading={form.processing}>{open === 'new' ? 'Create' : 'Save'}</Button>
                    </DialogActions>
                </Box>
            </Dialog>

            <MediaPicker open={pickerOpen} onClose={() => setPickerOpen(false)} onSelect={([item]) => { setImage(item); form.setData('media_id', item.id); }} />

            {deleting && (
                <ConfirmDialog
                    open
                    title="Delete this category?"
                    text={(deleting.children?.length ?? 0) > 0 ? `${deleting.name} has sub-categories. Delete or move them first.` : `${deleting.name} is deleted. Its ${deleting.products} products are kept without a category.`}
                    confirmLabel="Delete"
                    danger
                    busy={busy}
                    onConfirm={() => router.delete(`/admin/categories/${deleting.id}`, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => { setBusy(false); setDeleting(null); } })}
                    onClose={() => setDeleting(null)}
                />
            )}
        </AdminLayout>
    );
}
