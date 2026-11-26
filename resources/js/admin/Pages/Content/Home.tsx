import { router } from '@inertiajs/react';
import { DndContext, type DragEndEvent, KeyboardSensor, PointerSensor, closestCenter, useSensor, useSensors } from '@dnd-kit/core';
import { SortableContext, arrayMove, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import DragIndicator from '@mui/icons-material/DragIndicator';
import ExpandMore from '@mui/icons-material/ExpandMore';
import OpenInNew from '@mui/icons-material/OpenInNew';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Collapse from '@mui/material/Collapse';
import IconButton from '@mui/material/IconButton';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import Typography from '@mui/material/Typography';
import { useState } from 'react';
import AdminLayout from '../../components/AdminLayout';
import BlockFields, { type FieldDef, type MediaMap } from '../../components/BlockFields';
import { useCan } from '../../lib/can';

interface Section {
    type: string;
    label: string;
    hint: string | null;
    enabled: boolean;
    settings: Record<string, unknown>;
    fields: FieldDef[];
}

function Row({ section, open, disabled, media, onMedia, onToggleOpen, onChange }: {
    section: Section; open: boolean; disabled: boolean; media: MediaMap; onMedia: (items: MediaMap) => void; onToggleOpen: () => void; onChange: (section: Section) => void;
}) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: section.type, disabled });

    return (
        <Card ref={setNodeRef} style={{ transform: CSS.Translate.toString(transform), transition }} sx={{ opacity: isDragging ? 0.7 : 1, zIndex: isDragging ? 2 : 0, position: 'relative' }}>
            <Stack direction="row" sx={{ alignItems: 'center', gap: 1, px: 1.5, py: 1 }}>
                <IconButton {...attributes} {...listeners} aria-label={`Move ${section.label}. Press space, then the arrow keys.`} disabled={disabled} sx={{ cursor: disabled ? 'default' : 'grab', touchAction: 'none' }}>
                    <DragIndicator />
                </IconButton>
                <Box sx={{ flex: 1, minWidth: 0 }}>
                    <Typography sx={{ fontWeight: 600 }}>{section.label}</Typography>
                    <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>{section.enabled ? 'Shown on the home page' : 'Hidden'}</Typography>
                </Box>
                <Switch checked={section.enabled} disabled={disabled} onChange={(e) => onChange({ ...section, enabled: e.target.checked })} slotProps={{ input: { 'aria-label': `Show ${section.label}` } }} />
                <IconButton aria-label={`${open ? 'Close' : 'Edit'} ${section.label}`} aria-expanded={open} onClick={onToggleOpen} sx={{ transform: open ? 'rotate(180deg)' : 'none', transition: 'transform 160ms' }}>
                    <ExpandMore />
                </IconButton>
            </Stack>
            <Collapse in={open} unmountOnExit>
                <Box sx={{ px: 3, pb: 3, pt: 1, borderTop: 1, borderColor: 'divider' }}>
                    {section.hint && <Typography sx={{ color: 'text.secondary', mb: 2.5 }}>{section.hint}</Typography>}
                    <BlockFields
                        fields={section.fields} data={section.settings} disabled={disabled} media={media} reviews={[]}
                        onMedia={(items) => onMedia(Object.fromEntries(items.map((i) => [i.id, i])))}
                        onChange={(settings) => onChange({ ...section, settings })}
                    />
                </Box>
            </Collapse>
        </Card>
    );
}

export default function HomeEditor({ sections: saved, media: savedMedia }: { sections: Section[]; media: MediaMap }) {
    const can = useCan();
    const disabled = !can('content', 'edit');
    const [sections, setSections] = useState(saved);
    const [media, setMedia] = useState<MediaMap>({ ...savedMedia });
    const [open, setOpen] = useState<string | null>(null);
    const [dirty, setDirty] = useState(false);
    const [saving, setSaving] = useState(false);

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
    );

    const change = (next: Section[]) => {
        setSections(next);
        setDirty(true);
    };

    const onDragEnd = ({ active, over }: DragEndEvent) => {
        if (!over || active.id === over.id) return;
        change(arrayMove(sections, sections.findIndex((s) => s.type === active.id), sections.findIndex((s) => s.type === over.id)));
    };

    function save() {
        setSaving(true);
        router.put('/admin/home', { sections: sections.map((s) => ({ type: s.type, enabled: s.enabled, settings: s.settings })) } as never, {
            preserveScroll: true, preserveState: true, onSuccess: () => setDirty(false), onFinish: () => setSaving(false),
        });
    }

    return (
        <AdminLayout
            title="Home page"
            actions={
                <Stack direction="row" sx={{ gap: 1.5 }}>
                    <Button component="a" href="/" target="_blank" rel="noopener" variant="outlined" startIcon={<OpenInNew />}>View</Button>
                    {!disabled && <Button variant="contained" onClick={save} loading={saving} disabled={!dirty}>Save</Button>}
                </Stack>
            }
        >
            <Typography sx={{ color: 'text.secondary', mb: 2 }}>Drag the sections into the order you want, switch any of them off, and open one to edit its text, images and links.</Typography>
            {dirty && <Alert severity="info" sx={{ mb: 2 }}>You have changes that are not saved yet.</Alert>}

            <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
                <SortableContext items={sections.map((s) => s.type)} strategy={verticalListSortingStrategy}>
                    <Stack sx={{ gap: 1.5, maxWidth: 860 }}>
                        {sections.map((section) => (
                            <Row
                                key={section.type} section={section} open={open === section.type} disabled={disabled} media={media}
                                onMedia={(items) => setMedia((current) => ({ ...current, ...items }))}
                                onToggleOpen={() => setOpen(open === section.type ? null : section.type)}
                                onChange={(next) => change(sections.map((s) => (s.type === next.type ? next : s)))}
                            />
                        ))}
                    </Stack>
                </SortableContext>
            </DndContext>
        </AdminLayout>
    );
}
