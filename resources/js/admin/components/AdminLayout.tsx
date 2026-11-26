import { Head, router, usePage } from '@inertiajs/react';
import AdminPanelSettingsOutlined from '@mui/icons-material/AdminPanelSettingsOutlined';
import CategoryOutlined from '@mui/icons-material/CategoryOutlined';
import DashboardOutlined from '@mui/icons-material/DashboardOutlined';
import GroupOutlined from '@mui/icons-material/GroupOutlined';
import HistoryOutlined from '@mui/icons-material/HistoryOutlined';
import Inventory2Outlined from '@mui/icons-material/Inventory2Outlined';
import LocalOfferOutlined from '@mui/icons-material/LocalOfferOutlined';
import ChatBubbleOutlineOutlined from '@mui/icons-material/ChatBubbleOutlineOutlined';
import PeopleAltOutlined from '@mui/icons-material/PeopleAltOutlined';
import StarBorderOutlined from '@mui/icons-material/StarBorderOutlined';
import LockOutlined from '@mui/icons-material/LockOutlined';
import LogoutOutlined from '@mui/icons-material/LogoutOutlined';
import WebOutlined from '@mui/icons-material/WebOutlined';
import MenuOutlined from '@mui/icons-material/MenuOutlined';
import PersonOutline from '@mui/icons-material/PersonOutlineOutlined';
import PaymentsOutlined from '@mui/icons-material/PaymentsOutlined';
import PhotoLibraryOutlined from '@mui/icons-material/PhotoLibraryOutlined';
import ReceiptLongOutlined from '@mui/icons-material/ReceiptLongOutlined';
import Alert from '@mui/material/Alert';
import AppBar from '@mui/material/AppBar';
import Avatar from '@mui/material/Avatar';
import Box from '@mui/material/Box';
import ButtonBase from '@mui/material/ButtonBase';
import Divider from '@mui/material/Divider';
import Drawer from '@mui/material/Drawer';
import IconButton from '@mui/material/IconButton';
import List from '@mui/material/List';
import ListItemButton from '@mui/material/ListItemButton';
import ListItemIcon from '@mui/material/ListItemIcon';
import ListItemText from '@mui/material/ListItemText';
import ListSubheader from '@mui/material/ListSubheader';
import Menu from '@mui/material/Menu';
import MenuItem from '@mui/material/MenuItem';
import Snackbar from '@mui/material/Snackbar';
import Toolbar from '@mui/material/Toolbar';
import Typography from '@mui/material/Typography';
import useMediaQuery from '@mui/material/useMediaQuery';
import { useTheme } from '@mui/material/styles';
import { type ReactElement, type ReactNode, useEffect, useState } from 'react';
import { useCan } from '../lib/can';
import type { SharedProps } from '../lib/types';
import RouterLink from './RouterLink';

const DRAWER_WIDTH = 264;

interface NavItem {
    label: string;
    href: string;
    icon: ReactElement;
    module?: string;
    match: (path: string) => boolean;
}

