import { Head, router, usePage } from '@inertiajs/react';
import Add from '@mui/icons-material/Add';
import ArrowBack from '@mui/icons-material/ArrowBack';
import ArrowDownward from '@mui/icons-material/ArrowDownward';
import ArrowUpward from '@mui/icons-material/ArrowUpward';
import CheckCircleOutlined from '@mui/icons-material/CheckCircleOutlined';
import ContentCopyOutlined from '@mui/icons-material/ContentCopyOutlined';
import DeleteOutlined from '@mui/icons-material/DeleteOutlined';
import DesktopWindowsOutlined from '@mui/icons-material/DesktopWindowsOutlined';
import DragIndicator from '@mui/icons-material/DragIndicator';
import ErrorOutlined from '@mui/icons-material/ErrorOutlined';
import History from '@mui/icons-material/History';
import OpenInNew from '@mui/icons-material/OpenInNew';
import PhoneIphoneOutlined from '@mui/icons-material/PhoneIphoneOutlined';
import Redo from '@mui/icons-material/Redo';
import TabletMacOutlined from '@mui/icons-material/TabletMacOutlined';
import Tune from '@mui/icons-material/Tune';
import Undo from '@mui/icons-material/Undo';
import VisibilityOffOutlined from '@mui/icons-material/VisibilityOffOutlined';
import VisibilityOutlined from '@mui/icons-material/VisibilityOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import CircularProgress from '@mui/material/CircularProgress';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import Drawer from '@mui/material/Drawer';
import IconButton from '@mui/material/IconButton';
import List from '@mui/material/List';
import ListItem from '@mui/material/ListItem';
import ListItemText from '@mui/material/ListItemText';
import Menu from '@mui/material/Menu';
import MenuItem from '@mui/material/MenuItem';
import Snackbar from '@mui/material/Snackbar';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import useMediaQuery from '@mui/material/useMediaQuery';
import { useCallback, useEffect, useRef, useState } from 'react';
import BlockFields, { type MediaMap, type ReviewOption } from '../../components/BlockFields';
import MediaPicker from '../../components/MediaPicker';
import RouterLink from '../../components/RouterLink';
import { type Block, type Library, newBlock, newBlockId, setPath } from '../../lib/blocks';
import { useCan } from '../../lib/can';
import { formatDateTime } from '../../lib/format';
import { sendJson } from '../../lib/http';
import type { MediaItem, SharedProps } from '../../lib/types';

interface Version {
    id: number;
    publishedAt: string | null;
    by: string | null;
    blocks: number;
}

interface Props {
    page: { id: number; name: string; status: 'draft' | 'published'; url: string; previewUrl: string; canvasUrl: string; hasUnpublishedChanges: boolean };
    blocks: Block[];
    library: Library;
    media: MediaMap;
    reviews: ReviewOption[];
    problems: string[];
    versions: Version[];
}

type SaveState = 'saved' | 'unsaved' | 'saving' | 'error';
type Device = 'desktop' | 'tablet' | 'mobile';
type BlockAction = 'up' | 'down' | 'duplicate' | 'toggle' | 'delete';

interface SaveResult {
    problems: string[];
    hasUnpublishedChanges: boolean;
}

type CanvasMessage =
    | { type: 'ready' }
    | { type: 'select'; id: string | null }
    | { type: 'action'; id: string; action: BlockAction }
    | { type: 'text'; id: string; path: string; value: string }
    | { type: 'image'; id: string; path: string }
    | { type: 'drop'; index: number; blockType?: string; id?: string }
    | { type: 'history'; direction: 'undo' | 'redo' };

const DEVICES: Record<Device, { width: number; label: string }> = {
    desktop: { width: 1440, label: 'Desktop, 1440 pixels wide' },
    tablet: { width: 820, label: 'Tablet, 820 pixels wide' },
    mobile: { width: 390, label: 'Mobile, 390 pixels wide' },
};

/** Undo and redo keep this many steps. The PRD asks for at least 50. */
const HISTORY = 100;
const DRAG_TYPE = 'application/x-builder-block';

