import { useForm } from '@inertiajs/react';
import ArrowBackOutlined from '@mui/icons-material/ArrowBackOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import FormControlLabel from '@mui/material/FormControlLabel';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import type { FormEvent } from 'react';
import AdminLayout from '../../components/AdminLayout';
import RouterLink from '../../components/RouterLink';
import { useCan } from '../../lib/can';

type Category = { label: string; text: string };
interface ConsentData {
    enabled: boolean;
    region: string;
    title: string;
    text: string;
    accept: string;
    reject: string;
    choose: string;
    save: string;
    policy_page_id: number | null;
    categories: Record<'necessary' | 'analytics' | 'marketing', Category>;
}
interface Props {
    consent: ConsentData;
    regions: Record<string, string>;
    defaults: ConsentData;
    pages: { id: number; label: string }[];
    anyActive: boolean;
}

const two = { display: 'grid', gap: 2.5, gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' } } as const;
const names = { necessary: 'Necessary (always on)', analytics: 'Statistics (Google Analytics)', marketing: 'Marketing (ad services and Tag Manager)' } as const;

export default function ConsentSettings({ consent, regions, defaults, pages, anyActive }: Props) {
    const can = useCan();
    const locked = !can('integrations', 'edit');
    const form = useForm<ConsentData>(consent);
    const errors = form.errors as Record<string, string>;
    const { data } = form;
    const text = (key: 'title' | 'text' | 'accept' | 'reject' | 'choose' | 'save', label: string, max: number, multiline = false) => (
        <TextField label={label} required multiline={multiline} minRows={multiline ? 3 : undefined} disabled={locked} value={data[key]} onChange={(e) => form.setData(key, e.target.value)} error={!!errors[key]} helperText={errors[key]} slotProps={{ htmlInput: { maxLength: max } }} />
    );
    const save = (event: FormEvent) => {
        event.preventDefault();
        form.put('/admin/integrations/consent', { preserveScroll: true });
    };

    return (
        <AdminLayout title="Cookie banner">
            <Box sx={{ mb: 3 }}>
                <Button component={RouterLink} href="/admin/integrations" startIcon={<ArrowBackOutlined />} color="inherit">Integrations</Button>
            </Box>

            <Box component="form" onSubmit={save} noValidate sx={{ display: 'grid', gap: 3, gridTemplateColumns: { xs: 'minmax(0, 1fr)', lg: 'minmax(0, 3fr) minmax(0, 2fr)' }, alignItems: 'start' }}>
                <Stack spacing={3} sx={{ minWidth: 0 }}>
                    <Card sx={{ p: 3 }}>
                        <Typography variant="h4" component="h2">Who is asked</Typography>
                        <Typography sx={{ color: 'text.secondary', mt: 0.5, mb: 2 }}>
                            Until a visitor who must be asked has chosen, no statistics or marketing tag is loaded and no event is sent for them. Their choice is passed to Google (consent mode) and to Meta, and kept as a record.
                        </Typography>
                        {!anyActive && <Alert severity="info" sx={{ mb: 2 }}>No tracking service is switched on, so the banner does not show: there is nothing to ask about yet.</Alert>}
                        <FormControlLabel label="Ask visitors before using statistics and marketing cookies" control={<Switch checked={data.enabled} disabled={locked} onChange={(e) => form.setData('enabled', e.target.checked)} />} />
                        {!data.enabled && <Alert severity="warning" sx={{ mt: 1 }}>With the banner off, every switched-on service loads for every visitor. In Europe and several other places the law requires asking first.</Alert>}
                        <Box sx={{ ...two, mt: 2.5 }}>
                            <TextField select label="Ask" disabled={locked || !data.enabled} value={data.region} onChange={(e) => form.setData('region', e.target.value)} error={!!errors.region}
                                helperText={errors.region ?? (data.region === 'europe' ? 'The country comes from Cloudflare when the site is behind it; otherwise from the time zone of the visitor\'s device.' : 'The safest choice.')}>
                                {Object.entries(regions).map(([key, label]) => <MenuItem key={key} value={key}>{label}</MenuItem>)}
                            </TextField>
                            <TextField select label="Privacy page linked in the banner" disabled={locked} value={data.policy_page_id ?? ''} onChange={(e) => form.setData('policy_page_id', e.target.value === '' ? null : Number(e.target.value))} error={!!errors.policy_page_id} helperText={errors.policy_page_id ?? 'Only a published page is linked.'}>
                                <MenuItem value="">None</MenuItem>
                                {pages.map((page) => <MenuItem key={page.id} value={page.id}>{page.label}</MenuItem>)}
                            </TextField>
                        </Box>
                    </Card>

                    <Card sx={{ p: 3 }}>
                        <Typography variant="h4" component="h2" sx={{ mb: 2.5 }}>Banner text</Typography>
                        <Stack spacing={2.5}>
                            {text('title', 'Heading', 80)}
                            {text('text', 'Text', 600, true)}
                            <Box sx={two}>
                                {text('accept', 'Button: accept everything', 40)}
                                {text('reject', 'Button: only necessary', 40)}
                                {text('choose', 'Button: open the choices', 40)}
                                {text('save', 'Button: save the choices', 40)}
                            </Box>
                        </Stack>
                    </Card>

                    <Card sx={{ p: 3 }}>
                        <Typography variant="h4" component="h2">Categories</Typography>
                        <Typography sx={{ color: 'text.secondary', mt: 0.5, mb: 2.5 }}>What a visitor sees after choosing to decide per category.</Typography>
                        <Stack spacing={3}>
                            {(Object.keys(names) as (keyof typeof names)[]).map((key) => (
                                <Box key={key}>
                                    <Typography sx={{ fontWeight: 600, mb: 1.5 }}>{names[key]}</Typography>
                                    <Stack spacing={2}>
                                        <TextField label="Name" required disabled={locked} value={data.categories[key].label} onChange={(e) => form.setData('categories', { ...data.categories, [key]: { ...data.categories[key], label: e.target.value } })}
                                            error={!!errors[`categories.${key}.label`]} helperText={errors[`categories.${key}.label`]} slotProps={{ htmlInput: { maxLength: 40 } }} />
                                        <TextField label="Explanation" required multiline minRows={2} disabled={locked} value={data.categories[key].text} onChange={(e) => form.setData('categories', { ...data.categories, [key]: { ...data.categories[key], text: e.target.value } })}
                                            error={!!errors[`categories.${key}.text`]} helperText={errors[`categories.${key}.text`]} slotProps={{ htmlInput: { maxLength: 300 } }} />
                                    </Stack>
                                </Box>
                            ))}
                        </Stack>
                    </Card>

                    {!locked && (
                        <Box sx={{ display: 'flex', gap: 1.5, flexWrap: 'wrap' }}>
                            <Button type="submit" variant="contained" loading={form.processing} disabled={!form.isDirty}>Save cookie banner</Button>
                            <Button color="inherit" onClick={() => form.setData({ ...defaults, enabled: data.enabled, region: data.region, policy_page_id: data.policy_page_id })}>Use the original text</Button>
                        </Box>
                    )}
                </Stack>

                <Card sx={{ p: 3, position: { lg: 'sticky' }, top: { lg: 88 }, minWidth: 0 }}>
                    <Typography variant="h4" component="h2" sx={{ mb: 2 }}>Preview</Typography>
                    <Box sx={{ p: 2.5, border: 1, borderColor: 'divider', borderRadius: 3, bgcolor: '#fff', boxShadow: '0 8px 30px rgba(0,0,0,0.08)' }}>
                        <Typography sx={{ fontWeight: 600, fontSize: 17 }}>{data.title}</Typography>
                        <Typography sx={{ fontSize: 14, color: 'text.secondary', mt: 1 }}>{data.text}</Typography>
                        <Stack spacing={1.5} sx={{ mt: 2, pt: 2, borderTop: 1, borderColor: 'divider' }}>
                            {(Object.keys(names) as (keyof typeof names)[]).map((key) => (
                                <Box key={key} sx={{ display: 'flex', gap: 1.5, alignItems: 'flex-start' }}>
                                    <Box component="input" type="checkbox" checked={key === 'necessary'} disabled readOnly aria-hidden sx={{ mt: '5px' }} />
                                    <Box>
                                        <Typography sx={{ fontSize: 14, fontWeight: 600 }}>{data.categories[key].label}</Typography>
                                        <Typography sx={{ fontSize: 13, color: 'text.secondary' }}>{data.categories[key].text}</Typography>
                                    </Box>
                                </Box>
                            ))}
                        </Stack>
                        <Box sx={{ display: 'flex', gap: 1, flexWrap: 'wrap', mt: 2.5 }}>
                            <Button size="small" variant="contained" tabIndex={-1}>{data.accept}</Button>
                            <Button size="small" variant="outlined" tabIndex={-1}>{data.reject}</Button>
                            <Button size="small" variant="outlined" tabIndex={-1}>{data.save}</Button>
                        </Box>
                    </Box>
                    <Typography sx={{ fontSize: 13, color: 'text.secondary', mt: 2 }}>On the store the banner first shows without the categories, with "{data.choose}" to open them. Visitors can reopen it from "Cookie settings" in the footer.</Typography>
                </Card>
            </Box>
        </AdminLayout>
    );
}
