import { router } from '@inertiajs/react';
import Add from '@mui/icons-material/Add';
import OpenInNew from '@mui/icons-material/OpenInNew';
import SearchOutlined from '@mui/icons-material/SearchOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import IconButton from '@mui/material/IconButton';
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
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { useEffect, useRef, useState } from 'react';
import AdminLayout from '../../components/AdminLayout';
import RouterLink from '../../components/RouterLink';
import { useCan } from '../../lib/can';
import { formatDateTime } from '../../lib/format';
import type { Paginated } from '../../lib/types';

interface Post {
    id: number;
    title: string;
    status: 'draft' | 'published';
    scheduled: boolean;
    category: string | null;
    author: string | null;
    publishedAt: string | null;
    url: string;
}

interface Props {
    posts: Paginated<Post>;
    filters: { search: string; status: string };
}

export default function BlogIndex({ posts, filters }: Props) {
    const can = useCan();
    const [search, setSearch] = useState(filters.search);
    const first = useRef(true);

    const go = (next: Partial<Props['filters']> & { page?: number }) => {
        const query = Object.fromEntries(Object.entries({ ...filters, ...next }).filter(([, v]) => v !== '' && v !== undefined));
        router.get('/admin/blog', query, { preserveState: true, preserveScroll: true, replace: true });
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
        <AdminLayout title="Blog" actions={can('blog', 'create') ? <Button component={RouterLink} href="/admin/blog/create" variant="contained" startIcon={<Add />}>New post</Button> : undefined}>
            <Box sx={{ display: 'grid', gap: 2, mb: 2, gridTemplateColumns: { xs: '1fr', md: 'minmax(0, 1fr) 220px' } }}>
                <TextField label="Search" placeholder="Post title" value={search} onChange={(e) => setSearch(e.target.value)} slotProps={{ input: { startAdornment: <InputAdornment position="start"><SearchOutlined /></InputAdornment> } }} />
                <TextField select label="Status" value={filters.status} onChange={(e) => go({ status: e.target.value })} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}>
                    <MenuItem value="">All posts</MenuItem>
                    <MenuItem value="published">Published</MenuItem>
                    <MenuItem value="draft">Drafts</MenuItem>
                </TextField>
            </Box>

            {posts.data.length === 0 ? (
                <Card sx={{ p: 6, textAlign: 'center' }}>
                    <Typography variant="h4" component="p" sx={{ mb: 0.5 }}>No posts found</Typography>
                    <Typography sx={{ color: 'text.secondary' }}>{filters.search || filters.status ? 'Try a different search or status.' : 'Write your first post. Published posts appear at /blog on the store.'}</Typography>
                </Card>
            ) : (
                <Card>
                    <TableContainer>
                        <Table>
                            <TableHead>
                                <TableRow>
                                    <TableCell>Post</TableCell>
                                    <TableCell>Status</TableCell>
                                    <TableCell>Category</TableCell>
                                    <TableCell>Publish date</TableCell>
                                    <TableCell align="right">Open</TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {posts.data.map((post) => (
                                    <TableRow key={post.id} hover>
                                        <TableCell>
                                            <Typography component={RouterLink} href={`/admin/blog/${post.id}`} sx={{ fontWeight: 600, color: 'text.primary', textDecoration: 'none', '&:hover': { textDecoration: 'underline' } }}>{post.title}</Typography>
                                            {post.author && <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>By {post.author}</Typography>}
                                        </TableCell>
                                        <TableCell>
                                            <Chip size="small" variant="outlined" color={post.status === 'draft' ? 'default' : post.scheduled ? 'info' : 'success'} label={post.status === 'draft' ? 'Draft' : post.scheduled ? 'Scheduled' : 'Published'} />
                                        </TableCell>
                                        <TableCell>{post.category ?? 'None'}</TableCell>
                                        <TableCell sx={{ whiteSpace: 'nowrap' }}>{post.publishedAt ? formatDateTime(post.publishedAt) : 'Not set'}</TableCell>
                                        <TableCell align="right">
                                            <Tooltip title={post.status === 'published' && !post.scheduled ? 'Open post' : 'Preview'}>
                                                <IconButton aria-label={`Open ${post.title}`} component="a" href={post.url} target="_blank" rel="noopener"><OpenInNew /></IconButton>
                                            </Tooltip>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </TableContainer>
                </Card>
            )}

            {posts.last_page > 1 && (
                <Box sx={{ display: 'flex', justifyContent: 'center', mt: 3 }}>
                    <Pagination count={posts.last_page} page={posts.current_page} color="primary" onChange={(_, page) => go({ page })} />
                </Box>
            )}
        </AdminLayout>
    );
}
