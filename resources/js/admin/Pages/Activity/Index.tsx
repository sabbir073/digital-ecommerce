import { router } from '@inertiajs/react';
import SearchOutlined from '@mui/icons-material/SearchOutlined';
import Box from '@mui/material/Box';
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
import { actionLabel, formatDateTime } from '../../lib/format';
import type { Paginated } from '../../lib/types';

interface Entry {
    id: number;
    actor: string;
    action: string;
    module: string;
    label: string | null;
    changes: Record<string, unknown> | null;
    ip: string | null;
    at: string | null;
}

interface Filters {
    search?: string;
    person?: number;
    module?: string;
}

interface Props {
    logs: Paginated<Entry>;
    filters: Filters;
    people: { id: number; name: string }[];
    modules: Record<string, string>;
}

function describeChanges(changes: Entry['changes']): string {
    if (!changes) return '';
    return Object.entries(changes)
        .map(([key, value]) => {
            if (value && typeof value === 'object' && 'from' in value && 'to' in value) {
                const pair = value as { from: unknown; to: unknown };
                if (typeof pair.from !== 'object' && typeof pair.to !== 'object') return `${key}: ${String(pair.from)} → ${String(pair.to)}`;
                return `${key} changed`;
            }
            return typeof value === 'object' ? `${key} set` : `${key}: ${String(value)}`;
        })
        .join(' · ');
}

export default function ActivityIndex({ logs, filters, people, modules }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const first = useRef(true);

    const apply = (next: Filters) => {
        const query: Record<string, string | number> = {};
        Object.entries({ ...filters, ...next }).forEach(([key, value]) => {
            if (value !== undefined && value !== '' && value !== 0) query[key] = value as string | number;
        });
        router.get('/admin/activity', query, { preserveState: true, preserveScroll: true, replace: true });
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

    return (
        <AdminLayout title="Activity log">
            <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 2, mb: 2 }}>
                <TextField
                    label="Search"
                    placeholder="Person, action or item"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    sx={{ flex: '2 1 260px' }}
                    slotProps={{ input: { startAdornment: <InputAdornment position="start"><SearchOutlined /></InputAdornment> } }}
                />
                <TextField select label="Person" value={filters.person ?? 0} onChange={(e) => apply({ person: Number(e.target.value) })} sx={{ flex: '1 1 180px' }}>
                    <MenuItem value={0}>Everyone</MenuItem>
                    {people.map((person) => <MenuItem key={person.id} value={person.id}>{person.name}</MenuItem>)}
                </TextField>
                <TextField select label="Area" value={filters.module ?? ''} onChange={(e) => apply({ module: e.target.value })} sx={{ flex: '1 1 180px' }} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}>
                    <MenuItem value="">All areas</MenuItem>
                    {Object.entries(modules).map(([key, label]) => <MenuItem key={key} value={key}>{label}</MenuItem>)}
                </TextField>
            </Box>

            <Card>
                {logs.data.length === 0 ? (
                    <Box sx={{ p: 5, textAlign: 'center' }}>
                        <Typography variant="h4" component="p" sx={{ mb: 0.5 }}>No activity found</Typography>
                        <Typography sx={{ color: 'text.secondary' }}>Try a different search or filter.</Typography>
                    </Box>
                ) : (
                    <TableContainer>
                        <Table aria-label="Activity log">
                            <TableHead>
                                <TableRow>
                                    <TableCell>When</TableCell>
                                    <TableCell>Person</TableCell>
                                    <TableCell>What</TableCell>
                                    <TableCell>Area</TableCell>
                                    <TableCell>IP address</TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {logs.data.map((entry) => (
                                    <TableRow key={entry.id} hover>
                                        <TableCell sx={{ whiteSpace: 'nowrap' }}>{formatDateTime(entry.at)}</TableCell>
                                        <TableCell sx={{ fontWeight: 600 }}>{entry.actor}</TableCell>
                                        <TableCell>
                                            {actionLabel(entry.action)}
                                            {entry.label && entry.label !== entry.actor ? ` ${entry.label}` : ''}
                                            {entry.changes && (
                                                <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>{describeChanges(entry.changes)}</Typography>
                                            )}
                                        </TableCell>
                                        <TableCell><Chip size="small" label={modules[entry.module] ?? entry.module} /></TableCell>
                                        <TableCell sx={{ color: 'text.secondary' }}>{entry.ip}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </TableContainer>
                )}
            </Card>

            {logs.last_page > 1 && (
                <Box sx={{ display: 'flex', justifyContent: 'center', mt: 3 }}>
                    <Pagination
                        count={logs.last_page}
                        page={logs.current_page}
                        color="primary"
                        onChange={(_, page) => router.get('/admin/activity', { ...filters, page }, { preserveState: true })}
                    />
                </Box>
            )}
        </AdminLayout>
    );
}
