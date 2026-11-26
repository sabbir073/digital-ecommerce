import { router, useForm } from '@inertiajs/react';
import MoreVert from '@mui/icons-material/MoreVert';
import PersonAddAlt from '@mui/icons-material/PersonAddAlt';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import IconButton from '@mui/material/IconButton';
import Menu from '@mui/material/Menu';
import MenuItem from '@mui/material/MenuItem';
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
import { formatDateTime } from '../../lib/format';

interface Person {
    id: number;
    name: string;
    email: string;
    roleId: number;
    role: string | null;
    isSuper: boolean;
    status: 'invited' | 'active' | 'suspended';
    hasTwoFactor: boolean;
    lastLoginAt: string | null;
    isMe: boolean;
    canManage: boolean;
}

interface RoleOption {
    id: number;
    name: string;
    isSuper: boolean;
    assignable: boolean;
}

const STATUS: Record<Person['status'], { label: string; color: 'success' | 'warning' | 'default' }> = {
    active: { label: 'Active', color: 'success' },
    invited: { label: 'Invited', color: 'warning' },
    suspended: { label: 'Suspended', color: 'default' },
};

type Confirm = { person: Person; kind: 'suspend' | 'remove' | 'sign-out' };

export default function StaffIndex({ staff, roles }: { staff: Person[]; roles: RoleOption[] }) {
    const can = useCan();
    const [inviteOpen, setInviteOpen] = useState(false);
    const [editing, setEditing] = useState<Person | null>(null);
    const [menu, setMenu] = useState<{ anchor: HTMLElement; person: Person } | null>(null);
    const [confirm, setConfirm] = useState<Confirm | null>(null);
    const [busy, setBusy] = useState(false);

    const assignable = roles.filter((role) => role.assignable);
    const invite = useForm({ name: '', email: '', role_id: '' as number | '' });
    const edit = useForm({ name: '', role_id: '' as number | '' });

    const submitInvite = (event: FormEvent) => {
        event.preventDefault();
        invite.post('/admin/staff', {
            preserveScroll: true,
            onSuccess: () => {
                setInviteOpen(false);
                invite.reset();
            },
        });
    };

    const openEdit = (person: Person) => {
        edit.clearErrors();
        edit.setData({ name: person.name, role_id: person.roleId });
        setEditing(person);
    };

    const submitEdit = (event: FormEvent) => {
        event.preventDefault();
        if (!editing) return;
        edit.put(`/admin/staff/${editing.id}`, { preserveScroll: true, onSuccess: () => setEditing(null) });
    };

    const post = (url: string) => router.post(url, {}, { preserveScroll: true });

    const runConfirm = () => {
        if (!confirm) return;
        const options = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => { setBusy(false); setConfirm(null); } };
        if (confirm.kind === 'remove') router.delete(`/admin/staff/${confirm.person.id}`, options);
        else router.post(`/admin/staff/${confirm.person.id}/${confirm.kind}`, {}, options);
    };

    const confirmText: Record<Confirm['kind'], { title: string; text: string; label: string }> = {
        suspend: { title: 'Suspend this person?', text: 'They are signed out at once and cannot sign in until you reactivate them.', label: 'Suspend' },
        remove: { title: 'Remove this person?', text: 'Their account is deleted. Their past actions stay in the activity log.', label: 'Remove' },
        'sign-out': { title: 'Sign this person out?', text: 'Every session they have open ends at once. They can sign in again.', label: 'Sign out' },
    };

    return (
        <AdminLayout
            title="Staff"
            actions={
                can('staff', 'create') && (
                    <Button variant="contained" startIcon={<PersonAddAlt />} onClick={() => setInviteOpen(true)}>
                        Invite person
                    </Button>
                )
            }
        >
            <Card>
                <TableContainer>
                    <Table aria-label="Staff accounts">
                        <TableHead>
                            <TableRow>
                                <TableCell>Person</TableCell>
                                <TableCell>Role</TableCell>
                                <TableCell>Status</TableCell>
                                <TableCell>Two-factor</TableCell>
                                <TableCell>Last sign-in</TableCell>
                                <TableCell align="right"><span style={{ position: 'absolute', left: -9999 }}>Actions</span></TableCell>
                            </TableRow>
                        </TableHead>
                        <TableBody>
                            {staff.map((person) => (
                                <TableRow key={person.id} hover>
                                    <TableCell>
                                        <Typography sx={{ fontWeight: 600 }}>
                                            {person.name} {person.isMe && <Chip label="You" size="small" sx={{ ml: 0.5 }} />}
                                        </Typography>
                                        <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>{person.email}</Typography>
                                    </TableCell>
                                    <TableCell>{person.role}</TableCell>
                                    <TableCell><Chip size="small" label={STATUS[person.status].label} color={STATUS[person.status].color} variant="outlined" /></TableCell>
                                    <TableCell>{person.hasTwoFactor ? 'On' : 'Off'}</TableCell>
                                    <TableCell sx={{ whiteSpace: 'nowrap' }}>{formatDateTime(person.lastLoginAt)}</TableCell>
                                    <TableCell align="right">
                                        {person.canManage && (can('staff', 'edit') || can('staff', 'delete') || can('staff', 'create')) && (
                                            <IconButton aria-label={`Actions for ${person.name}`} onClick={(e) => setMenu({ anchor: e.currentTarget, person })}>
                                                <MoreVert />
                                            </IconButton>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </TableContainer>
            </Card>

            <Menu anchorEl={menu?.anchor} open={!!menu} onClose={() => setMenu(null)}>
                {menu && can('staff', 'edit') && (
                    <MenuItem onClick={() => { openEdit(menu.person); setMenu(null); }}>Edit name and role</MenuItem>
                )}
                {menu && can('staff', 'create') && menu.person.status === 'invited' && (
                    <MenuItem onClick={() => { post(`/admin/staff/${menu.person.id}/resend`); setMenu(null); }}>Send invitation again</MenuItem>
                )}
                {menu && can('staff', 'edit') && menu.person.status === 'active' && (
                    <MenuItem onClick={() => { setConfirm({ person: menu.person, kind: 'sign-out' }); setMenu(null); }}>Sign out everywhere</MenuItem>
                )}
                {menu && can('staff', 'edit') && menu.person.status !== 'suspended' && (
                    <MenuItem onClick={() => { setConfirm({ person: menu.person, kind: 'suspend' }); setMenu(null); }}>Suspend</MenuItem>
                )}
                {menu && can('staff', 'edit') && menu.person.status === 'suspended' && (
                    <MenuItem onClick={() => { post(`/admin/staff/${menu.person.id}/reactivate`); setMenu(null); }}>Reactivate</MenuItem>
                )}
                {menu && can('staff', 'delete') && (
                    <MenuItem sx={{ color: 'error.main' }} onClick={() => { setConfirm({ person: menu.person, kind: 'remove' }); setMenu(null); }}>Remove</MenuItem>
                )}
            </Menu>

            <Dialog open={inviteOpen} onClose={() => setInviteOpen(false)} maxWidth="xs" fullWidth>
                <Box component="form" onSubmit={submitInvite} noValidate>
                    <DialogTitle>Invite a person</DialogTitle>
                    <DialogContent>
                        <Stack spacing={2.5} sx={{ pt: 1 }}>
                            <Typography sx={{ color: 'text.secondary' }}>They get an email with a link to set their password. The link works for 7 days.</Typography>
                            <TextField label="Name" required autoFocus value={invite.data.name} onChange={(e) => invite.setData('name', e.target.value)} error={!!invite.errors.name} helperText={invite.errors.name} />
                            <TextField label="Email" type="email" required value={invite.data.email} onChange={(e) => invite.setData('email', e.target.value)} error={!!invite.errors.email} helperText={invite.errors.email} />
                            <TextField select label="Role" required value={invite.data.role_id} onChange={(e) => invite.setData('role_id', Number(e.target.value))} error={!!invite.errors.role_id} helperText={invite.errors.role_id}>
                                {assignable.map((role) => <MenuItem key={role.id} value={role.id}>{role.name}</MenuItem>)}
                            </TextField>
                        </Stack>
                    </DialogContent>
                    <DialogActions sx={{ px: 3, pb: 3 }}>
                        <Button variant="outlined" onClick={() => setInviteOpen(false)}>Cancel</Button>
                        <Button type="submit" variant="contained" loading={invite.processing}>Send invitation</Button>
                    </DialogActions>
                </Box>
            </Dialog>

            <Dialog open={!!editing} onClose={() => setEditing(null)} maxWidth="xs" fullWidth>
                <Box component="form" onSubmit={submitEdit} noValidate>
                    <DialogTitle>Edit {editing?.name}</DialogTitle>
                    <DialogContent>
                        <Stack spacing={2.5} sx={{ pt: 1 }}>
                            <TextField label="Name" required value={edit.data.name} onChange={(e) => edit.setData('name', e.target.value)} error={!!edit.errors.name} helperText={edit.errors.name} />
                            <TextField select label="Role" required value={edit.data.role_id} onChange={(e) => edit.setData('role_id', Number(e.target.value))} error={!!(edit.errors.role_id || (edit.errors as Record<string, string>).staff)} helperText={edit.errors.role_id ?? (edit.errors as Record<string, string>).staff}>
                                {roles.filter((role) => role.assignable || role.id === editing?.roleId).map((role) => <MenuItem key={role.id} value={role.id}>{role.name}</MenuItem>)}
                            </TextField>
                        </Stack>
                    </DialogContent>
                    <DialogActions sx={{ px: 3, pb: 3 }}>
                        <Button variant="outlined" onClick={() => setEditing(null)}>Cancel</Button>
                        <Button type="submit" variant="contained" loading={edit.processing}>Save</Button>
                    </DialogActions>
                </Box>
            </Dialog>

            {confirm && (
                <ConfirmDialog
                    open
                    title={confirmText[confirm.kind].title}
                    text={`${confirm.person.name}: ${confirmText[confirm.kind].text}`}
                    confirmLabel={confirmText[confirm.kind].label}
                    danger={confirm.kind !== 'sign-out'}
                    busy={busy}
                    onConfirm={runConfirm}
                    onClose={() => setConfirm(null)}
                />
            )}
        </AdminLayout>
    );
}
