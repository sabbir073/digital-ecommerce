import { router } from '@inertiajs/react';
import Add from '@mui/icons-material/Add';
import ArrowDownward from '@mui/icons-material/ArrowDownward';
import ArrowUpward from '@mui/icons-material/ArrowUpward';
import Close from '@mui/icons-material/Close';
import ContentCopyOutlined from '@mui/icons-material/ContentCopyOutlined';
import DeleteOutlined from '@mui/icons-material/DeleteOutlined';
import ExpandMore from '@mui/icons-material/ExpandMore';
import OpenInNew from '@mui/icons-material/OpenInNew';
import VisibilityOffOutlined from '@mui/icons-material/VisibilityOffOutlined';
import VisibilityOutlined from '@mui/icons-material/VisibilityOutlined';
import Accordion from '@mui/material/Accordion';
import AccordionDetails from '@mui/material/AccordionDetails';
import AccordionSummary from '@mui/material/AccordionSummary';
import Alert from '@mui/material/Alert';
import Autocomplete from '@mui/material/Autocomplete';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import InputAdornment from '@mui/material/InputAdornment';
import Menu from '@mui/material/Menu';
import MenuItem from '@mui/material/MenuItem';
import Radio from '@mui/material/Radio';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { useEffect, useState } from 'react';
import AdminLayout from '../../components/AdminLayout';
import BlockFields, { type FieldDef, type MediaMap, type ReviewOption } from '../../components/BlockFields';
import ConfirmDialog from '../../components/ConfirmDialog';
import { useCan } from '../../lib/can';
import type { MediaItem } from '../../lib/types';
import type { ProductOption } from './Index';

interface Block {
    id: string;
    type: string;
    hidden: boolean;
    style: { background: string; spacing: string; align: string };
    data: Record<string, unknown>;
}

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
    blocks: Block[];
    offers: Offer[];
    library: Record<string, { label: string; fields: FieldDef[] }>;
    products: ProductOption[];
    media: MediaMap;
    reviews: ReviewOption[];
    problems: string[];
}

const newId = () => `b${Date.now().toString(36)}${Math.random().toString(36).slice(2, 10)}`;

/** Starting content for a new block, taken from the defaults in the block library. */
function blankData(fields: FieldDef[]): Record<string, unknown> {
    const blank = (field: FieldDef): unknown => {
        const preset = (field as FieldDef & { default?: unknown }).default;
        if (preset !== undefined) return preset;
        switch (field.type) {
            case 'toggle':
                return false;
            case 'image':
            case 'video':
            case 'datetime':
                return null;
            case 'images':
            case 'reviews':
            case 'strings':
                return [];
            case 'list':
                return Array.from({ length: field.min ?? 1 }, () => Object.fromEntries((field.fields ?? []).map((f) => [f.key, blank(f)])));
            case 'table':
                return { columns: ['', ''], rows: [['', '']] };
            default:
                return '';
        }
    };
    return Object.fromEntries(fields.map((f) => [f.key, blank(f)]));
}

const DEFAULT_BG: Record<string, string> = { benefits: 'soft', order: 'soft', offer_bar: 'dark' };

/** A short line that tells blocks of the same type apart in the list. */
function summarise(block: Block): string {
    const d = block.data as Record<string, unknown>;
    const text = [d.headline, d.heading, d.text, d.title].find((v) => typeof v === 'string' && v !== '') as string | undefined;
    return text ? (text.length > 60 ? `${text.slice(0, 60)}…` : text) : '';
}

