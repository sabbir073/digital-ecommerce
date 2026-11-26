import { router, useForm } from '@inertiajs/react';
import DeleteOutline from '@mui/icons-material/DeleteOutlined';
import SendRounded from '@mui/icons-material/SendRounded';
import SettingsOutlined from '@mui/icons-material/SettingsOutlined';
import Alert from '@mui/material/Alert';
import Badge from '@mui/material/Badge';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { type FormEvent, type KeyboardEvent, useCallback, useEffect, useRef, useState } from 'react';
import AdminLayout from '../../components/AdminLayout';
import ConfirmDialog from '../../components/ConfirmDialog';
import { useCan } from '../../lib/can';
import { formatDateTime } from '../../lib/format';
import { getJson, sendJson } from '../../lib/http';

interface Conversation {
    id: number;
    name: string;
    email: string | null;
    isCustomer: boolean;
    status: 'open' | 'closed';
    assignee: { id: number; name: string } | null;
    pageUrl: string | null;
    preview: string;
    unread: number;
    lastAt: string | null;
    startedAt: string | null;
}

interface Message {
    id: number;
    from: 'visitor' | 'admin' | 'system';
    body: string;
    at: string | null;
    by: string | null;
}

interface Settings {
    enabled: boolean;
    on_selling_pages: boolean;
    welcome: string;
    away: string;
    notify_email: string;
}

interface PollResult {
    conversations: Conversation[];
    thread: { id: number; visitorActive: boolean; messages: Message[] } | null;
}

interface Props {
    conversations: Conversation[];
    open: number | null;
    settings: Settings;
    me: number;
}

const POLL_MS = 3000;

function timeOf(iso: string | null): string {
    return iso ? new Date(iso).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : '';
}

