import { router, useForm } from '@inertiajs/react';
import ArrowBack from '@mui/icons-material/ArrowBack';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import Divider from '@mui/material/Divider';
import InputAdornment from '@mui/material/InputAdornment';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { type FormEvent, type ReactNode, useState } from 'react';
import AdminLayout from '../../components/AdminLayout';
import ConfirmDialog from '../../components/ConfirmDialog';
import RouterLink from '../../components/RouterLink';
import { useCan } from '../../lib/can';
import { formatDateTime, formatMoney } from '../../lib/format';
import { STATUS_COLOR } from './Index';

interface Download {
    file: string | null;
    used: number;
    max: number;
    expiresAt: string | null;
    revoked: boolean;
}

interface Order {
    id: number;
    number: string;
    email: string;
    phone: string | null;
    customerNote: string | null;
    name: string | null;
    status: string;
    statusLabel: string;
    source: string;
    sellingPage: { id: number; name: string; deleted: boolean } | null;
    country: string | null;
    postcode: string | null;
    couponCode: string | null;
    subtotal: number;
    discount: number;
    tax: number;
    total: number;
    refunded: number;
    createdAt: string | null;
    paidAt: string | null;
    items: { id: number; name: string; variant: string | null; sku: string | null; unit: number; quantity: number; total: number; productId: number | null; downloads: Download[] }[];
    payment: {
        intent: string;
        status: string;
        brand: string | null;
        last4: string | null;
        failure: string | null;
        refunds: { id: string; amount: number; reason: string | null; at: string | null }[];
        disputes: { id: string; status: string; amount: number; reason: string | null }[];
    } | null;
    events: { type: string; note: string | null; at: string | null; by: string | null }[];
    refundable: number;
    wasPaid: boolean;
    hasDownloads: boolean;
}

const EVENT_LABEL: Record<string, string> = {
    created: 'Checkout started',
    paid: 'Paid',
    payment_failed: 'Payment failed',
    cancelled: 'Cancelled',
    refunded: 'Refund',
    dispute_opened: 'Dispute opened',
    email_queued: 'Email',
    email_failed: 'Email problem',
    amount_mismatch: 'Amount mismatch',
    tax_not_recorded: 'Tax not recorded',
    refund_failed: 'Refund not accepted',
    downloads_renewed: 'Downloads',
    note: 'Note',
};

function Row({ label, children, strong }: { label: string; children: ReactNode; strong?: boolean }) {
    return (
        <Box sx={{ display: 'flex', justifyContent: 'space-between', gap: 2, py: 0.5 }}>
            <Typography sx={{ color: strong ? 'text.primary' : 'text.secondary', fontWeight: strong ? 600 : 400 }}>{label}</Typography>
            <Typography sx={{ fontWeight: strong ? 700 : 400, textAlign: 'right' }}>{children}</Typography>
        </Box>
    );
}

function RefundDialog({ order, open, onClose }: { order: Order; open: boolean; onClose: () => void }) {
    const form = useForm({ amount: (order.refundable / 100).toFixed(2), reason: '' });
    const cents = Math.round(Number(form.data.amount) * 100);
    const full = cents >= order.total - order.refunded && cents === order.refundable;

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(`/admin/orders/${order.id}/refund`, { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth>
            <form onSubmit={submit}>
                <DialogTitle>Refund order {order.number}</DialogTitle>
                <DialogContent>
                    <Stack sx={{ gap: 2.5, pt: 1 }}>
                        <Typography sx={{ color: 'text.secondary' }}>The money goes back to the customer's card through Stripe. This cannot be undone.</Typography>
                        <TextField
                            label="Amount" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} autoFocus
                            error={!!form.errors.amount} helperText={form.errors.amount ?? `Up to ${formatMoney(order.refundable)} can be refunded.`}
                            slotProps={{ input: { startAdornment: <InputAdornment position="start">$</InputAdornment> }, htmlInput: { inputMode: 'decimal' } }}
                        />
                        <TextField label="Reason (for your records)" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} error={!!form.errors.reason} helperText={form.errors.reason} slotProps={{ htmlInput: { maxLength: 200 } }} />
                        {order.hasDownloads && (
                            <Alert severity={full ? 'warning' : 'info'}>
                                {full ? 'This refunds the order in full, so its download links stop working.' : 'A partial refund keeps the download links working.'}
                            </Alert>
                        )}
                    </Stack>
                </DialogContent>
                <DialogActions sx={{ px: 3, pb: 3 }}>
                    <Button variant="outlined" onClick={onClose}>Cancel</Button>
                    <Button type="submit" variant="contained" color="error" loading={form.processing} disabled={!(cents > 0)}>Refund {cents > 0 ? formatMoney(cents) : ''}</Button>
                </DialogActions>
            </form>
        </Dialog>
    );
}