const NAV: { heading: string; items: NavItem[] }[] = [
    {
        heading: 'Overview',
        items: [{ label: 'Dashboard', href: '/admin', icon: <DashboardOutlined />, match: (p) => p === '/admin' }],
    },
    {
        heading: 'Catalog',
        items: [
            { label: 'Products', href: '/admin/products', icon: <Inventory2Outlined />, module: 'products', match: (p) => p.startsWith('/admin/products') },
            { label: 'Categories', href: '/admin/categories', icon: <CategoryOutlined />, module: 'categories', match: (p) => p.startsWith('/admin/categories') },
            { label: 'Media library', href: '/admin/media', icon: <PhotoLibraryOutlined />, module: 'media', match: (p) => p.startsWith('/admin/media') },
        ],
    },
    {
        heading: 'Sales',
        items: [
            { label: 'Orders', href: '/admin/orders', icon: <ReceiptLongOutlined />, module: 'orders', match: (p) => p.startsWith('/admin/orders') },
            { label: 'Customers', href: '/admin/customers', icon: <PeopleAltOutlined />, module: 'customers', match: (p) => p.startsWith('/admin/customers') },
            { label: 'Selling pages', href: '/admin/selling-pages', icon: <WebOutlined />, module: 'selling_pages', match: (p) => p.startsWith('/admin/selling-pages') },
            { label: 'Coupons', href: '/admin/coupons', icon: <LocalOfferOutlined />, module: 'coupons', match: (p) => p.startsWith('/admin/coupons') },
            { label: 'Reviews', href: '/admin/reviews', icon: <StarBorderOutlined />, module: 'reviews', match: (p) => p.startsWith('/admin/reviews') },
            { label: 'Live chat', href: '/admin/chat', icon: <ChatBubbleOutlineOutlined />, module: 'chat', match: (p) => p.startsWith('/admin/chat') },
        ],
    },
    {
        heading: 'Settings',
        items: [{ label: 'Payments', href: '/admin/settings/payments', icon: <PaymentsOutlined />, module: 'settings', match: (p) => p.startsWith('/admin/settings/payments') }],
    },
    {
        heading: 'Team',
        items: [
            { label: 'Staff', href: '/admin/staff', icon: <GroupOutlined />, module: 'staff', match: (p) => p === '/admin/staff' },
            { label: 'Roles', href: '/admin/roles', icon: <AdminPanelSettingsOutlined />, module: 'staff', match: (p) => p.startsWith('/admin/roles') },
            { label: 'Sign-in security', href: '/admin/staff-security', icon: <LockOutlined />, module: 'staff', match: (p) => p.startsWith('/admin/staff-security') },
            { label: 'Activity log', href: '/admin/activity', icon: <HistoryOutlined />, module: 'activity', match: (p) => p.startsWith('/admin/activity') },
        ],
    },
];

