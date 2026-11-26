import { usePage } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Divider from '@mui/material/Divider';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import AdminLayout from '../components/AdminLayout';
import { actionLabel, formatDateTime, formatMoney } from '../lib/format';
import { STATUS_COLOR } from './Orders/Index';
import type { SharedProps } from '../lib/types';
import RouterLink from '../components/RouterLink';

interface Sales {
    today: { orders: number; revenue: number };
    month: { orders: number; revenue: number };
    latest: { id: number; number: string; customer: string; status: string; statusLabel: string; total: number; paidAt: string | null }[];
}

interface Props {
    sales: Sales | null;
    lowStock: { threshold: number; items: { id: number; name: string; variant: string | null; stock: number }[] } | null;
    topPages: { id: number; name: string; views: number; orders: number; conversion: number | null }[] | null;
    team: { active: number; invited: number; suspended: number; roles: number; withTwoFactor: number } | null;
    recentActivity: { id: number; actor: string; action: string; module: string; label: string | null; at: string | null }[] | null;
}

function Stat({ label, value }: { label: string; value: number | string }) {
    return (
        <Card sx={{ flex: '1 1 160px' }}>
            <CardContent>
                <Typography sx={{ color: 'text.secondary', fontSize: 13, fontWeight: 600 }}>{label}</Typography>
                <Typography variant="h1" component="p" sx={{ mt: 0.5 }}>{value}</Typography>
            </CardContent>
        </Card>
    );
}

