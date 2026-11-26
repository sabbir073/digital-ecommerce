import { router } from '@inertiajs/react';
import { type AnchorHTMLAttributes, forwardRef } from 'react';

/** A plain anchor that navigates with Inertia. Works as the `component` of any MUI element. */
const RouterLink = forwardRef<HTMLAnchorElement, AnchorHTMLAttributes<HTMLAnchorElement> & { href: string }>(
    function RouterLink({ href, onClick, ...rest }, ref) {
        return (
            <a
                ref={ref}
                href={href}
                {...rest}
                onClick={(event) => {
                    onClick?.(event);
                    const plainClick = event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey;
                    if (!event.defaultPrevented && plainClick) {
                        event.preventDefault();
                        router.visit(href);
                    }
                }}
            />
        );
    },
);

export default RouterLink;