export default function SellingPageBuilder({ page, blocks: savedBlocks, library, media: savedMedia, reviews, problems: savedProblems, versions }: Props) {
    const can = useCan();
    const canEdit = can('selling_pages', 'edit');
    const canPublish = can('selling_pages', 'publish');
    const { flash } = usePage<SharedProps>().props;
    const compact = useMediaQuery('(max-width:1099px)');
    const base = `/admin/selling-pages/${page.id}`;

    const [blocks, setBlocks] = useState<Block[]>(savedBlocks);
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [history, setHistory] = useState({ undo: 0, redo: 0 });
    const [saveState, setSaveState] = useState<SaveState>('saved');
    const [saveError, setSaveError] = useState('');
    const [problems, setProblems] = useState<string[]>(savedProblems);
    const [unpublished, setUnpublished] = useState(page.hasUnpublishedChanges);
    const [media, setMedia] = useState<MediaMap>({ ...savedMedia });
    const [device, setDevice] = useState<Device>('desktop');
    const [frame, setFrame] = useState({ scale: 1, height: 600 });
    const [toast, setToast] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
    const [picking, setPicking] = useState<{ id: string; path: string } | null>(null);
    const [versionsOpen, setVersionsOpen] = useState(false);
    const [restoring, setRestoring] = useState<number | null>(null);
    const [blockedOpen, setBlockedOpen] = useState(false);
    const [publishing, setPublishing] = useState(false);
    const [addAnchor, setAddAnchor] = useState<HTMLElement | null>(null);
    const [panelOpen, setPanelOpen] = useState(false);

    const blocksRef = useRef(blocks);
    const selectedRef = useRef<string | null>(null);
    const problemsRef = useRef(problems);
    const past = useRef<Block[][]>([]);
    const future = useRef<Block[][]>([]);
    const lastEdit = useRef<{ key: string; at: number } | null>(null);
    const iframe = useRef<HTMLIFrameElement>(null);
    const stage = useRef<HTMLDivElement>(null);
    const canvasReady = useRef(false);
    const renderTimer = useRef<number | undefined>(undefined);
    const renderSeq = useRef(0);
    const saveTimer = useRef<number | undefined>(undefined);
    const saving = useRef<Promise<boolean> | null>(null);
    const saveAgain = useRef(false);
    const dirty = useRef(false);

    const toCanvas = useCallback((message: Record<string, unknown>) => {
        iframe.current?.contentWindow?.postMessage({ source: 'builder', ...message }, window.location.origin);
    }, []);

    const select = useCallback(
        (id: string | null, scroll = false) => {
            selectedRef.current = id;
            setSelectedId(id);
            toCanvas({ type: 'select', id, scroll });
        },
        [toCanvas],
    );

    /** Redraw the canvas from the blocks in hand. The server draws them, so it is exactly what customers get. */
    const render = useCallback(() => {
        window.clearTimeout(renderTimer.current);
        renderTimer.current = window.setTimeout(async () => {
            if (!canvasReady.current) return;
            const seq = ++renderSeq.current;
            try {
                const { html } = await sendJson<{ html: string }>('POST', `${base}/render`, { blocks: blocksRef.current });
                if (seq === renderSeq.current) toCanvas({ type: 'html', html, selected: selectedRef.current });
            } catch (error) {
                if (seq === renderSeq.current) setToast({ type: 'error', text: `The canvas could not be redrawn. ${(error as Error).message}` });
            }
        }, 150);
    }, [base, toCanvas]);

    const saveNow = useCallback((): Promise<boolean> => {
        window.clearTimeout(saveTimer.current);
        if (saving.current) {
            saveAgain.current = true;
            return saving.current.then(() => (dirty.current ? saveNow() : true));
        }
        if (!dirty.current) return Promise.resolve(true);

        dirty.current = false;
        setSaveState('saving');
        const run = sendJson<SaveResult>('PUT', `${base}/draft`, { blocks: blocksRef.current })
            .then((result) => {
                problemsRef.current = result.problems;
                setProblems(result.problems);
                setUnpublished(result.hasUnpublishedChanges);
                setSaveState(dirty.current ? 'unsaved' : 'saved');
                return true;
            })
            .catch((error: Error) => {
                dirty.current = true;
                setSaveError(error.message);
                setSaveState('error');
                return false;
            })
            .finally(() => {
                saving.current = null;
                if (saveAgain.current) {
                    saveAgain.current = false;
                    if (dirty.current) void saveNow();
                }
            });
        saving.current = run;
        return run;
    }, [base]);

    const apply = useCallback(
        (next: Block[], redraw: boolean) => {
            blocksRef.current = next;
            setBlocks(next);
            setHistory({ undo: past.current.length, redo: future.current.length });
            if (selectedRef.current && !next.some((b) => b.id === selectedRef.current)) {
                selectedRef.current = null;
                setSelectedId(null);
            }
            dirty.current = true;
            setSaveState((state) => (state === 'saving' ? state : 'unsaved'));
            // Autosave one second after the last edit (the PRD allows two).
            window.clearTimeout(saveTimer.current);
            saveTimer.current = window.setTimeout(() => void saveNow(), 1000);
            if (redraw) render();
        },
        [render, saveNow],
    );

    /**
     * Record one action. Edits that share a `key` and follow each other closely (typing in one field)
     * count as a single undo step, so a sentence does not use up the history.
     */
    const commit = useCallback(
        (next: Block[], options: { key?: string; redraw?: boolean } = {}) => {
            if (!canEdit) return;
            const now = Date.now();
            const merge = options.key !== undefined && lastEdit.current?.key === options.key && now - lastEdit.current.at < 1500;
            if (!merge) {
                past.current.push(blocksRef.current);
                if (past.current.length > HISTORY) past.current.shift();
            }
            lastEdit.current = options.key !== undefined ? { key: options.key, at: now } : null;
            future.current = [];
            apply(next, options.redraw !== false);
        },
        [apply, canEdit],
    );

    const travel = useCallback(
        (direction: 'undo' | 'redo') => {
            const from = direction === 'undo' ? past.current : future.current;
            const to = direction === 'undo' ? future.current : past.current;
            const next = from.pop();
            if (!next) return;
            to.push(blocksRef.current);
            lastEdit.current = null;
            apply(next, true);
        },
        [apply],
    );

    const patch = useCallback(
        (id: string, change: (block: Block) => Block, options?: { key?: string; redraw?: boolean }) => {
            commit(blocksRef.current.map((b) => (b.id === id ? change(b) : b)), options);
        },
        [commit],
    );

    const insert = useCallback(
        (type: string, index?: number) => {
            if (!library[type] || (type === 'order' && blocksRef.current.some((b) => b.type === 'order'))) return;
            const block = newBlock(type, library);
            const next = [...blocksRef.current];
            // Added by a click, new content goes above the order block, which normally closes the page.
            const orderAt = next.findIndex((b) => b.type === 'order');
            next.splice(index ?? (type === 'order' || orderAt === -1 ? next.length : orderAt), 0, block);
            selectedRef.current = block.id;
            setSelectedId(block.id);
            commit(next);
            window.setTimeout(() => toCanvas({ type: 'select', id: block.id, scroll: true }), 600);
        },
        [commit, library, toCanvas],
    );

    const act = useCallback(
        (id: string, action: BlockAction) => {
            const list = blocksRef.current;
            const index = list.findIndex((b) => b.id === id);
            if (index === -1) return;
            const next = [...list];
            if (action === 'up' || action === 'down') {
                const target = index + (action === 'up' ? -1 : 1);
                if (target < 0 || target >= next.length) return;
                next.splice(target, 0, ...next.splice(index, 1));
            } else if (action === 'duplicate') {
                if (list[index].type === 'order') return;
                next.splice(index + 1, 0, { ...structuredClone(list[index]), id: newBlockId() });
            } else if (action === 'toggle') {
                next[index] = { ...list[index], hidden: !list[index].hidden };
            } else {
                next.splice(index, 1);
            }
            commit(next);
        },
        [commit],
    );

    // Messages from the canvas.
    useEffect(() => {
        const listen = (event: MessageEvent<CanvasMessage & { source?: string }>) => {
            if (event.origin !== window.location.origin || event.source !== iframe.current?.contentWindow || event.data?.source !== 'builder-canvas') return;
            const message = event.data;
            switch (message.type) {
                case 'ready':
                    canvasReady.current = true;
                    toCanvas({ type: 'readonly', value: !canEdit });
                    // The frame shows the saved draft. Redraw if the blocks moved on while it loaded.
                    if (blocksRef.current !== savedBlocks) render();
                    else toCanvas({ type: 'select', id: selectedRef.current });
                    break;
                case 'select':
                    selectedRef.current = message.id;
                    setSelectedId(message.id);
                    break;
                case 'action':
                    act(message.id, message.action);
                    break;
                case 'text':
                    patch(message.id, (b) => ({ ...b, data: setPath(b.data, message.path, message.value) }), { key: `text:${message.id}:${message.path}`, redraw: false });
                    break;
                case 'image':
                    if (canEdit) setPicking({ id: message.id, path: message.path });
                    break;
                case 'drop':
                    if (message.blockType) insert(message.blockType, message.index);
                    else if (message.id) {
                        const list = blocksRef.current;
                        const from = list.findIndex((b) => b.id === message.id);
                        const to = message.index > from ? message.index - 1 : message.index;
                        if (from === -1 || from === to) break;
                        const next = [...list];
                        next.splice(to, 0, ...next.splice(from, 1));
                        commit(next);
                    }
                    break;
                case 'history':
                    travel(message.direction);
                    break;
            }
        };
        window.addEventListener('message', listen);
        return () => window.removeEventListener('message', listen);
    }, [act, canEdit, commit, insert, patch, render, savedBlocks, toCanvas, travel]);

    // Ctrl+Z and Ctrl+Shift+Z (or Ctrl+Y), unless the person is typing in a field, which has its own undo.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            const key = event.key.toLowerCase();
            if (!(event.ctrlKey || event.metaKey) || (key !== 'z' && key !== 'y')) return;
            const target = event.target as HTMLElement;
            if (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)) return;
            event.preventDefault();
            travel(key === 'y' || event.shiftKey ? 'redo' : 'undo');
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [travel]);

    useEffect(() => {
        if (saveState === 'saved') return;
        const warn = (event: BeforeUnloadEvent) => event.preventDefault();
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [saveState]);

    useEffect(() => {
        if (flash.success) setToast({ type: 'success', text: flash.success });
        else if (flash.error) setToast({ type: 'error', text: flash.error });
    }, [flash]);

    // Fit the chosen device width into the space there is, by scaling the frame down when needed.
    useEffect(() => {
        const el = stage.current;
        if (!el) return;
        const measure = () => {
            const scale = Math.min(1, (el.clientWidth - 32) / DEVICES[device].width);
            setFrame({ scale, height: Math.max(200, (el.clientHeight - 32) / scale) });
        };
        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(el);
        return () => observer.disconnect();
    }, [device]);

    const preview = () => {
        if (saveState === 'saved') {
            window.open(page.previewUrl, '_blank', 'noopener');
            return;
        }
        // Opened right away so the browser allows it, then pointed at the draft once it is saved.
        const tab = window.open('', '_blank');
        void saveNow().then((ok) => {
            if (tab && ok) tab.location.href = page.previewUrl;
            else tab?.close();
        });
    };

    const publish = async () => {
        setPublishing(true);
        const ok = await saveNow();
        if (!ok) {
            setPublishing(false);
            return;
        }
        if (problemsRef.current.length > 0) {
            setPublishing(false);
            setBlockedOpen(true);
            return;
        }
        router.post(`${base}/publish`, {}, { preserveScroll: true, preserveState: true, onSuccess: () => setUnpublished(false), onFinish: () => setPublishing(false) });
    };

    const restore = async (version: Version) => {
        setRestoring(version.id);
        try {
            const result = await sendJson<SaveResult & { blocks: Block[]; media: MediaMap }>('POST', `${base}/versions/${version.id}/restore`, {});
            setMedia((current) => ({ ...current, ...result.media }));
            commit(result.blocks);
            setVersionsOpen(false);
            setToast({ type: 'success', text: 'That version is now the draft. Publish to make it live, or undo to go back.' });
        } catch (error) {
            setToast({ type: 'error', text: (error as Error).message });
        } finally {
            setRestoring(null);
        }
    };

    const selected = blocks.find((b) => b.id === selectedId) ?? null;
    const selectedIndex = selected ? blocks.indexOf(selected) : -1;
    const hasOrder = blocks.some((b) => b.type === 'order');
    const publishLabel = page.status === 'published' ? 'Publish changes' : 'Publish';

    const libraryList = (
        <Stack spacing={1}>
            {Object.entries(library).map(([type, definition]) => {
                const used = type === 'order' && hasOrder;
                return (
                    <Box
                        key={type}
                        component="button"
                        type="button"
                        disabled={!canEdit || used}
                        draggable={canEdit && !used}
                        onDragStart={(event: React.DragEvent) => {
                            event.dataTransfer.setData(DRAG_TYPE, type);
                            event.dataTransfer.effectAllowed = 'copy';
                            toCanvas({ type: 'dragging', blockType: type });
                        }}
                        onDragEnd={() => toCanvas({ type: 'dragging', blockType: null })}
                        onClick={() => insert(type)}
                        aria-label={used ? `${definition.label}, already on the page` : `Add ${definition.label} block`}
                        sx={{
                            display: 'flex', alignItems: 'center', gap: 1, width: '100%', minHeight: 44, px: 1.25, py: 1, textAlign: 'left',
                            font: 'inherit', fontWeight: 500, fontSize: 14, color: 'text.primary', bgcolor: 'background.paper',
                            border: 1, borderColor: 'divider', borderRadius: 2.5, cursor: 'grab', transition: 'border-color 120ms, box-shadow 120ms, transform 120ms',
                            '&:hover:not(:disabled)': { borderColor: 'primary.main', boxShadow: '0 2px 10px rgba(29,78,216,0.12)', transform: 'translateY(-1px)' },
                            '&:focus-visible': { outline: '2px solid', outlineColor: 'primary.main', outlineOffset: 2 },
                            '&:disabled': { opacity: 0.5, cursor: 'default' },
                        }}
                    >
                        <DragIndicator fontSize="small" sx={{ color: 'text.disabled' }} />
                        <Box component="span" sx={{ flex: 1 }}>{definition.label}</Box>
                        {used ? <Typography component="span" sx={{ fontSize: 12, color: 'text.secondary' }}>On the page</Typography> : <Add fontSize="small" sx={{ color: 'text.secondary' }} />}
                    </Box>
                );
            })}
        </Stack>
    );

    const settings = selected ? (
        <Box>
            <Stack direction="row" sx={{ alignItems: 'center', justifyContent: 'space-between', mb: 2 }}>
                <Stack direction="row" spacing={1} sx={{ alignItems: 'center', minWidth: 0 }}>
                    <Typography variant="h4" component="h2" noWrap>{library[selected.type].label}</Typography>
                    {selected.hidden && <Chip size="small" label="Hidden" />}
                </Stack>
            </Stack>
            {canEdit && (
                <Stack direction="row" sx={{ mb: 2.5, mx: -1 }}>
                    <Tooltip title="Move up"><span><IconButton aria-label="Move block up" disabled={selectedIndex === 0} onClick={() => act(selected.id, 'up')}><ArrowUpward fontSize="small" /></IconButton></span></Tooltip>
                    <Tooltip title="Move down"><span><IconButton aria-label="Move block down" disabled={selectedIndex === blocks.length - 1} onClick={() => act(selected.id, 'down')}><ArrowDownward fontSize="small" /></IconButton></span></Tooltip>
                    {selected.type !== 'order' && <Tooltip title="Duplicate"><IconButton aria-label="Duplicate block" onClick={() => act(selected.id, 'duplicate')}><ContentCopyOutlined fontSize="small" /></IconButton></Tooltip>}
                    <Tooltip title={selected.hidden ? 'Show on the page' : 'Hide from the page'}>
                        <IconButton aria-label={selected.hidden ? 'Show block on the page' : 'Hide block from the page'} onClick={() => act(selected.id, 'toggle')}>
                            {selected.hidden ? <VisibilityOffOutlined fontSize="small" /> : <VisibilityOutlined fontSize="small" />}
                        </IconButton>
                    </Tooltip>
                    <Tooltip title="Delete"><IconButton aria-label="Delete block" onClick={() => act(selected.id, 'delete')}><DeleteOutlined fontSize="small" /></IconButton></Tooltip>
                </Stack>
            )}
            <BlockFields
                key={selected.id}
                fields={library[selected.type].fields} data={selected.data} disabled={!canEdit} media={media} reviews={reviews}
                onMedia={(items: MediaItem[]) => setMedia((current) => ({ ...current, ...Object.fromEntries(items.map((i) => [i.id, i])) }))}
                onChange={(data) => patch(selected.id, (b) => ({ ...b, data }), { key: `panel:${selected.id}` })}
            />
            {selected.type === 'order' && (
                <Typography sx={{ color: 'text.secondary', fontSize: 14, mt: 2 }}>
                    The packs shown in this block are set on the <RouterLink href={base}>page settings</RouterLink> screen.
                </Typography>
            )}
            <Typography sx={{ fontWeight: 600, fontSize: 14, mt: 3, mb: 1.5 }}>Style</Typography>
            <Stack spacing={2}>
                {([
                    ['background', 'Background', { white: 'White', soft: 'Soft grey', dark: 'Dark' }],
                    ['spacing', 'Spacing', { sm: 'Small', md: 'Medium', lg: 'Large' }],
                    ['align', 'Text alignment', { left: 'Left', center: 'Centre' }],
                ] as const).map(([key, label, options]) => (
                    <TextField key={key} select size="small" label={label} value={selected.style[key]} disabled={!canEdit} onChange={(e) => patch(selected.id, (b) => ({ ...b, style: { ...b.style, [key]: e.target.value } }))}>
                        {Object.entries(options).map(([value, text]) => <MenuItem key={value} value={value}>{text}</MenuItem>)}
                    </TextField>
                ))}
            </Stack>
        </Box>
    ) : (
        <Box>
            <Typography variant="h4" component="h2" sx={{ mb: 1 }}>Block settings</Typography>
            <Typography sx={{ color: 'text.secondary', fontSize: 14 }}>
                Select a block on the page to change its content and style. Click any text on the page to edit it in place, or click an image to replace it.
            </Typography>
            {problems.length > 0 && (
                <Alert severity="warning" sx={{ mt: 2.5 }}>
                    <Typography sx={{ fontWeight: 600, fontSize: 14, mb: 0.5 }}>Before this page can be published</Typography>
                    <Box component="ul" sx={{ m: 0, pl: 2.25, fontSize: 14 }}>{problems.map((p) => <li key={p}>{p}</li>)}</Box>
                </Alert>
            )}
        </Box>
    );

    const status = (() => {
        if (!canEdit) return <Typography sx={{ fontSize: 13, color: 'text.secondary' }}>View only</Typography>;
        if (saveState === 'saving') return <Stack direction="row" spacing={0.75} sx={{ alignItems: 'center' }}><CircularProgress size={14} /><Typography sx={{ fontSize: 13, color: 'text.secondary' }}>Saving…</Typography></Stack>;
        if (saveState === 'error') {
            return (
                <Tooltip title={saveError}>
                    <Button size="small" color="error" startIcon={<ErrorOutlined />} onClick={() => void saveNow()}>Not saved. Try again</Button>
                </Tooltip>
            );
        }
        if (saveState === 'unsaved') return <Typography sx={{ fontSize: 13, color: 'text.secondary' }}>Unsaved changes</Typography>;
        return <Stack direction="row" spacing={0.5} sx={{ alignItems: 'center', color: 'success.main' }}><CheckCircleOutlined sx={{ fontSize: 16 }} /><Typography sx={{ fontSize: 13 }}>Draft saved</Typography></Stack>;
    })();

    return (
        <Box sx={{ height: '100dvh', display: 'flex', flexDirection: 'column', bgcolor: '#EEF0F4' }}>
            <Head title={`${page.name} · Builder`} />

            <Box component="header" sx={{ display: 'flex', alignItems: 'center', gap: 1, px: 1.5, minHeight: 60, bgcolor: 'background.paper', borderBottom: 1, borderColor: 'divider', flexWrap: 'wrap', py: 0.75 }}>
                <Tooltip title="Back to page settings">
                    <IconButton aria-label="Back to page settings" component={RouterLink} href={base}><ArrowBack /></IconButton>
                </Tooltip>
                <Box sx={{ minWidth: 0, mr: 1 }}>
                    <Typography component="h1" noWrap sx={{ fontFamily: '"Space Grotesk", sans-serif', fontWeight: 600, fontSize: 17, maxWidth: { xs: 140, md: 260 } }}>{page.name}</Typography>
                    <Typography sx={{ fontSize: 12, color: 'text.secondary', lineHeight: 1.2 }}>
                        {page.status === 'published' ? (unpublished ? 'Published, with changes not yet live' : 'Published') : 'Draft'}
                    </Typography>
                </Box>

                {compact && canEdit && (
                    <>
                        <Button size="small" variant="outlined" startIcon={<Add />} onClick={(e) => setAddAnchor(e.currentTarget)} aria-haspopup="menu">Add block</Button>
                        <Menu anchorEl={addAnchor} open={Boolean(addAnchor)} onClose={() => setAddAnchor(null)}>
                            {Object.entries(library).map(([type, definition]) => (
                                <MenuItem key={type} disabled={type === 'order' && hasOrder} onClick={() => { insert(type); setAddAnchor(null); }}>{definition.label}</MenuItem>
                            ))}
                        </Menu>
                    </>
                )}

                <Box sx={{ flex: 1 }} />

                <ToggleButtonGroup size="small" exclusive value={device} onChange={(_, value: Device | null) => value && setDevice(value)} aria-label="Preview width">
                    <ToggleButton value="desktop" aria-label={DEVICES.desktop.label}><Tooltip title="Desktop 1440"><DesktopWindowsOutlined fontSize="small" /></Tooltip></ToggleButton>
                    <ToggleButton value="tablet" aria-label={DEVICES.tablet.label}><Tooltip title="Tablet 820"><TabletMacOutlined fontSize="small" /></Tooltip></ToggleButton>
                    <ToggleButton value="mobile" aria-label={DEVICES.mobile.label}><Tooltip title="Mobile 390"><PhoneIphoneOutlined fontSize="small" /></Tooltip></ToggleButton>
                </ToggleButtonGroup>

                {canEdit && (
                    <Stack direction="row">
                        <Tooltip title="Undo (Ctrl+Z)"><span><IconButton aria-label="Undo" disabled={history.undo === 0} onClick={() => travel('undo')}><Undo /></IconButton></span></Tooltip>
                        <Tooltip title="Redo (Ctrl+Shift+Z)"><span><IconButton aria-label="Redo" disabled={history.redo === 0} onClick={() => travel('redo')}><Redo /></IconButton></span></Tooltip>
                    </Stack>
                )}

                <Box role="status" aria-live="polite" sx={{ minWidth: 104, display: 'flex', justifyContent: 'center' }}>{status}</Box>

                <Box sx={{ flex: 1 }} />

                <Tooltip title="Published versions"><IconButton aria-label="Published versions" onClick={() => setVersionsOpen(true)}><History /></IconButton></Tooltip>
                {compact && <Tooltip title="Block settings"><IconButton aria-label="Block settings" onClick={() => setPanelOpen(true)}><Tune /></IconButton></Tooltip>}
                <Button startIcon={<OpenInNew />} onClick={preview}>Preview</Button>
                {canPublish && (
                    <Button variant="contained" onClick={() => void publish()} disabled={publishing || saveState === 'error'}>{publishLabel}</Button>
                )}
            </Box>

            <Box sx={{ flex: 1, minHeight: 0, display: 'flex' }}>
                {!compact && (
                    <Box component="aside" aria-label="Block library" sx={{ width: 248, flexShrink: 0, overflowY: 'auto', p: 2, bgcolor: 'background.paper', borderRight: 1, borderColor: 'divider' }}>
                        <Typography variant="h4" component="h2" sx={{ mb: 0.5 }}>Blocks</Typography>
                        <Typography sx={{ color: 'text.secondary', fontSize: 13, mb: 2 }}>Drag a block onto the page, or click to add it.</Typography>
                        {libraryList}
                    </Box>
                )}

                <Box ref={stage} component="main" sx={{ flex: 1, minWidth: 0, overflow: 'hidden', p: 2, display: 'flex', justifyContent: 'center' }}>
                    <Box sx={{ width: DEVICES[device].width * frame.scale, height: frame.height * frame.scale, flexShrink: 0, borderRadius: 3, overflow: 'hidden', boxShadow: '0 8px 30px rgba(15,23,42,0.12)', bgcolor: '#fff', transition: 'width 200ms ease' }}>
                        <iframe
                            ref={iframe}
                            src={page.canvasUrl}
                            title={`${page.name}, page canvas at ${DEVICES[device].width} pixels`}
                            style={{ width: DEVICES[device].width, height: frame.height, border: 0, display: 'block', transform: `scale(${frame.scale})`, transformOrigin: '0 0' }}
                        />
                    </Box>
                </Box>

                {!compact && (
                    <Box component="aside" aria-label="Block settings" sx={{ width: 340, flexShrink: 0, overflowY: 'auto', p: 2.5, bgcolor: 'background.paper', borderLeft: 1, borderColor: 'divider' }}>
                        {settings}
                    </Box>
                )}
            </Box>

            {compact && (
                <Drawer anchor="right" open={panelOpen} onClose={() => setPanelOpen(false)} slotProps={{ paper: { sx: { width: 'min(360px, 92vw)', p: 2.5 } } }}>
                    {settings}
                    <Button sx={{ mt: 3 }} onClick={() => setPanelOpen(false)}>Done</Button>
                </Drawer>
            )}

            <MediaPicker
                open={picking !== null}
                onClose={() => setPicking(null)}
                onSelect={(items) => {
                    const target = picking;
                    setPicking(null);
                    if (!target || !items[0]) return;
                    setMedia((current) => ({ ...current, [items[0].id]: items[0] }));
                    patch(target.id, (b) => ({ ...b, data: setPath(b.data, target.path, items[0].id) }));
                }}
            />

            <Dialog open={versionsOpen} onClose={() => setVersionsOpen(false)} fullWidth maxWidth="sm">
                <DialogTitle>Published versions</DialogTitle>
                <DialogContent>
                    {versions.length === 0 ? (
                        <Typography sx={{ color: 'text.secondary' }}>This page has not been published yet. Each time you publish, that version is kept here, up to the last ten.</Typography>
                    ) : (
                        <>
                            <Typography sx={{ color: 'text.secondary', fontSize: 14 }}>
                                Restoring puts that version back as the draft. The live page changes only when you publish again. Packs and prices are not changed.
                            </Typography>
                            <List>
                                {versions.map((version, index) => (
                                    <ListItem
                                        key={version.id} divider={index < versions.length - 1} disableGutters
                                        secondaryAction={canEdit && <Button onClick={() => void restore(version)} disabled={restoring !== null}>{restoring === version.id ? 'Restoring…' : 'Restore'}</Button>}
                                    >
                                        <ListItemText
                                            primary={`${formatDateTime(version.publishedAt)}${index === 0 && page.status === 'published' ? ' · live now' : ''}`}
                                            secondary={`${version.by ? `Published by ${version.by}` : 'Published'} · ${version.blocks} ${version.blocks === 1 ? 'block' : 'blocks'}`}
                                        />
                                    </ListItem>
                                ))}
                            </List>
                        </>
                    )}
                </DialogContent>
                <DialogActions sx={{ px: 3, pb: 2.5 }}><Button onClick={() => setVersionsOpen(false)}>Close</Button></DialogActions>
            </Dialog>

            <Dialog open={blockedOpen} onClose={() => setBlockedOpen(false)} fullWidth maxWidth="sm">
                <DialogTitle>This page cannot be published yet</DialogTitle>
                <DialogContent>
                    <Box component="ul" sx={{ m: 0, pl: 2.5 }}>{problems.map((p) => <li key={p}>{p}</li>)}</Box>
                </DialogContent>
                <DialogActions sx={{ px: 3, pb: 2.5 }}><Button variant="contained" onClick={() => setBlockedOpen(false)}>OK</Button></DialogActions>
            </Dialog>

            <Snackbar open={toast !== null} autoHideDuration={6000} onClose={() => setToast(null)} anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}>
                <Alert severity={toast?.type ?? 'success'} variant="filled" onClose={() => setToast(null)}>{toast?.text}</Alert>
            </Snackbar>
        </Box>
    );
}
