import { router, useForm } from '@inertiajs/react';
import { DndContext, type DragEndEvent, KeyboardSensor, PointerSensor, closestCenter, useSensor, useSensors } from '@dnd-kit/core';
import { SortableContext, arrayMove, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import Add from '@mui/icons-material/Add';
import DeleteOutlined from '@mui/icons-material/DeleteOutlined';
import DragIndicator from '@mui/icons-material/DragIndicator';
import SubdirectoryArrowRight from '@mui/icons-material/SubdirectoryArrowRight';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { type FormEvent, useState } from 'react';
import AdminLayout from '../../components/AdminLayout';
import { useCan } from '../../lib/can';

type LinkType = 'category' | 'page' | 'selling_page' | 'blog' | 'url';
interface Link {
    _id: string;
    label: string;
    link_type: LinkType;
    link_id: number | null;
    url: string;
    children?: Link[];
}
interface Target {
    id: number;
    label: string;
}
type Targets = Record<'category' | 'page' | 'selling_page', Target[]>;
interface MenuData {
    location: string;
    label: string;
    name: string;
    items: Omit<Link, '_id'>[];
}
interface Announcement {
    enabled: boolean;
    text: string;
    link: string;
    starts_at: string | null;
    ends_at: string | null;
    demo: boolean;
}

const TYPE_LABEL: Record<LinkType, string> = { category: 'Category', page: 'Page', selling_page: 'Selling page', blog: 'Blog', url: 'Custom link' };
let counter = 0;
const uid = () => `l${Date.now().toString(36)}${counter++}`;
const withIds = (items: Omit<Link, '_id'>[]): Link[] => items.map((item) => ({ ...item, _id: uid(), children: item.children ? withIds(item.children) : undefined }));
const blank = (): Link => ({ _id: uid(), label: '', link_type: 'category', link_id: null, url: '' });

const toLocal = (iso: string | null): string => {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

function LinkRow({ link, targets, disabled, child, canNest, onChange, onRemove, onAddChild }: {
    link: Link; targets: Targets; disabled: boolean; child: boolean; canNest: boolean; onChange: (link: Link) => void; onRemove: () => void; onAddChild?: () => void;
}) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: link._id, disabled });
    const options = link.link_type === 'url' || link.link_type === 'blog' ? [] : targets[link.link_type];

    const setType = (type: LinkType) => onChange({ ...link, link_type: type, link_id: null, url: '' });
    const setTarget = (id: number) => {
        const target = options.find((o) => o.id === id);
        // A new link takes the name of what it opens; a label someone typed is left alone.
        onChange({ ...link, link_id: id, label: link.label.trim() === '' && target ? target.label.replace(/ \(draft.*\)$/, '') : link.label });
    };

    return (
        <Box ref={setNodeRef} style={{ transform: CSS.Translate.toString(transform), transition }} sx={{ opacity: isDragging ? 0.7 : 1, position: 'relative', zIndex: isDragging ? 2 : 0, bgcolor: 'background.paper' }}>
            <Box sx={{ display: 'grid', gap: 1.5, alignItems: 'start', gridTemplateColumns: { xs: 'auto minmax(0, 1fr) auto', md: `auto minmax(0, 1fr) 170px minmax(0, 1.2fr) auto` }, pl: child ? { xs: 2, md: 5 } : 0 }}>
                <IconButton {...attributes} {...listeners} aria-label={`Move ${link.label || 'link'}. Press space, then the arrow keys.`} disabled={disabled} sx={{ cursor: disabled ? 'default' : 'grab', touchAction: 'none', mt: 0.5 }}>
                    <DragIndicator />
                </IconButton>
                <TextField label="Label" size="small" value={link.label} disabled={disabled} onChange={(e) => onChange({ ...link, label: e.target.value })} slotProps={{ htmlInput: { maxLength: 60 } }} />
                <Stack direction="row" sx={{ gridColumn: { xs: '3', md: '5' }, gridRow: { xs: '1', md: '1' }, mt: 0.5 }}>
                    {canNest && onAddChild && <Tooltip title="Add a sub-link"><span><IconButton aria-label={`Add a sub-link under ${link.label || 'this link'}`} disabled={disabled} onClick={onAddChild}><SubdirectoryArrowRight /></IconButton></span></Tooltip>}
                    <Tooltip title="Remove"><span><IconButton aria-label={`Remove ${link.label || 'link'}`} disabled={disabled} onClick={onRemove}><DeleteOutlined /></IconButton></span></Tooltip>
                </Stack>
                <TextField select label="Opens" size="small" value={link.link_type} disabled={disabled} onChange={(e) => setType(e.target.value as LinkType)} sx={{ gridColumn: { xs: '2 / 4', md: '3' }, gridRow: { md: '1' } }}>
                    {(Object.keys(TYPE_LABEL) as LinkType[]).map((type) => <MenuItem key={type} value={type}>{TYPE_LABEL[type]}</MenuItem>)}
                </TextField>
                <Box sx={{ gridColumn: { xs: '2 / 4', md: '4' }, gridRow: { md: '1' } }}>
                    {link.link_type === 'url' && <TextField label="Address" size="small" fullWidth placeholder="https://… or /products" value={link.url} disabled={disabled} onChange={(e) => onChange({ ...link, url: e.target.value })} slotProps={{ htmlInput: { maxLength: 500 } }} />}
                    {link.link_type === 'blog' && <Typography sx={{ color: 'text.secondary', fontSize: 14, pt: 1 }}>The list of blog posts</Typography>}
                    {link.link_type !== 'url' && link.link_type !== 'blog' && (
                        <TextField select label={TYPE_LABEL[link.link_type]} size="small" fullWidth value={link.link_id ?? ''} disabled={disabled} onChange={(e) => setTarget(Number(e.target.value))}>
                            {options.length === 0 && <MenuItem value="" disabled>Nothing to choose from yet</MenuItem>}
                            {options.map((o) => <MenuItem key={o.id} value={o.id}>{o.label}</MenuItem>)}
                        </TextField>
                    )}
                </Box>
            </Box>
        </Box>
    );
}