export default function OrderShow({ order }: { order: Order }) {
    const dispute = order.payment?.disputes[0];
    const can = useCan();
    const canEdit = can('orders', 'edit');
    const [refunding, setRefunding] = useState(false);
    const [confirm, setConfirm] = useState<'cancel' | 'renew' | 'resend' | null>(null);
    const [busy, setBusy] = useState(false);
    const note = useForm({ note: '' });

    const CONFIRM = {
        cancel: { title: 'Cancel this order?', text: 'The unpaid order is closed and any stock held for it is released.', label: 'Cancel order', url: 'cancel', danger: true },
        renew: { title: 'Renew the download links?', text: 'Every file of this order can be downloaded again from the start, and the time limit begins again.', label: 'Renew links', url: 'downloads/renew', danger: false },
        resend: { title: 'Send the confirmation email again?', text: `The email with the download links goes to ${order.email}.`, label: 'Send email', url: 'resend', danger: false },
    };

    function run() {
        if (!confirm) return;
        setBusy(true);
        router.post(`/admin/orders/${order.id}/${CONFIRM[confirm].url}`, {}, { preserveScroll: true, onFinish: () => { setBusy(false); setConfirm(null); } });
    }

    const canCancel = canEdit && !order.wasPaid && order.status !== 'cancelled';

    return (
        <AdminLayout
            title={`Order ${order.number}`}
            actions={<Button component={RouterLink} href="/admin/orders" variant="outlined" startIcon={<ArrowBack />}>Orders</Button>}
        >
            <Stack spacing={1} direction="row" sx={{ mb: 3, alignItems: 'center', flexWrap: 'wrap' }}>
                <Chip variant="outlined" label={order.statusLabel} color={STATUS_COLOR[order.status] ?? 'default'} />
                <Typography sx={{ color: 'text.secondary' }}>
                    Placed {formatDateTime(order.createdAt)} from {order.source === 'selling_page' ? (order.sellingPage ? `the selling page “${order.sellingPage.name}”${order.sellingPage.deleted ? ' (deleted)' : ''}` : 'a selling page') : 'the cart'}
                    {order.paidAt ? ` · paid ${formatDateTime(order.paidAt)}` : ''}
                </Typography>
            </Stack>

            {(canEdit || can('refunds', 'create')) && (order.wasPaid || canCancel) && (
                <Stack direction="row" sx={{ gap: 1.5, mb: 3, flexWrap: 'wrap' }}>
                    {canEdit && order.wasPaid && <Button variant="outlined" onClick={() => setConfirm('resend')}>Send confirmation again</Button>}
                    {canEdit && order.wasPaid && order.hasDownloads && order.status !== 'refunded' && <Button variant="outlined" onClick={() => setConfirm('renew')}>Renew download links</Button>}
                    {can('refunds', 'create') && order.refundable > 0 && <Button variant="outlined" color="error" onClick={() => setRefunding(true)}>Refund</Button>}
                    {canCancel && <Button variant="outlined" color="error" onClick={() => setConfirm('cancel')}>Cancel order</Button>}
                </Stack>
            )}

            {dispute && <Alert severity="warning" sx={{ mb: 3 }}>The customer's bank opened a dispute for {formatMoney(dispute.amount)} ({dispute.status.replace(/_/g, ' ')}). Respond in your Stripe dashboard.</Alert>}
            {order.payment?.failure && order.status === 'payment_failed' && <Alert severity="error" sx={{ mb: 3 }}>{order.payment.failure}</Alert>}

            <Box sx={{ display: 'grid', gap: 3, gridTemplateColumns: { xs: '1fr', lg: '1fr 360px' }, alignItems: 'start' }}>
                <Stack spacing={3}>
                    <Card sx={{ p: 3 }}>
                        <Typography variant="h4" component="h2" sx={{ mb: 2 }}>Items</Typography>
                        <Stack divider={<Divider />} spacing={2}>
                            {order.items.map((item) => (
                                <Box key={item.id}>
                                    <Box sx={{ display: 'flex', justifyContent: 'space-between', gap: 2 }}>
                                        <Box>
                                            <Typography sx={{ fontWeight: 600 }}>{item.name}</Typography>
                                            <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>
                                                {[item.variant, item.sku ? `SKU ${item.sku}` : null, `${formatMoney(item.unit)} × ${item.quantity}`].filter(Boolean).join(' · ')}
                                            </Typography>
                                        </Box>
                                        <Typography sx={{ fontWeight: 600 }}>{formatMoney(item.total)}</Typography>
                                    </Box>
                                    {item.downloads.map((d, index) => (
                                        <Typography key={index} sx={{ color: 'text.secondary', fontSize: 13, mt: 0.5 }}>
                                            {d.file ?? 'File removed'}: downloaded {d.used} of {d.max} times
                                            {d.revoked ? ' · link cancelled' : d.expiresAt ? ` · link works until ${formatDateTime(d.expiresAt)}` : ''}
                                        </Typography>
                                    ))}
                                </Box>
                            ))}
                        </Stack>
                        <Divider sx={{ my: 2 }} />
                        <Row label="Subtotal">{formatMoney(order.subtotal)}</Row>
                        {order.discount > 0 && <Row label={`Discount${order.couponCode ? ` (${order.couponCode})` : ''}`}>-{formatMoney(order.discount)}</Row>}
                        <Row label="Tax">{formatMoney(order.tax)}</Row>
                        <Row label="Total" strong>{formatMoney(order.total)}</Row>
                        {order.refunded > 0 && <Row label="Refunded">-{formatMoney(order.refunded)}</Row>}
                    </Card>

                    <Card sx={{ p: 3 }}>
                        <Typography variant="h4" component="h2" sx={{ mb: 2 }}>History</Typography>
                        {canEdit && (
                            <Box component="form" onSubmit={(e: FormEvent) => { e.preventDefault(); note.post(`/admin/orders/${order.id}/notes`, { preserveScroll: true, onSuccess: () => note.reset() }); }} sx={{ display: 'flex', gap: 1.5, mb: 2.5, alignItems: 'flex-start' }}>
                                <TextField label="Add a note for the team" size="small" fullWidth value={note.data.note} onChange={(e) => note.setData('note', e.target.value)} error={!!note.errors.note} helperText={note.errors.note ?? 'Customers never see notes.'} slotProps={{ htmlInput: { maxLength: 250 } }} />
                                <Button type="submit" variant="outlined" loading={note.processing} disabled={!note.data.note.trim()}>Add</Button>
                            </Box>
                        )}
                        <Stack spacing={1.5}>
                            {order.events.map((event, index) => (
                                <Box key={index} sx={{ display: 'flex', gap: 2, justifyContent: 'space-between' }}>
                                    <Box>
                                        <Typography sx={{ fontWeight: 600 }}>{EVENT_LABEL[event.type] ?? event.type.replace(/_/g, ' ')}</Typography>
                                        {event.note && <Typography sx={{ color: 'text.secondary', fontSize: 13, overflowWrap: 'anywhere' }}>{event.note}</Typography>}
                                        {event.by && <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>By {event.by}</Typography>}
                                    </Box>
                                    <Typography sx={{ color: 'text.secondary', fontSize: 13, whiteSpace: 'nowrap' }}>{formatDateTime(event.at)}</Typography>
                                </Box>
                            ))}
                        </Stack>
                    </Card>
                </Stack>

                <Stack spacing={3}>
                    <Card sx={{ p: 3 }}>
                        <Typography variant="h4" component="h2" sx={{ mb: 2 }}>Customer</Typography>
                        <Typography sx={{ fontWeight: 600 }}>{order.name ?? 'No name given'}</Typography>
                        <Typography sx={{ wordBreak: 'break-all' }}>{order.email}</Typography>
                        {order.phone && <Typography>{order.phone}</Typography>}
                        {order.customerNote && (
                            <Box sx={{ mt: 1.5 }}>
                                <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>Note from the customer</Typography>
                                <Typography sx={{ whiteSpace: 'pre-line' }}>{order.customerNote}</Typography>
                            </Box>
                        )}
                        {can('customers', 'view') && order.wasPaid && <Button component={RouterLink} href={`/admin/customers/view?email=${encodeURIComponent(order.email)}`} size="small" sx={{ mt: 1, ml: -0.5 }}>All orders of this customer</Button>}
                        <Typography sx={{ color: 'text.secondary', mt: 1 }}>{[order.country, order.postcode].filter(Boolean).join(' · ') || 'No country given'}</Typography>
                    </Card>

                    <Card sx={{ p: 3 }}>
                        <Typography variant="h4" component="h2" sx={{ mb: 2 }}>Payment</Typography>
                        {order.payment ? (
                            <>
                                <Row label="Status">{order.payment.status}</Row>
                                {order.payment.last4 && <Row label="Card">{`${order.payment.brand ?? 'Card'} ending ${order.payment.last4}`}</Row>}
                                <Typography sx={{ color: 'text.secondary', fontSize: 13, mt: 1, wordBreak: 'break-all' }}>Stripe payment: {order.payment.intent}</Typography>
                                {order.payment.refunds.length > 0 && (
                                    <>
                                        <Divider sx={{ my: 2 }} />
                                        {order.payment.refunds.map((refund) => (
                                            <Box key={refund.id}>
                                                <Row label={`Refund ${formatDateTime(refund.at)}`}>-{formatMoney(refund.amount)}</Row>
                                                {refund.reason && <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>{refund.reason}</Typography>}
                                            </Box>
                                        ))}
                                    </>
                                )}
                            </>
                        ) : (
                            <Typography sx={{ color: 'text.secondary' }}>{order.total === 0 ? 'Nothing was charged for this order.' : 'No payment was started.'}</Typography>
                        )}
                    </Card>
                </Stack>
            </Box>

            {refunding && <RefundDialog order={order} open onClose={() => setRefunding(false)} />}
            <ConfirmDialog
                open={confirm !== null}
                title={confirm ? CONFIRM[confirm].title : ''}
                text={confirm ? CONFIRM[confirm].text : ''}
                confirmLabel={confirm ? CONFIRM[confirm].label : ''}
                danger={confirm ? CONFIRM[confirm].danger : false}
                busy={busy}
                onConfirm={run}
                onClose={() => setConfirm(null)}
            />
        </AdminLayout>
    );
}
