import { useForm } from '@inertiajs/react';
import AddOutlined from '@mui/icons-material/AddOutlined';
import CheckOutlined from '@mui/icons-material/CheckOutlined';
import DeleteOutlined from '@mui/icons-material/DeleteOutlined';
import OpenInNewOutlined from '@mui/icons-material/OpenInNewOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import ButtonBase from '@mui/material/ButtonBase';
import Card from '@mui/material/Card';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useState } from 'react';
import type { FormEvent } from 'react';
import AdminLayout from '../../components/AdminLayout';
import MediaPicker from '../../components/MediaPicker';
import { useCan } from '../../lib/can';
import type { MediaItem } from '../../lib/types';

type Social = { network: string; url: string };
interface ThemeData {
    accent: string;
    heading_font: string;
    body_font: string;
    density: string;
    card_ratings: boolean;
    card_compare: boolean;
    card_badges: boolean;
    logo_media_id: number | null;
    favicon_media_id: number | null;
    socials: Social[];
}
interface Props {
    theme: ThemeData;
    media: Record<number, MediaItem>;
    options: { accents: { hex: string; name: string }[]; fonts: { key: string; name: string; stack: string }[]; densities: Record<string, string>; socials: Record<string, string> };
    storeUrl: string;
}

// The same WCAG contrast sum the server runs before it saves, so the answer shows while typing.
function contrastWithWhite(hex: string): number | null {
    const match = /^#?([0-9a-f]{6})$/i.exec(hex.trim());
    if (!match) return null;
    const value = match[1] ?? '';
    const channel = (start: number) => {
        const v = parseInt(value.slice(start, start + 2), 16) / 255;
        return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
    };
    const luminance = 0.2126 * channel(0) + 0.7152 * channel(2) + 0.0722 * channel(4);
    return 1.05 / (luminance + 0.05);
}

