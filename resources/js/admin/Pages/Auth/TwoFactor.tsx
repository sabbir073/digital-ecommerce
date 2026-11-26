import { useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import type { FormEvent } from 'react';
import AuthLayout from '../../components/AuthLayout';
import RouterLink from '../../components/RouterLink';

export default function TwoFactor() {
    const form = useForm({ code: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/admin/two-factor');
    };

    return (
        <AuthLayout title="Enter your code" intro="Open your authenticator app and type the 6-digit code. You can also use a recovery code.">
            <Stack component="form" onSubmit={submit} spacing={2.5} noValidate>
                <TextField
                    label="Code"
                    autoComplete="one-time-code"
                    autoFocus
                    required
                    value={form.data.code}
                    onChange={(e) => form.setData('code', e.target.value)}
                    error={!!form.errors.code}
                    helperText={form.errors.code}
                    slotProps={{ htmlInput: { inputMode: 'text', 'aria-label': 'Authentication code' } }}
                />
                <Button type="submit" variant="contained" size="large" loading={form.processing}>
                    Continue
                </Button>
                <Button component={RouterLink} href="/admin/login" variant="text">
                    Back to sign in
                </Button>
            </Stack>
        </AuthLayout>
    );
}
