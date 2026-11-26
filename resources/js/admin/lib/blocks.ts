import type { FieldDef } from '../components/BlockFields';

export interface Block {
    id: string;
    type: string;
    hidden: boolean;
    style: { background: string; spacing: string; align: string };
    data: Record<string, unknown>;
}

export type Library = Record<string, { label: string; fields: FieldDef[] }>;

export const newBlockId = () => `b${Date.now().toString(36)}${Math.random().toString(36).slice(2, 10)}`;

const DEFAULT_BG: Record<string, string> = { benefits: 'soft', order: 'soft', offer_bar: 'dark' };

/** Starting content for a new block, taken from the defaults in the block library. */
function blankData(fields: FieldDef[]): Record<string, unknown> {
    const blank = (field: FieldDef): unknown => {
        const preset = (field as FieldDef & { default?: unknown }).default;
        if (preset !== undefined) return preset;
        switch (field.type) {
            case 'toggle':
                return false;
            case 'image':
            case 'video':
            case 'datetime':
                return null;
            case 'images':
            case 'reviews':
            case 'strings':
                return [];
            case 'list':
                return Array.from({ length: field.min ?? 1 }, () => Object.fromEntries((field.fields ?? []).map((f) => [f.key, blank(f)])));
            case 'table':
                return { columns: ['', ''], rows: [['', '']] };
            default:
                return '';
        }
    };
    return Object.fromEntries(fields.map((f) => [f.key, blank(f)]));
}

export function newBlock(type: string, library: Library): Block {
    return { id: newBlockId(), type, hidden: false, style: { background: DEFAULT_BG[type] ?? 'white', spacing: 'md', align: 'left' }, data: blankData(library[type].fields) };
}

/** Copy of `data` with the value at a dotted path (for example "items.1.title") replaced. */
export function setPath(data: Record<string, unknown>, path: string, value: unknown): Record<string, unknown> {
    const keys = path.split('.');
    const walk = (node: unknown, depth: number): unknown => {
        if (depth === keys.length) return value;
        const key = keys[depth];
        if (Array.isArray(node)) {
            const copy = [...node];
            copy[Number(key)] = walk(copy[Number(key)], depth + 1);
            return copy;
        }
        const object = (node ?? {}) as Record<string, unknown>;
        return { ...object, [key]: walk(object[key], depth + 1) };
    };
    return walk(data, 0) as Record<string, unknown>;
}