export default function ChatIndex({ conversations: initial, open, settings, me }: Props) {
    const can = useCan();
    const canReply = can('chat', 'edit');

    const [conversations, setConversations] = useState(initial);
    const [tab, setTab] = useState<'open' | 'closed'>('open');
    const [selected, setSelected] = useState<number | null>(open ?? initial.find((c) => c.status === 'open')?.id ?? null);
    const [messages, setMessages] = useState<Message[]>([]);
    const [visitorActive, setVisitorActive] = useState(false);
    const [text, setText] = useState('');
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);

    const selectedRef = useRef(selected);
    const lastId = useRef(0);
    const scroller = useRef<HTMLDivElement>(null);

    /** Adds messages once each: a reply and the next poll can both carry the same one. */
    const append = useCallback((incoming: Message[]) => {
        if (incoming.length === 0) return;
        setMessages((current) => {
            const known = new Set(current.map((m) => m.id));
            const fresh = incoming.filter((m) => !known.has(m.id));
            return fresh.length ? [...current, ...fresh] : current;
        });
        lastId.current = Math.max(lastId.current, ...incoming.map((m) => m.id));
    }, []);

    const poll = useCallback(async () => {
        const id = selectedRef.current;
        try {
            const data = await getJson<PollResult>(`/admin/chat/poll?conversation=${id ?? ''}&after=${lastId.current}`);
            setConversations(data.conversations);
            // The answer may be for a conversation the moderator has already left.
            if (data.thread && data.thread.id === selectedRef.current) {
                setVisitorActive(data.thread.visitorActive);
                append(data.thread.messages);
            }
            setError((current) => (current?.startsWith('Could not reach') ? null : current));
        } catch {
            setError('Could not reach the server. New messages will appear when the connection is back.');
        }
    }, [append]);

    useEffect(() => {
        selectedRef.current = selected;
        lastId.current = 0;
        setMessages([]);
        setVisitorActive(false);
        void poll();
    }, [selected, poll]);

    useEffect(() => {
        const timer = window.setInterval(() => void poll(), POLL_MS);
        return () => window.clearInterval(timer);
    }, [poll]);

    useEffect(() => {
        scroller.current?.scrollTo({ top: scroller.current.scrollHeight });
    }, [messages]);

    const current = conversations.find((c) => c.id === selected) ?? null;
    const shown = conversations.filter((c) => c.status === tab);
    const waiting = conversations.filter((c) => c.status === 'open').reduce((sum, c) => sum + (c.id === selected ? 0 : c.unread), 0);

    async function send(event?: FormEvent) {
        event?.preventDefault();
        const body = text.trim();
        if (!body || !current || sending) return;
        setSending(true);
        try {
            const data = await sendJson<{ message: Message }>('POST', `/admin/chat/${current.id}/messages`, { message: body });
            append([data.message]);
            setText('');
            setError(null);
            void poll();
        } catch (e) {
            setError(e instanceof Error ? e.message : 'The reply was not sent.');
        } finally {
            setSending(false);
        }
    }

    function onKeyDown(event: KeyboardEvent) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            void send();
        }
    }

    function act(action: 'close' | 'reopen' | 'assign') {
        if (!current) return;
        router.put(`/admin/chat/${current.id}`, { action }, { preserveScroll: true, preserveState: true, onSuccess: () => void poll() });
    }

    return (
        <AdminLayout
            title="Live chat"
            actions={canReply ? <Button variant="outlined" startIcon={<SettingsOutlined />} onClick={() => setSettingsOpen(true)}>Chat settings</Button> : undefined}
        >
            {!settings.enabled && <Alert severity="warning" sx={{ mb: 2 }}>Live chat is switched off, so visitors do not see the chat button.</Alert>}
            {error && <Alert severity="error" sx={{ mb: 2 }} onClose={() => setError(null)}>{error}</Alert>}

            <Card sx={{ display: 'grid', gridTemplateColumns: { xs: 'minmax(0, 1fr)', md: '320px minmax(0, 1fr)' }, height: { xs: 'auto', md: 'calc(100vh - 220px)' }, minHeight: 480 }}>
                <Box sx={{ borderRight: { md: 1 }, borderBottom: { xs: 1, md: 0 }, borderColor: { xs: 'divider', md: 'divider' }, display: 'flex', flexDirection: 'column', minHeight: 0, minWidth: 0, maxHeight: { xs: 300, md: 'none' } }}>
                    <Tabs value={tab} onChange={(_, value: 'open' | 'closed') => setTab(value)} variant="fullWidth" sx={{ borderBottom: 1, borderColor: 'divider' }}>
                        <Tab value="open" label={<Badge color="error" badgeContent={waiting} sx={{ '& .MuiBadge-badge': { right: -14 } }}>Open</Badge>} />
                        <Tab value="closed" label="Closed" />
                    </Tabs>
                    <Box sx={{ overflowY: 'auto', flex: 1 }}>
                        {shown.length === 0 && (
                            <Typography color="text.secondary" sx={{ p: 3, textAlign: 'center' }}>
                                {tab === 'open' ? 'No open conversations. New chats appear here as visitors write.' : 'No closed conversations.'}
                            </Typography>
                        )}
                        {shown.map((c) => (
                            <Box
                                key={c.id}
                                component="button"
                                type="button"
                                onClick={() => setSelected(c.id)}
                                sx={{
                                    display: 'block', width: '100%', textAlign: 'left', border: 0, borderBottom: 1, borderColor: 'divider', cursor: 'pointer', px: 2, py: 1.5, font: 'inherit', color: 'inherit',
                                    bgcolor: c.id === selected ? 'action.selected' : 'transparent', '&:hover': { bgcolor: c.id === selected ? 'action.selected' : 'action.hover' },
                                }}
                            >
                                <Stack direction="row" sx={{ alignItems: 'center', gap: 1 }}>
                                    <Typography noWrap sx={{ fontWeight: c.unread ? 700 : 500, flex: 1 }}>{c.name}</Typography>
                                    {c.unread > 0 && c.id !== selected && <Chip size="small" color="error" label={c.unread} aria-label={`${c.unread} unread`} />}
                                    <Typography variant="caption" color="text.secondary">{timeOf(c.lastAt)}</Typography>
                                </Stack>
                                <Typography variant="body2" color="text.secondary" noWrap>{c.preview || 'No messages yet'}</Typography>
                                <Typography variant="caption" color="text.secondary">{c.assignee ? `Handled by ${c.assignee.id === me ? 'you' : c.assignee.name}` : 'Not taken yet'}</Typography>
                            </Box>
                        ))}
                    </Box>
                </Box>

                {current ? (
                    <Box sx={{ display: 'flex', flexDirection: 'column', minHeight: 0, minWidth: 0, height: { xs: 520, md: 'auto' } }}>
                        <Stack direction="row" sx={{ alignItems: 'center', gap: 1.5, px: 2.5, py: 1.5, borderBottom: 1, borderColor: 'divider', flexWrap: 'wrap' }}>
                            <Box sx={{ flex: 1, minWidth: 200 }}>
                                <Stack direction="row" sx={{ alignItems: 'center', gap: 1 }}>
                                    <Typography variant="h6" noWrap>{current.name}</Typography>
                                    {current.isCustomer && <Chip size="small" label="Customer" />}
                                    <Chip size="small" color={visitorActive ? 'success' : 'default'} variant="outlined" label={visitorActive ? 'On the site now' : 'Left the site'} />
                                </Stack>
                                <Typography variant="body2" color="text.secondary" noWrap>
                                    {current.email ?? 'No email given'} · Started {formatDateTime(current.startedAt)}
                                    {current.pageUrl ? ` · From ${current.pageUrl.replace(/^https?:\/\/[^/]+/, '') || '/'}` : ''}
                                </Typography>
                            </Box>
                            {canReply && current.assignee?.id !== me && <Button size="small" onClick={() => act('assign')}>Take over</Button>}
                            {canReply && (current.status === 'open'
                                ? <Button size="small" variant="outlined" onClick={() => act('close')}>Close</Button>
                                : <Button size="small" variant="outlined" onClick={() => act('reopen')}>Reopen</Button>)}
                            {can('chat', 'delete') && (
                                <Tooltip title="Delete conversation">
                                    <IconButton aria-label="Delete conversation" onClick={() => setDeleting(true)}><DeleteOutline /></IconButton>
                                </Tooltip>
                            )}
                        </Stack>

                        <Box ref={scroller} sx={{ flex: 1, overflowY: 'auto', px: 2.5, py: 2, display: 'flex', flexDirection: 'column', gap: 1, bgcolor: 'background.default' }} aria-live="polite">
                            {messages.map((m) => m.from === 'system' ? (
                                <Typography key={m.id} variant="caption" color="text.secondary" sx={{ alignSelf: 'center', py: 0.5 }}>{m.body} · {timeOf(m.at)}</Typography>
                            ) : (
                                <Box key={m.id} sx={{ alignSelf: m.from === 'admin' ? 'flex-end' : 'flex-start', maxWidth: '75%' }}>
                                    <Box sx={{
                                        px: 1.75, py: 1, borderRadius: 3, whiteSpace: 'pre-wrap', overflowWrap: 'anywhere',
                                        bgcolor: m.from === 'admin' ? 'primary.main' : 'background.paper', color: m.from === 'admin' ? 'primary.contrastText' : 'text.primary',
                                        border: m.from === 'admin' ? 0 : 1, borderColor: 'divider',
                                    }}>
                                        {m.body}
                                    </Box>
                                    <Typography variant="caption" color="text.secondary" sx={{ display: 'block', textAlign: m.from === 'admin' ? 'right' : 'left', mt: 0.25 }}>
                                        {m.from === 'admin' ? `${m.by ?? 'Support'} · ` : ''}{timeOf(m.at)}
                                    </Typography>
                                </Box>
                            ))}
                        </Box>

                        {canReply ? (
                            <Box component="form" onSubmit={send} sx={{ borderTop: 1, borderColor: 'divider', p: 2 }}>
                                {!visitorActive && messages.length > 0 && (
                                    <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
                                        {current.email ? `The visitor has left the site. Your reply is also sent to ${current.email}.` : 'The visitor has left the site and gave no email. They see your reply when they come back.'}
                                    </Typography>
                                )}
                                <Stack direction="row" sx={{ gap: 1.5, alignItems: 'flex-end' }}>
                                    <TextField
                                        label="Your reply" value={text} onChange={(e) => setText(e.target.value)} onKeyDown={onKeyDown}
                                        multiline maxRows={5} fullWidth size="small" slotProps={{ htmlInput: { maxLength: 2000 } }}
                                    />
                                    <Button type="submit" variant="contained" endIcon={<SendRounded />} loading={sending} disabled={!text.trim()}>Send</Button>
                                </Stack>
                            </Box>
                        ) : (
                            <Typography color="text.secondary" sx={{ p: 2, borderTop: 1, borderColor: 'divider' }}>You can read conversations but not reply to them.</Typography>
                        )}
                    </Box>
                ) : (
                    <Box sx={{ display: 'grid', placeItems: 'center', p: 4, minHeight: 300 }}>
                        <Typography color="text.secondary" sx={{ textAlign: 'center', maxWidth: 360 }}>
                            {conversations.length ? 'Choose a conversation to read and answer it.' : 'No conversations yet. Keep this page open: visitors see the team as online while it is open, and new chats appear here within a few seconds.'}
                        </Typography>
                    </Box>
                )}
            </Card>

            <SettingsDialog open={settingsOpen} settings={settings} onClose={() => setSettingsOpen(false)} />
            <ConfirmDialog
                open={deleting}
                title="Delete this conversation?"
                text="The conversation and all its messages are removed for good. The visitor starts a new chat next time."
                confirmLabel="Delete"
                danger
                onClose={() => setDeleting(false)}
                onConfirm={() => current && router.delete(`/admin/chat/${current.id}`, { onFinish: () => setDeleting(false), onSuccess: () => { setSelected(null); void poll(); } })}
            />
        </AdminLayout>
    );
}

