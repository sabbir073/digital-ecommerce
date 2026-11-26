import Add from '@mui/icons-material/Add';
import Close from '@mui/icons-material/Close';
import FormatBold from '@mui/icons-material/FormatBold';
import FormatItalic from '@mui/icons-material/FormatItalic';
import FormatListBulleted from '@mui/icons-material/FormatListBulleted';
import FormatListNumbered from '@mui/icons-material/FormatListNumbered';
import InsertLink from '@mui/icons-material/InsertLink';
import Autocomplete from '@mui/material/Autocomplete';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { type ReactNode, useEffect, useRef, useState } from 'react';
import type { MediaItem } from '../lib/types';
import MediaPicker from './MediaPicker';

/** One editable field of a block, as described by the block library on the server. */
export interface FieldDef {
    key: string;
    label: string;
    type: 'text' | 'textarea' | 'richtext' | 'toggle' | 'select' | 'image' | 'video' | 'images' | 'reviews' | 'datetime' | 'url' | 'strings' | 'list' | 'table';
    max?: number;
    min?: number;
    itemMax?: number;
    itemLabel?: string;
    options?: Record<string, string>;
    fields?: FieldDef[];
    maxColumns?: number;
    maxRows?: number;
}

export type MediaMap = Record<number, MediaItem>;
export interface ReviewOption {
    id: number;
    label: string;
}

interface Shared {
    media: MediaMap;
    onMedia: (items: MediaItem[]) => void;
    reviews: ReviewOption[];
    disabled: boolean;
}

type Data = Record<string, unknown>;

const toLocalInput = (iso: unknown): string => {
    if (typeof iso !== 'string' || iso === '') return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
};

/** A small editor for headings, paragraphs, lists and links. The server cleans what it saves. */
function RichText({ value, onChange, disabled, label }: { value: string; onChange: (html: string) => void; disabled: boolean; label: string }) {
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (ref.current && ref.current.innerHTML !== value && document.activeElement !== ref.current) ref.current.innerHTML = value;
    }, [value]);

    const run = (command: string, argument?: string) => {
        ref.current?.focus();
        document.execCommand(command, false, argument);
        onChange(ref.current?.innerHTML ?? '');
    };
    const link = () => {
        const url = window.prompt('Link address (https://...)');
        if (url && /^(https?:\/\/|mailto:|\/)/i.test(url.trim())) run('createLink', url.trim());
    };

    const tools: [string, string, ReactNode, () => void][] = [
        ['Heading', 'h2', 'H2', () => run('formatBlock', 'h2')],
        ['Small heading', 'h3', 'H3', () => run('formatBlock', 'h3')],
        ['Paragraph', 'p', 'P', () => run('formatBlock', 'p')],
        ['Bold', 'b', <FormatBold fontSize="small" key="b" />, () => run('bold')],
        ['Italic', 'i', <FormatItalic fontSize="small" key="i" />, () => run('italic')],
        ['Bullet list', 'ul', <FormatListBulleted fontSize="small" key="ul" />, () => run('insertUnorderedList')],
        ['Numbered list', 'ol', <FormatListNumbered fontSize="small" key="ol" />, () => run('insertOrderedList')],
        ['Link', 'a', <InsertLink fontSize="small" key="a" />, link],
    ];

    return (
        <Box>
            <Typography component="span" id={`${label}-rt`} sx={{ fontSize: 13, fontWeight: 500, color: 'text.secondary' }}>{label}</Typography>
            <Box sx={{ mt: 0.5, border: 1, borderColor: 'divider', borderRadius: 2.5, overflow: 'hidden' }}>
                <Stack direction="row" role="toolbar" aria-label="Text formatting" sx={{ flexWrap: 'wrap', borderBottom: 1, borderColor: 'divider', bgcolor: 'background.default', p: 0.5 }}>
                    {tools.map(([title, key, icon, action]) => (
                        <Tooltip title={title} key={key}>
                            <span>
                                <IconButton size="small" aria-label={title} disabled={disabled} onMouseDown={(e) => e.preventDefault()} onClick={action} sx={{ width: 36, height: 36, fontSize: 13, fontWeight: 700 }}>{icon}</IconButton>
                            </span>
                        </Tooltip>
                    ))}
                </Stack>
                <Box
                    ref={ref}
                    role="textbox"
                    aria-multiline="true"
                    aria-labelledby={`${label}-rt`}
                    contentEditable={!disabled}
                    suppressContentEditableWarning
                    onInput={(e) => onChange((e.target as HTMLDivElement).innerHTML)}
                    onPaste={(e) => {
                        // Paste as plain text so formatting from other programs does not come along.
                        e.preventDefault();
                        document.execCommand('insertText', false, e.clipboardData.getData('text/plain'));
                    }}
                    sx={{ minHeight: 160, p: 2, outline: 'none', '&:focus-visible': { boxShadow: (t) => `inset 0 0 0 2px ${t.palette.primary.main}` }, '& h2': { fontSize: 22, my: 1 }, '& h3': { fontSize: 18, my: 1 }, '& p': { my: 1 }, '& ul, & ol': { pl: 3 }, '& a': { color: 'primary.main' } }}
                />
            </Box>
        </Box>
    );
}

