import { useForm } from '@inertiajs/react';
import ArrowBack from '@mui/icons-material/ArrowBack';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import FormControlLabel from '@mui/material/FormControlLabel';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import type { FormEvent, ReactNode } from 'react';
import AdminLayout from '../../components/AdminLayout';
import RouterLink from '../../components/RouterLink';
import { useCan } from '../../lib/can';

interface Settings {
    who_may_review: 'anyone' | 'verified';
    auto_approve_verified: boolean;
    hold_links: boolean;
    blocked_words: string[];
    request_enabled: boolean;
    request_delay_days: number;
    show_flags: boolean;
    home_min_rating: number;
    home_section: boolean;
    captcha_enabled: boolean;
    turnstile_site_key: string;
    has_turnstile_secret: boolean;
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <Card sx={{ p: 3 }}>
            <Typography variant="h4" component="h2" sx={{ mb: 2 }}>{title}</Typography>
            <Stack spacing={2}>{children}</Stack>
        </Card>
    );
}

export default function ReviewSettings({ settings }: { settings: Settings }) {
    const canEdit = useCan()('reviews', 'edit');
    const form = useForm({
        who_may_review: settings.who_may_review,
        auto_approve_verified: settings.auto_approve_verified,
        hold_links: settings.hold_links,
        blocked_words: settings.blocked_words.join('\n'),
        request_enabled: settings.request_enabled,
        request_delay_days: String(settings.request_delay_days),
        show_flags: settings.show_flags,
        home_section: settings.home_section,
        home_min_rating: settings.home_min_rating,
        captcha_enabled: settings.captcha_enabled,
        turnstile_site_key: settings.turnstile_site_key,
        turnstile_secret: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, blocked_words: data.blocked_words.split('\n').map((w) => w.trim()).filter(Boolean) }));
        form.put('/admin/reviews/settings', { preserveScroll: true, onSuccess: () => form.setData('turnstile_secret', '') });
    };

    const toggle = (key: 'auto_approve_verified' | 'hold_links' | 'request_enabled' | 'show_flags' | 'home_section' | 'captcha_enabled', label: string) => (
        <FormControlLabel control={<Switch checked={form.data[key]} disabled={!canEdit} onChange={(e) => form.setData(key, e.target.checked)} />} label={label} />
    );

    return (
        <AdminLayout title="Review settings" actions={<Button component={RouterLink} href="/admin/reviews" startIcon={<ArrowBack />}>Back to reviews</Button>}>
            <Stack component="form" onSubmit={submit} noValidate spacing={3} sx={{ maxWidth: 760 }}>
                <Section title="Who can review">
                    <TextField select label="Who may write a review" value={form.data.who_may_review} disabled={!canEdit} onChange={(e) => form.setData('who_may_review', e.target.value as Settings['who_may_review'])}>
                        <MenuItem value="anyone">Anyone. Reviews from people without an order wait for approval</MenuItem>
                        <MenuItem value="verified">Verified buyers only</MenuItem>
                    </TextField>
                    {toggle('auto_approve_verified', 'Publish reviews from verified buyers without waiting for approval')}
                </Section>

                <Section title="Holding back unwanted reviews">
                    {toggle('hold_links', 'Hold any review that contains a link until someone approves it')}
                    <TextField
                        label="Blocked words" multiline minRows={3} value={form.data.blocked_words} disabled={!canEdit}
                        onChange={(e) => form.setData('blocked_words', e.target.value)}
                        helperText="One word or phrase per line. A review containing any of them waits for approval."
                    />
                    {toggle('captcha_enabled', 'Ask for an “I am human” check (Cloudflare Turnstile) on the review form')}
                    {form.errors.captcha_enabled && <Typography sx={{ color: 'error.main', fontSize: 14 }}>{form.errors.captcha_enabled}</Typography>}
                    <TextField label="Turnstile site key" value={form.data.turnstile_site_key} disabled={!canEdit} onChange={(e) => form.setData('turnstile_site_key', e.target.value)} helperText="From your Cloudflare dashboard, under Turnstile. Free to use." />
                    <TextField
                        label="Turnstile secret key" type="password" autoComplete="off" value={form.data.turnstile_secret} disabled={!canEdit}
                        onChange={(e) => form.setData('turnstile_secret', e.target.value)}
                        helperText={settings.has_turnstile_secret ? 'A secret key is saved. Leave empty to keep it.' : 'Stored encrypted. It is never shown again.'}
                    />
                    <Typography sx={{ color: 'text.secondary', fontSize: 14 }}>Always on: a limit of reviews per email and per IP address, and a hidden field that catches simple bots.</Typography>
                </Section>

                <Section title="Review request email">
                    {toggle('request_enabled', 'Email customers a link to review what they bought')}
                    <TextField
                        label="Days after payment" type="number" value={form.data.request_delay_days} disabled={!canEdit} sx={{ maxWidth: 240 }}
                        onChange={(e) => form.setData('request_delay_days', e.target.value)} error={Boolean(form.errors.request_delay_days)}
                        helperText={form.errors.request_delay_days ?? 'Downloads are delivered at payment, so the delay counts from then. Applies to new orders.'}
                        slotProps={{ htmlInput: { min: 0, max: 90 } }}
                    />
                </Section>

                <Section title="Display">
                    {toggle('show_flags', 'Show country flags next to reviewers')}
                    {toggle('home_section', 'Show a “Store reviews” section on the home page')}
                    <TextField select label="Lowest rating shown on the home page" value={form.data.home_min_rating} disabled={!canEdit} sx={{ maxWidth: 320 }} onChange={(e) => form.setData('home_min_rating', Number(e.target.value))}>
                        {[5, 4, 3, 2, 1].map((n) => <MenuItem key={n} value={n}>{n === 5 ? '5 stars only' : n === 1 ? 'All ratings' : `${n} stars and up`}</MenuItem>)}
                    </TextField>
                </Section>

                {canEdit && <Button type="submit" variant="contained" disabled={form.processing} sx={{ alignSelf: 'flex-start' }}>Save settings</Button>}
            </Stack>
        </AdminLayout>
    );
}
