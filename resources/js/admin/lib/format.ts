const dateTime = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });

export function formatDateTime(iso: string | null): string {
    return iso ? dateTime.format(new Date(iso)) : 'Never';
}

const ACTIONS: Record<string, string> = {
    sign_in: 'Signed in',
    sign_out: 'Signed out',
    create: 'Created',
    edit: 'Edited',
    delete: 'Deleted',
    publish: 'Published',
    refund: 'Refunded',
    export: 'Exported',
    invite: 'Invited',
    resend_invite: 'Sent invitation again to',
    accept_invite: 'Accepted invitation',
    role_change: 'Changed role of',
    suspend: 'Suspended',
    reactivate: 'Reactivated',
    force_sign_out: 'Signed out',
    change_password: 'Changed password',
    enable_two_factor: 'Turned on two-factor',
    disable_two_factor: 'Turned off two-factor',
};

export function actionLabel(action: string): string {
    return ACTIONS[action] ?? action.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());
}

export function formatMoney(cents: number): string {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(cents / 100);
}

export function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    if (bytes < 1024 * 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
    return `${(bytes / 1024 / 1024 / 1024).toFixed(2)} GB`;
}

export function slugify(text: string): string {
    return text
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}
