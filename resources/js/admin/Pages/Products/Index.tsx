import { router } from '@inertiajs/react';
import Add from '@mui/icons-material/Add';
import FileDownloadOutlined from '@mui/icons-material/FileDownloadOutlined';
import FileUploadOutlined from '@mui/icons-material/FileUploadOutlined';
import MoreVert from '@mui/icons-material/MoreVert';
import SearchOutlined from '@mui/icons-material/SearchOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import IconButton from '@mui/material/IconButton';
import InputAdornment from '@mui/material/InputAdornment';
import Menu from '@mui/material/Menu';
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
import ConfirmDialog from '../../components/ConfirmDialog';
import RouterLink from '../../components/RouterLink';
import { useCan } from '../../lib/can';
import { formatMoney } from '../../lib/format';
import type { Paginated } from '../../lib/types';

interface Row {
    id: number;
    name: string;
    slug: string;
    status: 'draft' | 'active' | 'archived';
    category: string | null;
    priceCents: number;
    hasVariants: boolean;
    variantCount: number;
    trackStock: boolean;
    stock: number;
    thumb: string | null;
}

interface Filters {
    search?: string;
    status?: string;
    category?: number;
}

interface Props {
    products: Paginated<Row>;
    filters: Filters;
    categories: { id: number; name: string }[];
}

const STATUS = {
    active: { label: 'Active', color: 'success' },
    draft: { label: 'Draft', color: 'warning' },
    archived: { label: 'Archived', color: 'default' },
} as const;

