import { useForm } from '@inertiajs/react';
import ArrowBackOutlined from '@mui/icons-material/ArrowBackOutlined';
import CheckCircleOutlined from '@mui/icons-material/CheckCircleOutlined';
import ErrorOutlineOutlined from '@mui/icons-material/ErrorOutlineOutlined';
import OpenInNewOutlined from '@mui/icons-material/OpenInNewOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import FormControlLabel from '@mui/material/FormControlLabel';
import InputAdornment from '@mui/material/InputAdornment';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import AdminLayout from '../../components/AdminLayout';
import MediaPicker from '../../components/MediaPicker';
import RouterLink from '../../components/RouterLink';
import { useCan } from '../../lib/can';
import { sendJson } from '../../lib/http';
import type { MediaItem } from '../../lib/types';

interface Meta {
    meta_title: string;
    meta_description: string;
    canonical_url: string;
    index: boolean;
    follow: boolean;
    og_title: string;
    og_description: string;
    og_media_id: number | null;
    focus_keyword: string;
    custom_tags: string;
}
interface Check {
    key: string;
    ok: boolean;
    label: string;
    hint: string;
}
interface Props {
    item: { type: string; typeLabel: string; id: number; name: string; url: string; public: boolean; slug: string | null; pathPrefix: string | null; editUrl: string };
    meta: Meta;
    defaults: { title: string; description: string; canonical: string; image: string | null };
    shareImage: MediaItem | null;
    checks: Check[];
    limits: { title: number; description: number };
    host: string;
}

const slugify = (text: string) => text.toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');

function Counter({ length, max }: { length: number; max: number }) {
    return (
        <Box component="span" sx={{ color: length > max ? 'error.main' : 'text.secondary', fontVariantNumeric: 'tabular-nums' }}>
            {length} of {max}
        </Box>
    );
}

