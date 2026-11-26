import { router, useForm, usePage } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import type { FormEvent } from 'react';
import AdminLayout from '../../components/AdminLayout';
import type { SharedProps } from '../../lib/types';

interface Props {
    passwordHint: string;
    twoFactor: { enabled: boolean; setup: { secret: string; qrSvg: string } | null };
    recoveryCodes: string[] | null;
}

export default function AccountEdit({ passwordHint, twoFactor, recoveryCodes }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const password = useForm({ current_password: '', password: '', password_confirmation: '' });
    const confirm = useForm({ code: '' });
    const disable = useForm({ password: '' });

    const submitPassword = (event: FormEvent) => {
        event.preventDefault();
        password.put('/admin/account/password', { preserveScroll: true, onSuccess: () => password.reset() });
    };

    const submitConfirm = (event: FormEvent) => {
        event.preventDefault();
        confirm.post('/admin/account/two-factor/confirm', { preserveScroll: true, onSuccess: () => confirm.reset() });
    };

    const submitDisable = (event: FormEvent) => {
        event.preventDefault();
        disable.delete('/admin/account/two-factor', { preserveScroll: true, onSuccess: () => disable.reset() });
    };

    return (
        <AdminLayout title="My account">
            <Stack spacing={3} sx={{ maxWidth: 640 }}>
                <Card>
                    <CardContent>
                        <Typography variant="h3" component="h2">{auth.user?.name}</Typography>
                        <Typography sx={{ color: 'text.secondary' }}>{auth.user?.email} · {auth.user?.role}</Typography>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent component="form" onSubmit={submitPassword} noValidate>
                        <Typography variant="h3" component="h2" sx={{ mb: 2.5 }}>Change password</Typography>
                        <Stack spacing={2.5}>
                            <TextField label="Current password" type="password" autoComplete="current-password" required value={password.data.current_password} onChange={(e) => password.setData('current_password', e.target.value)} error={!!password.errors.current_password} helperText={password.errors.current_password} />
                            <TextField label="New password" type="password" autoComplete="new-password" required value={password.data.password} onChange={(e) => password.setData('password', e.target.value)} error={!!password.errors.password} helperText={password.errors.password ?? passwordHint} />
                            <TextField label="Repeat new password" type="password" autoComplete="new-password" required value={password.data.password_confirmation} onChange={(e) => password.setData('password_confirmation', e.target.value)} />
                            <Button type="submit" variant="contained" loading={password.processing} sx={{ alignSelf: 'flex-start' }}>Change password</Button>
                        </Stack>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent>
                        <Stack direction="row" spacing={1.5} sx={{ alignItems: 'center', mb: 1 }}>
                            <Typography variant="h3" component="h2">Two-factor sign-in</Typography>
                            <Chip size="small" label={twoFactor.enabled ? 'On' : 'Off'} color={twoFactor.enabled ? 'success' : 'default'} variant="outlined" />
                        </Stack>
                        <Typography sx={{ color: 'text.secondary', mb: 2.5 }}>
                            After your password, you type a 6-digit code from an authenticator app such as Google Authenticator or 1Password.
                        </Typography>

                        {recoveryCodes && (
                            <Alert severity="warning" sx={{ mb: 2.5 }}>
                                <Typography sx={{ fontWeight: 600, mb: 1 }}>Save these recovery codes now. They are shown only once.</Typography>
                                <Box component="ul" sx={{ m: 0, pl: 0, listStyle: 'none', display: 'grid', gridTemplateColumns: 'repeat(2, max-content)', columnGap: 4, fontFamily: 'monospace' }}>
                                    {recoveryCodes.map((code) => <li key={code}>{code}</li>)}
                                </Box>
                            </Alert>
                        )}

                        {!twoFactor.enabled && !twoFactor.setup && (
                            <Button variant="contained" onClick={() => router.post('/admin/account/two-factor', {}, { preserveScroll: true })}>
                                Set up two-factor
                            </Button>
                        )}

                        {!twoFactor.enabled && twoFactor.setup && (
                            <Stack component="form" onSubmit={submitConfirm} spacing={2.5} noValidate>
                                <Typography>1. Scan this code with your authenticator app, or type the key by hand.</Typography>
                                <Box role="img" aria-label="QR code for your authenticator app" sx={{ width: 200, height: 200, '& svg': { width: '100%', height: '100%' } }} dangerouslySetInnerHTML={{ __html: twoFactor.setup.qrSvg }} />
                                <Typography sx={{ fontFamily: 'monospace', wordBreak: 'break-all' }}>{twoFactor.setup.secret}</Typography>
                                <Typography>2. Type the 6-digit code the app shows.</Typography>
                                <TextField label="Code" autoComplete="one-time-code" required value={confirm.data.code} onChange={(e) => confirm.setData('code', e.target.value)} error={!!confirm.errors.code} helperText={confirm.errors.code} sx={{ maxWidth: 220 }} slotProps={{ htmlInput: { inputMode: 'numeric' } }} />
                                <Button type="submit" variant="contained" loading={confirm.processing} sx={{ alignSelf: 'flex-start' }}>Turn on</Button>
                            </Stack>
                        )}

                        {twoFactor.enabled && (
                            <Stack component="form" onSubmit={submitDisable} spacing={2.5} noValidate>
                                <TextField label="Password" type="password" autoComplete="current-password" required value={disable.data.password} onChange={(e) => disable.setData('password', e.target.value)} error={!!disable.errors.password} helperText={disable.errors.password ?? 'Type your password to turn two-factor off.'} />
                                <Button type="submit" variant="outlined" color="error" loading={disable.processing} sx={{ alignSelf: 'flex-start' }}>Turn off two-factor</Button>
                            </Stack>
                        )}
                    </CardContent>
                </Card>
            </Stack>
        </AdminLayout>
    );
}
