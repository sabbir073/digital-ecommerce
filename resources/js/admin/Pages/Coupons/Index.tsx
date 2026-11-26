import { router, useForm } from '@inertiajs/react';
import Add from '@mui/icons-material/Add';
import DeleteOutlined from '@mui/icons-material/DeleteOutlined';
import EditOutlined from '@mui/icons-material/EditOutlined';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { type FormEvent, useState } from 'react';
import AdminLayout from '../../components/AdminLayout';
import ConfirmDialog from '../../components/ConfirmDialog';
import { useCan } from '../../lib/can';

interface Coupon {
    id: number;
    code: string;
    type: 'percent' | 'fixed';
    value: string;
    minSpend: string;
    startsAt: string | null;
    endsAt: string | null;
    usageLimit: number | null;
    usedCount: number;
    sellingPages: boolean;
    active: boolean;
    state: string;
}

type FormData = {
    code: string;
    type: 'percent' | 'fixed';
    value: string;
    min_spend: string;
    starts_at: string;
    ends_at: string;
    usage_limit: string;
    applies_to_selling_pages: boolean;
    is_active: boolean;
};

const EMPTY: FormData = { code: '', type: 'percent', value: '', min_spend: '', starts_at: '', ends_at: '', usage_limit: '', applies_to_selling_pages: true, is_active: true };

const STATE_COLOR: Record<string, 'success' | 'default' | 'warning'> = { Active: 'success', Scheduled: 'warning' };

