import { router, useForm } from '@inertiajs/react';
import ArrowBack from '@mui/icons-material/ArrowBack';
import OpenInNew from '@mui/icons-material/OpenInNew';
import Autocomplete from '@mui/material/Autocomplete';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { type FormEvent, useState } from 'react';
import AdminLayout from '../../components/AdminLayout';
import { RichText } from '../../components/BlockFields';
import ConfirmDialog from '../../components/ConfirmDialog';
import MediaPicker from '../../components/MediaPicker';
import RouterLink from '../../components/RouterLink';
import { useCan } from '../../lib/can';
import { slugify } from '../../lib/format';
import type { MediaItem } from '../../lib/types';

interface Post {
    id: number;
    title: string;
    slug: string;
    excerpt: string;
    body: string;
    category: string;
    tags: string[];
    author_id: number | null;
    status: 'draft' | 'published';
    published_at: string | null;
    cover: MediaItem | null;
    url: string;
    live: boolean;
}

interface Props {
    post: Post | null;
    categories: string[];
    authors: { id: number; name: string }[];
    me: number;
}

const toLocal = (iso: string | null): string => {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

export default function BlogEdit({ post, categories, authors, me }: Props) {
    const can = useCan();
    const canSave = post ? can('blog', 'edit') : can('blog', 'create');
    const canPublish = can('blog', 'publish');
    const form = useForm({
        title: post?.title ?? '',
        slug: post?.slug ?? '',
        excerpt: post?.excerpt ?? '',
        body: post?.body ?? '',
        category: post?.category ?? '',
        tags: post?.tags ?? ([] as string[]),
        author_id: post?.author_id ?? me,
        status: post?.status ?? ('draft' as 'draft' | 'published'),
        published_at: post?.published_at ?? (null as string | null),
        cover_media_id: post?.cover?.id ?? (null as number | null),
    });
    const [cover, setCover] = useState<MediaItem | null>(post?.cover ?? null);
    const [picking, setPicking] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [slugTouched, setSlugTouched] = useState(Boolean(post));

    function save(status: 'draft' | 'published') {
        form.transform((data) => ({ ...data, status }));
        if (post) form.put(`/admin/blog/${post.id}`, { preserveScroll: true, onSuccess: () => form.setData('status', status) });
        else form.post('/admin/blog');
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        save(form.data.status);
    }

    const scheduled = form.data.published_at !== null && new Date(form.data.published_at).getTime() > Date.now();

    return (
        <AdminLayout
            title={post ? 'Edit post' : 'New post'}
            actions={
                <Stack direction="row" sx={{ gap: 1.5, flexWrap: 'wrap' }}>
                    <Button component={RouterLink} href="/admin/blog" variant="outlined" startIcon={<ArrowBack />}>Blog</Button>
                    {post && <Button component="a" href={post.url} target="_blank" rel="noopener" variant="outlined" startIcon={<OpenInNew />}>{post.live ? 'View' : 'Preview'}</Button>}
                    {post && can('seo') && <Button component={RouterLink} href={`/admin/seo/post/${post.id}`} variant="outlined">SEO</Button>}
                </Stack>
            }
        >
            <Box component="form" onSubmit={submit} sx={{ display: 'grid', gap: 3, gridTemplateColumns: { xs: 'minmax(0, 1fr)', lg: 'minmax(0, 1fr) 340px' }, alignItems: 'start' }}>
                <Card sx={{ p: 3 }}>
                    <Stack sx={{ gap: 2.5 }}>
                        <TextField
                            label="Title" value={form.data.title} disabled={!canSave} error={!!form.errors.title} helperText={form.errors.title}
                            onChange={(e) => form.setData((data) => ({ ...data, title: e.target.value, slug: slugTouched ? data.slug : slugify(e.target.value) }))}
                            slotProps={{ htmlInput: { maxLength: 160 } }}
                        />
                        <TextField
                            label="Address" value={form.data.slug} disabled={!canSave} error={!!form.errors.slug} helperText={form.errors.slug}
                            onChange={(e) => { setSlugTouched(true); form.setData('slug', e.target.value); }}
                            slotProps={{ input: { startAdornment: <InputAdornment position="start">/blog/</InputAdornment> }, htmlInput: { maxLength: 160 } }}
                        />
                        <TextField label="Summary" multiline minRows={2} value={form.data.excerpt} disabled={!canSave} error={!!form.errors.excerpt} helperText={form.errors.excerpt ?? 'One or two sentences shown in the list of posts and in search results.'} onChange={(e) => form.setData('excerpt', e.target.value)} slotProps={{ htmlInput: { maxLength: 300 } }} />
                        <RichText label="Post" value={form.data.body} disabled={!canSave} onChange={(html) => form.setData('body', html)} />
                        {form.errors.body && <Typography color="error" sx={{ fontSize: 13 }}>{form.errors.body}</Typography>}
                    </Stack>
                </Card>

                <Stack sx={{ gap: 3 }}>
                    <Card sx={{ p: 3 }}>
                        <Typography variant="h4" component="h2" sx={{ mb: 0.5 }}>Publishing</Typography>
                        <Typography sx={{ color: 'text.secondary', mb: 2 }}>
                            {form.data.status === 'published' ? (scheduled ? 'Scheduled. It appears on the store on the publish date.' : 'Published. Visitors can read it.') : 'Draft. Only staff can see it.'}
                        </Typography>
                        <Stack sx={{ gap: 2.5 }}>
                            <TextField
                                label="Publish date" type="datetime-local" value={toLocal(form.data.published_at)} disabled={!canSave}
                                onChange={(e) => form.setData('published_at', e.target.value ? new Date(e.target.value).toISOString() : null)}
                                error={!!form.errors.published_at} helperText={form.errors.published_at ?? 'Empty means the moment you publish. A later date schedules the post.'}
                                slotProps={{ inputLabel: { shrink: true } }}
                            />
                            <TextField select label="Author" value={form.data.author_id ?? ''} disabled={!canSave} onChange={(e) => form.setData('author_id', Number(e.target.value))}>
                                {authors.map((author) => <MenuItem key={author.id} value={author.id}>{author.name}</MenuItem>)}
                            </TextField>
                            {canSave && (
                                <Stack direction="row" sx={{ gap: 1.5, flexWrap: 'wrap' }}>
                                    {form.data.status === 'published' ? (
                                        <>
                                            <Button variant="contained" loading={form.processing} onClick={() => save('published')}>Save</Button>
                                            {canPublish && <Button variant="outlined" disabled={form.processing} onClick={() => save('draft')}>Unpublish</Button>}
                                        </>
                                    ) : (
                                        <>
                                            {canPublish && <Button variant="contained" loading={form.processing} onClick={() => save('published')}>{scheduled ? 'Schedule' : 'Publish'}</Button>}
                                            <Button variant="outlined" disabled={form.processing} onClick={() => save('draft')}>Save draft</Button>
                                        </>
                                    )}
                                </Stack>
                            )}
                        </Stack>
                    </Card>

                    <Card sx={{ p: 3 }}>
                        <Typography variant="h4" component="h2" sx={{ mb: 2 }}>Cover image</Typography>
                        {cover?.thumb && <Box component="img" src={cover.thumb} alt={cover.alt ?? cover.filename} sx={{ width: '100%', aspectRatio: '16 / 10', objectFit: 'cover', borderRadius: 3, mb: 2, display: 'block' }} />}
                        {canSave && (
                            <Stack direction="row" sx={{ gap: 1.5 }}>
                                <Button variant="outlined" onClick={() => setPicking(true)}>{cover ? 'Change' : 'Choose image'}</Button>
                                {cover && <Button onClick={() => { setCover(null); form.setData('cover_media_id', null); }}>Remove</Button>}
                            </Stack>
                        )}
                        {form.errors.cover_media_id && <Typography color="error" sx={{ fontSize: 13, mt: 1 }}>{form.errors.cover_media_id}</Typography>}
                    </Card>

                    <Card sx={{ p: 3 }}>
                        <Typography variant="h4" component="h2" sx={{ mb: 2 }}>Category and tags</Typography>
                        <Stack sx={{ gap: 2.5 }}>
                            <Autocomplete
                                freeSolo options={categories} value={form.data.category} disabled={!canSave}
                                onInputChange={(_, value) => form.setData('category', value)}
                                renderInput={(params) => <TextField {...params} label="Category" error={!!form.errors.category} helperText={form.errors.category ?? 'Pick one or type a new name.'} />}
                            />
                            <Autocomplete
                                multiple freeSolo options={[] as string[]} value={form.data.tags} disabled={!canSave}
                                onChange={(_, value) => form.setData('tags', value.map((v) => v.trim()).filter(Boolean).slice(0, 10))}
                                renderInput={(params) => <TextField {...params} label="Tags" helperText="Type a tag and press Enter. Up to 10." />}
                            />
                        </Stack>
                    </Card>

                    {post && can('blog', 'delete') && <Button color="error" onClick={() => setDeleting(true)} sx={{ alignSelf: 'flex-start' }}>Delete post</Button>}
                </Stack>
            </Box>

            <MediaPicker open={picking} onClose={() => setPicking(false)} onSelect={(items) => { setCover(items[0] ?? null); form.setData('cover_media_id', items[0]?.id ?? null); setPicking(false); }} />
            <ConfirmDialog
                open={deleting} title="Delete this post?" text="The post is removed for good, and its address stops working." confirmLabel="Delete" danger
                onClose={() => setDeleting(false)}
                onConfirm={() => post && router.delete(`/admin/blog/${post.id}`, { onFinish: () => setDeleting(false) })}
            />
        </AdminLayout>
    );
}