export default function SeoEdit({ item, meta, defaults, shareImage, checks: savedChecks, limits, host }: Props) {
    const can = useCan();
    const locked = !can('seo', 'edit');
    const form = useForm({ ...meta, slug: item.slug ?? '' });
    const [image, setImage] = useState<MediaItem | null>(shareImage);
    const [picking, setPicking] = useState(false);
    const [checks, setChecks] = useState(savedChecks);
    const { data } = form;
    const base = item.type === 'home' ? '/admin/seo/home' : `/admin/seo/${item.type}/${item.id}`;
    const checkUrl = item.type === 'home' ? '/admin/seo-check/home' : `/admin/seo-check/${item.type}/${item.id}`;

    const title = data.meta_title.trim() || defaults.title;
    const description = data.meta_description.trim() || defaults.description;
    const slug = slugify(data.slug);
    const address = item.pathPrefix ? `${item.pathPrefix}${slug}` : item.url;
    const slugChanged = item.slug !== null && slug !== item.slug;
    const shareTitle = data.og_title.trim() || title;
    const shareDescription = data.og_description.trim() || description;
    const sharePicture = image?.thumb ?? image?.url ?? defaults.image;
    const failing = checks.filter((check) => !check.ok).length;

    useEffect(() => setChecks(savedChecks), [savedChecks]);

    // The checklist follows the fields a moment after typing stops.
    useEffect(() => {
        const timer = window.setTimeout(async () => {
            try {
                const result = await sendJson<{ checks: Check[] }>('POST', checkUrl, { meta_title: data.meta_title, meta_description: data.meta_description, focus_keyword: data.focus_keyword });
                setChecks(result.checks);
            } catch {
                // The saved checklist stays on screen; saving reports any real problem.
            }
        }, 500);
        return () => window.clearTimeout(timer);
    }, [checkUrl, data.meta_title, data.meta_description, data.focus_keyword]);

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.transform((values) => ({ ...values, slug }));
        form.put(base, { preserveScroll: true });
    };

    return (
        <AdminLayout title={`SEO: ${item.name}`}>
            <Box sx={{ display: 'flex', gap: 1.5, flexWrap: 'wrap', alignItems: 'center', mb: 3 }}>
                <Button component={RouterLink} href="/admin/seo" startIcon={<ArrowBackOutlined />} color="inherit">SEO dashboard</Button>
                <Chip label={item.typeLabel} size="small" />
                {!item.public && <Chip label="Not published" size="small" variant="outlined" />}
                <Box sx={{ flex: 1 }} />
                <Button component={RouterLink} href={item.editUrl} color="inherit">Edit the {item.typeLabel.toLowerCase()}</Button>
                {item.public && <Button component="a" href={item.url} target="_blank" rel="noopener" endIcon={<OpenInNewOutlined />} color="inherit">View</Button>}
            </Box>

            <Box component="form" onSubmit={save} noValidate sx={{ display: 'grid', gap: 3, gridTemplateColumns: { xs: 'minmax(0, 1fr)', lg: 'minmax(0, 3fr) minmax(0, 2fr)' }, alignItems: 'start' }}>
                <Stack spacing={3} sx={{ minWidth: 0 }}>
                    <Card sx={{ p: 3 }}>
                        <Typography variant="h4" component="h2" sx={{ mb: 2.5 }}>Search result</Typography>
                        <Stack spacing={2.5}>
                            <TextField label="Meta title" disabled={locked} value={data.meta_title} onChange={(e) => form.setData('meta_title', e.target.value)} placeholder={defaults.title} error={!!form.errors.meta_title}
                                helperText={<Box component="span" sx={{ display: 'flex', justifyContent: 'space-between', gap: 2 }}><span>{form.errors.meta_title ?? (data.meta_title.trim() ? 'Your own title.' : 'Empty, so the title template is used.')}</span><Counter length={title.length} max={limits.title} /></Box>}
                                slotProps={{ htmlInput: { maxLength: 255 }, inputLabel: { shrink: true } }} />
                            <TextField label="Meta description" multiline minRows={3} disabled={locked} value={data.meta_description} onChange={(e) => form.setData('meta_description', e.target.value)} placeholder={defaults.description} error={!!form.errors.meta_description}
                                helperText={<Box component="span" sx={{ display: 'flex', justifyContent: 'space-between', gap: 2 }}><span>{form.errors.meta_description ?? (data.meta_description.trim() ? 'Your own description.' : 'Empty, so the description template is used.')}</span><Counter length={description.length} max={limits.description} /></Box>}
                                slotProps={{ htmlInput: { maxLength: 500 }, inputLabel: { shrink: true } }} />
                            <TextField label="Focus keyword" disabled={locked} value={data.focus_keyword} onChange={(e) => form.setData('focus_keyword', e.target.value)} error={!!form.errors.focus_keyword}
                                helperText={form.errors.focus_keyword ?? 'Optional. The words you want this page to be found for. The checklist then looks for them in the title, the description and the text.'} slotProps={{ htmlInput: { maxLength: 100 } }} />
                        </Stack>
                    </Card>

                    <Card sx={{ p: 3 }}>
                        <Typography variant="h4" component="h2" sx={{ mb: 2.5 }}>Address and indexing</Typography>
                        <Stack spacing={2.5}>
                            {item.pathPrefix && (
                                <>
                                    <TextField label="Address (slug)" required disabled={locked} value={data.slug} onChange={(e) => form.setData('slug', e.target.value)} onBlur={() => form.setData('slug', slug)} error={!!form.errors.slug}
                                        helperText={form.errors.slug ?? 'Lowercase letters, numbers and dashes.'}
                                        slotProps={{ htmlInput: { maxLength: 160 }, input: { startAdornment: <InputAdornment position="start" sx={{ display: { xs: 'none', sm: 'flex' } }}>{item.pathPrefix.replace(/^https?:\/\//, '')}</InputAdornment> } }} />
                                    {slugChanged && slug !== '' && (
                                        <Alert severity="info">
                                            {item.public
                                                ? 'This page is published. When you save, the old address redirects to the new one (301), so links and search results keep working.'
                                                : 'This page is not published yet, so no redirect is needed.'}
                                        </Alert>
                                    )}
                                </>
                            )}
                            <TextField label="Canonical address" disabled={locked} value={data.canonical_url} onChange={(e) => form.setData('canonical_url', e.target.value)} placeholder={address} error={!!form.errors.canonical_url}
                                helperText={form.errors.canonical_url ?? 'Filled in automatically with the address of this page. Change it only when the same content lives at another address that should rank instead.'}
                                slotProps={{ htmlInput: { maxLength: 255, inputMode: 'url' }, inputLabel: { shrink: true } }} />
                            <Box>
                                <FormControlLabel control={<Switch checked={data.index} disabled={locked} onChange={(e) => form.setData('index', e.target.checked)} />} label="Let search engines show this page (index)" />
                                <FormControlLabel control={<Switch checked={data.follow} disabled={locked} onChange={(e) => form.setData('follow', e.target.checked)} />} label="Let search engines follow the links on this page (follow)" />
                                {!data.index && <Alert severity="warning" sx={{ mt: 1 }}>This page will be hidden from search results and left out of the sitemap.</Alert>}
                            </Box>
                        </Stack>
                    </Card>

                    <Card sx={{ p: 3 }}>
                        <Typography variant="h4" component="h2">Social sharing</Typography>
                        <Typography sx={{ color: 'text.secondary', mt: 0.5, mb: 2.5 }}>What Facebook, X, WhatsApp and others show when someone shares the link. Empty fields use the search result title and description.</Typography>
                        <Stack spacing={2.5}>
                            <TextField label="Share title" disabled={locked} value={data.og_title} onChange={(e) => form.setData('og_title', e.target.value)} placeholder={title} error={!!form.errors.og_title} helperText={form.errors.og_title} slotProps={{ htmlInput: { maxLength: 255 }, inputLabel: { shrink: true } }} />
                            <TextField label="Share description" multiline minRows={2} disabled={locked} value={data.og_description} onChange={(e) => form.setData('og_description', e.target.value)} placeholder={description} error={!!form.errors.og_description} helperText={form.errors.og_description} slotProps={{ htmlInput: { maxLength: 500 }, inputLabel: { shrink: true } }} />
                            <Box sx={{ display: 'flex', gap: 2, alignItems: 'center', flexWrap: 'wrap' }}>
                                {sharePicture && <Box component="img" src={sharePicture} alt="" sx={{ width: 120, height: 63, objectFit: 'cover', borderRadius: 1.5, border: 1, borderColor: 'divider' }} />}
                                <Box>
                                    <Typography sx={{ fontWeight: 600 }}>Share image</Typography>
                                    <Typography sx={{ fontSize: 13, color: 'text.secondary', mb: 1 }}>
                                        {image ? 'Chosen for this page.' : defaults.image ? 'Using the image of the item itself.' : 'None. The default share image from SEO settings is used when there is one.'} Best at 1200 by 630.
                                    </Typography>
                                    {!locked && (
                                        <Stack direction="row" spacing={1}>
                                            <Button size="small" variant="outlined" onClick={() => setPicking(true)}>{image ? 'Change image' : 'Choose image'}</Button>
                                            {image && <Button size="small" color="inherit" onClick={() => { setImage(null); form.setData('og_media_id', null); }}>Remove</Button>}
                                        </Stack>
                                    )}
                                </Box>
                            </Box>
                            {form.errors.og_media_id && <Alert severity="error">{form.errors.og_media_id}</Alert>}
                        </Stack>
                    </Card>

                    <Card sx={{ p: 3 }}>
                        <Typography variant="h4" component="h2" sx={{ mb: 2.5 }}>Custom meta tags</Typography>
                        <TextField label="Tags for this page only" fullWidth multiline minRows={3} disabled={locked} value={data.custom_tags} onChange={(e) => form.setData('custom_tags', e.target.value)} error={!!form.errors.custom_tags}
                            helperText={form.errors.custom_tags ?? 'One <meta> or <link> tag per line. Anything else is removed when you save.'}
                            placeholder={'<meta name="author" content="…">'}
                            slotProps={{ htmlInput: { maxLength: 5000, spellCheck: false, style: { fontFamily: 'ui-monospace, Menlo, monospace', fontSize: 14 } }, inputLabel: { shrink: true } }} />
                    </Card>

                    {!locked && (
                        <Box sx={{ display: 'flex', gap: 1.5 }}>
                            <Button type="submit" variant="contained" loading={form.processing} disabled={!form.isDirty}>Save SEO</Button>
                            {form.isDirty && <Button color="inherit" onClick={() => { form.reset(); form.clearErrors(); setImage(shareImage); }}>Undo changes</Button>}
                        </Box>
                    )}
                </Stack>

                <Stack spacing={3} sx={{ minWidth: 0, position: { lg: 'sticky' }, top: { lg: 88 } }}>
                    <Card sx={{ p: 3 }}>
                        <Typography variant="h4" component="h2" sx={{ mb: 2 }}>Search result preview</Typography>
                        <Box sx={{ p: 2, border: 1, borderColor: 'divider', borderRadius: 2, bgcolor: '#fff', fontFamily: 'Arial, sans-serif' }}>
                            <Typography sx={{ fontSize: 14, color: '#202124', fontFamily: 'inherit' }}>{host}</Typography>
                            <Typography sx={{ fontSize: 12, color: '#4d5156', fontFamily: 'inherit', wordBreak: 'break-all', mb: 0.5 }}>{(data.canonical_url.trim() || address).replace(/^https?:\/\//, '').replace(/\//g, ' › ').replace(/ › $/, '')}</Typography>
                            <Typography sx={{ fontSize: 20, lineHeight: 1.3, color: '#1a0dab', fontFamily: 'inherit', display: '-webkit-box', WebkitLineClamp: 1, WebkitBoxOrient: 'vertical', overflow: 'hidden' }}>{title || 'No title'}</Typography>
                            <Typography sx={{ fontSize: 14, lineHeight: 1.58, color: '#4d5156', fontFamily: 'inherit', mt: 0.5, display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden' }}>{description || 'No description. Search engines will pick some text from the page.'}</Typography>
                        </Box>
                    </Card>

                    <Card sx={{ p: 3 }}>
                        <Typography variant="h4" component="h2" sx={{ mb: 2 }}>Share preview</Typography>
                        <Box sx={{ border: 1, borderColor: 'divider', borderRadius: 2, overflow: 'hidden', bgcolor: '#fff' }}>
                            {sharePicture
                                ? <Box component="img" src={sharePicture} alt="" sx={{ display: 'block', width: '100%', aspectRatio: '1200 / 630', objectFit: 'cover' }} />
                                : <Box sx={{ aspectRatio: '1200 / 630', display: 'grid', placeItems: 'center', bgcolor: 'action.hover', color: 'text.secondary', fontSize: 13 }}>No image</Box>}
                            <Box sx={{ p: 1.5, borderTop: 1, borderColor: 'divider' }}>
                                <Typography sx={{ fontSize: 12, color: '#65676b', textTransform: 'uppercase' }}>{host}</Typography>
                                <Typography sx={{ fontSize: 16, fontWeight: 600, color: '#1c1e21', lineHeight: 1.3, display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden' }}>{shareTitle || 'No title'}</Typography>
                                {shareDescription && <Typography sx={{ fontSize: 14, color: '#65676b', display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden' }}>{shareDescription}</Typography>}
                            </Box>
                        </Box>
                    </Card>

                    <Card sx={{ p: 3 }}>
                        <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 1, mb: 2 }}>
                            <Typography variant="h4" component="h2">Checklist</Typography>
                            <Chip size="small" color={failing ? 'warning' : 'success'} label={failing ? `${failing} to fix` : 'All good'} />
                        </Box>
                        <Stack spacing={1.5} aria-live="polite">
                            {checks.map((check) => (
                                <Box key={check.key} sx={{ display: 'flex', gap: 1.25, alignItems: 'flex-start' }}>
                                    {check.ok ? <CheckCircleOutlined color="success" sx={{ fontSize: 20, mt: '1px' }} /> : <ErrorOutlineOutlined color="warning" sx={{ fontSize: 20, mt: '1px' }} />}
                                    <Box sx={{ minWidth: 0 }}>
                                        <Typography sx={{ fontWeight: 600, fontSize: 14 }}>{check.label}</Typography>
                                        <Typography sx={{ fontSize: 13, color: 'text.secondary' }}>{check.hint}</Typography>
                                    </Box>
                                </Box>
                            ))}
                        </Stack>
                        <Typography sx={{ fontSize: 13, color: 'text.secondary', mt: 2 }}>Headings, images and text are checked as they were last saved in the editor.</Typography>
                    </Card>
                </Stack>
            </Box>

            <MediaPicker open={picking} onClose={() => setPicking(false)} onSelect={(items) => { const picked = items[0]; if (picked) { setImage(picked); form.setData('og_media_id', picked.id); } setPicking(false); }} />
        </AdminLayout>
    );
}
