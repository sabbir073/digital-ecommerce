import { router, useForm } from '@inertiajs/react';
import ArrowBackOutlined from '@mui/icons-material/ArrowBackOutlined';
import OpenInNewOutlined from '@mui/icons-material/OpenInNewOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import FormControlLabel from '@mui/material/FormControlLabel';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useState } from 'react';
import type { FormEvent } from 'react';
import AdminLayout from '../../components/AdminLayout';
import ConfirmDialog from '../../components/ConfirmDialog';
import MediaPicker from '../../components/MediaPicker';
import RouterLink from '../../components/RouterLink';
import { useCan } from '../../lib/can';
import type { MediaItem } from '../../lib/types';

type Template = { title: string; description: string };
interface Props {
    settings: {
        templates: Record<string, Template>;
        share_media_id: number | null;
        verification: string;
        head: string;
        body_start: string;
        body_end: string;
        robots: string;
        sitemap_exclude: string[];
    };
    shareImage: MediaItem | null;
    types: { type: string; label: string; variables: Record<string, string>; defaults: Template }[];
    sitemaps: Record<string, string>;
    sitemapUrl: string;
    robotsUrl: string;
    defaultRobots: string;
    robotsIsDefault: boolean;
    canEditCode: boolean;
}

const mono = { fontFamily: 'ui-monospace, Menlo, monospace', fontSize: 14, lineHeight: 1.6 };