export default function Dashboard({ sales, lowStock, topPages, team, recentActivity }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const firstName = auth.user?.name.split(' ')[0] ?? '';

    return (
        <AdminLayout title={`Hello, ${firstName}`}>
            <Stack spacing={3}>
                {!auth.user?.hasTwoFactor && (
                    <Alert severity="info" action={<Button component={RouterLink} href="/admin/account" color="inherit" size="small">Set up</Button>}>
                        Two-factor sign-in is off for your account. Turn it on to protect the store.
                    </Alert>
                )}

                {sales && (
                    <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 2 }}>
                        <Stat label="Orders today" value={sales.today.orders} />
                        <Stat label="Revenue today" value={formatMoney(sales.today.revenue)} />
                        <Stat label="Orders this month" value={sales.month.orders} />
                        <Stat label="Revenue this month" value={formatMoney(sales.month.revenue)} />
                    </Box>
                )}

                {(sales || lowStock || topPages) && (
                    <Box sx={{ display: 'grid', gap: 3, gridTemplateColumns: { xs: 'minmax(0, 1fr)', lg: 'repeat(3, minmax(0, 1fr))' }, alignItems: 'start' }}>
                        {sales && (
                            <Card>
                                <CardContent sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
                                    <Typography variant="h3" component="h2" sx={{ flex: 1 }}>Latest orders</Typography>
                                    <Button component={RouterLink} href="/admin/orders" variant="outlined" size="small">View all</Button>
                                </CardContent>
                                <Divider />
                                {sales.latest.length === 0 && <CardContent><Typography sx={{ color: 'text.secondary' }}>No paid orders yet.</Typography></CardContent>}
                                {sales.latest.map((order, index) => (
                                    <Box key={order.id}>
                                        {index > 0 && <Divider />}
                                        <Box component={RouterLink} href={`/admin/orders/${order.id}`} sx={{ px: 2, py: 1.5, display: 'flex', gap: 1.5, alignItems: 'center', color: 'inherit', textDecoration: 'none', '&:hover': { bgcolor: 'action.hover' } }}>
                                            <Box sx={{ flex: 1, minWidth: 0 }}>
                                                <Typography sx={{ fontWeight: 600 }}>{order.number}</Typography>
                                                <Typography noWrap sx={{ color: 'text.secondary', fontSize: 13 }}>{order.customer}</Typography>
                                            </Box>
                                            {order.status !== 'paid' && <Chip size="small" variant="outlined" label={order.statusLabel} color={STATUS_COLOR[order.status] ?? 'default'} />}
                                            <Typography sx={{ fontWeight: 600 }}>{formatMoney(order.total)}</Typography>
                                        </Box>
                                    </Box>
                                ))}
                            </Card>
                        )}

                        {lowStock && (
                            <Card>
                                <CardContent>
                                    <Typography variant="h3" component="h2">Low stock</Typography>
                                    <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>Products with {lowStock.threshold} or fewer left</Typography>
                                </CardContent>
                                <Divider />
                                {lowStock.items.length === 0 && <CardContent><Typography sx={{ color: 'text.secondary' }}>Nothing is running low.</Typography></CardContent>}
                                {lowStock.items.map((item, index) => (
                                    <Box key={`${item.id}-${item.variant ?? ''}`}>
                                        {index > 0 && <Divider />}
                                        <Box component={RouterLink} href={`/admin/products/${item.id}`} sx={{ px: 2, py: 1.5, display: 'flex', gap: 1.5, alignItems: 'center', color: 'inherit', textDecoration: 'none', '&:hover': { bgcolor: 'action.hover' } }}>
                                            <Box sx={{ flex: 1, minWidth: 0 }}>
                                                <Typography noWrap sx={{ fontWeight: 600 }}>{item.name}</Typography>
                                                {item.variant && <Typography noWrap sx={{ color: 'text.secondary', fontSize: 13 }}>{item.variant}</Typography>}
                                            </Box>
                                            <Chip size="small" variant="outlined" color={item.stock === 0 ? 'error' : 'warning'} label={item.stock === 0 ? 'Sold out' : `${item.stock} left`} />
                                        </Box>
                                    </Box>
                                ))}
                            </Card>
                        )}

                        {topPages && (
                            <Card>
                                <CardContent>
                                    <Typography variant="h3" component="h2">Top selling pages</Typography>
                                    <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>Share of visits that ended in a paid order</Typography>
                                </CardContent>
                                <Divider />
                                {topPages.length === 0 && <CardContent><Typography sx={{ color: 'text.secondary' }}>No published selling page has been visited yet.</Typography></CardContent>}
                                {topPages.map((page, index) => (
                                    <Box key={page.id}>
                                        {index > 0 && <Divider />}
                                        <Box component={RouterLink} href={`/admin/selling-pages/${page.id}`} sx={{ px: 2, py: 1.5, display: 'flex', gap: 1.5, alignItems: 'center', color: 'inherit', textDecoration: 'none', '&:hover': { bgcolor: 'action.hover' } }}>
                                            <Box sx={{ flex: 1, minWidth: 0 }}>
                                                <Typography noWrap sx={{ fontWeight: 600 }}>{page.name}</Typography>
                                                <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>{page.orders} {page.orders === 1 ? 'order' : 'orders'} from {page.views} {page.views === 1 ? 'visit' : 'visits'}</Typography>
                                            </Box>
                                            <Typography sx={{ fontWeight: 600 }}>{page.conversion}%</Typography>
                                        </Box>
                                    </Box>
                                ))}
                            </Card>
                        )}
                    </Box>
                )}

                {team && (
                    <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 2 }}>
                        <Stat label="Active staff" value={team.active} />
                        <Stat label="Pending invitations" value={team.invited} />
                        <Stat label="Suspended" value={team.suspended} />
                        <Stat label="Roles" value={team.roles} />
                        <Stat label="Using two-factor" value={team.withTwoFactor} />
                    </Box>
                )}

                {recentActivity && (
                    <Card>
                        <CardContent sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
                            <Typography variant="h3" component="h2" sx={{ flex: 1 }}>Recent activity</Typography>
                            <Button component={RouterLink} href="/admin/activity" variant="outlined" size="small">View all</Button>
                        </CardContent>
                        <Divider />
                        {recentActivity.length === 0 ? (
                            <CardContent><Typography sx={{ color: 'text.secondary' }}>Nothing has happened yet.</Typography></CardContent>
                        ) : (
                            recentActivity.map((entry, index) => (
                                <Box key={entry.id}>
                                    {index > 0 && <Divider />}
                                    <Box sx={{ px: 2, py: 1.5, display: 'flex', flexWrap: 'wrap', gap: 1, alignItems: 'baseline' }}>
                                        <Typography sx={{ flex: 1, minWidth: 220 }}>
                                            <strong>{entry.actor}</strong> · {actionLabel(entry.action)}
                                            {entry.label && entry.label !== entry.actor ? ` ${entry.label}` : ''}
                                        </Typography>
                                        <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>{formatDateTime(entry.at)}</Typography>
                                    </Box>
                                </Box>
                            ))
                        )}
                    </Card>
                )}

                {!team && !recentActivity && !sales && !lowStock && !topPages && (
                    <Card>
                        <CardContent>
                            <Typography sx={{ color: 'text.secondary' }}>
                                You are signed in as {auth.user?.role}. Use the menu to open the areas your role includes.
                            </Typography>
                        </CardContent>
                    </Card>
                )}
            </Stack>
        </AdminLayout>
    );
}
