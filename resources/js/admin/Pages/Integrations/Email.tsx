import { useForm, usePage } from '@inertiajs/react';
import ArrowBackOutlined from '@mui/icons-material/ArrowBackOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import FormControlLabel from '@mui/material/FormControlLabel';
import MenuItem from '@mui/material/MenuItem';
import Radio from '@mui/material/Radio';
import RadioGroup from '@mui/material/RadioGroup';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useState } from 'react';
import type { FormEvent } from 'react';
import AdminLayout from '../../components/AdminLayout';
import RouterLink from '../../components/RouterLink';
import { useCan } from '../../lib/can';
import { formatDateTime } from '../../lib/format';
import { sendJson } from '../../lib/http';
import type { SharedProps } from '../../lib/types';

interface MailData {
    driver: 'server' | 'smtp';
    host: string;
    port: number;
    encryption: string;
    username: string;
    password: string;
    from_address: string;
    from_name: string;
}
interface Props {
    mail: MailData;
    savedPassword: string | null;
    encryption: Record<string, string>;
    serverMailer: string;
    state: { status: string; last_error: string | null; last_event_at: string | null };
    testTo: string | null;
}

const two = { display: 'grid', gap: 2.5, gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' } } as const;

export default function EmailSettings({ mail, savedPassword, encryption, serverMailer, state, testTo }: Props) {
    const can = useCan();
    const isSuper = !!usePage<SharedProps>().props.auth.user?.isSuper;
    const locked = !can('integrations', 'edit') || !isSuper;
    const form = useForm<MailData>(mail);
    const { data, errors } = form;
    const [to, setTo] = useState(testTo ?? '');
    const [testing, setTesting] = useState(false);
    const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null);
    const smtp = data.driver === 'smtp';

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.put('/admin/integrations/email', { preserveScroll: true, onSuccess: () => form.setData('password', '') });
    };
    const test = async () => {
        setTesting(true);
        setResult(null);
        try {
            setResult(await sendJson<{ ok: boolean; message: string }>('POST', '/admin/integrations/email/test', { to }));
        } catch (error) {
            setResult({ ok: false, message: (error as Error).message });
        } finally {
            setTesting(false);
        }
    };

    return (
        <AdminLayout title="Email sending">
            <Box sx={{ mb: 3 }}>
                <Button component={RouterLink} href="/admin/integrations" startIcon={<ArrowBackOutlined />} color="inherit">Integrations</Button>
            </Box>

            <Stack spacing={3} sx={{ maxWidth: 860 }}>
                <Card component="form" onSubmit={save} noValidate sx={{ p: 3 }}>
                    <Box sx={{ display: 'flex', gap: 1, alignItems: 'center', justifyContent: 'space-between' }}>
                        <Typography variant="h4" component="h2">How the store sends email</Typography>
                        <Chip size="small" color={state.status === 'error' ? 'error' : smtp && mail.driver === 'smtp' ? 'success' : 'default'} label={state.status === 'error' ? 'Error' : mail.driver === 'smtp' ? 'SMTP account' : 'Server default'} />
                    </Box>
                    <Typography sx={{ color: 'text.secondary', mt: 0.5, mb: 2 }}>Order confirmations, download links, password resets, review requests and staff invitations all go out this way.</Typography>
                    {!isSuper && <Alert severity="info" sx={{ mb: 2 }}>Only a Super Admin can change the email account.</Alert>}
                    {state.status === 'error' && state.last_error && <Alert severity="error" sx={{ mb: 2 }}>The last test failed: {state.last_error}</Alert>}

                    <RadioGroup value={data.driver} onChange={(e) => form.setData('driver', e.target.value as MailData['driver'])}>
                        <FormControlLabel value="server" disabled={locked} control={<Radio />} label={`Use the server's own setting (currently: ${serverMailer === 'log' ? 'emails are written to a log file and not delivered' : serverMailer})`} />
                        <FormControlLabel value="smtp" disabled={locked} control={<Radio />} label="Send through an SMTP account" />
                    </RadioGroup>

                    <Stack spacing={2.5} sx={{ mt: 2.5 }}>
                        {smtp && (
                            <>
                                <Typography sx={{ fontSize: 14, color: 'text.secondary' }}>
                                    Works with your hosting mailbox and with email services such as Amazon SES, Mailgun, Postmark, SendGrid and Brevo: each gives you an SMTP server name, a user name and a password or key.
                                </Typography>
                                <Box sx={two}>
                                    <TextField label="SMTP server" required disabled={locked} value={data.host} onChange={(e) => form.setData('host', e.target.value)} error={!!errors.host} helperText={errors.host ?? 'For example smtp.example.com'} slotProps={{ htmlInput: { maxLength: 190, spellCheck: false } }} />
                                    <Box sx={{ display: 'grid', gap: 2.5, gridTemplateColumns: '1fr 1.4fr' }}>
                                        <TextField label="Port" required type="number" disabled={locked} value={data.port} onChange={(e) => form.setData('port', Number(e.target.value))} error={!!errors.port} helperText={errors.port} />
                                        <TextField select label="Encryption" disabled={locked} value={data.encryption} onChange={(e) => form.setData('encryption', e.target.value)} error={!!errors.encryption} helperText={errors.encryption}>
                                            {Object.entries(encryption).map(([key, label]) => <MenuItem key={key} value={key}>{label}</MenuItem>)}
                                        </TextField>
                                    </Box>
                                    <TextField label="User name" disabled={locked} value={data.username} onChange={(e) => form.setData('username', e.target.value)} error={!!errors.username} helperText={errors.username} autoComplete="off" slotProps={{ htmlInput: { maxLength: 190, spellCheck: false } }} />
                                    <TextField label="Password or key" type="password" disabled={locked} value={data.password} onChange={(e) => form.setData('password', e.target.value)} error={!!errors.password} autoComplete="new-password"
                                        helperText={errors.password ?? (savedPassword ? 'Saved and stored encrypted. Leave empty to keep it.' : 'Stored encrypted and never shown again.')} placeholder={savedPassword ? `Saved: ${savedPassword}` : undefined} slotProps={{ inputLabel: { shrink: true }, htmlInput: { maxLength: 500 } }} />
                                </Box>
                            </>
                        )}
                        <Box sx={two}>
                            <TextField label="Send from this address" required={smtp} type="email" disabled={locked} value={data.from_address} onChange={(e) => form.setData('from_address', e.target.value)} error={!!errors.from_address} helperText={errors.from_address ?? 'Use an address on your own domain, or emails land in spam.'} slotProps={{ htmlInput: { maxLength: 190 } }} />
                            <TextField label="Sender name" disabled={locked} value={data.from_name} onChange={(e) => form.setData('from_name', e.target.value)} error={!!errors.from_name} helperText={errors.from_name ?? 'Empty uses the store name.'} slotProps={{ htmlInput: { maxLength: 80 } }} />
                        </Box>
                    </Stack>
                    {!locked && <Button type="submit" variant="contained" loading={form.processing} disabled={!form.isDirty} sx={{ mt: 2.5 }}>Save email settings</Button>}
                </Card>

                <Card sx={{ p: 3 }}>
                    <Typography variant="h4" component="h2">Send a test email</Typography>
                    <Typography sx={{ color: 'text.secondary', mt: 0.5, mb: 2 }}>
                        Uses the saved settings. {state.last_event_at ? `Last successful test: ${formatDateTime(state.last_event_at)}.` : ''}
                    </Typography>
                    <Box sx={{ display: 'flex', gap: 1.5, flexWrap: 'wrap', alignItems: 'flex-start' }}>
                        <TextField size="small" label="Send to" type="email" value={to} onChange={(e) => setTo(e.target.value)} disabled={!can('integrations', 'edit')} sx={{ flex: '1 1 260px', maxWidth: 360 }} />
                        {can('integrations', 'edit') && <Button variant="outlined" loading={testing} disabled={to.trim() === '' || form.isDirty} onClick={test}>Send test email</Button>}
                    </Box>
                    {form.isDirty && <Typography sx={{ fontSize: 13, color: 'text.secondary', mt: 1 }}>Save your changes first.</Typography>}
                    {result && <Alert severity={result.ok ? 'success' : 'error'} sx={{ mt: 2 }}>{result.message}</Alert>}
                </Card>
            </Stack>
        </AdminLayout>
    );
}