function SettingsDialog({ open, settings, onClose }: { open: boolean; settings: Settings; onClose: () => void }) {
    const form = useForm<Settings>({ ...settings });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put('/admin/chat/settings', { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
            <form onSubmit={submit}>
                <DialogTitle>Chat settings</DialogTitle>
                <DialogContent>
                    <Stack sx={{ gap: 2.5, pt: 1 }}>
                        <FormControlLabel control={<Switch checked={form.data.enabled} onChange={(e) => form.setData('enabled', e.target.checked)} />} label="Show the chat button on the store" />
                        <FormControlLabel control={<Switch checked={form.data.on_selling_pages} onChange={(e) => form.setData('on_selling_pages', e.target.checked)} />} label="Also show it on selling pages" />
                        <TextField label="Greeting when someone is at the inbox" value={form.data.welcome} onChange={(e) => form.setData('welcome', e.target.value)} error={!!form.errors.welcome} helperText={form.errors.welcome} slotProps={{ htmlInput: { maxLength: 200 } }} />
                        <TextField label="Message when nobody is at the inbox" value={form.data.away} onChange={(e) => form.setData('away', e.target.value)} error={!!form.errors.away} helperText={form.errors.away} slotProps={{ htmlInput: { maxLength: 200 } }} />
                        <TextField
                            label="Email for new chats while away" type="email" value={form.data.notify_email} onChange={(e) => form.setData('notify_email', e.target.value)}
                            error={!!form.errors.notify_email} helperText={form.errors.notify_email ?? 'When a visitor writes and nobody has the inbox open, this address gets an email. Leave empty for none.'}
                        />
                    </Stack>
                </DialogContent>
                <DialogActions sx={{ px: 3, pb: 3 }}>
                    <Button variant="outlined" onClick={onClose}>Cancel</Button>
                    <Button type="submit" variant="contained" loading={form.processing}>Save</Button>
                </DialogActions>
            </form>
        </Dialog>
    );
}