function MediaField({ field, value, onChange, shared }: { field: FieldDef; value: unknown; onChange: (v: unknown) => void; shared: Shared }) {
    const [open, setOpen] = useState(false);
    const multiple = field.type === 'images';
    const ids = multiple ? ((value as number[] | undefined) ?? []) : value ? [value as number] : [];
    const max = field.max ?? 8;

    const select = (items: MediaItem[]) => {
        shared.onMedia(items);
        if (multiple) onChange([...new Set([...ids, ...items.map((i) => i.id)])].slice(0, max));
        else onChange(items[0]?.id ?? null);
        setOpen(false);
    };

    return (
        <Box>
            <Typography sx={{ fontSize: 13, fontWeight: 500, color: 'text.secondary', mb: 0.75 }}>{field.label}</Typography>
            <Stack direction="row" spacing={1.5} useFlexGap sx={{ flexWrap: 'wrap', alignItems: 'center' }}>
                {ids.map((id) => {
                    const item = shared.media[id];
                    return (
                        <Box key={id} sx={{ position: 'relative', width: 96, height: 96, borderRadius: 2.5, overflow: 'hidden', bgcolor: 'background.default', border: 1, borderColor: 'divider', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                            {item?.thumb ? <img src={item.thumb} alt={item.alt ?? item.filename} style={{ width: '100%', height: '100%', objectFit: 'cover' }} /> : <Typography sx={{ fontSize: 12, p: 1, textAlign: 'center', wordBreak: 'break-all' }}>{item?.filename ?? 'File removed'}</Typography>}
                            <IconButton
                                size="small" aria-label={`Remove ${item?.filename ?? 'file'}`} disabled={shared.disabled}
                                onClick={() => onChange(multiple ? ids.filter((x) => x !== id) : null)}
                                sx={{ position: 'absolute', top: 2, right: 2, bgcolor: 'background.paper', '&:hover': { bgcolor: 'background.paper' } }}
                            >
                                <Close fontSize="small" />
                            </IconButton>
                        </Box>
                    );
                })}
                {(multiple ? ids.length < max : true) && (
                    <Button variant="outlined" disabled={shared.disabled} onClick={() => setOpen(true)}>
                        {multiple ? 'Add images' : ids.length ? 'Change' : field.type === 'video' ? 'Choose video' : 'Choose image'}
                    </Button>
                )}
            </Stack>
            <MediaPicker open={open} multiple={multiple} type={field.type === 'video' ? 'video' : 'image'} onClose={() => setOpen(false)} onSelect={select} />
        </Box>
    );
}

function Field({ field, value, onChange, shared, error }: { field: FieldDef; value: unknown; onChange: (v: unknown) => void; shared: Shared; error?: string }) {
    const { disabled } = shared;

    switch (field.type) {
        case 'text':
        case 'url':
        case 'textarea':
            return (
                <TextField
                    label={field.label} value={(value as string) ?? ''} disabled={disabled} fullWidth
                    multiline={field.type === 'textarea'} minRows={field.type === 'textarea' ? 2 : undefined}
                    type={field.type === 'url' ? 'url' : 'text'}
                    onChange={(e) => onChange(e.target.value)}
                    error={Boolean(error)} helperText={error}
                    slotProps={{ htmlInput: { maxLength: field.max ?? 500 } }}
                />
            );
        case 'richtext':
            return <RichText label={field.label} value={(value as string) ?? ''} onChange={onChange} disabled={disabled} />;
        case 'toggle':
            return <FormControlLabel control={<Switch checked={Boolean(value)} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />} label={field.label} />;
        case 'select':
            return (
                <TextField select label={field.label} value={(value as string) ?? ''} disabled={disabled} onChange={(e) => onChange(e.target.value)} sx={{ minWidth: 220 }}>
                    {Object.entries(field.options ?? {}).map(([key, label]) => <MenuItem key={key} value={key}>{label}</MenuItem>)}
                </TextField>
            );
        case 'datetime':
            return (
                <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                    <TextField
                        label={field.label} type="datetime-local" value={toLocalInput(value)} disabled={disabled} fullWidth
                        onChange={(e) => onChange(e.target.value ? new Date(e.target.value).toISOString() : null)}
                        slotProps={{ inputLabel: { shrink: true } }}
                    />
                    {Boolean(value) && <Button disabled={disabled} onClick={() => onChange(null)}>Clear</Button>}
                </Stack>
            );
        case 'image':
        case 'video':
        case 'images':
            return <MediaField field={field} value={value} onChange={onChange} shared={shared} />;
        case 'reviews': {
            const ids = (value as number[] | undefined) ?? [];
            return (
                <Autocomplete
                    multiple disabled={disabled} options={shared.reviews} getOptionLabel={(o) => o.label}
                    value={ids.map((id) => shared.reviews.find((r) => r.id === id)).filter((r): r is ReviewOption => Boolean(r))}
                    onChange={(_, chosen) => onChange(chosen.map((r) => r.id).slice(0, field.max ?? 12))}
                    noOptionsText="This product has no approved reviews yet"
                    renderInput={(params) => <TextField {...params} label={field.label} helperText="Used when “Hand-picked” is chosen. Only approved reviews of the linked product are listed." />}
                />
            );
        }
        case 'strings': {
            const lines = (value as string[] | undefined) ?? [];
            return (
                <Box>
                    <Typography sx={{ fontSize: 13, fontWeight: 500, color: 'text.secondary', mb: 0.75 }}>{field.label}</Typography>
                    <Stack spacing={1}>
                        {lines.map((line, index) => (
                            <Stack direction="row" spacing={1} key={index} sx={{ alignItems: 'center' }}>
                                <TextField
                                    size="small" fullWidth value={line} disabled={disabled}
                                    onChange={(e) => onChange(lines.map((l, i) => (i === index ? e.target.value : l)))}
                                    slotProps={{ htmlInput: { maxLength: field.itemMax ?? 160, 'aria-label': `${field.label} ${index + 1}` } }}
                                />
                                <IconButton aria-label={`Remove line ${index + 1}`} disabled={disabled} onClick={() => onChange(lines.filter((_, i) => i !== index))}><Close fontSize="small" /></IconButton>
                            </Stack>
                        ))}
                        {lines.length < (field.max ?? 10) && <Button startIcon={<Add />} disabled={disabled} onClick={() => onChange([...lines, ''])} sx={{ alignSelf: 'flex-start' }}>Add line</Button>}
                    </Stack>
                </Box>
            );
        }
        case 'list': {
            const items = (value as Data[] | undefined) ?? [];
            const min = field.min ?? 0;
            return (
                <Box>
                    <Typography sx={{ fontSize: 13, fontWeight: 500, color: 'text.secondary', mb: 0.75 }}>{field.label}</Typography>
                    <Stack spacing={1.5}>
                        {items.map((item, index) => (
                            <Box key={index} sx={{ border: 1, borderColor: 'divider', borderRadius: 3, p: 2 }}>
                                <Stack direction="row" sx={{ justifyContent: 'space-between', alignItems: 'center', mb: 1.5 }}>
                                    <Typography sx={{ fontWeight: 600, fontSize: 14 }}>{field.itemLabel ?? 'Item'} {index + 1}</Typography>
                                    <Tooltip title={items.length <= min ? `At least ${min} needed` : 'Remove'}>
                                        <span>
                                            <IconButton size="small" aria-label={`Remove ${field.itemLabel ?? 'item'} ${index + 1}`} disabled={disabled || items.length <= min} onClick={() => onChange(items.filter((_, i) => i !== index))}><Close fontSize="small" /></IconButton>
                                        </span>
                                    </Tooltip>
                                </Stack>
                                <Stack spacing={2}>
                                    {(field.fields ?? []).map((sub) => (
                                        <Field key={sub.key} field={sub} value={item[sub.key]} shared={shared} onChange={(v) => onChange(items.map((it, i) => (i === index ? { ...it, [sub.key]: v } : it)))} />
                                    ))}
                                </Stack>
                            </Box>
                        ))}
                        {items.length < (field.max ?? 20) && (
                            <Button startIcon={<Add />} disabled={disabled} sx={{ alignSelf: 'flex-start' }} onClick={() => onChange([...items, Object.fromEntries((field.fields ?? []).map((f) => [f.key, '']))])}>
                                Add {(field.itemLabel ?? 'item').toLowerCase()}
                            </Button>
                        )}
                    </Stack>
                </Box>
            );
        }
        case 'table': {
            const table = (value as { columns: string[]; rows: string[][] } | undefined) ?? { columns: [], rows: [] };
            const set = (next: { columns: string[]; rows: string[][] }) => onChange(next);
            return (
                <Box>
                    <Typography sx={{ fontSize: 13, fontWeight: 500, color: 'text.secondary', mb: 0.75 }}>{field.label}</Typography>
                    <Box sx={{ overflowX: 'auto', pb: 1 }}>
                        <Box component="table" sx={{ borderCollapse: 'separate', borderSpacing: '8px' }}>
                            <thead>
                                <tr>
                                    {table.columns.map((column, c) => (
                                        <th key={c}>
                                            <Stack direction="row" sx={{ alignItems: 'center' }}>
                                                <TextField size="small" value={column} disabled={disabled} placeholder="Column title" sx={{ minWidth: 160 }}
                                                    onChange={(e) => set({ ...table, columns: table.columns.map((x, i) => (i === c ? e.target.value : x)) })}
                                                    slotProps={{ htmlInput: { 'aria-label': `Column ${c + 1} title`, style: { fontWeight: 600 } } }} />
                                                <IconButton size="small" aria-label={`Remove column ${c + 1}`} disabled={disabled} onClick={() => set({ columns: table.columns.filter((_, i) => i !== c), rows: table.rows.map((r) => r.filter((_, i) => i !== c)) })}><Close fontSize="small" /></IconButton>
                                            </Stack>
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {table.rows.map((row, r) => (
                                    <tr key={r}>
                                        {table.columns.map((_, c) => (
                                            <td key={c}>
                                                <TextField size="small" value={row[c] ?? ''} disabled={disabled} sx={{ minWidth: 160 }}
                                                    onChange={(e) => set({ ...table, rows: table.rows.map((x, i) => (i === r ? table.columns.map((__, j) => (j === c ? e.target.value : (x[j] ?? ''))) : x)) })}
                                                    slotProps={{ htmlInput: { 'aria-label': `Row ${r + 1}, column ${c + 1}` } }} />
                                            </td>
                                        ))}
                                        <td><IconButton size="small" aria-label={`Remove row ${r + 1}`} disabled={disabled} onClick={() => set({ ...table, rows: table.rows.filter((_, i) => i !== r) })}><Close fontSize="small" /></IconButton></td>
                                    </tr>
                                ))}
                            </tbody>
                        </Box>
                    </Box>
                    <Stack direction="row" spacing={1}>
                        <Button startIcon={<Add />} disabled={disabled || table.columns.length >= (field.maxColumns ?? 5)} onClick={() => set({ columns: [...table.columns, ''], rows: table.rows.map((r) => [...r, '']) })}>Add column</Button>
                        <Button startIcon={<Add />} disabled={disabled || table.columns.length === 0 || table.rows.length >= (field.maxRows ?? 20)} onClick={() => set({ ...table, rows: [...table.rows, table.columns.map(() => '')] })}>Add row</Button>
                    </Stack>
                </Box>
            );
        }
        default:
            return null;
    }
}

/** The settings form for one block: one input per field in the block library. */
export default function BlockFields({ fields, data, onChange, media, onMedia, reviews, disabled }: { fields: FieldDef[]; data: Data; onChange: (data: Data) => void } & Shared) {
    const shared = { media, onMedia, reviews, disabled };
    return (
        <Stack spacing={2.5}>
            {fields.map((field) => (
                <Field key={field.key} field={field} value={data[field.key]} shared={shared} onChange={(value) => onChange({ ...data, [field.key]: value })} />
            ))}
        </Stack>
    );
}
