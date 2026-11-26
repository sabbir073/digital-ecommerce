import { DndContext, type DragEndEvent, KeyboardSensor, PointerSensor, closestCenter, useSensor, useSensors } from '@dnd-kit/core';
import { SortableContext, arrayMove, rectSortingStrategy, sortableKeyboardCoordinates, useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import Close from '@mui/icons-material/Close';
import Box from '@mui/material/Box';
import IconButton from '@mui/material/IconButton';
import type { MediaItem } from '../lib/types';

function Tile({ item, first, disabled, onRemove }: { item: MediaItem; first: boolean; disabled: boolean; onRemove: () => void }) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: item.id, disabled });

    return (
        <Box
            ref={setNodeRef}
            style={{ transform: CSS.Transform.toString(transform), transition }}
            sx={{ position: 'relative', aspectRatio: '1', borderRadius: 3, overflow: 'hidden', border: 1, borderColor: first ? 'primary.main' : 'divider', bgcolor: 'background.default', opacity: isDragging ? 0.6 : 1, zIndex: isDragging ? 2 : 0 }}
        >
            <Box
                {...attributes}
                {...listeners}
                aria-label={`${item.alt || item.filename}. Drag, or press space then the arrow keys, to reorder.`}
                sx={{ width: '100%', height: '100%', cursor: disabled ? 'default' : 'grab', touchAction: 'none' }}
            >
                {item.thumb && <img src={item.thumb} alt="" draggable={false} style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }} />}
            </Box>
            {first && (
                <Box sx={{ position: 'absolute', left: 6, bottom: 6, px: 1, py: 0.25, borderRadius: 999, bgcolor: 'primary.main', color: '#fff', fontSize: 11, fontWeight: 600 }}>Main</Box>
            )}
            {!disabled && (
                <IconButton size="small" aria-label={`Remove ${item.filename}`} onClick={onRemove} sx={{ position: 'absolute', top: 4, right: 4, bgcolor: 'rgba(255,255,255,0.92)', '&:hover': { bgcolor: '#fff' } }}>
                    <Close fontSize="small" />
                </IconButton>
            )}
        </Box>
    );
}

/** Image grid that reorders by drag (mouse, touch or keyboard). The first image is the main one. */
export default function SortableImages({ items, disabled = false, onChange }: { items: MediaItem[]; disabled?: boolean; onChange: (items: MediaItem[]) => void }) {
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
    );

    const onDragEnd = ({ active, over }: DragEndEvent) => {
        if (!over || active.id === over.id) return;
        const from = items.findIndex((i) => i.id === active.id);
        const to = items.findIndex((i) => i.id === over.id);
        onChange(arrayMove(items, from, to));
    };

    return (
        <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
            <SortableContext items={items.map((i) => i.id)} strategy={rectSortingStrategy}>
                <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: 'repeat(auto-fill, minmax(112px, 1fr))' }}>
                    {items.map((item, index) => (
                        <Tile key={item.id} item={item} first={index === 0} disabled={disabled} onRemove={() => onChange(items.filter((i) => i.id !== item.id))} />
                    ))}
                </Box>
            </SortableContext>
        </DndContext>
    );
}
