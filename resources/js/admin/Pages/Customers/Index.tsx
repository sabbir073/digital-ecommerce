import { router } from '@inertiajs/react';
import FileDownloadOutlined from '@mui/icons-material/FileDownloadOutlined';
import SearchOutlined from '@mui/icons-material/SearchOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import Pagination from '@mui/material/Pagination';
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

export interface Customer {
    email: string;
    name: string | null;
    hasAccount: boolean;
    orders: number;
    spent: number;
    lastOrderAt: string | null;
    joinedAt: string | null;
}

interface Props {
    customers: Paginated<Customer>;
    filters: { search: string; sort: string };
}

export default function CustomersIndex({ customers, filters }: Props) {
    const [search, setSearch] = useState(filters.search);
    const first = useRef(true);

    const go = (next: Partial<Props['filters']> & { page?: number }) => {
        const query = Object.fromEntries(Object.entries({ ...filters, ...next }).filter(([, v]) => v !== '' && v !== undefined));
        router.get('/admin/customers', query, { preserveState: true, preserveScroll: true, replace: true });
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
        <AdminLayout title="Customers" actions={<Button component="a" href="/admin/customers/export" variant="outlined" startIcon={<FileDownloadOutlined />}>Export CSV</Button>}>
            <Box sx={{ display: 'grid', gap: 2, mb: 2, gridTemplateColumns: { xs: '1fr', md: 'minmax(0, 1fr) 240px' } }}>
                <TextField label="Search" placeholder="Email or name" value={search} onChange={(e) => setSearch(e.target.value)} slotProps={{ input: { startAdornment: <InputAdornment position="start"><SearchOutlined /></InputAdornment> } }} />
                <TextField select label="Sort by" value={filters.sort} onChange={(e) => go({ sort: e.target.value })}>
                    <MenuItem value="recent">Most recent</MenuItem>
                    <MenuItem value="spent">Total spent</MenuItem>
                    <MenuItem value="orders">Number of orders</MenuItem>
                </TextField>
            </Box>

            {customers.data.length === 0 ? (
                <Card sx={{ p: 6, textAlign: 'center' }}>
                    <Typography variant="h4" component="p" sx={{ mb: 0.5 }}>No customers found</Typography>
                    <Typography sx={{ color: 'text.secondary' }}>{filters.search ? 'Try a different search.' : 'People appear here once they pay for an order or make an account.'}</Typography>
                </Card>
            ) : (
                <Card>
                    <TableContainer>
                        <Table>
                            <TableHead>
                                <TableRow>
                                    <TableCell>Customer</TableCell>
                                    <TableCell align="right">Orders</TableCell>
                                    <TableCell align="right">Total spent</TableCell>
                                    <TableCell>Last order</TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {customers.data.map((c) => (
                                    <TableRow key={c.email} hover>
                                        <TableCell>
                                            <Typography component={RouterLink} href={`/admin/customers/view?email=${encodeURIComponent(c.email)}`} sx={{ fontWeight: 600, color: 'text.primary', textDecoration: 'none', '&:hover': { textDecoration: 'underline' } }}>{c.name ?? c.email}</Typography>
                                            <Typography sx={{ color: 'text.secondary', fontSize: 13, display: 'flex', gap: 1, alignItems: 'center', flexWrap: 'wrap' }}>
                                                {c.name ? c.email : null}
                                                <Chip size="small" variant="outlined" label={c.hasAccount ? 'Account' : 'Guest'} />
                                            </Typography>
                                        </TableCell>
                                        <TableCell align="right">{c.orders}</TableCell>
                                        <TableCell align="right" sx={{ fontWeight: 600 }}>{formatMoney(c.spent)}</TableCell>
                                        <TableCell sx={{ whiteSpace: 'nowrap' }}>{c.lastOrderAt ? formatDateTime(c.lastOrderAt) : 'No orders yet'}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </TableContainer>
                </Card>
            )}

            {customers.last_page > 1 && (
                <Box sx={{ display: 'flex', justifyContent: 'center', mt: 3 }}>
                    <Pagination count={customers.last_page} page={customers.current_page} color="primary" onChange={(_, page) => go({ page })} />
                </Box>
            )}
        </AdminLayout>
    );
}
