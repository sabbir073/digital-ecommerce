import { router } from '@inertiajs/react';
import DeleteOutlined from '@mui/icons-material/DeleteOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import IconButton from '@mui/material/IconButton';
import Pagination from '@mui/material/Pagination';
import Stack from '@mui/material/Stack';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { useState } from 'react';
import AdminLayout from '../../components/AdminLayout';
import ConfirmDialog from '../../components/ConfirmDialog';
import { useCan } from '../../lib/can';
import { formatDateTime } from '../../lib/format';
import type { Paginated } from '../../lib/types';

interface Message {
    id: number;
    name: string;
    email: string;
    message: string;
    status: 'new' | 'read' | 'done';
    createdAt: string | null;
}

export default function Messages({ messages, status, counts }: { messages: Paginated<Message>; status: 'open' | 'done'; counts: { new: number } }) {
    const can = useCan();
    const canEdit = can('chat', 'edit');
    const [deleting, setDeleting] = useState<Message | null>(null);
    const go = (query: Record<string, string | number>) => router.get('/admin/messages', query, { preserveScroll: true, preserveState: true, replace: true });
    const set = (message: Message, next: Message['status']) => router.put(`/admin/messages/${message.id}`, { status: next }, { preserveScroll: true });

    return (
        <AdminLayout title="Messages">
            <Typography sx={{ color: 'text.secondary', mb: 2 }}>Messages sent through a contact form on one of your pages. Reply from your own email.</Typography>
            <Tabs value={status} onChange={(_, value: string) => go({ status: value })} sx={{ mb: 2 }}>
                <Tab value="open" label={counts.new > 0 ? `To answer (${counts.new} new)` : 'To answer'} />
                <Tab value="done" label="Answered" />
            </Tabs>

            {messages.data.length === 0 ? (
                <Card sx={{ p: 6, textAlign: 'center' }}>
                    <Typography variant="h4" component="p" sx={{ mb: 0.5 }}>{status === 'open' ? 'Nothing to answer' : 'No answered messages'}</Typography>
                    <Typography sx={{ color: 'text.secondary' }}>{status === 'open' ? 'New messages appear here. Add a Contact form block to a page to receive them.' : 'Messages you mark as answered are kept here.'}</Typography>
                </Card>
            ) : (
                <Stack sx={{ gap: 2 }}>
                    {messages.data.map((message) => (
                        <Card key={message.id} sx={{ p: 3 }}>
                            <Stack direction="row" sx={{ gap: 1.5, alignItems: 'flex-start', flexWrap: 'wrap' }}>
                                <Box sx={{ flex: 1, minWidth: 220 }}>
                                    <Typography sx={{ fontWeight: 600, display: 'flex', gap: 1, alignItems: 'center', flexWrap: 'wrap' }}>
                                        {message.name}
                                        {message.status === 'new' && <Chip size="small" color="primary" label="New" />}
                                    </Typography>
                                    <Typography sx={{ color: 'text.secondary', fontSize: 13, overflowWrap: 'anywhere' }}>{message.email} · {formatDateTime(message.createdAt)}</Typography>
                                </Box>
                                <Button component="a" href={`mailto:${message.email}?subject=${encodeURIComponent('Re: your message')}`} variant="outlined" size="small" onClick={() => canEdit && message.status === 'new' && set(message, 'read')}>Reply by email</Button>
                                {canEdit && (message.status === 'done'
                                    ? <Button size="small" onClick={() => set(message, 'read')}>Move back</Button>
                                    : <Button size="small" variant="contained" onClick={() => set(message, 'done')}>Mark answered</Button>)}
                                {can('chat', 'delete') && <Tooltip title="Delete"><IconButton aria-label={`Delete message from ${message.name}`} onClick={() => setDeleting(message)}><DeleteOutlined /></IconButton></Tooltip>}
                            </Stack>
                            <Typography sx={{ mt: 2, whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>{message.message}</Typography>
                        </Card>
                    ))}
                </Stack>
            )}

            {messages.last_page > 1 && (
                <Box sx={{ display: 'flex', justifyContent: 'center', mt: 3 }}>
                    <Pagination count={messages.last_page} page={messages.current_page} color="primary" onChange={(_, page) => go({ status, page })} />
                </Box>
            )}

            <ConfirmDialog
                open={deleting !== null} title="Delete this message?" text="The message is removed for good." confirmLabel="Delete" danger
                onClose={() => setDeleting(null)}
                onConfirm={() => deleting && router.delete(`/admin/messages/${deleting.id}`, { preserveScroll: true, onFinish: () => setDeleting(null) })}
            />
        </AdminLayout>
    );
}