export default function ProductsIndex({ products, filters, categories }: Props) {
    const can = useCan();
    const [search, setSearch] = useState(filters.search ?? '');
    const [menu, setMenu] = useState<{ anchor: HTMLElement; row: Row } | null>(null);
    const [deleting, setDeleting] = useState<Row | null>(null);
    const [busy, setBusy] = useState(false);
    const [importing, setImporting] = useState(false);
    const importInput = useRef<HTMLInputElement>(null);
    const first = useRef(true);

    const apply = (next: Filters) => {
        const query: Record<string, string | number> = {};
        Object.entries({ ...filters, ...next }).forEach(([key, value]) => {
            if (value !== undefined && value !== '' && value !== 0) query[key] = value as string | number;
        });
        router.get('/admin/products', query, { preserveState: true, preserveScroll: true, replace: true });
    };

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const timer = setTimeout(() => apply({ search }), 350);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const setStatus = (row: Row, status: Row['status']) => router.post(`/admin/products/${row.id}/status`, { status }, { preserveScroll: true });

    const importCsv = (files: FileList | null) => {
        if (!files?.[0]) return;
        router.post('/admin/products/import', { file: files[0] }, {
            forceFormData: true,
            onStart: () => setImporting(true),
            onFinish: () => {
                setImporting(false);
                if (importInput.current) importInput.current.value = '';
            },
        });
    };

    const filtered = !!(filters.search || filters.status || filters.category);

    return (
        <AdminLayout
            title="Products"
            actions={
                <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap', rowGap: 1 }}>
                    <Button component="a" href="/admin/products/export" variant="outlined" startIcon={<FileDownloadOutlined />}>Export CSV</Button>
                    {can('products', 'create') && (
                        <>
                            <input ref={importInput} type="file" hidden accept=".csv,text/csv" onChange={(e) => importCsv(e.target.files)} />
                            <Button variant="outlined" startIcon={<FileUploadOutlined />} loading={importing} onClick={() => importInput.current?.click()}>Import CSV</Button>
                            <Button component={RouterLink} href="/admin/products/create" variant="contained" startIcon={<Add />}>New product</Button>
                        </>
                    )}
                </Stack>
            }
        >
            <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 2, mb: 2 }}>
                <TextField label="Search" placeholder="Name or SKU" value={search} onChange={(e) => setSearch(e.target.value)} sx={{ flex: '2 1 260px' }} slotProps={{ input: { startAdornment: <InputAdornment position="start"><SearchOutlined /></InputAdornment> } }} />
                <TextField select label="Status" value={filters.status ?? ''} onChange={(e) => apply({ status: e.target.value })} sx={{ flex: '1 1 160px' }} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}>
                    <MenuItem value="">Active and draft</MenuItem>
                    <MenuItem value="active">Active</MenuItem>
                    <MenuItem value="draft">Draft</MenuItem>
                    <MenuItem value="archived">Archived</MenuItem>
                </TextField>
                <TextField select label="Category" value={filters.category ?? 0} onChange={(e) => apply({ category: Number(e.target.value) })} sx={{ flex: '1 1 200px' }}>
                    <MenuItem value={0}>All categories</MenuItem>
                    {categories.map((c) => <MenuItem key={c.id} value={c.id}>{c.name}</MenuItem>)}
                </TextField>
            </Box>

            <Card>
                {products.data.length === 0 ? (
                    <Box sx={{ p: 6, textAlign: 'center' }}>
                        <Typography variant="h4" component="p" sx={{ mb: 0.5 }}>{filtered ? 'No products match' : 'No products yet'}</Typography>
                        <Typography sx={{ color: 'text.secondary' }}>{filtered ? 'Try a different search or filter.' : 'Create your first product, or import a CSV file.'}</Typography>
                    </Box>
                ) : (
                    <TableContainer>
                        <Table aria-label="Products">
                            <TableHead>
                                <TableRow>
                                    <TableCell>Product</TableCell>
                                    <TableCell>Status</TableCell>
                                    <TableCell>Category</TableCell>
                                    <TableCell align="right">Price</TableCell>
                                    <TableCell align="right">Stock</TableCell>
                                    <TableCell align="right"><span style={{ position: 'absolute', left: -9999 }}>Actions</span></TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {products.data.map((row) => (
                                    <TableRow key={row.id} hover>
                                        <TableCell>
                                            <Box component={RouterLink} href={`/admin/products/${row.id}`} sx={{ display: 'flex', alignItems: 'center', gap: 1.5, color: 'inherit', textDecoration: 'none' }}>
                                                <Box sx={{ width: 48, height: 48, borderRadius: 2, overflow: 'hidden', bgcolor: 'background.default', flexShrink: 0 }}>
                                                    {row.thumb && <img src={row.thumb} alt="" loading="lazy" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />}
                                                </Box>
                                                <Box sx={{ minWidth: 0 }}>
                                                    <Typography sx={{ fontWeight: 600 }}>{row.name}</Typography>
                                                    {row.hasVariants && <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>{row.variantCount} variants</Typography>}
                                                </Box>
                                            </Box>
                                        </TableCell>
                                        <TableCell><Chip size="small" variant="outlined" label={STATUS[row.status].label} color={STATUS[row.status].color} /></TableCell>
                                        <TableCell>{row.category ?? '—'}</TableCell>
                                        <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>{row.hasVariants ? 'From ' : ''}{formatMoney(row.priceCents)}</TableCell>
                                        <TableCell align="right">
                                            {row.trackStock ? (
                                                <Typography component="span" sx={{ color: row.stock === 0 ? 'error.main' : 'inherit', fontWeight: row.stock === 0 ? 600 : 400 }}>{row.stock}</Typography>
                                            ) : 'Unlimited'}
                                        </TableCell>
                                        <TableCell align="right">
                                            <IconButton aria-label={`Actions for ${row.name}`} onClick={(e) => setMenu({ anchor: e.currentTarget, row })}><MoreVert /></IconButton>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </TableContainer>
                )}
            </Card>

            {products.last_page > 1 && (
                <Box sx={{ display: 'flex', justifyContent: 'center', mt: 3 }}>
                    <Pagination count={products.last_page} page={products.current_page} color="primary" onChange={(_, page) => router.get('/admin/products', { ...filters, page }, { preserveState: true })} />
                </Box>
            )}

            <Menu anchorEl={menu?.anchor} open={!!menu} onClose={() => setMenu(null)}>
                {menu && <MenuItem component={RouterLink} href={`/admin/products/${menu.row.id}`}>{can('products', 'edit') ? 'Edit' : 'View'}</MenuItem>}
                {menu && menu.row.status === 'active' && <MenuItem component="a" href={`/product/${menu.row.slug}`} target="_blank" rel="noopener">View on storefront</MenuItem>}
                {menu && can('products', 'create') && <MenuItem onClick={() => { router.post(`/admin/products/${menu.row.id}/duplicate`); setMenu(null); }}>Duplicate</MenuItem>}
                {menu && can('products', 'publish') && menu.row.status !== 'active' && <MenuItem onClick={() => { setStatus(menu.row, 'active'); setMenu(null); }}>Publish</MenuItem>}
                {menu && can('products', 'publish') && menu.row.status === 'active' && <MenuItem onClick={() => { setStatus(menu.row, 'draft'); setMenu(null); }}>Move to drafts</MenuItem>}
                {menu && can('products', 'publish') && menu.row.status !== 'archived' && <MenuItem onClick={() => { setStatus(menu.row, 'archived'); setMenu(null); }}>Archive</MenuItem>}
                {menu && can('products', 'delete') && <MenuItem sx={{ color: 'error.main' }} onClick={() => { setDeleting(menu.row); setMenu(null); }}>Delete</MenuItem>}
            </Menu>

            {deleting && (
                <ConfirmDialog
                    open
                    title="Delete this product?"
                    text={`${deleting.name} and its digital files are deleted for good. Past orders keep their own copy of the name and price. To hide it instead, archive it.`}
                    confirmLabel="Delete"
                    danger
                    busy={busy}
                    onConfirm={() => router.delete(`/admin/products/${deleting.id}`, { onStart: () => setBusy(true), onFinish: () => { setBusy(false); setDeleting(null); } })}
                    onClose={() => setDeleting(null)}
                />
            )}
        </AdminLayout>
    );
}
