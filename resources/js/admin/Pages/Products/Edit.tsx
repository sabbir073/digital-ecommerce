import { router, useForm } from '@inertiajs/react';
import Add from '@mui/icons-material/Add';
import ArrowBack from '@mui/icons-material/ArrowBack';
import DeleteOutline from '@mui/icons-material/DeleteOutlined';
import InsertDriveFileOutlined from '@mui/icons-material/InsertDriveFileOutlined';
import UploadFileOutlined from '@mui/icons-material/UploadFileOutlined';
import Alert from '@mui/material/Alert';
import Autocomplete from '@mui/material/Autocomplete';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import ButtonBase from '@mui/material/ButtonBase';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import InputAdornment from '@mui/material/InputAdornment';
import LinearProgress from '@mui/material/LinearProgress';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { type FormEvent, type ReactNode, useRef, useState } from 'react';
import AdminLayout from '../../components/AdminLayout';
import MediaPicker from '../../components/MediaPicker';
import RouterLink from '../../components/RouterLink';
import SortableImages from '../../components/SortableImages';
import { useCan } from '../../lib/can';
import { formatBytes, slugify } from '../../lib/format';
import type { MediaItem } from '../../lib/types';

interface OptionValue {
    value: string;
    swatch_hex: string | null;
}

interface Option {
    name: string;
    display: 'pill' | 'swatch';
    values: OptionValue[];
}

interface VariantRow {
    id?: number;
    values: string[];
    price: string;
    compare_at: string;
    sku: string;
    stock: number;
    media_id: number | null;
    image?: MediaItem | null;
}

interface DigitalFile {
    id: number;
    filename: string;
    sizeBytes: number;
    version: string | null;
    variantId: number | null;
}

interface ProductData {
    id: number;
    name: string;
    slug: string;
    short_description: string;
    description: string;
    category_id: number | null;
    tags: string[];
    status: 'draft' | 'active' | 'archived';
    price: string;
    compare_at: string;
    sku: string;
    brand: string;
    track_stock: boolean;
    stock: number;
    images: MediaItem[];
    options: Option[];
    variants: VariantRow[];
    files: DigitalFile[];
    seo: { meta_title: string; meta_description: string };
}

interface Props {
    product: ProductData | null;
    categories: { id: number; name: string }[];
    maxFileMb: number;
}

type FormShape = {
    name: string;
    slug: string;
    short_description: string;
    description: string;
    category_id: number | '';
    tags: string[];
    status: ProductData['status'];
    price: string;
    compare_at: string;
    sku: string;
    brand: string;
    track_stock: boolean;
    stock: number;
    image_ids: number[];
    options: Option[];
    variants: VariantRow[];
    seo: { meta_title: string; meta_description: string };
};

const keyOf = (values: string[]) => values.map((v) => v.trim().toLowerCase()).join('|');

/** Every combination of the option values, keeping the rows that already exist. */
function buildVariants(options: Option[], current: VariantRow[], basePrice: string): VariantRow[] {
    const usable = options.filter((o) => o.values.length > 0);
    if (usable.length === 0 || usable.length !== options.length) return usable.length === 0 ? [] : current;

    const combos = usable.reduce<string[][]>((acc, option) => acc.flatMap((prefix) => option.values.map((v) => [...prefix, v.value])), [[]]);
    const existing = new Map(current.map((row) => [keyOf(row.values), row]));

    return combos.map((values) => existing.get(keyOf(values)) ?? { values, price: basePrice || '0.00', compare_at: '', sku: '', stock: 0, media_id: null, image: null });
}

function Section({ title, hint, children }: { title: string; hint?: string; children: ReactNode }) {
    return (
        <Card>
            <CardContent>
                <Typography variant="h3" component="h2" sx={{ mb: hint ? 0.5 : 2.5 }}>{title}</Typography>
                {hint && <Typography sx={{ color: 'text.secondary', mb: 2.5 }}>{hint}</Typography>}
                {children}
            </CardContent>
        </Card>
    );
}

