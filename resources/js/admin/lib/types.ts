export type PermissionMap = Record<string, string[]>;

export interface SharedProps {
    store: { name: string };
    auth: {
        user: { id: number; name: string; email: string; role: string | null; isSuper: boolean; hasTwoFactor: boolean } | null;
        permissions: PermissionMap;
    };
    payments: { testMode: boolean } | null;
    flash: { success: string | null; error: string | null };
    [key: string]: unknown;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export interface MediaItem {
    id: number;
    url: string;
    thumb: string | null;
    filename: string;
    type: 'image' | 'video';
    alt: string | null;
    width: number | null;
    height: number | null;
    sizeBytes: number;
    folderId: number | null;
    createdAt: string | null;
}
