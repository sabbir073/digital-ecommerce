import { router } from '@inertiajs/react';
import SearchOutlined from '@mui/icons-material/SearchOutlined';
import Box from '@mui/material/Box';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import Pagination from '@mui/material/Pagination';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useEffect, useRef, useState } from 'react';
import AdminLayout from '../../components/AdminLayout';
import RouterLink from '../../components/RouterLink';
import { formatDateTime, formatMoney } from '../../lib/format';
import type { Paginated } from '../../lib/types';

export interface OrderRow {
    id: number;
    number: string;
    email: string;
    name: string | null;
    status: string;
    statusLabel: string;
    source: string;
    items: number;
    total: number;
    createdAt: string | null;
}

interface Props {
    orders: Paginated<OrderRow>;
    filters: { search: string; status: string };
    statuses: Record<string, string>;
}

export const STATUS_COLOR: Record<string, 'success' | 'warning' | 'error' | 'default' | 'info'> = {
    paid: 'success',
    pending_payment: 'warning',
    payment_failed: 'error',
    refunded: 'default',
    partially_refunded: 'info',
    cancelled: 'default',
};

export default function OrdersIndex({ orders, filters, statuses }: Props) {
    const [search, setSearch] = useState(filters.search);
    const first = useRef(true);

    const go = (next: Partial<Props['filters']> & { page?: number }) => {
        const query = Object.fromEntries(Object.entries({ ...filters, ...next }).filter(([, v]) => v !== '' && v !== undefined));
        router.get('/admin/orders', query, { preserveState: true, preserveScroll: true, replace: true });
    };

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const timer = window.setTimeout(() => go({ search }), 300);
        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <AdminLayout title="Orders">
            <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} sx={{ mb: 2 }}>
                <TextField label="Search" placeholder="Order number, email or name" value={search} onChange={(e) => setSearch(e.target.value)} sx={{ flex: 1 }} slotProps={{ input: { startAdornment: <InputAdornment position="start"><SearchOutlined /></InputAdornment> } }} />
                <TextField select label="Status" value={filters.status} onChange={(e) => go({ status: e.target.value })} sx={{ minWidth: 220 }} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}>
                    <MenuItem value="">Paid and refunded</MenuItem>
                    {Object.entries(statuses).map(([value, label]) => <MenuItem key={value} value={value}>{label}</MenuItem>)}
                </TextField>
            </Stack>

            {orders.data.length === 0 ? (
                <Card sx={{ p: 6, textAlign: 'center' }}>
                    <Typography variant="h4" component="p" sx={{ mb: 0.5 }}>No orders found</Typography>
                    <Typography sx={{ color: 'text.secondary' }}>{filters.search || filters.status ? 'Try a different search or status.' : 'Paid orders appear here as soon as Stripe confirms them.'}</Typography>
                </Card>
            ) : (
                <Card>
                    <TableContainer>
                        <Table>
                            <TableHead>
                                <TableRow>
                                    <TableCell>Order</TableCell>
                                    <TableCell>Customer</TableCell>
                                    <TableCell>Status</TableCell>
                                    <TableCell>Source</TableCell>
                                    <TableCell align="right">Total</TableCell>
                                    <TableCell>Date</TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {orders.data.map((order) => (
                                    <TableRow key={order.id} hover>
                                        <TableCell>
                                            <Typography component={RouterLink} href={`/admin/orders/${order.id}`} sx={{ fontWeight: 600, color: 'text.primary', textDecoration: 'none', '&:hover': { textDecoration: 'underline' } }}>{order.number}</Typography>
                                            <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>{order.items} {order.items === 1 ? 'item' : 'items'}</Typography>
                                        </TableCell>
                                        <TableCell>
                                            <Box>{order.name ?? order.email}</Box>
                                            {order.name && <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>{order.email}</Typography>}
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
                </Card>
            )}

            {orders.last_page > 1 && (
                <Box sx={{ display: 'flex', justifyContent: 'center', mt: 3 }}>
                    <Pagination count={orders.last_page} page={orders.current_page} color="primary" onChange={(_, page) => go({ page })} />
                </Box>
            )}
        </AdminLayout>
    );
}
