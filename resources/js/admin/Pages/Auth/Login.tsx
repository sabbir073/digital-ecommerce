import { useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import FormControlLabel from '@mui/material/FormControlLabel';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import type { FormEvent } from 'react';
import AuthLayout from '../../components/AuthLayout';

export default function Login() {
    const form = useForm({ email: '', password: '', remember: false });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/admin/login', { onFinish: () => form.reset('password') });
    };

    return (
        <AuthLayout title="Sign in" intro="Use your admin account.">
            <Stack component="form" onSubmit={submit} spacing={2.5} noValidate>
                <TextField
                    label="Email"
                    type="email"
                    autoComplete="username"
                    autoFocus
                    required
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                    error={!!form.errors.email}
                    helperText={form.errors.email}
                />
                <TextField
                    label="Password"
                    type="password"
                    autoComplete="current-password"
                    required
                    value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                    error={!!form.errors.password}
                    helperText={form.errors.password}
                />
                <FormControlLabel
                    control={<Checkbox checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} />}
                    label="Keep me signed in on this device"
                />
                <Button type="submit" variant="contained" size="large" loading={form.processing}>
                    Sign in
                </Button>
            </Stack>
        </AuthLayout>
    );
}
