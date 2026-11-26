import { router } from '@inertiajs/react';
import Add from '@mui/icons-material/Add';
import Close from '@mui/icons-material/Close';
import DashboardCustomizeOutlined from '@mui/icons-material/DashboardCustomizeOutlined';
import DeleteOutlined from '@mui/icons-material/DeleteOutlined';
import OpenInNew from '@mui/icons-material/OpenInNew';
import Alert from '@mui/material/Alert';
import Autocomplete from '@mui/material/Autocomplete';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import Radio from '@mui/material/Radio';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { useEffect, useState } from 'react';
import AdminLayout from '../../components/AdminLayout';
import RouterLink from '../../components/RouterLink';
import { useCan } from '../../lib/can';
import type { ProductOption } from './Index';

interface OfferItem {
    product_id: number | null;
    variant_id: number | null;
    quantity: number;
}

interface Offer {
    id?: number;
    label: string;
    price: string;
    note: string;
    is_default: boolean;
    items: OfferItem[];
}

interface Props {
    page: { id: number; name: string; slug: string; productId: number; status: 'draft' | 'published'; publishedAt: string | null; url: string; previewUrl: string; hasUnpublishedChanges: boolean };
    offers: Offer[];
    products: ProductOption[];
    blockCount: number;
    problems: string[];
}

