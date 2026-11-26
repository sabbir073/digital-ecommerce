import CheckCircle from '@mui/icons-material/CheckCircle';
import CloudUploadOutlined from '@mui/icons-material/CloudUploadOutlined';
import SearchOutlined from '@mui/icons-material/SearchOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import ButtonBase from '@mui/material/ButtonBase';
import CircularProgress from '@mui/material/CircularProgress';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import InputAdornment from '@mui/material/InputAdornment';
import Pagination from '@mui/material/Pagination';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useCan } from '../lib/can';
import { getJson, postForm } from '../lib/http';
import type { MediaItem, Paginated } from '../lib/types';

interface Props {
    open: boolean;
    multiple?: boolean;
    type?: 'image' | 'video';
    onClose: () => void;
    onSelect: (items: MediaItem[]) => void;
}

/** Pick existing files from the media library, or upload new ones in place. */
export default function MediaPicker({ open, multiple = false, type = 'image', onClose, onSelect }: Props) {
    const can = useCan();
    const [items, setItems] = useState<Paginated<MediaItem> | null>(null);
    const [selected, setSelected] = useState<MediaItem[]>([]);
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const fileInput = useRef<HTMLInputElement>(null);

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const query = new URLSearchParams({ type, page: String(page) });
            if (search) query.set('search', search);
            const data = await getJson<{ items: Paginated<MediaItem> }>(`/admin/media/browse?${query}`);
            setItems(data.items);
        } catch {
            setError('The media library could not be loaded. Try again.');
        } finally {
            setLoading(false);
        }
    }, [type, page, search]);

    useEffect(() => {
        if (!open) return;
        const timer = setTimeout(load, search ? 300 : 0);
        return () => clearTimeout(timer);
    }, [open, load, search]);

    useEffect(() => {
        if (open) {
            setSelected([]);
            setSearch('');
            setPage(1);
        }
    }, [open]);

    const toggle = (item: MediaItem) => {
        if (!multiple) {
            setSelected([item]);
            return;
        }
        setSelected((current) => (current.some((s) => s.id === item.id) ? current.filter((s) => s.id !== item.id) : [...current, item]));
    };

    const upload = async (files: FileList | null) => {
        if (!files || files.length === 0) return;
        setUploading(true);
        setError(null);
        try {
            const body = new FormData();
            Array.from(files).forEach((file) => body.append('files[]', file));
            const data = await postForm<{ items: MediaItem[] }>('/admin/media', body);
            const usable = data.items.filter((item) => item.type === type);
            setSelected((current) => (multiple ? [...current, ...usable] : usable.slice(0, 1)));
            setPage(1);
            await load();
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Upload failed.');
        } finally {
            setUploading(false);
            if (fileInput.current) fileInput.current.value = '';
        }
    };

    return (
        <Dialog open={open} onClose={onClose} maxWidth="md" fullWidth>
            <DialogTitle>{type === 'image' ? (multiple ? 'Choose images' : 'Choose an image') : 'Choose a video'}</DialogTitle>
            <DialogContent>
                <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap', mb: 2, pt: 1 }}>
                    <TextField
                        label="Search"
                        value={search}
                        onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                        sx={{ flex: '1 1 240px' }}
                        size="small"
                        slotProps={{ input: { startAdornment: <InputAdornment position="start"><SearchOutlined /></InputAdornment> } }}
                    />
                    {can('media', 'create') && (
                        <>
                            <input ref={fileInput} type="file" hidden multiple accept={type === 'image' ? 'image/jpeg,image/png,image/webp,image/gif' : 'video/mp4,video/webm'} onChange={(e) => upload(e.target.files)} />
                            <Button variant="outlined" startIcon={<CloudUploadOutlined />} loading={uploading} onClick={() => fileInput.current?.click()}>
                                Upload
                            </Button>
                        </>
                    )}
                </Box>

                {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}

                {loading && !items ? (
                    <Box sx={{ display: 'grid', placeItems: 'center', py: 8 }}><CircularProgress /></Box>
                ) : items && items.data.length === 0 ? (
                    <Box sx={{ textAlign: 'center', py: 6 }}>
                        <Typography variant="h4" component="p" sx={{ mb: 0.5 }}>{search ? 'No files match' : 'The library is empty'}</Typography>
                        <Typography sx={{ color: 'text.secondary' }}>{search ? 'Try a different search.' : 'Upload a file to get started.'}</Typography>
                    </Box>
                ) : (
                    <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: 'repeat(auto-fill, minmax(128px, 1fr))', opacity: loading ? 0.5 : 1 }}>
                        {items?.data.map((item) => {
                            const isSelected = selected.some((s) => s.id === item.id);
                            return (
                                <ButtonBase
                                    key={item.id}
                                    onClick={() => toggle(item)}
                                    onDoubleClick={() => { onSelect([item]); onClose(); }}
                                    aria-pressed={isSelected}
                                    aria-label={item.alt || item.filename}
                                    sx={{ position: 'relative', aspectRatio: '1', borderRadius: 3, overflow: 'hidden', bgcolor: 'background.default', outline: isSelected ? '3px solid' : '1px solid', outlineColor: isSelected ? 'primary.main' : 'divider' }}
                                >
                                    {item.thumb ? (
                                        <img src={item.thumb} alt="" loading="lazy" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                                    ) : (
                                        <Typography sx={{ p: 1, fontSize: 12, wordBreak: 'break-all' }}>{item.filename}</Typography>
                                    )}
                                    {isSelected && <CheckCircle color="primary" sx={{ position: 'absolute', top: 6, right: 6, bgcolor: '#fff', borderRadius: '50%' }} />}
                                </ButtonBase>
                            );
                        })}
                    </Box>
                )}

                {items && items.last_page > 1 && (
                    <Box sx={{ display: 'flex', justifyContent: 'center', mt: 2 }}>
                        <Pagination count={items.last_page} page={items.current_page} onChange={(_, p) => setPage(p)} color="primary" />
                    </Box>
                )}
            </DialogContent>
            <DialogActions sx={{ px: 3, pb: 3 }}>
                <Button variant="outlined" onClick={onClose}>Cancel</Button>
                <Button variant="contained" disabled={selected.length === 0} onClick={() => { onSelect(selected); onClose(); }}>
                    {selected.length > 1 ? `Use ${selected.length} files` : 'Use file'}
                </Button>
            </DialogActions>
        </Dialog>
    );
}
