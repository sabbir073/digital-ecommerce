import { useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import type { FormEvent } from 'react';
import AuthLayout from '../../components/AuthLayout';

export default function AcceptInvite({ token, name, email, passwordHint }: { token: string; name: string; email: string; passwordHint: string }) {
    const form = useForm({ password: '', password_confirmation: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/admin/invite/${token}`);
    };

    return (
        <AuthLayout title={`Welcome, ${name}`} intro={`Set a password for ${email}.`}>
            <Stack component="form" onSubmit={submit} spacing={2.5} noValidate>
                <TextField
                    label="Password"
                    type="password"
                    autoComplete="new-password"
                    autoFocus
                    required
                    value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                    error={!!form.errors.password}
                    helperText={form.errors.password ?? passwordHint}
                />
                <TextField
                    label="Repeat password"
                    type="password"
                    autoComplete="new-password"
                    required
                    value={form.data.password_confirmation}
                    onChange={(e) => form.setData('password_confirmation', e.target.value)}
                />
                <Button type="submit" variant="contained" size="large" loading={form.processing}>
                    Set password
                </Button>
            </Stack>
        </AuthLayout>
    );
}
