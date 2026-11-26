// Storefront behaviour shared by every public page. Kept tiny on purpose:
// selling pages have a 150 KB JavaScript budget (PRD section 9).

/** Material-style ripple on every .btn. */
function ripple(event: PointerEvent): void {
    const button = (event.target as HTMLElement | null)?.closest<HTMLElement>('.btn');
    if (!button || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const rect = button.getBoundingClientRect();
    const size = Math.max(rect.width, rect.height) * 2;
    const wave = document.createElement('span');
    wave.className = 'btn-ripple';
    wave.style.width = wave.style.height = `${size}px`;
    wave.style.left = `${event.clientX - rect.left - size / 2}px`;
    wave.style.top = `${event.clientY - rect.top - size / 2}px`;
    wave.addEventListener('animationend', () => wave.remove());
    button.appendChild(wave);
}

/** Mobile navigation: one button opens and closes the menu panel. */
function mobileMenu(): void {
    const toggle = document.querySelector<HTMLButtonElement>('[data-menu-toggle]');
    const panel = document.querySelector<HTMLElement>('[data-menu-panel]');
    if (!toggle || !panel) return;

    const setOpen = (open: boolean) => {
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', String(open));
    };

    toggle.addEventListener('click', () => setOpen(panel.hidden === true));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !panel.hidden) {
            setOpen(false);
            toggle.focus();
        }
    });
}

/** Filter controls marked data-autosubmit apply as soon as they change. */
function autoSubmit(): void {
    document.querySelectorAll<HTMLInputElement | HTMLSelectElement>('[data-autosubmit]').forEach((control) => {
        control.addEventListener('change', () => control.form?.requestSubmit());
    });
}

/**
 * Cart: every form marked data-cart-form is sent in the background and the
 * server's fresh cart HTML replaces every [data-cart-root] on the page.
 * Without JavaScript the same forms post normally and the cart page reloads.
 */
function cart(): void {
    const drawer = document.querySelector<HTMLElement>('[data-cart-drawer]');
    const panel = drawer?.querySelector<HTMLElement>('.drawer-panel') ?? null;
    let opener: HTMLElement | null = null;

    const roots = () => Array.from(document.querySelectorAll<HTMLElement>('[data-cart-root]'));
    const busy = (on: boolean) => roots().forEach((root) => root.classList.toggle('is-loading', on));

    const apply = (data: { html?: string; count?: number }) => {
        if (typeof data.html === 'string') roots().forEach((root) => (root.innerHTML = data.html as string));
        if (typeof data.count === 'number') {
            document.querySelectorAll<HTMLElement>('[data-cart-count]').forEach((badge) => {
                badge.textContent = String(data.count);
                badge.hidden = data.count === 0;
                badge.classList.remove('is-bumped');
                void badge.offsetWidth;
                badge.classList.add('is-bumped');
            });
        }
    };

    const open = async (from: HTMLElement | null) => {
        if (!drawer || !panel) return;
        opener = from;
        drawer.hidden = false;
        document.documentElement.style.overflow = 'hidden';
        panel.focus();
    };
    const close = () => {
        if (!drawer || drawer.hidden) return;
        drawer.hidden = true;
        document.documentElement.style.overflow = '';
        opener?.focus();
    };
    const load = async () => {
        if (!drawer) return;
        busy(true);
        try {
            const response = await fetch(drawer.dataset.url as string, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            apply(await response.json());
        } finally {
            busy(false);
        }
    };

    // On the cart page itself the icon is a plain link; everywhere else it opens the panel.
    if (drawer && !document.querySelector('[data-cart-page]')) {
        document.querySelectorAll<HTMLElement>('[data-cart-open]').forEach((link) =>
            link.addEventListener('click', (event) => {
                event.preventDefault();
                void open(link);
                void load();
            }),
        );
    }
    drawer?.addEventListener('click', (event) => {
        if ((event.target as HTMLElement).closest('[data-cart-close]')) close();
    });
    document.addEventListener('keydown', (event) => {
        if (!drawer || drawer.hidden) return;
        if (event.key === 'Escape') close();
        if (event.key === 'Tab' && panel) {
            // Keep keyboard focus inside the open panel.
            const items = Array.from(panel.querySelectorAll<HTMLElement>('a[href], button:not([disabled]), input:not([disabled])'));
            if (items.length === 0) return;
            const first = items[0];
            const last = items[items.length - 1];
            if (event.shiftKey && (document.activeElement === first || document.activeElement === panel)) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    });

    document.addEventListener('submit', async (event) => {
        const form = (event.target as HTMLElement).closest<HTMLFormElement>('form[data-cart-form]');
        if (!form) return;
        const submitter = (event as SubmitEvent).submitter as HTMLButtonElement | null;
        if (submitter?.name === 'intent' && submitter.value === 'buy') return; // Buy now is a normal page change

        event.preventDefault();
        const body = new FormData(form);
        if (submitter?.name) body.set(submitter.name, submitter.value);
        const opens = form.hasAttribute('data-cart-opens');
        submitter?.classList.add('is-busy');
        busy(true);
        try {
            const response = await fetch(form.action, { method: 'POST', body, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (response.status === 419 || response.status === 429 || response.status >= 500) {
                apply({ html: undefined });
                window.location.assign(form.dataset.fallback ?? '/cart');
                return;
            }
            apply(await response.json());
            if (opens) void open(submitter);
        } catch {
            form.submit();
        } finally {
            submitter?.classList.remove('is-busy');
            busy(false);
        }
    });
}

document.addEventListener('pointerdown', ripple, { passive: true });
cart();
autoSubmit();
mobileMenu();

export {};