function LinkList({ links, targets, disabled, child = false, canNest, onChange }: { links: Link[]; targets: Targets; disabled: boolean; child?: boolean; canNest: boolean; onChange: (links: Link[]) => void }) {
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
    );
    const onDragEnd = ({ active, over }: DragEndEvent) => {
        if (!over || active.id === over.id) return;
        onChange(arrayMove(links, links.findIndex((l) => l._id === active.id), links.findIndex((l) => l._id === over.id)));
    };
    const replace = (next: Link) => onChange(links.map((l) => (l._id === next._id ? next : l)));

    return (
        <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
            <SortableContext items={links.map((l) => l._id)} strategy={verticalListSortingStrategy}>
                <Stack sx={{ gap: 2 }}>
                    {links.map((link) => (
                        <Stack key={link._id} sx={{ gap: 2 }}>
                            <LinkRow
                                link={link} targets={targets} disabled={disabled} child={child} canNest={canNest}
                                onChange={replace} onRemove={() => onChange(links.filter((l) => l._id !== link._id))}
                                onAddChild={canNest ? () => replace({ ...link, children: [...(link.children ?? []), blank()] }) : undefined}
                            />
                            {canNest && (link.children ?? []).length > 0 && (
                                <LinkList links={link.children ?? []} targets={targets} disabled={disabled} child canNest={false} onChange={(children) => replace({ ...link, children })} />
                            )}
                        </Stack>
                    ))}
                </Stack>
            </SortableContext>
        </DndContext>
    );
}