export default function ProductEdit({ product, categories, maxFileMb }: Props) {
    const can = useCan();
    const editable = product ? can('products', 'edit') : can('products', 'create');
    const [images, setImages] = useState<MediaItem[]>(product?.images ?? []);
    const [slugTouched, setSlugTouched] = useState(!!product);
    const [imagePicker, setImagePicker] = useState(false);
    const [variantPicker, setVariantPicker] = useState<number | null>(null);
    const [newValue, setNewValue] = useState<string[]>(['', '']);
    const [uploading, setUploading] = useState(false);
    const [fileError, setFileError] = useState<string | null>(null);
    const [fileVersion, setFileVersion] = useState('');
    const fileInput = useRef<HTMLInputElement>(null);

    const form = useForm<FormShape>({
        name: product?.name ?? '',
        slug: product?.slug ?? '',
        short_description: product?.short_description ?? '',
        description: product?.description ?? '',
        category_id: product?.category_id ?? '',
        tags: product?.tags ?? [],
        status: product?.status ?? 'draft',
        price: product?.price ?? '',
        compare_at: product?.compare_at ?? '',
        sku: product?.sku ?? '',
        brand: product?.brand ?? '',
        track_stock: product?.track_stock ?? false,
        stock: product?.stock ?? 0,
        image_ids: product?.images.map((i) => i.id) ?? [],
        options: product?.options ?? [],
        variants: product?.variants ?? [],
        seo: product?.seo ?? { meta_title: '', meta_description: '' },
    });
    const errors = form.errors as Record<string, string>;
    const data = form.data;
    const hasVariants = data.options.length > 0;

    const setOptions = (options: Option[]) => {
        form.setData((current) => ({ ...current, options, variants: buildVariants(options, current.variants, current.price) }));
    };

    const updateImages = (next: MediaItem[]) => {
        setImages(next);
        form.setData('image_ids', next.map((i) => i.id));
    };

    const addValue = (optionIndex: number) => {
        const value = newValue[optionIndex].trim();
        if (!value) return;
        const option = data.options[optionIndex];
        if (option.values.some((v) => v.value.toLowerCase() === value.toLowerCase())) return;
        setOptions(data.options.map((o, i) => (i === optionIndex ? { ...o, values: [...o.values, { value, swatch_hex: o.display === 'swatch' ? '#111317' : null }] } : o)));
        setNewValue((current) => current.map((v, i) => (i === optionIndex ? '' : v)));
    };

    const setVariant = (index: number, patch: Partial<VariantRow>) => {
        form.setData('variants', data.variants.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((current) => ({
            ...current,
            category_id: current.category_id === '' ? null : current.category_id,
            compare_at: current.compare_at === '' ? null : current.compare_at,
            variants: current.variants.map(({ image: _image, ...row }) => ({ ...row, compare_at: row.compare_at === '' ? null : row.compare_at })),
        }));
        if (product) form.put(`/admin/products/${product.id}`, { preserveScroll: true });
        else form.post('/admin/products');
    };

    const uploadFile = (files: FileList | null) => {
        if (!files?.[0] || !product) return;
        setFileError(null);
        router.post(`/admin/products/${product.id}/files`, { file: files[0], version: fileVersion || null }, {
            forceFormData: true,
            preserveScroll: true,
            onStart: () => setUploading(true),
            onError: (e) => setFileError(Object.values(e)[0] ?? 'Upload failed.'),
            onSuccess: () => setFileVersion(''),
            onFinish: () => {
                setUploading(false);
                if (fileInput.current) fileInput.current.value = '';
            },
        });
    };

    const money = { startAdornment: <InputAdornment position="start">$</InputAdornment> };
    const errorCount = Object.keys(errors).length;

    return (
        <AdminLayout
            title={product ? product.name : 'New product'}
            actions={
                <Stack direction="row" spacing={1}>
                    <Button component={RouterLink} href="/admin/products" variant="outlined" startIcon={<ArrowBack />}>Products</Button>
                    {product?.status === 'active' && <Button component="a" href={`/product/${product.slug}`} target="_blank" rel="noopener" variant="outlined">View</Button>}
                    {editable && <Button variant="contained" loading={form.processing} onClick={submit}>{product ? 'Save' : 'Create product'}</Button>}
                </Stack>
            }
        >
            <Box component="form" onSubmit={submit} noValidate sx={{ display: 'grid', gap: 3, gridTemplateColumns: { xs: '1fr', lg: 'minmax(0, 2fr) minmax(280px, 1fr)' }, alignItems: 'start' }}>
                <Stack spacing={3} sx={{ minWidth: 0 }}>
                    {errorCount > 0 && <Alert severity="error">Please fix {errorCount === 1 ? 'the field' : `the ${errorCount} fields`} marked below.</Alert>}

                    <Section title="Basics">
                        <Stack spacing={2.5}>
                            <TextField label="Name" required disabled={!editable} value={data.name} onChange={(e) => form.setData((c) => ({ ...c, name: e.target.value, slug: slugTouched ? c.slug : slugify(e.target.value) }))} error={!!errors.name} helperText={errors.name} />
                            <TextField label="URL slug" required disabled={!editable} value={data.slug} onChange={(e) => { setSlugTouched(true); form.setData('slug', e.target.value); }} error={!!errors.slug} helperText={errors.slug ?? `Page address: /product/${data.slug || 'your-slug'}`} />
                            <TextField label="Short description" multiline minRows={2} disabled={!editable} value={data.short_description} onChange={(e) => form.setData('short_description', e.target.value)} error={!!errors.short_description} helperText={errors.short_description ?? `${data.short_description.length}/500. Shown next to the price.`} />
                            <TextField label="Details" multiline minRows={6} disabled={!editable} value={data.description} onChange={(e) => form.setData('description', e.target.value)} error={!!errors.description} helperText={errors.description ?? 'Shown in the Details section of the product page. Leave a blank line between paragraphs.'} />
                        </Stack>
                    </Section>

                    <Section title="Images" hint="Drag to reorder. The first image is the main one.">
                        {images.length > 0 && <Box sx={{ mb: 2 }}><SortableImages items={images} disabled={!editable} onChange={updateImages} /></Box>}
                        {images.some((i) => !i.alt) && <Alert severity="warning" sx={{ mb: 2 }}>Some images have no alt text. Add it in the media library. Until then the product name is used.</Alert>}
                        {editable && <Button variant="outlined" startIcon={<Add />} onClick={() => setImagePicker(true)}>Add images</Button>}
                        {errors.image_ids && <Typography color="error" sx={{ mt: 1 }}>{errors.image_ids}</Typography>}
                    </Section>

                    <Section title="Price and stock">
                        <Stack spacing={2.5}>
                            <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: 'repeat(3, 1fr)' } }}>
                                <TextField label="Price" required disabled={!editable} value={data.price} onChange={(e) => form.setData('price', e.target.value)} error={!!errors.price} helperText={errors.price ?? (hasVariants ? 'Default for new variants.' : undefined)} slotProps={{ input: money, htmlInput: { inputMode: 'decimal' } }} />
                                <TextField label="Compare-at price" disabled={!editable} value={data.compare_at} onChange={(e) => form.setData('compare_at', e.target.value)} error={!!errors.compare_at} helperText={errors.compare_at ?? 'Shown crossed out when on sale.'} slotProps={{ input: money, htmlInput: { inputMode: 'decimal' } }} />
                                <TextField label="SKU" disabled={!editable} value={data.sku} onChange={(e) => form.setData('sku', e.target.value)} error={!!errors.sku} helperText={errors.sku} />
                            </Box>
                            <FormControlLabel control={<Switch checked={data.track_stock} disabled={!editable} onChange={(e) => form.setData('track_stock', e.target.checked)} />} label="Limit how many can be sold (track stock)" />
                            {data.track_stock && !hasVariants && (
                                <TextField label="Units in stock" type="number" disabled={!editable} value={data.stock} onChange={(e) => form.setData('stock', Math.max(0, Number(e.target.value)))} error={!!errors.stock} helperText={errors.stock} sx={{ maxWidth: 220 }} slotProps={{ htmlInput: { min: 0 } }} />
                            )}
                            {!data.track_stock && <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>Digital products usually have no limit. Turn this on for limited editions or seats.</Typography>}
                        </Stack>
                    </Section>

                    <Section title="Variants" hint="Up to two options, for example Licence and Format. Each combination gets its own price, SKU, stock and image.">
                        <Stack spacing={2.5}>
                            {data.options.map((option, optionIndex) => (
                                <Box key={optionIndex} sx={{ border: 1, borderColor: 'divider', borderRadius: 3, p: 2 }}>
                                    <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap', alignItems: 'flex-start' }}>
                                        <TextField label="Option name" size="small" disabled={!editable} value={option.name} onChange={(e) => setOptions(data.options.map((o, i) => (i === optionIndex ? { ...o, name: e.target.value } : o)))} error={!!errors[`options.${optionIndex}.name`]} helperText={errors[`options.${optionIndex}.name`]} sx={{ flex: '1 1 180px' }} />
                                        <TextField select label="Shown as" size="small" disabled={!editable} value={option.display} onChange={(e) => setOptions(data.options.map((o, i) => (i === optionIndex ? { ...o, display: e.target.value as Option['display'], values: o.values.map((v) => ({ ...v, swatch_hex: e.target.value === 'swatch' ? v.swatch_hex ?? '#111317' : null })) } : o)))} sx={{ flex: '0 1 160px' }}>
                                            <MenuItem value="pill">Pills</MenuItem>
                                            <MenuItem value="swatch">Colour swatches</MenuItem>
                                        </TextField>
                                        {editable && <IconButton aria-label={`Remove option ${option.name || optionIndex + 1}`} onClick={() => setOptions(data.options.filter((_, i) => i !== optionIndex))}><DeleteOutline /></IconButton>}
                                    </Box>
                                    <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1, mt: 2, alignItems: 'center' }}>
                                        {option.values.map((value, valueIndex) => (
                                            <Chip
                                                key={value.value}
                                                label={value.value}
                                                onDelete={editable ? () => setOptions(data.options.map((o, i) => (i === optionIndex ? { ...o, values: o.values.filter((_, vi) => vi !== valueIndex) } : o))) : undefined}
                                                icon={option.display === 'swatch' ? (
                                                    <Box component="input" type="color" aria-label={`Colour for ${value.value}`} disabled={!editable} value={value.swatch_hex ?? '#111317'}
                                                        onChange={(e) => setOptions(data.options.map((o, i) => (i === optionIndex ? { ...o, values: o.values.map((v, vi) => (vi === valueIndex ? { ...v, swatch_hex: e.target.value } : v)) } : o)))}
                                                        sx={{ width: 22, height: 22, p: 0, border: 0, background: 'none', cursor: 'pointer', ml: '6px !important' }} />
                                                ) : undefined}
                                            />
                                        ))}
                                        {editable && (
                                            <TextField
                                                size="small"
                                                placeholder="Add a value, press Enter"
                                                value={newValue[optionIndex]}
                                                onChange={(e) => setNewValue((c) => c.map((v, i) => (i === optionIndex ? e.target.value : v)))}
                                                onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addValue(optionIndex); } }}
                                                onBlur={() => addValue(optionIndex)}
                                                sx={{ width: 220 }}
                                                slotProps={{ htmlInput: { 'aria-label': `New value for ${option.name || 'option'}` } }}
                                            />
                                        )}
                                    </Box>
                                    {errors[`options.${optionIndex}.values`] && <Typography color="error" sx={{ mt: 1, fontSize: 13 }}>{errors[`options.${optionIndex}.values`]}</Typography>}
                                </Box>
                            ))}

                            {editable && data.options.length < 2 && (
                                <Button variant="outlined" startIcon={<Add />} sx={{ alignSelf: 'flex-start' }} onClick={() => setOptions([...data.options, { name: '', display: 'pill', values: [] }])}>
                                    Add option
                                </Button>
                            )}
                            {errors.variants && <Typography color="error">{errors.variants}</Typography>}

                            {data.variants.length > 0 && (
                                <TableContainer sx={{ border: 1, borderColor: 'divider', borderRadius: 3 }}>
                                    <Table size="small" aria-label="Variants">
                                        <TableHead>
                                            <TableRow>
                                                <TableCell>Image</TableCell>
                                                <TableCell>Variant</TableCell>
                                                <TableCell>Price</TableCell>
                                                <TableCell>SKU</TableCell>
                                                {data.track_stock && <TableCell>Stock</TableCell>}
                                            </TableRow>
                                        </TableHead>
                                        <TableBody>
                                            {data.variants.map((row, index) => (
                                                <TableRow key={keyOf(row.values)}>
                                                    <TableCell>
                                                        <ButtonBase disabled={!editable} onClick={() => setVariantPicker(index)} aria-label={`Choose image for ${row.values.join(' / ')}`} sx={{ width: 44, height: 44, borderRadius: 2, overflow: 'hidden', border: 1, borderColor: 'divider', bgcolor: 'background.default' }}>
                                                            {row.image?.thumb ? <img src={row.image.thumb} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} /> : <Add fontSize="small" />}
                                                        </ButtonBase>
                                                    </TableCell>
                                                    <TableCell sx={{ fontWeight: 600, whiteSpace: 'nowrap' }}>{row.values.join(' / ')}</TableCell>
                                                    <TableCell>
                                                        <TextField size="small" disabled={!editable} value={row.price} onChange={(e) => setVariant(index, { price: e.target.value })} error={!!errors[`variants.${index}.price`]} sx={{ minWidth: 110 }} slotProps={{ input: money, htmlInput: { inputMode: 'decimal', 'aria-label': `Price for ${row.values.join(' / ')}` } }} />
                                                    </TableCell>
                                                    <TableCell>
                                                        <TextField size="small" disabled={!editable} value={row.sku} onChange={(e) => setVariant(index, { sku: e.target.value })} sx={{ minWidth: 120 }} slotProps={{ htmlInput: { 'aria-label': `SKU for ${row.values.join(' / ')}` } }} />
                                                    </TableCell>
                                                    {data.track_stock && (
                                                        <TableCell>
                                                            <TextField size="small" type="number" disabled={!editable} value={row.stock} onChange={(e) => setVariant(index, { stock: Math.max(0, Number(e.target.value)) })} sx={{ width: 100 }} slotProps={{ htmlInput: { min: 0, 'aria-label': `Stock for ${row.values.join(' / ')}` } }} />
                                                        </TableCell>
                                                    )}
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </TableContainer>
                            )}
                        </Stack>
                    </Section>

                    <Section title="Digital files" hint="The files a buyer downloads after paying. They are stored privately and are only reachable through each buyer's own download link.">
                        {!product ? (
                            <Typography sx={{ color: 'text.secondary' }}>Create the product first, then upload its files here.</Typography>
                        ) : (
                            <Stack spacing={2}>
                                {product.files.length === 0 && <Alert severity="warning">This product has no file yet. Buyers would have nothing to download.</Alert>}
                                {product.files.map((file) => (
                                    <Box key={file.id} sx={{ display: 'flex', alignItems: 'center', gap: 1.5, border: 1, borderColor: 'divider', borderRadius: 3, p: 1.5 }}>
                                        <InsertDriveFileOutlined color="action" />
                                        <Box sx={{ flex: 1, minWidth: 0 }}>
                                            <Typography noWrap sx={{ fontWeight: 600 }}>{file.filename}</Typography>
                                            <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>{formatBytes(file.sizeBytes)}{file.version ? ` · Version ${file.version}` : ''}</Typography>
                                        </Box>
                                        {editable && <IconButton aria-label={`Remove ${file.filename}`} onClick={() => router.delete(`/admin/products/${product.id}/files/${file.id}`, { preserveScroll: true })}><DeleteOutline /></IconButton>}
                                    </Box>
                                ))}
                                {uploading && <LinearProgress sx={{ borderRadius: 2 }} />}
                                {fileError && <Typography color="error" role="alert">{fileError}</Typography>}
                                {editable && (
                                    <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap', alignItems: 'center' }}>
                                        <TextField size="small" label="Version (optional)" value={fileVersion} onChange={(e) => setFileVersion(e.target.value)} sx={{ width: 180 }} />
                                        <input ref={fileInput} type="file" hidden onChange={(e) => uploadFile(e.target.files)} />
                                        <Button variant="outlined" startIcon={<UploadFileOutlined />} loading={uploading} onClick={() => fileInput.current?.click()}>Upload file</Button>
                                        <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>Up to {maxFileMb} MB.</Typography>
                                    </Box>
                                )}
                            </Stack>
                        )}
                    </Section>
                </Stack>

                <Stack spacing={3}>
                    <Section title="Status">
                        <TextField select label="Status" disabled={!editable || !can('products', 'publish')} value={data.status} onChange={(e) => form.setData('status', e.target.value as FormShape['status'])} error={!!errors.status} helperText={errors.status ?? (data.status === 'active' ? 'Visible on the storefront.' : 'Hidden from the storefront.')}>
                            <MenuItem value="draft">Draft</MenuItem>
                            <MenuItem value="active">Active</MenuItem>
                            <MenuItem value="archived">Archived</MenuItem>
                        </TextField>
                    </Section>

                    <Section title="Organisation">
                        <Stack spacing={2.5}>
                            <TextField select label="Category" disabled={!editable} value={data.category_id} onChange={(e) => form.setData('category_id', e.target.value === '' ? '' : Number(e.target.value))} error={!!errors.category_id} helperText={errors.category_id} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}>
                                <MenuItem value="">No category</MenuItem>
                                {categories.map((c) => <MenuItem key={c.id} value={c.id}>{c.name}</MenuItem>)}
                            </TextField>
                            <Autocomplete
                                multiple
                                freeSolo
                                disabled={!editable}
                                options={[] as string[]}
                                value={data.tags}
                                onChange={(_, value) => form.setData('tags', value.map((v) => v.trim()).filter(Boolean))}
                                renderInput={(params) => <TextField {...params} label="Tags" helperText="Type a tag and press Enter. Search matches tags." />}
                            />
                            <TextField label="Brand" disabled={!editable} value={data.brand} onChange={(e) => form.setData('brand', e.target.value)} error={!!errors.brand} helperText={errors.brand} />
                        </Stack>
                    </Section>

                    <Section title="Search engines">
                        <Stack spacing={2.5}>
                            <TextField label="SEO title" disabled={!editable} value={data.seo.meta_title} onChange={(e) => form.setData('seo', { ...data.seo, meta_title: e.target.value })} helperText={`${data.seo.meta_title.length}/60. Empty uses the product name.`} error={data.seo.meta_title.length > 60} />
                            <TextField label="SEO description" multiline minRows={3} disabled={!editable} value={data.seo.meta_description} onChange={(e) => form.setData('seo', { ...data.seo, meta_description: e.target.value })} helperText={`${data.seo.meta_description.length}/160. Empty uses the short description.`} error={data.seo.meta_description.length > 160} />
                        </Stack>
                    </Section>
                </Stack>
            </Box>

            <MediaPicker open={imagePicker} multiple onClose={() => setImagePicker(false)} onSelect={(items) => updateImages([...images, ...items.filter((item) => !images.some((i) => i.id === item.id))])} />
            <MediaPicker open={variantPicker !== null} onClose={() => setVariantPicker(null)} onSelect={([item]) => { if (variantPicker !== null) setVariant(variantPicker, { media_id: item.id, image: item }); }} />
        </AdminLayout>
    );
}