export default function SellingPageEdit({ page, blocks: savedBlocks, offers: savedOffers, library, products, media: savedMedia, reviews, problems }: Props) {
    const can = useCan();
    const canEdit = can('selling_pages', 'edit');
    const canPublish = can('selling_pages', 'publish');

    const [name, setName] = useState(page.name);
    const [slug, setSlug] = useState(page.slug);
    const [productId, setProductId] = useState<number | null>(page.productId);
    const [offers, setOffers] = useState<Offer[]>(savedOffers);
    const [blocks, setBlocks] = useState<Block[]>(savedBlocks);
    const [media, setMedia] = useState<MediaMap>(savedMedia);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [dirty, setDirty] = useState(false);
    const [saving, setSaving] = useState(false);
    const [expanded, setExpanded] = useState<string | null>(null);
    const [addAnchor, setAddAnchor] = useState<HTMLElement | null>(null);
    const [removing, setRemoving] = useState<Block | null>(null);

    // After a save the server sends back the cleaned draft; show exactly what was stored.
    useEffect(() => {
        setBlocks(savedBlocks);
        setOffers(savedOffers);
        setMedia((current) => ({ ...current, ...savedMedia }));
    }, [savedBlocks, savedOffers, savedMedia]);

    useEffect(() => {
        if (!dirty) return;
        const warn = (event: BeforeUnloadEvent) => event.preventDefault();
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);

    const touch = () => setDirty(true);
    const changeBlocks = (next: Block[]) => { setBlocks(next); touch(); };
    const changeOffers = (next: Offer[]) => { setOffers(next); touch(); };
    const patchBlock = (id: string, patch: Partial<Block>) => changeBlocks(blocks.map((b) => (b.id === id ? { ...b, ...patch } : b)));
    const patchOffer = (index: number, patch: Partial<Offer>) => changeOffers(offers.map((o, i) => (i === index ? { ...o, ...patch } : o)));

    const move = (index: number, by: number) => {
        const next = [...blocks];
        const [block] = next.splice(index, 1);
        next.splice(index + by, 0, block);
        changeBlocks(next);
    };

    const addBlock = (type: string) => {
        const block: Block = { id: newId(), type, hidden: false, style: { background: DEFAULT_BG[type] ?? 'white', spacing: 'md', align: 'left' }, data: blankData(library[type].fields) };
        // New content goes above the order block, which normally closes the page.
        const orderAt = blocks.findIndex((b) => b.type === 'order');
        const next = [...blocks];
        next.splice(type === 'order' || orderAt === -1 ? next.length : orderAt, 0, block);
        changeBlocks(next);
        setExpanded(block.id);
        setAddAnchor(null);
    };

    const save = (then?: () => void) => {
        setSaving(true);
        router.put(
            `/admin/selling-pages/${page.id}`,
            { name, slug, product_id: productId, blocks, offers } as never,
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

    const hasOrder = blocks.some((b) => b.type === 'order');
    const product = (id: number | null) => products.find((p) => p.id === id) ?? null;
    const firstError = Object.values(errors)[0];

    return (
        <AdminLayout
            title={page.name}
            actions={
                <Stack direction="row" spacing={1} useFlexGap sx={{ flexWrap: 'wrap' }}>
                    <Tooltip title={dirty ? 'Save the draft first to preview your latest changes' : ''}>
                        <span>
                            <Button component="a" href={page.previewUrl} target="_blank" rel="noopener" startIcon={<OpenInNew />} disabled={dirty}>Preview</Button>
                        </span>
                    </Tooltip>
                    {canEdit && <Button variant="outlined" onClick={() => save()} disabled={saving || !dirty}>{dirty ? 'Save draft' : 'Saved'}</Button>}
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
                    <Stack direction="row" sx={{ justifyContent: 'space-between', alignItems: 'center', mb: 0.5 }}>
                        <Typography variant="h4" component="h2">Page content</Typography>
                        {canEdit && <Button variant="outlined" startIcon={<Add />} onClick={(e) => setAddAnchor(e.currentTarget)} aria-haspopup="menu">Add block</Button>}
                    </Stack>
                    <Typography sx={{ color: 'text.secondary', mb: 2.5 }}>
                        Blocks appear on the page in this order. Customers see your changes only after you publish.
                    </Typography>

                    <Menu anchorEl={addAnchor} open={Boolean(addAnchor)} onClose={() => setAddAnchor(null)}>
                        {Object.entries(library).map(([type, definition]) => (
                            <MenuItem key={type} disabled={type === 'order' && hasOrder} onClick={() => addBlock(type)}>
                                {definition.label}{type === 'order' && hasOrder ? ' (already on the page)' : ''}
                            </MenuItem>
                        ))}
                    </Menu>

                    {blocks.length === 0 && <Typography sx={{ color: 'text.secondary' }}>This page has no blocks yet. Add an Order block first: customers pay in it.</Typography>}

                    {blocks.map((block, index) => {
                        const definition = library[block.type];
                        const summary = summarise(block);
                        return (
                            <Accordion key={block.id} expanded={expanded === block.id} onChange={(_, open) => setExpanded(open ? block.id : null)} disableGutters variant="outlined" sx={{ mb: 1.5, borderRadius: '12px !important', '&:before': { display: 'none' }, opacity: block.hidden ? 0.65 : 1 }}>
                                <AccordionSummary expandIcon={<ExpandMore />} aria-controls={`block-${block.id}`} id={`block-${block.id}-header`} component="div">
                                    <Stack direction="row" spacing={1} sx={{ alignItems: 'center', width: '100%', minWidth: 0, pr: 1 }}>
                                        <Typography sx={{ fontWeight: 600, whiteSpace: 'nowrap' }}>{definition.label}</Typography>
                                        {block.hidden && <Chip size="small" label="Hidden" />}
                                        <Typography noWrap sx={{ color: 'text.secondary', fontSize: 14, flex: 1, minWidth: 0 }}>{summary}</Typography>
                                        {canEdit && (
                                            <Stack direction="row" onClick={(e) => e.stopPropagation()} sx={{ flexShrink: 0 }}>
                                                <Tooltip title="Move up"><span><IconButton aria-label={`Move ${definition.label} up`} disabled={index === 0} onClick={() => move(index, -1)}><ArrowUpward fontSize="small" /></IconButton></span></Tooltip>
                                                <Tooltip title="Move down"><span><IconButton aria-label={`Move ${definition.label} down`} disabled={index === blocks.length - 1} onClick={() => move(index, 1)}><ArrowDownward fontSize="small" /></IconButton></span></Tooltip>
                                                <Tooltip title={block.hidden ? 'Show on the page' : 'Hide from the page'}>
                                                    <IconButton aria-label={block.hidden ? `Show ${definition.label}` : `Hide ${definition.label}`} onClick={() => patchBlock(block.id, { hidden: !block.hidden })}>
                                                        {block.hidden ? <VisibilityOffOutlined fontSize="small" /> : <VisibilityOutlined fontSize="small" />}
                                                    </IconButton>
                                                </Tooltip>
                                                {block.type !== 'order' && (
                                                    <Tooltip title="Duplicate">
                                                        <IconButton aria-label={`Duplicate ${definition.label}`} onClick={() => { const copy = { ...structuredClone(block), id: newId() }; const next = [...blocks]; next.splice(index + 1, 0, copy); changeBlocks(next); }}>
                                                            <ContentCopyOutlined fontSize="small" />
                                                        </IconButton>
                                                    </Tooltip>
                                                )}
                                                <Tooltip title="Delete"><IconButton aria-label={`Delete ${definition.label}`} onClick={() => setRemoving(block)}><DeleteOutlined fontSize="small" /></IconButton></Tooltip>
                                            </Stack>
                                        )}
                                    </Stack>
                                </AccordionSummary>
                                <AccordionDetails sx={{ pt: 1, pb: 3 }}>
                                    <BlockFields
                                        fields={definition.fields} data={block.data} disabled={!canEdit} media={media} reviews={reviews}
                                        onMedia={(items: MediaItem[]) => setMedia((current) => ({ ...current, ...Object.fromEntries(items.map((i) => [i.id, i])) }))}
                                        onChange={(data) => patchBlock(block.id, { data })}
                                    />
                                    {block.type === 'order' && <Typography sx={{ color: 'text.secondary', fontSize: 14, mt: 2 }}>The packs shown in this block are set under “Packs” above.</Typography>}
                                    <Typography sx={{ fontWeight: 600, fontSize: 14, mt: 3, mb: 1.5 }}>Style</Typography>
                                    <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                                        <TextField select size="small" label="Background" value={block.style.background} disabled={!canEdit} sx={{ minWidth: 160 }} onChange={(e) => patchBlock(block.id, { style: { ...block.style, background: e.target.value } })}>
                                            <MenuItem value="white">White</MenuItem>
                                            <MenuItem value="soft">Soft grey</MenuItem>
                                            <MenuItem value="dark">Dark</MenuItem>
                                        </TextField>
                                        <TextField select size="small" label="Spacing" value={block.style.spacing} disabled={!canEdit} sx={{ minWidth: 160 }} onChange={(e) => patchBlock(block.id, { style: { ...block.style, spacing: e.target.value } })}>
                                            <MenuItem value="sm">Small</MenuItem>
                                            <MenuItem value="md">Medium</MenuItem>
                                            <MenuItem value="lg">Large</MenuItem>
                                        </TextField>
                                        <TextField select size="small" label="Text alignment" value={block.style.align} disabled={!canEdit} sx={{ minWidth: 160 }} onChange={(e) => patchBlock(block.id, { style: { ...block.style, align: e.target.value } })}>
                                            <MenuItem value="left">Left</MenuItem>
                                            <MenuItem value="center">Centre</MenuItem>
                                        </TextField>
                                    </Stack>
                                </AccordionDetails>
                            </Accordion>
                        );
                    })}
                </Card>
            </Stack>

            <ConfirmDialog
                open={removing !== null}
                title={`Delete this ${removing ? library[removing.type].label : ''} block?`}
                text="Its content is removed from the draft. The live page keeps it until you publish."
                confirmLabel="Delete block"
                danger
                onClose={() => setRemoving(null)}
                onConfirm={() => { if (removing) changeBlocks(blocks.filter((b) => b.id !== removing.id)); setRemoving(null); }}
            />
        </AdminLayout>
    );
}