export default function CouponsIndex({ coupons }: { coupons: Coupon[] }) {
    const can = useCan();
    const [open, setOpen] = useState<Coupon | 'new' | null>(null);
    const [deleting, setDeleting] = useState<Coupon | null>(null);
    const [busy, setBusy] = useState(false);
    const form = useForm<FormData>(EMPTY);
    const errors = form.errors as Record<string, string>;
    const data = form.data;

    const openForm = (coupon: Coupon | 'new') => {
        form.clearErrors();
        form.setData(
            coupon === 'new'
                ? EMPTY
                : {
                      code: coupon.code,
                      type: coupon.type,
                      value: coupon.value,
                      min_spend: coupon.minSpend,
                      starts_at: coupon.startsAt ?? '',
                      ends_at: coupon.endsAt ?? '',
                      usage_limit: coupon.usageLimit?.toString() ?? '',
                      applies_to_selling_pages: coupon.sellingPages,
                      is_active: coupon.active,
                  },
        );
        setOpen(coupon);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((d) => ({ ...d, min_spend: d.min_spend || null, starts_at: d.starts_at || null, ends_at: d.ends_at || null, usage_limit: d.usage_limit || null }));
        const options = { preserveScroll: true, onSuccess: () => setOpen(null) };
        if (open === 'new') form.post('/admin/coupons', options);
        else if (open) form.put(`/admin/coupons/${open.id}`, options);
    };

    const describe = (c: Coupon) => (c.type === 'percent' ? `${c.value}% off` : `$${c.value} off`);

    return (
        <AdminLayout title="Coupons" actions={can('coupons', 'create') && <Button variant="contained" startIcon={<Add />} onClick={() => openForm('new')}>New coupon</Button>}>
            {coupons.length === 0 ? (
                <Card sx={{ p: 6, textAlign: 'center' }}>
                    <Typography variant="h4" component="p" sx={{ mb: 0.5 }}>No coupons yet</Typography>
                    <Typography sx={{ color: 'text.secondary' }}>Coupons work in the cart checkout and on selling pages.</Typography>
                </Card>
            ) : (
                <Card>
                    <TableContainer>
                        <Table>
                            <TableHead>
                                <TableRow>
                                    <TableCell>Code</TableCell>
                                    <TableCell>Discount</TableCell>
                                    <TableCell>Status</TableCell>
                                    <TableCell>Used</TableCell>
                                    <TableCell>Ends</TableCell>
                                    <TableCell align="right"><span style={{ position: 'absolute', left: -9999 }}>Actions</span></TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {coupons.map((coupon) => (
                                    <TableRow key={coupon.id} hover>
                                        <TableCell sx={{ fontWeight: 600, fontFamily: 'monospace', fontSize: 14 }}>{coupon.code}</TableCell>
                                        <TableCell>
                                            {describe(coupon)}
                                            {coupon.minSpend && <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>Min spend ${coupon.minSpend}</Typography>}
                                        </TableCell>
                                        <TableCell><Chip size="small" variant="outlined" label={coupon.state} color={STATE_COLOR[coupon.state] ?? 'default'} /></TableCell>
                                        <TableCell>{coupon.usedCount}{coupon.usageLimit !== null ? ` of ${coupon.usageLimit}` : ''}</TableCell>
                                        <TableCell>{coupon.endsAt ? new Date(coupon.endsAt).toLocaleDateString(undefined, { dateStyle: 'medium' }) : 'No end date'}</TableCell>
                                        <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
                                            {can('coupons', 'edit') && <Tooltip title="Edit"><IconButton aria-label={`Edit ${coupon.code}`} onClick={() => openForm(coupon)}><EditOutlined fontSize="small" /></IconButton></Tooltip>}
                                            {can('coupons', 'delete') && <Tooltip title="Delete"><IconButton aria-label={`Delete ${coupon.code}`} onClick={() => setDeleting(coupon)}><DeleteOutlined fontSize="small" /></IconButton></Tooltip>}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </TableContainer>
                </Card>
            )}

            <Dialog open={open !== null} onClose={() => setOpen(null)} maxWidth="sm" fullWidth>
                <form onSubmit={submit} noValidate>
                    <DialogTitle>{open === 'new' ? 'New coupon' : 'Edit coupon'}</DialogTitle>
                    <DialogContent>
                        <Stack spacing={2.5} sx={{ pt: 1 }}>
                            <TextField label="Code" required autoFocus value={data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} error={!!errors.code} helperText={errors.code ?? 'What the customer types, for example WELCOME10.'} slotProps={{ htmlInput: { maxLength: 40, style: { fontFamily: 'monospace' } } }} />
                            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                                <TextField select label="Type" value={data.type} onChange={(e) => form.setData('type', e.target.value as FormData['type'])} sx={{ flex: 1 }}>
                                    <MenuItem value="percent">Percentage off</MenuItem>
                                    <MenuItem value="fixed">Fixed amount off</MenuItem>
                                </TextField>
                                <TextField label={data.type === 'percent' ? 'Percent' : 'Amount'} required value={data.value} onChange={(e) => form.setData('value', e.target.value)} error={!!errors.value} helperText={errors.value} sx={{ flex: 1 }}
                                    slotProps={{ htmlInput: { inputMode: 'decimal' }, input: data.type === 'percent' ? { endAdornment: <InputAdornment position="end">%</InputAdornment> } : { startAdornment: <InputAdornment position="start">$</InputAdornment> } }} />
                            </Stack>
                            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                                <TextField label="Minimum spend" value={data.min_spend} onChange={(e) => form.setData('min_spend', e.target.value)} error={!!errors.min_spend} helperText={errors.min_spend ?? 'Leave empty for none.'} sx={{ flex: 1 }} slotProps={{ htmlInput: { inputMode: 'decimal' }, input: { startAdornment: <InputAdornment position="start">$</InputAdornment> } }} />
                                <TextField label="Total uses allowed" value={data.usage_limit} onChange={(e) => form.setData('usage_limit', e.target.value.replace(/\D/g, ''))} error={!!errors.usage_limit} helperText={errors.usage_limit ?? 'Leave empty for no limit.'} sx={{ flex: 1 }} slotProps={{ htmlInput: { inputMode: 'numeric' } }} />
                            </Stack>
                            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                                <TextField label="Starts" type="datetime-local" value={data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} error={!!errors.starts_at} helperText={errors.starts_at} sx={{ flex: 1 }} slotProps={{ inputLabel: { shrink: true } }} />
                                <TextField label="Ends" type="datetime-local" value={data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} error={!!errors.ends_at} helperText={errors.ends_at} sx={{ flex: 1 }} slotProps={{ inputLabel: { shrink: true } }} />
                            </Stack>
                            <FormControlLabel control={<Switch checked={data.applies_to_selling_pages} onChange={(e) => form.setData('applies_to_selling_pages', e.target.checked)} />} label="Also works on selling pages" />
                            <FormControlLabel control={<Switch checked={data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />} label="Active" />
                        </Stack>
                    </DialogContent>
                    <DialogActions sx={{ px: 3, pb: 3 }}>
                        <Button variant="outlined" onClick={() => setOpen(null)}>Cancel</Button>
                        <Button type="submit" variant="contained" loading={form.processing}>Save</Button>
                    </DialogActions>
                </form>
            </Dialog>

            <ConfirmDialog
                open={deleting !== null}
                title="Delete this coupon?"
                text={`${deleting?.code ?? ''} will stop working at once. Past orders keep their discount.`}
                confirmLabel="Delete"
                danger
                busy={busy}
                onClose={() => setDeleting(null)}
                onConfirm={() => {
                    if (!deleting) return;
                    setBusy(true);
                    router.delete(`/admin/coupons/${deleting.id}`, { preserveScroll: true, onFinish: () => { setBusy(false); setDeleting(null); } });
                }}
            />
        </AdminLayout>
    );
}
