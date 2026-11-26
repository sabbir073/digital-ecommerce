import ArrowBack from '@mui/icons-material/ArrowBack';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import Divider from '@mui/material/Divider';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import type { ReactNode } from 'react';
import AdminLayout from '../../components/AdminLayout';
import RouterLink from '../../components/RouterLink';
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
    name: string | null;
    status: string;
    statusLabel: string;
    source: string;
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
    events: { type: string; note: string | null; at: string | null }[];
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
};

function Row({ label, children, strong }: { label: string; children: ReactNode; strong?: boolean }) {
    return (
        <Box sx={{ display: 'flex', justifyContent: 'space-between', gap: 2, py: 0.5 }}>
            <Typography sx={{ color: strong ? 'text.primary' : 'text.secondary', fontWeight: strong ? 600 : 400 }}>{label}</Typography>
            <Typography sx={{ fontWeight: strong ? 700 : 400, textAlign: 'right' }}>{children}</Typography>
        </Box>
    );
}

export default function OrderShow({ order }: { order: Order }) {
    const dispute = order.payment?.disputes[0];

    return (
        <AdminLayout
            title={`Order ${order.number}`}
            actions={<Button component={RouterLink} href="/admin/orders" variant="outlined" startIcon={<ArrowBack />}>Orders</Button>}
        >
            <Stack spacing={1} direction="row" sx={{ mb: 3, alignItems: 'center', flexWrap: 'wrap' }}>
                <Chip variant="outlined" label={order.statusLabel} color={STATUS_COLOR[order.status] ?? 'default'} />
                <Typography sx={{ color: 'text.secondary' }}>
                    Placed {formatDateTime(order.createdAt)} from the {order.source === 'selling_page' ? 'selling page' : 'cart'}
                    {order.paidAt ? ` · paid ${formatDateTime(order.paidAt)}` : ''}
                </Typography>
            </Stack>

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
                        <Stack spacing={1.5}>
                            {order.events.map((event, index) => (
                                <Box key={index} sx={{ display: 'flex', gap: 2, justifyContent: 'space-between' }}>
                                    <Box>
                                        <Typography sx={{ fontWeight: 600 }}>{EVENT_LABEL[event.type] ?? event.type.replace(/_/g, ' ')}</Typography>
                                        {event.note && <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>{event.note}</Typography>}
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
                                        {order.payment.refunds.map((refund) => <Row key={refund.id} label={`Refund ${formatDateTime(refund.at)}`}>-{formatMoney(refund.amount)}</Row>)}
                                    </>
                                )}
                            </>
                        ) : (
                            <Typography sx={{ color: 'text.secondary' }}>{order.total === 0 ? 'Nothing was charged for this order.' : 'No payment was started.'}</Typography>
                        )}
                    </Card>
                </Stack>
            </Box>
        </AdminLayout>
    );
}