export default function SellingPageEdit({ page, offers: savedOffers, products, blockCount, problems }: Props) {
    const can = useCan();
    const canEdit = can('selling_pages', 'edit');
    const canPublish = can('selling_pages', 'publish');

    const [name, setName] = useState(page.name);
    const [slug, setSlug] = useState(page.slug);
    const [productId, setProductId] = useState<number | null>(page.productId);
    const [offers, setOffers] = useState<Offer[]>(savedOffers);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [dirty, setDirty] = useState(false);
    const [saving, setSaving] = useState(false);

    // After a save the server sends back what it stored; show exactly that.
    useEffect(() => setOffers(savedOffers), [savedOffers]);

    useEffect(() => {
        if (!dirty) return;
        const warn = (event: BeforeUnloadEvent) => event.preventDefault();
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);

    const touch = () => setDirty(true);
    const changeOffers = (next: Offer[]) => { setOffers(next); touch(); };
    const patchOffer = (index: number, patch: Partial<Offer>) => changeOffers(offers.map((o, i) => (i === index ? { ...o, ...patch } : o)));

    const save = (then?: () => void) => {
        setSaving(true);
        router.put(
            `/admin/selling-pages/${page.id}`,
            { name, slug, product_id: productId, offers } as never,
            {
                preserveScroll: true,
                onSuccess: () => { setErrors({}); setDirty(false); then?.(); },
                onError: (e) => setErrors(e as Record<string, string>),
                onFinish: () => setSaving(false),
            },
        );
    };

    const publish = () => {
        const send = () => router.post(`/admin/selling-pages/${page.id}/publish`, {}, { preserveScroll: true });
        if (dirty) save(send);
        else send();
    };

    const product = (id: number | null) => products.find((p) => p.id === id) ?? null;
    const firstError = Object.values(errors)[0];

    return (
        <AdminLayout
            title={page.name}
            actions={
                <Stack direction="row" spacing={1} useFlexGap sx={{ flexWrap: 'wrap' }}>
                    <Tooltip title={dirty ? 'Save first to preview your latest changes' : ''}>
                        <span>
                            <Button component="a" href={page.previewUrl} target="_blank" rel="noopener" startIcon={<OpenInNew />} disabled={dirty}>Preview</Button>
                        </span>
                    </Tooltip>
                    {can('seo') && <Button component={RouterLink} href={`/admin/seo/selling_page/${page.id}`}>SEO</Button>}
                    {canEdit && <Button variant="outlined" onClick={() => save()} disabled={saving || !dirty}>{dirty ? 'Save' : 'Saved'}</Button>}
                    {canPublish && page.status === 'published' && (
                        <Button color="inherit" onClick={() => router.post(`/admin/selling-pages/${page.id}/unpublish`, {}, { preserveScroll: true })}>Unpublish</Button>
                    )}
                    {canPublish && <Button variant="contained" onClick={publish} disabled={saving}>{page.status === 'published' ? 'Publish changes' : 'Publish'}</Button>}
                </Stack>
            }
        >
            <Stack spacing={3} sx={{ maxWidth: 960 }}>
                <Stack direction="row" spacing={1} useFlexGap sx={{ flexWrap: 'wrap', alignItems: 'center' }}>
                    <Chip variant="outlined" color={page.status === 'published' ? 'success' : 'default'} label={page.status === 'published' ? 'Published' : 'Draft'} />
                    {page.hasUnpublishedChanges && <Chip variant="outlined" color="warning" label="The live page is older than this draft" />}
                    {page.status === 'published' && (
                        <Typography component="a" href={page.url} target="_blank" rel="noopener" sx={{ color: 'primary.main', fontSize: 14 }}>{page.url}</Typography>
                    )}
                </Stack>

                {firstError && <Alert severity="error">{firstError}</Alert>}
                {problems.length > 0 && !dirty && (
                    <Alert severity="warning">
                        <Typography sx={{ fontWeight: 600, mb: 0.5 }}>Before this page can be published</Typography>
                        <Box component="ul" sx={{ m: 0, pl: 2.5 }}>{problems.map((p) => <li key={p}>{p}</li>)}</Box>
                    </Alert>
                )}

                <Card sx={{ p: 3 }}>
                    <Typography variant="h4" component="h2" sx={{ mb: 2.5 }}>Page settings</Typography>
                    <Stack spacing={2.5}>
                        <TextField label="Page name" required value={name} disabled={!canEdit} onChange={(e) => { setName(e.target.value); touch(); }} error={Boolean(errors.name)} helperText={errors.name} />
                        <TextField
                            label="Page address" required value={slug} disabled={!canEdit}
                            onChange={(e) => { setSlug(e.target.value.toLowerCase().replace(/[^a-z0-9-]+/g, '-')); touch(); }}
                            error={Boolean(errors.slug)} helperText={errors.slug ?? 'Changing the address of a live page breaks links you have already shared.'}
                            slotProps={{ input: { startAdornment: <InputAdornment position="start">/p/</InputAdornment> } }}
                        />
                        <Autocomplete
                            options={products} getOptionLabel={(o) => o.name} disabled={!canEdit} disableClearable={productId !== null}
                            value={product(productId) as ProductOption}
                            onChange={(_, value) => { setProductId(value?.id ?? null); touch(); }}
                            renderInput={(params) => <TextField {...params} label="Linked product" required error={Boolean(errors.product_id)} helperText={errors.product_id ?? 'Its reviews are used by the Reviews block, and its product page links to this page.'} />}
                        />
                    </Stack>
                </Card>

                <Card sx={{ p: 3 }}>
                    <Typography variant="h4" component="h2">Packs</Typography>
                    <Typography sx={{ color: 'text.secondary', mt: 0.5, mb: 2.5 }}>
                        One to four packs the customer chooses from. A pack is sold at its own fixed price, whatever its products cost in the catalog, and shows “Sold out” when any product in it is out of stock. Pack changes go live as soon as you save.
                    </Typography>
                    <Stack spacing={2}>
                        {offers.map((offer, o) => (
                            <Box key={o} sx={{ border: 1, borderColor: 'divider', borderRadius: 3, p: 2.5 }}>
                                <Stack direction="row" sx={{ justifyContent: 'space-between', alignItems: 'center', mb: 2 }}>
                                    <FormControlLabel
                                        control={<Radio checked={offer.is_default} disabled={!canEdit} onChange={() => changeOffers(offers.map((x, i) => ({ ...x, is_default: i === o })))} />}
                                        label="Selected when the page opens"
                                    />
                                    <Tooltip title={offers.length === 1 ? 'A page needs at least one pack' : 'Remove pack'}>
                                        <span>
                                            <IconButton aria-label={`Remove pack ${o + 1}`} disabled={!canEdit || offers.length === 1} onClick={() => changeOffers(offers.filter((_, i) => i !== o))}><DeleteOutlined fontSize="small" /></IconButton>
                                        </span>
                                    </Tooltip>
                                </Stack>
                                <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                                    <TextField label="Pack name" required fullWidth value={offer.label} disabled={!canEdit} onChange={(e) => patchOffer(o, { label: e.target.value })} error={Boolean(errors[`offers.${o}.label`])} helperText={errors[`offers.${o}.label`]} />
                                    <TextField
                                        label="Pack price" required value={offer.price} disabled={!canEdit} sx={{ minWidth: 160 }}
                                        onChange={(e) => patchOffer(o, { price: e.target.value })}
                                        error={Boolean(errors[`offers.${o}.price`])} helperText={errors[`offers.${o}.price`]}
                                        slotProps={{ input: { startAdornment: <InputAdornment position="start">$</InputAdornment> }, htmlInput: { inputMode: 'decimal' } }}
                                    />
                                </Stack>
                                <TextField label="Note under the name (optional), for example “Most popular”" fullWidth value={offer.note} disabled={!canEdit} onChange={(e) => patchOffer(o, { note: e.target.value })} sx={{ mt: 2 }} slotProps={{ htmlInput: { maxLength: 120 } }} />

                                <Typography sx={{ fontWeight: 600, fontSize: 14, mt: 2.5, mb: 1 }}>What is in this pack</Typography>
                                <Stack spacing={1.5}>
                                    {offer.items.map((item, i) => {
                                        const chosen = product(item.product_id);
                                        const setItem = (patch: Partial<OfferItem>) => patchOffer(o, { items: offer.items.map((x, j) => (j === i ? { ...x, ...patch } : x)) });
                                        const variantError = errors[`offers.${o}.items.${i}.variant_id`];
                                        return (
                                            <Stack key={i} direction={{ xs: 'column', md: 'row' }} spacing={1.5} sx={{ alignItems: { md: 'flex-start' } }}>
                                                <Autocomplete
                                                    sx={{ flex: 2 }} size="small" options={products} getOptionLabel={(p) => p.name} disabled={!canEdit} value={chosen}
                                                    onChange={(_, value) => setItem({ product_id: value?.id ?? null, variant_id: value?.variants[0]?.id ?? null })}
                                                    renderInput={(params) => <TextField {...params} label="Product" error={Boolean(errors[`offers.${o}.items.${i}.product_id`])} helperText={errors[`offers.${o}.items.${i}.product_id`] ? 'Choose a product.' : undefined} />}
                                                />
                                                {chosen && chosen.variants.length > 0 && (
                                                    <TextField select size="small" label="Option" sx={{ flex: 1, minWidth: 160 }} disabled={!canEdit} value={item.variant_id ?? ''} onChange={(e) => setItem({ variant_id: Number(e.target.value) })} error={Boolean(variantError)} helperText={variantError}>
                                                        {chosen.variants.map((v) => <MenuItem key={v.id} value={v.id}>{v.label}</MenuItem>)}
                                                    </TextField>
                                                )}
                                                <TextField
                                                    size="small" label="Quantity" type="number" sx={{ width: 110 }} disabled={!canEdit} value={item.quantity}
                                                    onChange={(e) => setItem({ quantity: Math.max(1, Math.min(99, Number(e.target.value) || 1)) })}
                                                    slotProps={{ htmlInput: { min: 1, max: 99 } }}
                                                />
                                                <IconButton aria-label={`Remove product ${i + 1} from pack ${o + 1}`} disabled={!canEdit || offer.items.length === 1} onClick={() => patchOffer(o, { items: offer.items.filter((_, j) => j !== i) })}><Close fontSize="small" /></IconButton>
                                            </Stack>
                                        );
                                    })}
                                    {canEdit && <Button startIcon={<Add />} sx={{ alignSelf: 'flex-start' }} onClick={() => patchOffer(o, { items: [...offer.items, { product_id: null, variant_id: null, quantity: 1 }] })}>Add product to pack</Button>}
                                </Stack>
                            </Box>
                        ))}
                        {canEdit && offers.length < 4 && (
                            <Button
                                variant="outlined" startIcon={<Add />} sx={{ alignSelf: 'flex-start' }}
                                onClick={() => {
                                    const p = product(productId);
                                    changeOffers([...offers, { label: '', price: p?.price ?? '', note: '', is_default: false, items: [{ product_id: p?.id ?? null, variant_id: p?.variants[0]?.id ?? null, quantity: 1 }] }]);
                                }}
                            >
                                Add pack
                            </Button>
                        )}
                    </Stack>
                </Card>

                <Card sx={{ p: 3 }}>
                    <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} sx={{ justifyContent: 'space-between', alignItems: { sm: 'center' } }}>
                        <Box>
                            <Typography variant="h4" component="h2">Page content</Typography>
                            <Typography sx={{ color: 'text.secondary', mt: 0.5 }}>
                                {blockCount === 1 ? '1 block' : `${blockCount} blocks`} on the page. Arrange blocks, edit text and images, and publish in the builder.
                            </Typography>
                        </Box>
                        <Button variant="contained" component={RouterLink} href={`/admin/selling-pages/${page.id}/builder`} startIcon={<DashboardCustomizeOutlined />} sx={{ flexShrink: 0 }}>Open builder</Button>
                    </Stack>
                </Card>
            </Stack>

        </AdminLayout>
    );
}