export default function AdminLayout({ title, actions, children }: { title: string; actions?: ReactNode; children: ReactNode }) {
    const page = usePage<SharedProps>();
    const { store, auth, flash, payments } = page.props;
    const path = page.url.split('?')[0];
    const can = useCan();
    const theme = useTheme();
    const isDesktop = useMediaQuery(theme.breakpoints.up('lg'));
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [menuAnchor, setMenuAnchor] = useState<HTMLElement | null>(null);
    const [toast, setToast] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

    useEffect(() => {
        if (flash.success) setToast({ type: 'success', text: flash.success });
        else if (flash.error) setToast({ type: 'error', text: flash.error });
    }, [flash]);

    const user = auth.user;
    const initials = (user?.name ?? '?').split(/\s+/).map((part) => part[0]).slice(0, 2).join('').toUpperCase();

    const drawer = (
        <Box component="nav" aria-label="Admin sections" sx={{ display: 'flex', flexDirection: 'column', height: '100%' }}>
            <Toolbar sx={{ px: 3 }}>
                <Typography component={RouterLink} href="/admin" variant="h4" sx={{ color: 'text.primary', textDecoration: 'none' }}>
                    {store.name}
                </Typography>
            </Toolbar>
            <Divider />
            <Box sx={{ px: 1.5, py: 1, overflowY: 'auto', flex: 1 }}>
                {NAV.map((group) => {
                    const items = group.items.filter((item) => !item.module || can(item.module));
                    if (items.length === 0) return null;
                    return (
                        <List
                            key={group.heading}
                            dense
                            subheader={
                                <ListSubheader disableSticky sx={{ bgcolor: 'transparent', fontSize: 12, letterSpacing: '0.06em', textTransform: 'uppercase', lineHeight: '32px' }}>
                                    {group.heading}
                                </ListSubheader>
                            }
                        >
                            {items.map((item) => (
                                <ListItemButton
                                    key={item.href}
                                    component={RouterLink}
                                    href={item.href}
                                    selected={item.match(path)}
                                    onClick={() => setDrawerOpen(false)}
                                    sx={{ mb: 0.5 }}
                                >
                                    <ListItemIcon sx={{ minWidth: 38, color: 'inherit' }}>{item.icon}</ListItemIcon>
                                    <ListItemText primary={item.label} slotProps={{ primary: { sx: { fontSize: 15, fontWeight: 500 } } }} />
                                </ListItemButton>
                            ))}
                        </List>
                    );
                })}
            </Box>
        </Box>
    );

    return (
        <Box sx={{ display: 'flex', minHeight: '100vh' }}>
            <Head title={title} />
            <Drawer
                variant={isDesktop ? 'permanent' : 'temporary'}
                open={isDesktop || drawerOpen}
                onClose={() => setDrawerOpen(false)}
                ModalProps={{ keepMounted: true }}
                sx={{ width: { lg: DRAWER_WIDTH }, flexShrink: 0, '& .MuiDrawer-paper': { width: DRAWER_WIDTH, borderRight: 1, borderColor: 'divider' } }}
            >
                {drawer}
            </Drawer>

            <Box sx={{ flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column' }}>
                <AppBar position="sticky" color="inherit" elevation={0} sx={{ borderBottom: 1, borderColor: 'divider' }}>
                    <Toolbar sx={{ gap: 1 }}>
                        {!isDesktop && (
                            <IconButton edge="start" aria-label="Open menu" onClick={() => setDrawerOpen(true)}>
                                <MenuOutlined />
                            </IconButton>
                        )}
                        <Box sx={{ flex: 1 }} />
                        <ButtonBase
                            onClick={(event) => setMenuAnchor(event.currentTarget)}
                            aria-haspopup="menu"
                            aria-label="Account menu"
                            sx={{ borderRadius: 999, p: 0.5, pr: 1.5, gap: 1.25, minHeight: 44 }}
                        >
                            <Avatar sx={{ width: 36, height: 36, bgcolor: 'secondary.main', fontSize: 14, fontWeight: 600 }}>{initials}</Avatar>
                            <Box sx={{ textAlign: 'left', display: { xs: 'none', sm: 'block' } }}>
                                <Typography sx={{ fontSize: 14, fontWeight: 600, lineHeight: 1.2 }}>{user?.name}</Typography>
                                <Typography sx={{ fontSize: 12, color: 'text.secondary', lineHeight: 1.2 }}>{user?.role}</Typography>
                            </Box>
                        </ButtonBase>
                        <Menu anchorEl={menuAnchor} open={!!menuAnchor} onClose={() => setMenuAnchor(null)} anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }} transformOrigin={{ vertical: 'top', horizontal: 'right' }}>
                            <MenuItem component={RouterLink} href="/admin/account" onClick={() => setMenuAnchor(null)}>
                                <ListItemIcon><PersonOutline fontSize="small" /></ListItemIcon>
                                My account
                            </MenuItem>
                            <MenuItem onClick={() => router.post('/admin/logout')}>
                                <ListItemIcon><LogoutOutlined fontSize="small" /></ListItemIcon>
                                Sign out
                            </MenuItem>
                        </Menu>
                    </Toolbar>
                </AppBar>

                <Box component="main" sx={{ flex: 1, p: { xs: 2, sm: 3, lg: 4 }, maxWidth: 1280, width: '100%', mx: 'auto' }}>
                    {payments?.testMode && (
                        <Alert severity="warning" sx={{ mb: 3 }}>Test mode: Stripe test keys are active, so no real money is taken.</Alert>
                    )}
                    <Box sx={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 2, mb: 3 }}>
                        <Typography variant="h2" component="h1" sx={{ flex: 1, minWidth: 200 }}>
                            {title}
                        </Typography>
                        {actions}
                    </Box>
                    {children}
                </Box>
            </Box>

            <Snackbar open={!!toast} autoHideDuration={5000} onClose={() => setToast(null)} anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}>
                {toast ? (
                    <Alert severity={toast.type} variant="filled" onClose={() => setToast(null)} sx={{ borderRadius: 3 }}>
                        {toast.text}
                    </Alert>
                ) : undefined}
            </Snackbar>
        </Box>
    );
}
