import { router, useForm } from '@inertiajs/react';
import ContentCopyOutlined from '@mui/icons-material/ContentCopyOutlined';
import OpenInNewOutlined from '@mui/icons-material/OpenInNewOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { useState } from 'react';
import type { FormEvent } from 'react';
import AdminLayout from '../../components/AdminLayout';
import RouterLink from '../../components/RouterLink';
import { useCan } from '../../lib/can';
import { formatDateTime } from '../../lib/format';
import { sendJson } from '../../lib/http';

interface Field {
    name: string;
    label: string;
    hint: string | null;
    type: 'text' | 'secret' | 'switch';
    required: boolean;
    value: string | boolean;
    saved: string | null;
}
interface Service {
    key: string;
    name: string;
    about: string;
    category: 'analytics' | 'marketing';
    needs: string | null;
    needsMet: boolean;
    server: boolean;
    enabled: boolean;
    active: boolean;
    status: 'off' | 'on' | 'error';
    error: string | null;
    lastEventAt: string | null;
    canTestFromServer: boolean;
    fields: Field[];
}
interface Props {
    services: Service[];
    feeds: { name: string; url: string; about: string }[];
    logBrowserEvents: boolean;
    storeUrl: string;
}

const statusChip = { off: { label: 'Off', color: 'default' }, on: { label: 'On', color: 'success' }, error: { label: 'Error', color: 'error' } } as const;

