import { usePage } from '@inertiajs/react';
import type { SharedProps } from './types';

/**
 * Hides controls a person cannot use. This is only for the interface:
 * the server checks every request again.
 */
export function useCan(): (module: string, action?: string) => boolean {
    const { auth } = usePage<SharedProps>().props;
    return (module, action = 'view') => !!auth.user && (auth.user.isSuper || !!auth.permissions[module]?.includes(action));
}
