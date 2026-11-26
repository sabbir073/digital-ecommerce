import { router, useForm } from '@inertiajs/react';
import CloudUploadOutlined from '@mui/icons-material/CloudUploadOutlined';
import CreateNewFolderOutlined from '@mui/icons-material/CreateNewFolderOutlined';
import SearchOutlined from '@mui/icons-material/SearchOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import ButtonBase from '@mui/material/ButtonBase';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import InputAdornment from '@mui/material/InputAdornment';
import LinearProgress from '@mui/material/LinearProgress';
import MenuItem from '@mui/material/MenuItem';
import Pagination from '@mui/material/Pagination';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import AdminLayout from '../../components/AdminLayout';
import ConfirmDialog from '../../components/ConfirmDialog';
import { useCan } from '../../lib/can';
import { formatBytes, formatDateTime } from '../../lib/format';
import type { MediaItem, Paginated } from '../../lib/types';

interface Filters {
    search?: string;
    folder?: number;
    type?: string;
}

interface Props {
    items: Paginated<MediaItem>;
    folders: { id: number; name: string }[];
    filters: Filters;
    maxUploadMb: number;
}

export default function MediaIndex({ items, folders, filters, maxUploadMb }: Props) {
    const can = useCan();
    const fileInput = useRef<HTMLInputElement>(null);
    const [search, setSearch] = useState(filters.search ?? '');
    const [uploading, setUploading] = useState(false);
    const [uploadError, setUploadError] = useState<string | null>(null);
    const [open, setOpen] = useState<MediaItem | null>(null);
    const [deleting, setDeleting] = useState<MediaItem | null>(null);
    const [deletingFolder, setDeletingFolder] = useState(false);
    const [folderOpen, setFolderOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const first = useRef(true);

    const details = useForm({ alt: '', folder_id: '' as number | '' });
    const folderForm = useForm({ name: '' });

    const apply = (next: Filters) => {
        const query: Record<string, string | number> = {};
        Object.entries({ ...filters, ...next }).forEach(([key, value]) => {
            if (value !== undefined && value !== '' && value !== 0) query[key] = value as string | number;
        });
        router.get('/admin/media', query, { preserveState: true, preserveScroll: true, replace: true });
    };

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const timer = setTimeout(() => apply({ search }), 350);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const upload = (files: FileList | null) => {
        if (!files || files.length === 0) return;
        setUploadError(null);
        router.post(
            '/admin/media',
            { files: Array.from(files), folder_id: filters.folder ?? null },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => setUploading(true),
                onError: (errors) => setUploadError(Object.values(errors)[0] ?? 'Upload failed.'),
                onFinish: () => {
                    setUploading(false);
                    if (fileInput.current) fileInput.current.value = '';
                },
            },
        );
    };

    const openItem = (item: MediaItem) => {
        details.clearErrors();
        details.setData({ alt: item.alt ?? '', folder_id: item.folderId ?? '' });
        setOpen(item);
    };

    const saveDetails = (event: FormEvent) => {
        event.preventDefault();
        if (!open) return;
        details.transform((data) => ({ ...data, folder_id: data.folder_id === '' ? null : data.folder_id }));
        details.put(`/admin/media/${open.id}`, { preserveScroll: true, onSuccess: () => setOpen(null) });
    };

    const saveFolder = (event: FormEvent) => {
        event.preventDefault();
        folderForm.post('/admin/media/folders', { preserveScroll: true, onSuccess: () => { setFolderOpen(false); folderForm.reset(); } });
    };

    return (
        <AdminLayout
            title="Media library"
            actions={
                <Stack direction="row" spacing={1}>
                    {can('media', 'create') && (
                        <>
                            <Button variant="outlined" startIcon={<CreateNewFolderOutlined />} onClick={() => setFolderOpen(true)}>New folder</Button>
                            <input ref={fileInput} type="file" hidden multiple accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm" onChange={(e) => upload(e.target.files)} />
                            <Button variant="contained" startIcon={<CloudUploadOutlined />} loading={uploading} onClick={() => fileInput.current?.click()}>Upload</Button>
                        </>
                    )}
                </Stack>
            }
        >
            <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 2, mb: 2 }}>
                <TextField
                    label="Search"
                    placeholder="File name or alt text"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    sx={{ flex: '2 1 260px' }}
                    slotProps={{ input: { startAdornment: <InputAdornment position="start"><SearchOutlined /></InputAdornment> } }}
                />
                <TextField select label="Folder" value={filters.folder ?? 0} onChange={(e) => apply({ folder: Number(e.target.value) })} sx={{ flex: '1 1 180px' }}>
                    <MenuItem value={0}>All files</MenuItem>
                    {folders.map((folder) => <MenuItem key={folder.id} value={folder.id}>{folder.name}</MenuItem>)}
                </TextField>
                <TextField select label="Type" value={filters.type ?? ''} onChange={(e) => apply({ type: e.target.value })} sx={{ flex: '1 1 140px' }} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}>
                    <MenuItem value="">Images and videos</MenuItem>
                    <MenuItem value="image">Images</MenuItem>
                    <MenuItem value="video">Videos</MenuItem>
                </TextField>
            </Box>

            {filters.folder && can('media', 'delete') && (
                <Box sx={{ mb: 2 }}>
                    <Button size="small" color="error" variant="text" onClick={() => setDeletingFolder(true)}>Delete this folder</Button>
                </Box>
            )}

            {uploading && <LinearProgress sx={{ mb: 2, borderRadius: 2 }} />}
            {uploadError && <Typography color="error" sx={{ mb: 2 }} role="alert">{uploadError}</Typography>}

            {items.data.length === 0 ? (
                <Card sx={{ p: 6, textAlign: 'center' }}>
                    <Typography variant="h4" component="p" sx={{ mb: 0.5 }}>
                        {filters.search || filters.folder || filters.type ? 'No files match' : 'No files yet'}
                    </Typography>
                    <Typography sx={{ color: 'text.secondary' }}>
                        {filters.search || filters.folder || filters.type
                            ? 'Try a different search or filter.'
                            : `Upload images or videos, up to ${maxUploadMb} MB each. Images are resized and compressed for you.`}
                    </Typography>
                </Card>
            ) : (
                <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: 'repeat(auto-fill, minmax(160px, 1fr))' }}>
                    {items.data.map((item) => (
                        <ButtonBase
                            key={item.id}
                            onClick={() => openItem(item)}
                            aria-label={`Open ${item.filename}`}
                            sx={{ display: 'block', textAlign: 'left', borderRadius: 4, overflow: 'hidden', border: 1, borderColor: 'divider', bgcolor: 'background.paper' }}
                        >
                            <Box sx={{ aspectRatio: '1', bgcolor: 'background.default', display: 'grid', placeItems: 'center' }}>
                                {item.thumb ? (
                                    <img src={item.thumb} alt="" loading="lazy" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                                ) : (
                                    <Chip label="Video" />
                                )}
                            </Box>
                            <Box sx={{ p: 1.25 }}>
                                <Typography noWrap sx={{ fontSize: 13, fontWeight: 600 }}>{item.filename}</Typography>
                                <Typography noWrap sx={{ fontSize: 12, color: item.alt ? 'text.secondary' : 'warning.dark' }}>
                                    {item.type === 'image' ? (item.alt ? item.alt : 'No alt text') : formatBytes(item.sizeBytes)}
                                </Typography>
                            </Box>
                        </ButtonBase>
                    ))}
                </Box>
            )}

            {items.last_page > 1 && (
                <Box sx={{ display: 'flex', justifyContent: 'center', mt: 3 }}>
                    <Pagination count={items.last_page} page={items.current_page} color="primary" onChange={(_, page) => router.get('/admin/media', { ...filters, page }, { preserveState: true })} />
                </Box>
            )}

            <Dialog open={!!open} onClose={() => setOpen(null)} maxWidth="sm" fullWidth>
                {open && (
                    <Box component="form" onSubmit={saveDetails} noValidate>
                        <DialogTitle sx={{ wordBreak: 'break-all' }}>{open.filename}</DialogTitle>
                        <DialogContent>
                            <Stack spacing={2.5}>
                                <Box sx={{ bgcolor: 'background.default', borderRadius: 3, overflow: 'hidden', display: 'grid', placeItems: 'center', maxHeight: 320 }}>
                                    {open.type === 'image' ? (
                                        <img src={open.url} alt={open.alt ?? ''} style={{ maxWidth: '100%', maxHeight: 320, objectFit: 'contain' }} />
                                    ) : (
                                        <video src={open.url} controls style={{ maxWidth: '100%', maxHeight: 320 }} />
                                    )}
                                </Box>
                                <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>
                                    {open.width && open.height ? `${open.width} × ${open.height}px · ` : ''}
                                    {formatBytes(open.sizeBytes)} · Uploaded {formatDateTime(open.createdAt)}
                                </Typography>
                                {open.type === 'image' && (
                                    <TextField
                                        label="Alt text"
                                        value={details.data.alt}
                                        disabled={!can('media', 'edit')}
                                        onChange={(e) => details.setData('alt', e.target.value)}
                                        error={!!details.errors.alt}
                                        helperText={details.errors.alt ?? 'Describe the image for people who cannot see it.'}
                                    />
                                )}
                                <TextField select label="Folder" value={details.data.folder_id} disabled={!can('media', 'edit')} onChange={(e) => details.setData('folder_id', e.target.value === '' ? '' : Number(e.target.value))} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}>
                                    <MenuItem value="">No folder</MenuItem>
                                    {folders.map((folder) => <MenuItem key={folder.id} value={folder.id}>{folder.name}</MenuItem>)}
                                </TextField>
                            </Stack>
                        </DialogContent>
                        <DialogActions sx={{ px: 3, pb: 3 }}>
                            {can('media', 'delete') && (
                                <Button color="error" variant="text" onClick={() => { setDeleting(open); setOpen(null); }} sx={{ mr: 'auto' }}>Delete</Button>
                            )}
                            <Button variant="outlined" onClick={() => setOpen(null)}>Close</Button>
                            {can('media', 'edit') && <Button type="submit" variant="contained" loading={details.processing}>Save</Button>}
                        </DialogActions>
                    </Box>
                )}
            </Dialog>

            <Dialog open={folderOpen} onClose={() => setFolderOpen(false)} maxWidth="xs" fullWidth>
                <Box component="form" onSubmit={saveFolder} noValidate>
                    <DialogTitle>New folder</DialogTitle>
                    <DialogContent>
                        <TextField label="Folder name" required autoFocus value={folderForm.data.name} onChange={(e) => folderForm.setData('name', e.target.value)} error={!!folderForm.errors.name} helperText={folderForm.errors.name} sx={{ mt: 1 }} />
                    </DialogContent>
                    <DialogActions sx={{ px: 3, pb: 3 }}>
                        <Button variant="outlined" onClick={() => setFolderOpen(false)}>Cancel</Button>
                        <Button type="submit" variant="contained" loading={folderForm.processing}>Create folder</Button>
                    </DialogActions>
                </Box>
            </Dialog>

            {deleting && (
                <ConfirmDialog
                    open
                    title="Delete this file?"
                    text={`${deleting.filename} is removed from the library and from every product or page that uses it.`}
                    confirmLabel="Delete"
                    danger
                    busy={busy}
                    onConfirm={() => router.delete(`/admin/media/${deleting.id}`, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => { setBusy(false); setDeleting(null); } })}
                    onClose={() => setDeleting(null)}
                />
            )}

            {deletingFolder && filters.folder && (
                <ConfirmDialog
                    open
                    title="Delete this folder?"
                    text="The folder is removed. The files in it are kept."
                    confirmLabel="Delete folder"
                    danger
                    busy={busy}
                    onConfirm={() => router.delete(`/admin/media/folders/${filters.folder}`, { onStart: () => setBusy(true), onFinish: () => { setBusy(false); setDeletingFolder(false); }, onSuccess: () => router.get('/admin/media') })}
                    onClose={() => setDeletingFolder(false)}
                />
            )}
        </AdminLayout>
    );
}