export default function ThemeSettings({ theme, media, options, storeUrl }: Props) {
    const can = useCan();
    const locked = !can('theme', 'edit');
    const form = useForm<ThemeData>(theme);
    const errors = form.errors as Record<string, string>;
    const [images, setImages] = useState<Record<number, MediaItem>>(media);
    const [picking, setPicking] = useState<'logo_media_id' | 'favicon_media_id' | null>(null);

    const preset = options.accents.some((a) => a.hex.toLowerCase() === form.data.accent.toLowerCase());
    const ratio = contrastWithWhite(form.data.accent);
    const accentOk = ratio !== null && ratio >= 4.5;
    const font = (key: string) => options.fonts.find((f) => f.key === key)?.stack ?? 'inherit';
    const unused = Object.keys(options.socials).filter((network) => !form.data.socials.some((s) => s.network === network));
    const setSocial = (index: number, patch: Partial<Social>) => form.setData('socials', form.data.socials.map((s, i) => (i === index ? { ...s, ...patch } : s)));

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.put('/admin/settings/theme', { preserveScroll: true });
    };

    const image = (field: 'logo_media_id' | 'favicon_media_id', label: string, hint: string) => {
        const item = form.data[field] ? images[form.data[field] as number] : undefined;
        return (
            <Box>
                <Typography sx={{ fontWeight: 600 }}>{label}</Typography>
                <Typography sx={{ color: 'text.secondary', fontSize: 13, mb: 1.5 }}>{hint}</Typography>
                <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, flexWrap: 'wrap' }}>
                    {item && (
                        <Box sx={{ height: 56, minWidth: 56, px: 1.5, display: 'grid', placeItems: 'center', border: 1, borderColor: 'divider', borderRadius: 2, bgcolor: 'background.paper' }}>
                            <Box component="img" src={item.thumb ?? item.url} alt={item.alt ?? label} sx={{ maxHeight: 36, maxWidth: 160, objectFit: 'contain' }} />
                        </Box>
                    )}
                    {!locked && <Button variant="outlined" onClick={() => setPicking(field)}>{item ? 'Change' : 'Choose image'}</Button>}
                    {!locked && item && <Button color="inherit" onClick={() => form.setData(field, null)}>Remove</Button>}
                </Box>
            </Box>
        );
    };

    return (
        <AdminLayout title="Theme" actions={<Button component="a" href={storeUrl} target="_blank" rel="noopener" variant="outlined" startIcon={<OpenInNewOutlined />}>View store</Button>}>
            <Stack spacing={3} sx={{ maxWidth: 820 }} component="form" onSubmit={save} noValidate>
                <Card sx={{ p: 3 }}>
                    <Typography variant="h4" component="h2" sx={{ mb: 2.5 }}>Logo and favicon</Typography>
                    <Box sx={{ display: 'grid', gap: 3, gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' } }}>
                        {image('logo_media_id', 'Logo', 'Shown in the header at 32 pixels high. Without one, the store name is shown as text.')}
                        {image('favicon_media_id', 'Favicon', 'The small icon in the browser tab. A square image works best.')}
                    </Box>
                    <Typography sx={{ color: 'text.secondary', fontSize: 13, mt: 2.5 }}>The store name and footer text are under Settings, Store.</Typography>
                </Card>

                <Card sx={{ p: 3 }}>
                    <Typography variant="h4" component="h2">Accent colour</Typography>
                    <Typography sx={{ color: 'text.secondary', mt: 0.5, mb: 2.5 }}>Used for buttons, links and highlights. Every colour in this set keeps text easy to read.</Typography>
                    <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1.5 }} role="radiogroup" aria-label="Accent colour">
                        {options.accents.map((accent) => {
                            const active = accent.hex.toLowerCase() === form.data.accent.toLowerCase();
                            return (
                                <ButtonBase key={accent.hex} role="radio" aria-checked={active} aria-label={accent.name} title={accent.name} disabled={locked} onClick={() => form.setData('accent', accent.hex)}
                                    sx={{ width: 44, height: 44, borderRadius: '50%', bgcolor: accent.hex, color: '#fff', outline: active ? '2px solid' : 'none', outlineColor: accent.hex, outlineOffset: 3 }}>
                                    {active && <CheckOutlined fontSize="small" />}
                                </ButtonBase>
                            );
                        })}
                    </Box>
                    <Box sx={{ display: 'flex', alignItems: 'flex-start', gap: 2, mt: 3, flexWrap: 'wrap' }}>
                        <TextField label="Custom colour" disabled={locked} value={form.data.accent} onChange={(e) => form.setData('accent', e.target.value)} error={!!errors.accent || (ratio !== null && !accentOk)}
                            helperText={errors.accent ?? (ratio === null ? 'Six letters or numbers, like #1D4ED8.' : accentOk ? `Contrast with white text: ${ratio.toFixed(1)} to 1. Good.` : `Contrast with white text: ${ratio.toFixed(1)} to 1. It needs 4.5 to 1, so choose a darker shade.`)}
                            sx={{ width: 320 }} slotProps={{ htmlInput: { maxLength: 7 } }} />
                        <Box component="input" type="color" aria-label="Pick a custom colour" disabled={locked} value={/^#[0-9a-f]{6}$/i.test(form.data.accent) ? form.data.accent : '#1D4ED8'} onChange={(e: React.ChangeEvent<HTMLInputElement>) => form.setData('accent', e.target.value.toUpperCase())}
                            sx={{ width: 56, height: 56, p: 0.5, border: 1, borderColor: 'divider', borderRadius: 2, bgcolor: 'background.paper', cursor: 'pointer' }} />
                        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5, height: 56 }}>
                            <Box sx={{ px: 3, py: 1.25, borderRadius: 999, bgcolor: ratio !== null ? form.data.accent : 'grey.400', color: '#fff', fontWeight: 600 }}>Add to cart</Box>
                            <Typography sx={{ color: ratio !== null ? form.data.accent : 'text.secondary', fontWeight: 500, textDecoration: 'underline' }}>A link</Typography>
                        </Box>
                    </Box>
                    {!preset && accentOk && <Alert severity="info" sx={{ mt: 2 }}>This is a custom colour. It passes the contrast check.</Alert>}
                </Card>

                <Card sx={{ p: 3 }}>
                    <Typography variant="h4" component="h2">Fonts</Typography>
                    <Typography sx={{ color: 'text.secondary', mt: 0.5, mb: 2.5 }}>All fonts are served from your own site, so nothing is loaded from another company.</Typography>
                    <Box sx={{ display: 'grid', gap: 2.5, gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' } }}>
                        <TextField select label="Heading font" disabled={locked} value={form.data.heading_font} onChange={(e) => form.setData('heading_font', e.target.value)}>
                            {options.fonts.map((f) => <MenuItem key={f.key} value={f.key}>{f.name}{f.key === 'space-grotesk' ? ' (default)' : ''}</MenuItem>)}
                        </TextField>
                        <TextField select label="Body font" disabled={locked} value={form.data.body_font} onChange={(e) => form.setData('body_font', e.target.value)}>
                            {options.fonts.map((f) => <MenuItem key={f.key} value={f.key}>{f.name}{f.key === 'inter' ? ' (default)' : ''}</MenuItem>)}
                        </TextField>
                    </Box>
                    <Typography sx={{ color: 'text.secondary', fontSize: 13, mt: 2 }}>
                        Chosen: {options.fonts.find((f) => f.key === form.data.heading_font)?.name} for headings, {options.fonts.find((f) => f.key === form.data.body_font)?.name} for text ({font(form.data.body_font).split(',')[0]?.replace(/'/g, '')}). Save, then use View store to see them.
                    </Typography>
                </Card>

                <Card sx={{ p: 3 }}>
                    <Typography variant="h4" component="h2" sx={{ mb: 2.5 }}>Product grid and cards</Typography>
                    <TextField select label="Grid density on large screens" disabled={locked} value={form.data.density} onChange={(e) => form.setData('density', e.target.value)} sx={{ minWidth: 320, mb: 2 }} helperText="Phones always show two products in a row.">
                        {Object.entries(options.densities).map(([value, label]) => <MenuItem key={value} value={value}>{label}</MenuItem>)}
                    </TextField>
                    <Stack>
                        <FormControlLabel control={<Switch checked={form.data.card_ratings} disabled={locked} onChange={(e) => form.setData('card_ratings', e.target.checked)} />} label="Show star ratings on product cards" />
                        <FormControlLabel control={<Switch checked={form.data.card_compare} disabled={locked} onChange={(e) => form.setData('card_compare', e.target.checked)} />} label="Show the crossed-out compare-at price" />
                        <FormControlLabel control={<Switch checked={form.data.card_badges} disabled={locked} onChange={(e) => form.setData('card_badges', e.target.checked)} />} label="Show Sale and Sold out badges" />
                    </Stack>
                </Card>

                <Card sx={{ p: 3 }}>
                    <Typography variant="h4" component="h2">Social links</Typography>
                    <Typography sx={{ color: 'text.secondary', mt: 0.5, mb: 2.5 }}>Shown in the footer. Only the ones you add appear.</Typography>
                    <Stack spacing={2}>
                        {form.data.socials.length === 0 && <Typography sx={{ color: 'text.secondary' }}>No social links yet.</Typography>}
                        {form.data.socials.map((social, index) => (
                            <Box key={index} sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr auto', sm: '180px minmax(0, 1fr) auto' }, alignItems: 'start' }}>
                                <TextField select label="Network" disabled={locked} value={social.network} onChange={(e) => setSocial(index, { network: e.target.value })} error={!!errors[`socials.${index}.network`]} helperText={errors[`socials.${index}.network`]} sx={{ gridColumn: { xs: '1 / -1', sm: 'auto' } }}>
                                    {Object.entries(options.socials).filter(([network]) => network === social.network || unused.includes(network)).map(([network, label]) => <MenuItem key={network} value={network}>{label}</MenuItem>)}
                                </TextField>
                                <TextField label="Link" placeholder="https://" disabled={locked} value={social.url} onChange={(e) => setSocial(index, { url: e.target.value.trim() })} error={!!errors[`socials.${index}.url`]} helperText={errors[`socials.${index}.url`]} />
                                {!locked && <IconButton aria-label={`Remove ${options.socials[social.network] ?? 'link'}`} onClick={() => form.setData('socials', form.data.socials.filter((_, i) => i !== index))} sx={{ mt: 1 }}><DeleteOutlined /></IconButton>}
                            </Box>
                        ))}
                    </Stack>
                    {!locked && unused.length > 0 && <Button startIcon={<AddOutlined />} onClick={() => form.setData('socials', [...form.data.socials, { network: unused[0] as string, url: '' }])} sx={{ mt: 2 }}>Add social link</Button>}
                </Card>

                {!locked && (
                    <Box>
                        <Button type="submit" variant="contained" size="large" loading={form.processing} disabled={!accentOk}>Save theme</Button>
                    </Box>
                )}
            </Stack>

            <MediaPicker open={picking !== null} type="image" onClose={() => setPicking(null)} onSelect={(items) => {
                const item = items[0];
                if (item && picking) {
                    setImages((current) => ({ ...current, [item.id]: item }));
                    form.setData(picking, item.id);
                }
                setPicking(null);
            }} />
        </AdminLayout>
    );
}
