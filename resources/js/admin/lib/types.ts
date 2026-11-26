export type PermissionMap = Record<string, string[]>;

export interface SharedProps {
    store: { name: string };
    auth: {
        user: { id: number; name: string; email: string; role: string | null; isSuper: boolean; hasTwoFactor: boolean } | null;
        permissions: PermissionMap;
    };
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
