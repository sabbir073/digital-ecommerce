import { router, useForm } from '@inertiajs/react';
import Add from '@mui/icons-material/Add';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Checkbox from '@mui/material/Checkbox';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { type FormEvent, useState } from 'react';
import AdminLayout from '../../components/AdminLayout';
import ConfirmDialog from '../../components/ConfirmDialog';
import { useCan } from '../../lib/can';
import type { PermissionMap } from '../../lib/types';

interface Role {
    id: number;
    name: string;
    description: string | null;
    isSystem: boolean;
    isSuper: boolean;
    people: number;
    permissions: PermissionMap;
}

interface Props {
    roles: Role[];
    modules: Record<string, string>;
    actions: string[];
}

type FormData = { name: string; description: string; permissions: PermissionMap };

const capital = (text: string) => text.charAt(0).toUpperCase() + text.slice(1);

export default function RolesIndex({ roles, modules, actions }: Props) {
    const can = useCan();
    // null = closed, 'new' = create, Role = view or edit that role
    const [open, setOpen] = useState<Role | 'new' | null>(null);
    const [deleting, setDeleting] = useState<Role | null>(null);
    const [busy, setBusy] = useState(false);
    const form = useForm<FormData>({ name: '', description: '', permissions: {} });

    const current = open !== 'new' ? open : null;
    const readOnly = !!current && (current.isSystem || !can('staff', 'edit'));

    const openRole = (role: Role | 'new', copyFrom?: Role) => {
        form.clearErrors();
        const source = role === 'new' ? copyFrom : role;
        form.setData({
            name: role === 'new' ? (copyFrom ? `${copyFrom.name} copy` : '') : role.name,
            description: source?.description ?? '',
            permissions: source ? JSON.parse(JSON.stringify(source.permissions)) : {},
        });
        setOpen(role);
    };

    const has = (module: string, action: string) =>
        current?.isSuper ? true : !!form.data.permissions[module]?.includes(action);

    const toggle = (module: string, action: string) => {
        const set = new Set(form.data.permissions[module] ?? []);
        if (set.has(action)) set.delete(action);
        else set.add(action);
        form.setData('permissions', { ...form.data.permissions, [module]: [...set] });
    };

    const toggleRow = (module: string) => {
        const all = (form.data.permissions[module]?.length ?? 0) === actions.length;
        form.setData('permissions', { ...form.data.permissions, [module]: all ? [] : [...actions] });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setOpen(null) };
        if (open === 'new') form.post('/admin/roles', options);
        else if (open) form.put(`/admin/roles/${open.id}`, options);
    };

    const errors = form.errors as Record<string, string>;

    return (
        <AdminLayout
            title="Roles"
            actions={can('staff', 'create') && <Button variant="contained" startIcon={<Add />} onClick={() => openRole('new')}>New role</Button>}
        >
            <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: 'repeat(2, 1fr)', lg: 'repeat(3, 1fr)' } }}>
                {roles.map((role) => (
                    <Card key={role.id} sx={{ display: 'flex', flexDirection: 'column' }}>
                        <CardContent sx={{ flex: 1 }}>
                            <Stack direction="row" spacing={1} sx={{ alignItems: 'center', mb: 1 }}>
                                <Typography variant="h3" component="h2" sx={{ flex: 1 }}>{role.name}</Typography>
                                <Chip size="small" label={role.isSystem ? 'Built-in' : 'Custom'} color={role.isSystem ? 'default' : 'primary'} variant="outlined" />
                            </Stack>
                            <Typography sx={{ color: 'text.secondary', mb: 1.5 }}>{role.description ?? 'No description.'}</Typography>
                            <Typography sx={{ fontSize: 13, color: 'text.secondary' }}>
                                {role.people} {role.people === 1 ? 'person' : 'people'}
                            </Typography>
                        </CardContent>
                        <Stack direction="row" spacing={1} sx={{ p: 2, pt: 0, flexWrap: 'wrap', rowGap: 1 }}>
                            <Button size="small" variant="outlined" onClick={() => openRole(role)}>
                                {role.isSystem || !can('staff', 'edit') ? 'View access' : 'Edit'}
                            </Button>
                            {can('staff', 'create') && !role.isSuper && (
                                <Button size="small" variant="text" onClick={() => openRole('new', role)}>Duplicate</Button>
                            )}
                            {can('staff', 'delete') && !role.isSystem && (
                                <Button size="small" variant="text" color="error" onClick={() => setDeleting(role)}>Delete</Button>
                            )}
                        </Stack>
                    </Card>
                ))}
            </Box>

            <Dialog open={!!open} onClose={() => setOpen(null)} maxWidth="md" fullWidth>
                <Box component="form" onSubmit={submit} noValidate>
                    <DialogTitle>{open === 'new' ? 'New role' : current?.name}</DialogTitle>
                    <DialogContent>
                        <Stack spacing={2.5} sx={{ pt: 1 }}>
                            {readOnly ? (
                                <Typography sx={{ color: 'text.secondary' }}>
                                    {current?.isSuper
                                        ? 'Super Admins can do everything, including managing other Super Admins and payment keys.'
                                        : current?.isSystem
                                          ? 'This is a built-in role and cannot be changed. Duplicate it to make your own version.'
                                          : 'You can view this role but not change it.'}
                                </Typography>
                            ) : (
                                <>
                                    <TextField label="Role name" required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} error={!!errors.name} helperText={errors.name} />
                                    <TextField label="Description" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} error={!!errors.description} helperText={errors.description} />
                                </>
                            )}
                            <TableContainer sx={{ border: 1, borderColor: 'divider', borderRadius: 3 }}>
                                <Table size="small" aria-label="Permissions">
                                    <TableHead>
                                        <TableRow>
                                            <TableCell>Area</TableCell>
                                            {actions.map((action) => <TableCell key={action} align="center">{capital(action)}</TableCell>)}
                                        </TableRow>
                                    </TableHead>
                                    <TableBody>
                                        {Object.entries(modules).map(([module, label]) => (
                                            <TableRow key={module} hover>
                                                <TableCell component="th" scope="row">
                                                    {readOnly ? label : (
                                                        <Button variant="text" size="small" onClick={() => toggleRow(module)} sx={{ minHeight: 32, minWidth: 0, px: 1, ml: -1, color: 'text.primary', justifyContent: 'flex-start' }} aria-label={`Toggle all for ${label}`}>
                                                            {label}
                                                        </Button>
                                                    )}
                                                </TableCell>
                                                {actions.map((action) => (
                                                    <TableCell key={action} align="center" padding="checkbox">
                                                        <Checkbox
                                                            checked={has(module, action)}
                                                            disabled={readOnly}
                                                            onChange={() => toggle(module, action)}
                                                            slotProps={{ input: { 'aria-label': `${label}: ${action}` } }}
                                                        />
                                                    </TableCell>
                                                ))}
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </TableContainer>
                        </Stack>
                    </DialogContent>
                    <DialogActions sx={{ px: 3, pb: 3 }}>
                        <Button variant="outlined" onClick={() => setOpen(null)}>{readOnly ? 'Close' : 'Cancel'}</Button>
                        {!readOnly && <Button type="submit" variant="contained" loading={form.processing}>{open === 'new' ? 'Create role' : 'Save role'}</Button>}
                    </DialogActions>
                </Box>
            </Dialog>

            {deleting && (
                <ConfirmDialog
                    open
                    title="Delete this role?"
                    text={deleting.people > 0 ? `${deleting.name} still has ${deleting.people} people. Move them to another role first.` : `${deleting.name} will be deleted. This cannot be undone.`}
                    confirmLabel="Delete"
                    danger
                    busy={busy}
                    onConfirm={() =>
                        router.delete(`/admin/roles/${deleting.id}`, {
                            preserveScroll: true,
                            onStart: () => setBusy(true),
                            onFinish: () => { setBusy(false); setDeleting(null); },
                        })
                    }
                    onClose={() => setDeleting(null)}
                />
            )}
        </AdminLayout>
    );
}