function ServiceCard({ service, locked, storeUrl }: { service: Service; locked: boolean; storeUrl: string }) {
    const form = useForm({ enabled: service.enabled, settings: Object.fromEntries(service.fields.map((field) => [field.name, field.value])) as Record<string, string | boolean> });
    const errors = form.errors as Record<string, string>;
    const [testing, setTesting] = useState(false);
    const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null);
    const chip = statusChip[service.status];

    const save = (event: FormEvent) => {
        event.preventDefault();
        setResult(null);
        form.put(`/admin/integrations/${service.key}`, { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };
    const test = async () => {
        setTesting(true);
        try {
            setResult(await sendJson<{ ok: boolean; message: string }>('POST', `/admin/integrations/${service.key}/test`, {}));
            router.reload({ only: ['services'] });
        } catch (error) {
            setResult({ ok: false, message: (error as Error).message });
        } finally {
            setTesting(false);
        }
    };

    return (
        <Card component="form" onSubmit={save} noValidate sx={{ p: 3, display: 'flex', flexDirection: 'column', minWidth: 0 }}>
            <Box sx={{ display: 'flex', gap: 1, alignItems: 'flex-start', justifyContent: 'space-between' }}>
                <Typography variant="h4" component="h2">{service.name}</Typography>
                <Chip size="small" color={chip.color} label={chip.label} sx={{ flexShrink: 0 }} />
            </Box>
            <Typography sx={{ color: 'text.secondary', fontSize: 14, mt: 0.5 }}>{service.about}</Typography>
            <Typography sx={{ color: 'text.secondary', fontSize: 13, mt: 0.5 }}>
                Waits for the visitor to allow {service.category === 'analytics' ? 'statistics' : 'marketing'} cookies. {service.lastEventAt ? `Last event: ${formatDateTime(service.lastEventAt)}.` : 'No event sent yet.'}
            </Typography>
            {service.status === 'error' && service.error && <Alert severity="error" sx={{ mt: 2 }}>{service.error}</Alert>}
            {service.needs && !service.needsMet && <Alert severity="info" sx={{ mt: 2 }}>Switch on {service.needs} first: this service uses its ID.</Alert>}

            <Stack spacing={2} sx={{ mt: 2.5, flex: 1 }}>
                {service.fields.map((field) => field.type === 'switch' ? (
                    <Box key={field.name}>
                        <FormControlLabel label={field.label} control={<Switch checked={!!form.data.settings[field.name]} disabled={locked} onChange={(e) => form.setData('settings', { ...form.data.settings, [field.name]: e.target.checked })} />} />
                        {field.hint && <Typography sx={{ fontSize: 13, color: 'text.secondary' }}>{field.hint}</Typography>}
                    </Box>
                ) : (
                    <Box key={field.name}>
                        <TextField
                            fullWidth size="small" label={field.label} required={field.required && !field.saved} disabled={locked}
                            type={field.type === 'secret' ? 'password' : 'text'} autoComplete="off"
                            value={form.data.settings[field.name] as string}
                            onChange={(e) => form.setData('settings', { ...form.data.settings, [field.name]: e.target.value })}
                            placeholder={field.saved ? `Saved: ${field.saved}. Leave empty to keep it.` : undefined}
                            error={!!errors[`settings.${field.name}`]}
                            helperText={errors[`settings.${field.name}`] ?? field.hint ?? undefined}
                            slotProps={{ inputLabel: { shrink: true }, htmlInput: { spellCheck: false } }}
                        />
                        {field.type === 'secret' && field.saved && !locked && !field.required && (
                            <Button size="small" color="inherit" sx={{ mt: 0.5 }} onClick={() => router.delete(`/admin/integrations/${service.key}/${field.name}`, { preserveScroll: true })}>Remove the saved {field.label.toLowerCase()}</Button>
                        )}
                    </Box>
                ))}
            </Stack>

            {errors.enabled && <Alert severity="error" sx={{ mt: 2 }}>{errors.enabled}</Alert>}
            {result && <Alert severity={result.ok ? 'success' : 'warning'} sx={{ mt: 2 }}>{result.message}</Alert>}
            <Box sx={{ display: 'flex', gap: 1.5, flexWrap: 'wrap', alignItems: 'center', mt: 2.5 }}>
                <FormControlLabel sx={{ mr: 'auto' }} label={form.data.enabled ? 'Switched on' : 'Switched off'} control={<Switch checked={form.data.enabled} disabled={locked} onChange={(e) => form.setData('enabled', e.target.checked)} />} />
                {!locked && service.active && (service.canTestFromServer
                    ? <Button size="small" variant="outlined" loading={testing} disabled={form.isDirty} onClick={test}>Send test event</Button>
                    : service.key !== 'meta_capi' && <Tooltip title="Opens the store in a new tab. Its page view is sent to this service and shows in the event log."><Button size="small" variant="outlined" component="a" href={storeUrl} target="_blank" rel="noopener" endIcon={<OpenInNewOutlined />}>Send test event</Button></Tooltip>)}
                {!locked && <Button type="submit" size="small" variant="contained" loading={form.processing} disabled={!form.isDirty}>Save</Button>}
            </Box>
        </Card>
    );
}

export default function Integrations({ services, feeds, logBrowserEvents, storeUrl }: Props) {
    const can = useCan();
    const locked = !can('integrations', 'edit');
    const [copied, setCopied] = useState('');
    const copy = async (url: string) => {
        await navigator.clipboard.writeText(url);
        setCopied(url);
        window.setTimeout(() => setCopied(''), 2000);
    };
    const tiles = [
        { title: 'Cookie banner', text: 'The text and categories of the consent banner, and who is asked.', href: '/admin/integrations/consent' },
        { title: 'Email sending', text: 'The SMTP account that sends store emails, with a test button.', href: '/admin/integrations/email' },
        { title: 'Custom scripts', text: 'Code for any service without a card, site-wide or on chosen pages.', href: '/admin/integrations/scripts' },
        { title: 'Event log', text: 'The last 500 events, what was sent and what each service answered.', href: '/admin/integrations/log' },
    ];

    return (
        <AdminLayout title="Integrations">
            <Typography sx={{ color: 'text.secondary', mb: 3, maxWidth: 760 }}>
                Paste an ID, switch the service on, and it works. Every service gets the same store events (page view, view item, add to cart, begin checkout, purchase and more). A purchase is sent once per order, after payment is confirmed.
            </Typography>

            <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr', lg: 'repeat(4, 1fr)' }, mb: 3 }}>
                {tiles.map((tile) => (
                    <Card key={tile.href} component={RouterLink} href={tile.href} sx={{ p: 2.5, textDecoration: 'none', color: 'inherit', display: 'block', '&:hover': { borderColor: 'primary.main' } }}>
                        <Typography sx={{ fontWeight: 600 }}>{tile.title}</Typography>
                        <Typography sx={{ fontSize: 13, color: 'text.secondary', mt: 0.5 }}>{tile.text}</Typography>
                    </Card>
                ))}
            </Box>

            <Box sx={{ display: 'grid', gap: 3, gridTemplateColumns: { xs: 'minmax(0, 1fr)', md: 'repeat(2, minmax(0, 1fr))', xl: 'repeat(3, minmax(0, 1fr))' }, alignItems: 'stretch' }}>
                {services.map((service) => <ServiceCard key={service.key} service={service} locked={locked} storeUrl={storeUrl} />)}
            </Box>

            <Card sx={{ p: 3, mt: 3 }}>
                <Typography variant="h4" component="h2">Product feeds</Typography>
                <Typography sx={{ color: 'text.secondary', mt: 0.5, mb: 2 }}>Nothing to set up here. The feeds list every product that is on sale and has an image, and are always up to date.</Typography>
                <Stack spacing={2}>
                    {feeds.map((feed) => (
                        <Box key={feed.url}>
                            <Typography sx={{ fontWeight: 600 }}>{feed.name}</Typography>
                            <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5 }}>
                                <Typography sx={{ fontFamily: 'ui-monospace, Menlo, monospace', fontSize: 14, wordBreak: 'break-all' }}>{feed.url}</Typography>
                                <Tooltip title={copied === feed.url ? 'Copied' : 'Copy the address'}><IconButton size="small" aria-label={`Copy the ${feed.name} feed address`} onClick={() => void copy(feed.url)}><ContentCopyOutlined fontSize="small" /></IconButton></Tooltip>
                                <Tooltip title="Open the feed"><IconButton size="small" aria-label={`Open the ${feed.name} feed`} component="a" href={feed.url} target="_blank" rel="noopener"><OpenInNewOutlined fontSize="small" /></IconButton></Tooltip>
                            </Box>
                            <Typography sx={{ fontSize: 13, color: 'text.secondary' }}>{feed.about}</Typography>
                        </Box>
                    ))}
                </Stack>
            </Card>

            <Card sx={{ p: 3, mt: 3 }}>
                <Typography variant="h4" component="h2">More</Typography>
                <Stack spacing={1} sx={{ mt: 1.5 }}>
                    <Typography sx={{ fontSize: 14 }}>Search engine verification tags are under <RouterLink href="/admin/seo/settings">SEO settings</RouterLink>.</Typography>
                    <Typography sx={{ fontSize: 14 }}>Extra tracking IDs for one selling page are on that page's settings, under Selling pages.</Typography>
                    <FormControlLabel label="Record the events visitors' browsers send in the event log" control={
                        <Switch checked={logBrowserEvents} disabled={locked} onChange={(e) => router.put('/admin/integrations/logging', { log_browser_events: e.target.checked }, { preserveScroll: true })} />
                    } />
                </Stack>
            </Card>
        </AdminLayout>
    );
}
