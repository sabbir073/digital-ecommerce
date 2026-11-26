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

document.addEventListener('pointerdown', ripple, { passive: true });
mobileMenu();
