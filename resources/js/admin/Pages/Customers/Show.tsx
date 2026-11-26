import ArrowBack from '@mui/icons-material/ArrowBack';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import Typography from '@mui/material/Typography';
import AdminLayout from '../../components/AdminLayout';
import RouterLink from '../../components/RouterLink';
import { useCan } from '../../lib/can';
import { formatDateTime, formatMoney } from '../../lib/format';
import { STATUS_COLOR } from '../Orders/Index';
import type { Customer } from './Index';

interface Props {
    customer: Customer & { refunded: number; reviews: number };
    orders: { id: number; number: string; status: string; statusLabel: string; source: string; items: number; total: number; createdAt: string | null }[];
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <Card sx={{ flex: '1 1 180px' }}>
            <CardContent>
                <Typography sx={{ color: 'text.secondary', fontSize: 13, fontWeight: 600 }}>{label}</Typography>
                <Typography variant="h2" component="p" sx={{ mt: 0.5 }}>{value}</Typography>
            </CardContent>
        </Card>
    );
}

export default function CustomerShow({ customer, orders }: Props) {
    const can = useCan();

    return (
        <AdminLayout title={customer.name ?? customer.email} actions={<Button component={RouterLink} href="/admin/customers" variant="outlined" startIcon={<ArrowBack />}>Customers</Button>}>
            <Typography sx={{ color: 'text.secondary', mb: 3, display: 'flex', gap: 1, alignItems: 'center', flexWrap: 'wrap', overflowWrap: 'anywhere' }}>
                {customer.email}
                <Chip size="small" variant="outlined" label={customer.hasAccount ? `Account since ${formatDateTime(customer.joinedAt)}` : 'Guest, no account'} />
            </Typography>

            <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 2, mb: 3 }}>
                <Stat label="Orders" value={String(customer.orders)} />
                <Stat label="Total spent" value={formatMoney(customer.spent)} />
                <Stat label="Refunded" value={formatMoney(customer.refunded)} />
                <Stat label="Reviews written" value={String(customer.reviews)} />
            </Box>

            <Card>
                <CardContent><Typography variant="h4" component="h2">Order history</Typography></CardContent>
                {orders.length === 0 ? (
                    <CardContent sx={{ pt: 0 }}><Typography sx={{ color: 'text.secondary' }}>This person has an account but has not ordered yet.</Typography></CardContent>
                ) : (
                    <TableContainer>
                        <Table>
                            <TableHead>
                                <TableRow>
                                    <TableCell>Order</TableCell>
                                    <TableCell>Status</TableCell>
                                    <TableCell>Source</TableCell>
                                    <TableCell align="right">Total</TableCell>
                                    <TableCell>Date</TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {orders.map((order) => (
                                    <TableRow key={order.id} hover>
                                        <TableCell>
                                            {can('orders', 'view')
                                                ? <Typography component={RouterLink} href={`/admin/orders/${order.id}`} sx={{ fontWeight: 600, color: 'text.primary', textDecoration: 'none', '&:hover': { textDecoration: 'underline' } }}>{order.number}</Typography>
                                                : <Typography sx={{ fontWeight: 600 }}>{order.number}</Typography>}
                                            <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>{order.items} {order.items === 1 ? 'item' : 'items'}</Typography>
                                        </TableCell>
                                        <TableCell><Chip size="small" variant="outlined" label={order.statusLabel} color={STATUS_COLOR[order.status] ?? 'default'} /></TableCell>
                                        <TableCell>{order.source === 'selling_page' ? 'Selling page' : 'Cart'}</TableCell>
                                        <TableCell align="right" sx={{ fontWeight: 600 }}>{formatMoney(order.total)}</TableCell>
                                        <TableCell sx={{ whiteSpace: 'nowrap' }}>{formatDateTime(order.createdAt)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </TableContainer>
                )}
            </Card>
        </AdminLayout>
    );
}
