import { useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import FormControlLabel from '@mui/material/FormControlLabel';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import type { FormEvent } from 'react';
import AdminLayout from '../../components/AdminLayout';
import { useCan } from '../../lib/can';

interface Policy {
    min_length: number;
    mixed_case: boolean;
    numbers: boolean;
    symbols: boolean;
}

export default function Security({ policy }: { policy: Policy }) {
    const can = useCan();
    const editable = can('staff', 'edit');
    const form = useForm<Policy>(policy);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put('/admin/staff-security', { preserveScroll: true });
    };

    const toggle = (key: 'mixed_case' | 'numbers' | 'symbols', label: string) => (
        <FormControlLabel
            control={<Switch checked={form.data[key]} disabled={!editable} onChange={(e) => form.setData(key, e.target.checked)} />}
            label={label}
        />
    );

    return (
        <AdminLayout title="Sign-in security">
            <Card sx={{ maxWidth: 640 }}>
                <CardContent component="form" onSubmit={submit} noValidate>
                    <Typography variant="h3" component="h2" sx={{ mb: 0.5 }}>Password policy</Typography>
                    <Typography sx={{ color: 'text.secondary', mb: 3 }}>
                        These rules apply to every admin password that is set or changed from now on.
                    </Typography>
                    <Stack spacing={2}>
                        <TextField
                            label="Minimum length"
                            type="number"
                            disabled={!editable}
                            value={form.data.min_length}
                            onChange={(e) => form.setData('min_length', Number(e.target.value))}
                            error={!!form.errors.min_length}
                            helperText={form.errors.min_length ?? 'Between 8 and 64 characters.'}
                            slotProps={{ htmlInput: { min: 8, max: 64 } }}
                            sx={{ maxWidth: 220 }}
                        />
                        {toggle('mixed_case', 'Require upper and lower case letters')}
                        {toggle('numbers', 'Require a number')}
                        {toggle('symbols', 'Require a symbol')}
                        {editable && (
                            <Button type="submit" variant="contained" loading={form.processing} sx={{ alignSelf: 'flex-start' }}>
                                Save policy
                            </Button>
                        )}
                    </Stack>
                </CardContent>
            </Card>
        </AdminLayout>
    );
}
