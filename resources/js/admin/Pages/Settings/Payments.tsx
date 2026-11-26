import { useForm } from '@inertiajs/react';
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
import { useCan } from '../../lib/can';

type Mode = 'test' | 'live';
type KeySet = { publishable: string; secret: string; webhook_secret: string };
type Saved = { publishable: string | null; secret: string | null; webhookSecret: string | null };

interface Props {
    mode: Mode;
    keys: Record<Mode, Saved>;
    webhookUrl: string;
    taxEnabled: boolean;
    allowOverselling: boolean;
    canEditKeys: boolean;
}

const BLANK: KeySet = { publishable: '', secret: '', webhook_secret: '' };

export default function PaymentSettings({ mode, keys, webhookUrl, taxEnabled, allowOverselling, canEditKeys }: Props) {
    const can = useCan();
    const keyForm = useForm<{ mode: Mode; test: KeySet; live: KeySet }>({ mode, test: BLANK, live: BLANK });
    const optionForm = useForm({ tax_enabled: taxEnabled, allow_overselling: allowOverselling });
    const errors = keyForm.errors as Record<string, string>;

    const saveKeys = (event: FormEvent) => {
        event.preventDefault();
        keyForm.put('/admin/settings/payments/keys', { preserveScroll: true, onSuccess: () => keyForm.setData({ mode: keyForm.data.mode, test: BLANK, live: BLANK }) });
    };
    const saveOptions = (event: FormEvent) => {
        event.preventDefault();
        optionForm.put('/admin/settings/payments/options', { preserveScroll: true });
    };

    const keyFields = (which: Mode) => {
        const saved = keys[which];
        const set = (name: keyof KeySet, value: string) => keyForm.setData(which, { ...keyForm.data[which], [name]: value.trim() });
        const hint = (value: string | null, fallback: string) => (value ? `Saved: ${value}. Leave empty to keep it.` : fallback);

        return (
            <Stack spacing={2.5}>
                <TextField label="Publishable key" autoComplete="off" disabled={!canEditKeys} value={keyForm.data[which].publishable} onChange={(e) => set('publishable', e.target.value)} error={!!errors[`${which}.publishable`]} helperText={errors[`${which}.publishable`] ?? hint(saved.publishable, `Starts with pk_${which}_`)} />
                <TextField label="Secret key" type="password" autoComplete="off" disabled={!canEditKeys} value={keyForm.data[which].secret} onChange={(e) => set('secret', e.target.value)} error={!!errors[`${which}.secret`]} helperText={errors[`${which}.secret`] ?? hint(saved.secret ? 'a secret key' : null, `Starts with sk_${which}_. Stored encrypted and never shown again.`)} />
                <TextField label="Webhook signing secret" type="password" autoComplete="off" disabled={!canEditKeys} value={keyForm.data[which].webhook_secret} onChange={(e) => set('webhook_secret', e.target.value)} error={!!errors[`${which}.webhook_secret`]} helperText={errors[`${which}.webhook_secret`] ?? hint(saved.webhookSecret ? 'a signing secret' : null, 'Starts with whsec_. Orders are marked Paid only when Stripe calls the webhook.')} />
            </Stack>
        );
    };

    return (
        <AdminLayout title="Payments">
            <Stack spacing={3} sx={{ maxWidth: 820 }}>
                <Card sx={{ p: 3 }} component="form" onSubmit={saveKeys} noValidate>
                    <Typography variant="h4" component="h2">Stripe</Typography>
                    <Typography sx={{ color: 'text.secondary', mt: 0.5, mb: 2.5 }}>Card details never reach this server: customers type them into Stripe's own form on the checkout page.</Typography>

                    {!canEditKeys && <Alert severity="info" sx={{ mb: 2.5 }}>Only a Super Admin can change payment keys.</Alert>}
                    {errors.mode && <Alert severity="error" sx={{ mb: 2.5 }}>{errors.mode}</Alert>}

                    <TextField select label="Mode" disabled={!canEditKeys} value={keyForm.data.mode} onChange={(e) => keyForm.setData('mode', e.target.value as Mode)} sx={{ minWidth: 260, mb: 3 }} helperText={keyForm.data.mode === 'test' ? 'No real money is taken.' : 'Real cards are charged.'}>
                        <MenuItem value="test">Test</MenuItem>
                        <MenuItem value="live">Live</MenuItem>
                    </TextField>

                    <Box sx={{ display: 'grid', gap: 4, gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' } }}>
                        <Box>
                            <Typography sx={{ fontWeight: 600, mb: 2 }}>Test keys</Typography>
                            {keyFields('test')}
                        </Box>
                        <Box>
                            <Typography sx={{ fontWeight: 600, mb: 2 }}>Live keys</Typography>
                            {keyFields('live')}
                        </Box>
                    </Box>

                    <Alert severity="info" sx={{ mt: 3 }}>
                        In Stripe, add a webhook endpoint with this address and the events payment_intent.succeeded, payment_intent.payment_failed, refund.created, charge.refunded and charge.dispute.created:
                        <Box component="span" sx={{ display: 'block', fontFamily: 'monospace', mt: 0.5, wordBreak: 'break-all' }}>{webhookUrl}</Box>
                    </Alert>

                    {canEditKeys && <Button type="submit" variant="contained" loading={keyForm.processing} sx={{ mt: 3 }}>Save keys</Button>}
                </Card>

                <Card sx={{ p: 3 }} component="form" onSubmit={saveOptions} noValidate>
                    <Typography variant="h4" component="h2" sx={{ mb: 2 }}>Checkout options</Typography>
                    <Stack spacing={2}>
                        <Box>
                            <FormControlLabel control={<Switch checked={optionForm.data.tax_enabled} disabled={!can('settings', 'edit')} onChange={(e) => optionForm.setData('tax_enabled', e.target.checked)} />} label="Work out tax with Stripe Tax" />
                            <Typography sx={{ color: 'text.secondary', fontSize: 13, ml: 6 }}>Tax is added at checkout from the customer's country and postcode. Stripe Tax must be switched on in your Stripe account.</Typography>
                        </Box>
                        <Box>
                            <FormControlLabel control={<Switch checked={optionForm.data.allow_overselling} disabled={!can('settings', 'edit')} onChange={(e) => optionForm.setData('allow_overselling', e.target.checked)} />} label="Allow overselling" />
                            <Typography sx={{ color: 'text.secondary', fontSize: 13, ml: 6 }}>
                                Off: stock is held for a customer while they pay, and an item with no stock cannot be bought. On: orders are always accepted and stock can go below zero.
                            </Typography>
                        </Box>
                    </Stack>
                    {can('settings', 'edit') && <Button type="submit" variant="contained" loading={optionForm.processing} sx={{ mt: 3 }}>Save options</Button>}
                </Card>
            </Stack>
        </AdminLayout>
    );
}