export default function SeoSettings({ settings, shareImage, types, sitemaps, sitemapUrl, robotsUrl, robotsIsDefault, canEditCode }: Props) {
    const can = useCan();
    const locked = !can('seo', 'edit');
    const [type, setType] = useState(types[0]?.type ?? 'home');
    const [image, setImage] = useState<MediaItem | null>(shareImage);
    const [picking, setPicking] = useState(false);
    const [confirmReset, setConfirmReset] = useState(false);
    const templates = useForm({ templates: settings.templates, share_media_id: settings.share_media_id });
    const verification = useForm({ verification: settings.verification });
    const code = useForm({ head: settings.head, body_start: settings.body_start, body_end: settings.body_end });
    const robots = useForm({ robots: settings.robots });
    const sitemap = useForm({ sitemap_exclude: settings.sitemap_exclude });
    const current = types.find((t) => t.type === type) ?? types[0];
    const submit = (form: { put: (url: string, options: object) => void }, url: string) => (event: FormEvent) => {
        event.preventDefault();
        form.put(url, { preserveScroll: true });
    };
    const setTemplate = (field: keyof Template, value: string) => templates.setData('templates', { ...templates.data.templates, [type]: { ...(templates.data.templates[type] as Template), [field]: value } });
    const templateError = (field: keyof Template) => (templates.errors as Record<string, string>)[`templates.${type}.${field}`];
    const codeLocked = locked || !canEditCode;

    if (!current) return null;
    const template = templates.data.templates[type] as Template;

    return (
        <AdminLayout title="SEO settings">
            <Box sx={{ display: 'flex', gap: 1.5, flexWrap: 'wrap', mb: 3 }}>
                <Button component={RouterLink} href="/admin/seo" startIcon={<ArrowBackOutlined />} color="inherit">SEO dashboard</Button>
            </Box>

            <Stack spacing={3} sx={{ maxWidth: 960 }}>
                <Card component="form" onSubmit={submit(templates, '/admin/seo/settings/templates')} noValidate sx={{ p: 3 }}>
                    <Typography variant="h4" component="h2">Title and description templates</Typography>
                    <Typography sx={{ color: 'text.secondary', mt: 0.5 }}>A page without its own meta title or description uses the template for its kind. Words in double braces are filled in for each page.</Typography>
                    <Tabs value={type} onChange={(_, value: string) => setType(value)} variant="scrollable" scrollButtons="auto" sx={{ my: 2.5, borderBottom: 1, borderColor: 'divider' }}>
                        {types.map((t) => <Tab key={t.type} value={t.type} label={t.label} />)}
                    </Tabs>
                    <Stack spacing={2.5}>
                        <TextField label="Title template" required disabled={locked} value={template.title} onChange={(e) => setTemplate('title', e.target.value)} error={!!templateError('title')} helperText={templateError('title') ?? `Original: ${current.defaults.title}`} slotProps={{ htmlInput: { maxLength: 255 } }} />
                        <TextField label="Description template" multiline minRows={2} disabled={locked} value={template.description} onChange={(e) => setTemplate('description', e.target.value)} error={!!templateError('description')} helperText={templateError('description') ?? `Original: ${current.defaults.description}`} slotProps={{ htmlInput: { maxLength: 500 } }} />
                        <Box>
                            <Typography sx={{ fontWeight: 600, mb: 1 }}>Words you can use for {current.label.toLowerCase()}</Typography>
                            <Stack spacing={1}>
                                {Object.entries(current.variables).map(([name, text]) => (
                                    <Box key={name} sx={{ display: 'flex', gap: 1.5, alignItems: 'baseline' }}>
                                        <Chip label={`{{${name}}}`} size="small" variant="outlined" disabled={locked} onClick={() => setTemplate('title', `${template.title}{{${name}}}`)} sx={{ fontFamily: 'monospace', flexShrink: 0 }} />
                                        <Typography sx={{ fontSize: 13, color: 'text.secondary' }}>{text}</Typography>
                                    </Box>
                                ))}
                            </Stack>
                            <Typography sx={{ fontSize: 13, color: 'text.secondary', mt: 1 }}>Click a word to add it to the end of the title template.</Typography>
                        </Box>

                        <Box sx={{ pt: 2.5, borderTop: 1, borderColor: 'divider', display: 'flex', gap: 2, alignItems: 'center', flexWrap: 'wrap' }}>
                            {image && <Box component="img" src={image.thumb ?? image.url} alt="" sx={{ width: 120, height: 63, objectFit: 'cover', borderRadius: 1.5, border: 1, borderColor: 'divider' }} />}
                            <Box>
                                <Typography sx={{ fontWeight: 600 }}>Default share image</Typography>
                                <Typography sx={{ fontSize: 13, color: 'text.secondary', mb: 1 }}>Shown when a link is shared and the page has no image of its own. Best at 1200 by 630.</Typography>
                                {!locked && (
                                    <Stack direction="row" spacing={1}>
                                        <Button size="small" variant="outlined" onClick={() => setPicking(true)}>{image ? 'Change image' : 'Choose image'}</Button>
                                        {image && <Button size="small" color="inherit" onClick={() => { setImage(null); templates.setData('share_media_id', null); }}>Remove</Button>}
                                    </Stack>
                                )}
                            </Box>
                        </Box>
                        {!locked && <Box><Button type="submit" variant="contained" loading={templates.processing} disabled={!templates.isDirty}>Save templates</Button></Box>}
                    </Stack>
                </Card>

                <Card component="form" onSubmit={submit(verification, '/admin/seo/settings/verification')} noValidate sx={{ p: 3 }}>
                    <Typography variant="h4" component="h2">Site verification</Typography>
                    <Typography sx={{ color: 'text.secondary', mt: 0.5, mb: 2.5 }}>Paste the tags that Google Search Console, Bing Webmaster Tools and others give you to prove the site is yours. One tag per line.</Typography>
                    <TextField label="Verification tags" fullWidth multiline minRows={3} disabled={locked} value={verification.data.verification} onChange={(e) => verification.setData('verification', e.target.value)} error={!!verification.errors.verification}
                        helperText={verification.errors.verification ?? 'Only <meta> and <link> tags are kept.'} placeholder={'<meta name="google-site-verification" content="…">'}
                        slotProps={{ htmlInput: { maxLength: 5000, spellCheck: false, style: mono }, inputLabel: { shrink: true } }} />
                    {!locked && <Button type="submit" variant="contained" loading={verification.processing} disabled={!verification.isDirty} sx={{ mt: 2.5 }}>Save verification tags</Button>}
                </Card>

                <Card component="form" onSubmit={submit(code, '/admin/seo/settings/code')} noValidate sx={{ p: 3 }}>
                    <Typography variant="h4" component="h2">Custom code</Typography>
                    <Typography sx={{ color: 'text.secondary', mt: 0.5, mb: 2 }}>Added to every storefront page exactly as written. It is not added inside the admin area or the page builder.</Typography>
                    <Alert severity={canEditCode ? 'warning' : 'info'} sx={{ mb: 2.5 }}>
                        {canEditCode
                            ? 'Code here runs for every visitor, including on checkout. Paste only code from a service you trust. A mistake can break the store for everyone.'
                            : 'Only a Super Admin can change this code, because it runs for every visitor.'}
                    </Alert>
                    <Stack spacing={2.5}>
                        <TextField label="Inside <head>" multiline minRows={3} maxRows={16} disabled={codeLocked} value={code.data.head} onChange={(e) => code.setData('head', e.target.value)} error={!!code.errors.head} helperText={code.errors.head ?? 'Meta tags, styles and scripts that must load first.'} slotProps={{ htmlInput: { maxLength: 20000, spellCheck: false, style: mono } }} />
                        <TextField label="Start of <body>" multiline minRows={3} maxRows={16} disabled={codeLocked} value={code.data.body_start} onChange={(e) => code.setData('body_start', e.target.value)} error={!!code.errors.body_start} helperText={code.errors.body_start ?? 'For example the no-script part of a tag manager.'} slotProps={{ htmlInput: { maxLength: 20000, spellCheck: false, style: mono } }} />
                        <TextField label="End of <body>" multiline minRows={3} maxRows={16} disabled={codeLocked} value={code.data.body_end} onChange={(e) => code.setData('body_end', e.target.value)} error={!!code.errors.body_end} helperText={code.errors.body_end ?? 'Scripts that can wait until the page has loaded.'} slotProps={{ htmlInput: { maxLength: 20000, spellCheck: false, style: mono } }} />
                    </Stack>
                    {!codeLocked && <Button type="submit" variant="contained" loading={code.processing} disabled={!code.isDirty} sx={{ mt: 2.5 }}>Save custom code</Button>}
                </Card>

                <Card component="form" onSubmit={submit(robots, '/admin/seo/settings/robots')} noValidate sx={{ p: 3 }}>
                    <Box sx={{ display: 'flex', gap: 1, alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap' }}>
                        <Typography variant="h4" component="h2">robots.txt</Typography>
                        <Button component="a" href={robotsUrl} target="_blank" rel="noopener" size="small" color="inherit" endIcon={<OpenInNewOutlined />}>Open robots.txt</Button>
                    </Box>
                    <Typography sx={{ color: 'text.secondary', mt: 0.5, mb: 2.5 }}>Tells search engines which parts of the store to stay out of. The sitemap address is added at the end automatically.</Typography>
                    <TextField label="Rules" fullWidth multiline minRows={8} maxRows={24} disabled={locked} value={robots.data.robots} onChange={(e) => robots.setData('robots', e.target.value)} error={!!robots.errors.robots}
                        helperText={robots.errors.robots ?? (robotsIsDefault ? 'This is the safe default.' : 'You have changed the default.')} slotProps={{ htmlInput: { maxLength: 10000, spellCheck: false, style: mono } }} />
                    {/^\s*Disallow:\s*\/\s*$/m.test(robots.data.robots) && <Alert severity="warning" sx={{ mt: 2 }}>"Disallow: /" blocks the whole store from search engines.</Alert>}
                    {!locked && (
                        <Box sx={{ display: 'flex', gap: 1.5, flexWrap: 'wrap', mt: 2.5 }}>
                            <Button type="submit" variant="contained" loading={robots.processing} disabled={!robots.isDirty}>Save robots.txt</Button>
                            {!robotsIsDefault && <Button color="inherit" onClick={() => setConfirmReset(true)}>Reset to the default</Button>}
                        </Box>
                    )}
                </Card>

                <Card component="form" onSubmit={submit(sitemap, '/admin/seo/settings/sitemap')} noValidate sx={{ p: 3 }}>
                    <Box sx={{ display: 'flex', gap: 1, alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap' }}>
                        <Typography variant="h4" component="h2">Sitemap</Typography>
                        <Button component="a" href={sitemapUrl} target="_blank" rel="noopener" size="small" color="inherit" endIcon={<OpenInNewOutlined />}>Open sitemap.xml</Button>
                    </Box>
                    <Typography sx={{ color: 'text.secondary', mt: 0.5, mb: 2 }}>Made automatically, one file per kind of content. It lists only published pages that search engines may show, and it is up to date the moment something is published.</Typography>
                    <Stack>
                        {Object.entries(sitemaps).map(([key, label]) => {
                            const included = !sitemap.data.sitemap_exclude.includes(key);
                            return (
                                <FormControlLabel key={key} label={`Include ${label.toLowerCase()}`} control={
                                    <Switch checked={included} disabled={locked} onChange={(e) => sitemap.setData('sitemap_exclude', e.target.checked ? sitemap.data.sitemap_exclude.filter((k) => k !== key) : [...sitemap.data.sitemap_exclude, key])} />
                                } />
                            );
                        })}
                    </Stack>
                    {!locked && <Button type="submit" variant="contained" loading={sitemap.processing} disabled={!sitemap.isDirty} sx={{ mt: 2 }}>Save sitemap</Button>}
                </Card>
            </Stack>

            <MediaPicker open={picking} onClose={() => setPicking(false)} onSelect={(items) => { const picked = items[0]; if (picked) { setImage(picked); templates.setData('share_media_id', picked.id); } setPicking(false); }} />
            <ConfirmDialog open={confirmReset} title="Reset robots.txt to the default?" text="Your own rules are removed and the safe default is used again." confirmLabel="Reset" onClose={() => setConfirmReset(false)}
                onConfirm={() => router.delete('/admin/seo/settings/robots', { preserveScroll: true, onSuccess: () => robots.setDefaults(), onFinish: () => setConfirmReset(false) })} />
        </AdminLayout>
    );
}