function MenuCard({ menu, targets, disabled }: { menu: MenuData; targets: Targets; disabled: boolean }) {
    const header = menu.location === 'header';
    const [name, setName] = useState(menu.name);
    const [links, setLinks] = useState<Link[]>(() => withIds(menu.items));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    function save() {
        setSaving(true);
        setError(null);
        const strip = (link: Link): Record<string, unknown> => ({ label: link.label, link_type: link.link_type, link_id: link.link_id, url: link.url, children: (link.children ?? []).map(strip) });
        router.put(`/admin/navigation/${menu.location}`, { name, items: links.map(strip) } as never, {
            preserveScroll: true, preserveState: true,
            onError: (errors) => setError(Object.values(errors)[0] ?? 'The menu could not be saved.'),
            onFinish: () => setSaving(false),
        });
    }

    return (
        <Card sx={{ p: 3 }}>
            <Stack direction="row" sx={{ alignItems: 'center', gap: 2, mb: 2, flexWrap: 'wrap' }}>
                <Typography variant="h4" component="h2" sx={{ flex: 1 }}>{menu.label}</Typography>
                {!disabled && <Button variant="contained" onClick={save} loading={saving}>Save</Button>}
            </Stack>
            {header
                ? <Typography sx={{ color: 'text.secondary', mb: 2.5 }}>While this menu is empty, the header shows your top-level categories. A link can have sub-links, which open under it.</Typography>
                : <TextField label="Column heading" size="small" value={name} disabled={disabled} onChange={(e) => setName(e.target.value)} sx={{ mb: 2.5, maxWidth: 320 }} fullWidth slotProps={{ htmlInput: { maxLength: 60 } }} />}
            {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}

            <LinkList links={links} targets={targets} disabled={disabled} canNest={header} onChange={setLinks} />
            {links.length === 0 && <Typography sx={{ color: 'text.secondary' }}>No links yet.</Typography>}
            {!disabled && links.length < 20 && <Button startIcon={<Add />} onClick={() => setLinks([...links, blank()])} sx={{ mt: 2 }}>Add link</Button>}
        </Card>
    );
}

function AnnouncementCard({ announcement, disabled }: { announcement: Announcement; disabled: boolean }) {
    const form = useForm({ enabled: announcement.enabled, text: announcement.text, link: announcement.link, starts_at: announcement.starts_at, ends_at: announcement.ends_at });
    const date = (key: 'starts_at' | 'ends_at', label: string) => (
        <TextField
            label={label} type="datetime-local" size="small" value={toLocal(form.data[key])} disabled={disabled}
            onChange={(e) => form.setData(key, e.target.value ? new Date(e.target.value).toISOString() : null)}
            error={!!form.errors[key]} helperText={form.errors[key] ?? 'Optional'} slotProps={{ inputLabel: { shrink: true } }}
        />
    );

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put('/admin/navigation/announcement', { preserveScroll: true });
    }

    return (
        <Card component="form" onSubmit={submit} sx={{ p: 3 }}>
            <Stack direction="row" sx={{ alignItems: 'center', gap: 2, mb: 1, flexWrap: 'wrap' }}>
                <Typography variant="h4" component="h2" sx={{ flex: 1 }}>Announcement bar</Typography>
                {!disabled && <Button type="submit" variant="contained" loading={form.processing}>Save</Button>}
            </Stack>
            <Typography sx={{ color: 'text.secondary', mb: 2 }}>A thin dark bar above the header on every store page.</Typography>
            {announcement.demo && <Alert severity="info" sx={{ mb: 2 }}>This text was added with the demo data. Saving here makes it yours, so clearing the demo data will keep it.</Alert>}
            <Stack sx={{ gap: 2.5 }}>
                <FormControlLabel control={<Switch checked={form.data.enabled} disabled={disabled} onChange={(e) => form.setData('enabled', e.target.checked)} />} label="Show the announcement bar" />
                <TextField label="Text" size="small" value={form.data.text} disabled={disabled} onChange={(e) => form.setData('text', e.target.value)} error={!!form.errors.text} helperText={form.errors.text} slotProps={{ htmlInput: { maxLength: 160 } }} />
                <TextField label="Link (optional)" size="small" placeholder="https://… or /products" value={form.data.link} disabled={disabled} onChange={(e) => form.setData('link', e.target.value)} error={!!form.errors.link} helperText={form.errors.link} slotProps={{ htmlInput: { maxLength: 500 } }} />
                <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>
                    {date('starts_at', 'Show from')}
                    {date('ends_at', 'Show until')}
                </Box>
            </Stack>
        </Card>
    );
}

export default function Navigation({ menus, targets, announcement }: { menus: MenuData[]; targets: Targets; announcement: Announcement }) {
    const disabled = !useCan()('content', 'edit');

    return (
        <AdminLayout title="Header and footer">
            <Stack sx={{ gap: 3, maxWidth: 1040 }}>
                <AnnouncementCard announcement={announcement} disabled={disabled} />
                {menus.map((menu) => <MenuCard key={menu.location} menu={menu} targets={targets} disabled={disabled} />)}
            </Stack>
        </AdminLayout>
    );
}
