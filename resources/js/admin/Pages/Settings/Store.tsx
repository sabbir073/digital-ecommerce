import { useForm } from '@inertiajs/react';
import DownloadOutlined from '@mui/icons-material/DownloadOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import FormControlLabel from '@mui/material/FormControlLabel';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import type { FormEvent, ReactNode } from 'react';
import AdminLayout from '../../components/AdminLayout';
import { useCan } from '../../lib/can';

interface Props {
    store: { name: string; tagline: string; footer_text: string; contact_email: string; contact_phone: string; address: string; delivery_text: string; returns_text: string; currency_format: string; number_prefix: string; number_style: string };
    checkout: { guest_checkout: boolean; phone: string; order_notes: boolean; terms: boolean; terms_page_id: number | null };
    stock: { low_stock_threshold: number; allow_overselling: boolean };
    maintenance: { enabled: boolean; message: string };
    options: { currencyFormats: Record<string, string>; numberStyles: Record<string, string>; phone: Record<string, string>; pages: { id: number; label: string }[] };
    storeUrl: string;
}

const two = { display: 'grid', gap: 2.5, gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' } };

function Toggle({ label, hint, checked, disabled, onChange }: { label: string; hint: ReactNode; checked: boolean; disabled: boolean; onChange: (value: boolean) => void }) {
    return (
        <Box>
            <FormControlLabel control={<Switch checked={checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />} label={label} />
            <Typography sx={{ color: 'text.secondary', fontSize: 13, ml: 6 }}>{hint}</Typography>
        </Box>
    );
}

export default function StoreSettings({ store, checkout, stock, maintenance, options }: Props) {
    const can = useCan();
    const locked = !can('settings', 'edit');
    const details = useForm(store);
    const checkoutForm = useForm(checkout);
    const stockForm = useForm(stock);
    const maintenanceForm = useForm(maintenance);

    const submit = (form: { put: (url: string, options: { preserveScroll: boolean }) => void }, url: string) => (event: FormEvent) => {
        event.preventDefault();
        form.put(url, { preserveScroll: true });
    };
    const exports = [
        { label: 'Orders', href: '/admin/orders/export', module: 'orders' },
        { label: 'Customers', href: '/admin/customers/export', module: 'customers' },
        { label: 'Products', href: '/admin/products/export', module: 'products' },
        { label: 'Reviews', href: '/admin/reviews/export', module: 'reviews' },
    ].filter((item) => can(item.module, 'view'));

    return (
        <AdminLayout title="Store settings">
            <Stack spacing={3} sx={{ maxWidth: 820 }}>
                <Card sx={{ p: 3 }} component="form" onSubmit={submit(details, '/admin/settings/store/details')} noValidate>
                    <Typography variant="h4" component="h2" sx={{ mb: 2.5 }}>Store details</Typography>
                    <Stack spacing={2.5}>
                        <Box sx={two}>
                            <TextField label="Store name" required disabled={locked} value={details.data.name} onChange={(e) => details.setData('name', e.target.value)} error={!!details.errors.name} helperText={details.errors.name ?? 'Shown in the header, the footer, emails and the browser tab.'} />
                            <TextField label="Tagline" disabled={locked} value={details.data.tagline} onChange={(e) => details.setData('tagline', e.target.value)} error={!!details.errors.tagline} helperText={details.errors.tagline ?? 'The description of the home page in search results.'} />
                        </Box>
                        <TextField label="Footer text" multiline minRows={2} disabled={locked} value={details.data.footer_text} onChange={(e) => details.setData('footer_text', e.target.value)} error={!!details.errors.footer_text} helperText={details.errors.footer_text ?? 'A sentence about the store, shown under its name in the footer.'} />
                        <Box sx={two}>
                            <TextField label="Contact email" type="email" disabled={locked} value={details.data.contact_email} onChange={(e) => details.setData('contact_email', e.target.value)} error={!!details.errors.contact_email} helperText={details.errors.contact_email ?? 'Contact form messages are sent here, and it shows in the footer.'} />
                            <TextField label="Contact phone" disabled={locked} value={details.data.contact_phone} onChange={(e) => details.setData('contact_phone', e.target.value)} error={!!details.errors.contact_phone} helperText={details.errors.contact_phone ?? 'Shown in the footer when filled in.'} />
                        </Box>
                        <TextField label="Business address" multiline minRows={2} disabled={locked} value={details.data.address} onChange={(e) => details.setData('address', e.target.value)} error={!!details.errors.address} helperText={details.errors.address ?? 'Shown in the footer and at the bottom of customer emails.'} />
                        <Box sx={two}>
                            <TextField label="Delivery text" multiline minRows={3} disabled={locked} value={details.data.delivery_text} onChange={(e) => details.setData('delivery_text', e.target.value)} error={!!details.errors.delivery_text} helperText={details.errors.delivery_text ?? 'Shown under "Delivery" on every product page. Leave empty to hide it.'} slotProps={{ htmlInput: { maxLength: 1000 } }} />
                            <TextField label="Returns and refunds text" multiline minRows={3} disabled={locked} value={details.data.returns_text} onChange={(e) => details.setData('returns_text', e.target.value)} error={!!details.errors.returns_text} helperText={details.errors.returns_text ?? 'Shown under "Returns" on every product page. Leave empty to hide it.'} slotProps={{ htmlInput: { maxLength: 1000 } }} />
                        </Box>
                        <Box sx={{ display: 'grid', gap: 2.5, gridTemplateColumns: { xs: '1fr', md: '1fr 1fr 1fr' } }}>
                            <TextField select label="How prices are written" disabled={locked} value={details.data.currency_format} onChange={(e) => details.setData('currency_format', e.target.value)} helperText="The store sells in US dollars.">
                                {Object.entries(options.currencyFormats).map(([value, label]) => <MenuItem key={value} value={value}>{label}</MenuItem>)}
                            </TextField>
                            <TextField label="Order number prefix" disabled={locked} value={details.data.number_prefix} onChange={(e) => details.setData('number_prefix', e.target.value.toUpperCase())} error={!!details.errors.number_prefix} helperText={details.errors.number_prefix ?? 'Optional, like NL-'} slotProps={{ htmlInput: { maxLength: 8 } }} />
                            <TextField select label="Order number format" disabled={locked} value={details.data.number_style} onChange={(e) => details.setData('number_style', e.target.value)} helperText="Applies to new orders.">
                                {Object.entries(options.numberStyles).map(([value, label]) => <MenuItem key={value} value={value}>{label}</MenuItem>)}
                            </TextField>
                        </Box>
                    </Stack>
                    {!locked && <Button type="submit" variant="contained" loading={details.processing} sx={{ mt: 3 }}>Save details</Button>}
                </Card>

                <Card sx={{ p: 3 }} component="form" onSubmit={submit(checkoutForm, '/admin/settings/store/checkout')} noValidate>
                    <Typography variant="h4" component="h2">Checkout</Typography>
                    <Typography sx={{ color: 'text.secondary', mt: 0.5, mb: 2.5 }}>Email, name, country and postcode are always asked, because the download links and the tax depend on them.</Typography>
                    <Stack spacing={2.5}>
                        <Toggle label="Guest checkout" disabled={locked} checked={checkoutForm.data.guest_checkout} onChange={(v) => checkoutForm.setData('guest_checkout', v)}
                            hint="On: anyone can buy without an account. Off: the store checkout asks for a password and makes an account with the order. Selling pages always take orders without an account." />
                        <TextField select label="Phone number" disabled={locked} value={checkoutForm.data.phone} onChange={(e) => checkoutForm.setData('phone', e.target.value)} sx={{ maxWidth: 320 }}>
                            {Object.entries(options.phone).map(([value, label]) => <MenuItem key={value} value={value}>{label}</MenuItem>)}
                        </TextField>
                        <Toggle label="Order notes" disabled={locked} checked={checkoutForm.data.order_notes} onChange={(v) => checkoutForm.setData('order_notes', v)}
                            hint="Adds an optional note field. The note shows on the order in admin." />
                        <Toggle label="Terms checkbox" disabled={locked} checked={checkoutForm.data.terms} onChange={(v) => checkoutForm.setData('terms', v)}
                            hint="Customers must tick a box to accept your terms before they can pay." />
                        {checkoutForm.data.terms && (
                            <TextField select label="Terms page" disabled={locked} value={checkoutForm.data.terms_page_id ?? ''} onChange={(e) => checkoutForm.setData('terms_page_id', e.target.value === '' ? null : Number(e.target.value))}
                                error={!!checkoutForm.errors.terms_page_id} helperText={checkoutForm.errors.terms_page_id ?? 'The word "terms" links to this page once it is published. Make the page under Content, Pages.'} sx={{ maxWidth: 420 }}>
                                <MenuItem value="">No link</MenuItem>
                                {options.pages.map((page) => <MenuItem key={page.id} value={page.id}>{page.label}</MenuItem>)}
                            </TextField>
                        )}
                    </Stack>
                    {!locked && <Button type="submit" variant="contained" loading={checkoutForm.processing} sx={{ mt: 3 }}>Save checkout</Button>}
                </Card>

                <Card sx={{ p: 3 }} component="form" onSubmit={submit(stockForm, '/admin/settings/store/stock')} noValidate>
                    <Typography variant="h4" component="h2" sx={{ mb: 2.5 }}>Stock</Typography>
                    <Stack spacing={2.5}>
                        <TextField label="Low-stock threshold" type="number" disabled={locked} value={stockForm.data.low_stock_threshold} onChange={(e) => stockForm.setData('low_stock_threshold', Number(e.target.value))}
                            error={!!stockForm.errors.low_stock_threshold} helperText={stockForm.errors.low_stock_threshold ?? 'Products at or below this number show under Low stock on the dashboard. Whether a product tracks stock is set on the product.'} sx={{ maxWidth: 420 }} slotProps={{ htmlInput: { min: 0 } }} />
                        <Toggle label="Allow orders when out of stock" disabled={locked} checked={stockForm.data.allow_overselling} onChange={(v) => stockForm.setData('allow_overselling', v)}
                            hint="Off: stock is held for a customer while they pay, and an item with no stock cannot be bought. On: orders are always accepted and stock can go below zero." />
                    </Stack>
                    {!locked && <Button type="submit" variant="contained" loading={stockForm.processing} sx={{ mt: 3 }}>Save stock rules</Button>}
                </Card>

                <Card sx={{ p: 3 }} component="form" onSubmit={submit(maintenanceForm, '/admin/settings/store/maintenance')} noValidate>
                    <Typography variant="h4" component="h2" sx={{ mb: 2.5 }}>Maintenance mode</Typography>
                    {maintenance.enabled && <Alert severity="warning" sx={{ mb: 2.5 }}>The store is closed to visitors right now. You see it because you are signed in.</Alert>}
                    <Stack spacing={2.5}>
                        <Toggle label="Close the store to visitors" disabled={locked} checked={maintenanceForm.data.enabled} onChange={(v) => maintenanceForm.setData('enabled', v)}
                            hint="Visitors see the message below. Signed-in staff see the store as usual, payments already made still complete, and customers can still open their orders and downloads." />
                        <TextField label="Message" multiline minRows={2} disabled={locked} value={maintenanceForm.data.message} onChange={(e) => maintenanceForm.setData('message', e.target.value)}
                            error={!!maintenanceForm.errors.message} helperText={maintenanceForm.errors.message ?? 'Empty shows: We are making a few changes and will be back shortly.'} slotProps={{ htmlInput: { maxLength: 500 } }} />
                    </Stack>
                    {!locked && <Button type="submit" variant="contained" color={maintenanceForm.data.enabled ? 'warning' : 'primary'} loading={maintenanceForm.processing} sx={{ mt: 3 }}>Save maintenance mode</Button>}
                </Card>

                {exports.length > 0 && (
                    <Card sx={{ p: 3 }}>
                        <Typography variant="h4" component="h2">Data</Typography>
                        <Typography sx={{ color: 'text.secondary', mt: 0.5, mb: 2.5 }}>Download everything as CSV files that open in Excel or Google Sheets. Each download is written to the activity log.</Typography>
                        <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1.5 }}>
                            {exports.map((item) => <Button key={item.href} component="a" href={item.href} variant="outlined" startIcon={<DownloadOutlined />}>{item.label}</Button>)}
                        </Box>
                    </Card>
                )}
            </Stack>
        </AdminLayout>
    );
}
