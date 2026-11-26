// Product page: gallery, variant pickers and quantity stepper (PRD section 3).
// Loaded only on product pages.

interface VariantState {
    id: number;
    values: number[];
    price: number;
    compareAt: number | null;
    stock: number | null; // null = unlimited
    mediaId: number | null;
}

interface ProductState {
    tracked: boolean;
    base: { price: number; compareAt: number | null; stock: number | null };
    variants: VariantState[];
}

const money = (cents: number): string =>
    (cents / 100).toLocaleString('en-US', { style: 'currency', currency: 'USD' });

const available = (stock: number | null): boolean => stock === null || stock > 0;

function gallery(root: HTMLElement): (mediaId: number) => void {
    const track = root.querySelector<HTMLElement>('[data-gallery-track]');
    const slides = Array.from(root.querySelectorAll<HTMLElement>('[data-gallery-slide]'));
    const thumbs = Array.from(root.querySelectorAll<HTMLButtonElement>('[data-gallery-thumb]'));
    if (!track || slides.length === 0) return () => undefined;

    const smooth = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const show = (index: number) => {
        track.scrollTo({ left: slides[index].offsetLeft - track.offsetLeft, behavior: smooth ? 'smooth' : 'auto' });
    };
    const mark = (index: number) => {
        thumbs.forEach((thumb, i) => (i === index ? thumb.setAttribute('aria-current', 'true') : thumb.removeAttribute('aria-current')));
    };

    thumbs.forEach((thumb, index) =>
        thumb.addEventListener('click', () => {
            show(index);
            mark(index);
        }),
    );

    // Swiping (or arrow keys) moves the strip; keep the highlighted thumbnail in step.
    let frame = 0;
    track.addEventListener(
        'scroll',
        () => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => mark(Math.round(track.scrollLeft / track.clientWidth)));
        },
        { passive: true },
    );
    track.addEventListener('keydown', (event) => {
        const current = Math.round(track.scrollLeft / track.clientWidth);
        if (event.key === 'ArrowRight' && current < slides.length - 1) show(current + 1);
        if (event.key === 'ArrowLeft' && current > 0) show(current - 1);
    });

    return (mediaId: number) => {
        const index = slides.findIndex((slide) => Number(slide.dataset.mediaId) === mediaId);
        if (index >= 0) {
            show(index);
            mark(index);
        }
    };
}

function init(): void {
    const root = document.querySelector<HTMLElement>('[data-product]');
    const stateNode = document.getElementById('product-state');
    if (!root || !stateNode) return;

    const state = JSON.parse(stateNode.textContent ?? '{}') as ProductState;
    const showMedia = gallery(root);

    const el = <T extends HTMLElement>(selector: string) => root.querySelector<T>(selector);
    const priceEl = el('[data-price]');
    const compareEl = el('[data-compare]');
    const saveEl = el('[data-save]');
    const totalEl = el('[data-total]');
    const noteEl = el('[data-stock-note]');
    const addLabel = el('[data-add-label]');
    const qty = el<HTMLInputElement>('[data-qty]');
    const variantInput = el<HTMLInputElement>('[data-variant-input]');
    const buyButtons = Array.from(root.querySelectorAll<HTMLButtonElement>('[data-buy-button]'));
    const groups = Array.from(root.querySelectorAll<HTMLElement>('[data-option]'));
    const radios = (group: HTMLElement) => Array.from(group.querySelectorAll<HTMLInputElement>('input[type="radio"]'));

    /** Chosen value id per option group, in group order (null when nothing is picked). */
    const selection = (): (number | null)[] =>
        groups.map((group) => {
            const checked = radios(group).find((radio) => radio.checked);
            return checked ? Number(checked.value) : null;
        });

    const currentVariant = (): VariantState | null => {
        if (state.variants.length === 0) return null;
        const chosen = selection();
        if (chosen.some((id) => id === null)) return null;
        return state.variants.find((variant) => chosen.every((id) => variant.values.includes(id as number))) ?? null;
    };

    /** Disable every value that has no in-stock variant alongside the other chosen values. */
    const refreshAvailability = () => {
        const chosen = selection();
        groups.forEach((group, groupIndex) => {
            radios(group).forEach((radio) => {
                const candidate = Number(radio.value);
                const possible = state.variants.some(
                    (variant) =>
                        variant.values.includes(candidate) &&
                        available(variant.stock) &&
                        chosen.every((id, index) => index === groupIndex || id === null || variant.values.includes(id)),
                );
                radio.disabled = !possible;
                radio.closest('label')?.classList.toggle('is-unavailable', !possible);
            });
        });
    };

    const clampQty = (stock: number | null): number => {
        if (!qty) return 1;
        let value = Math.floor(Number(qty.value)) || 1;
        if (value < 1) value = 1;
        if (stock !== null) {
            qty.max = String(Math.max(1, stock));
            if (value > stock) value = Math.max(1, stock);
        } else {
            qty.removeAttribute('max');
        }
        qty.value = String(value);
        return value;
    };

    const render = (moveGallery: boolean) => {
        refreshAvailability();
        const variant = currentVariant();
        const hasVariants = state.variants.length > 0;
        const price = variant ? variant.price : state.base.price;
        const compareAt = variant ? variant.compareAt : hasVariants ? null : state.base.compareAt;
        const stock = variant ? variant.stock : hasVariants ? 0 : state.base.stock;
        const soldOut = !available(stock);
        const quantity = clampQty(stock);

        groups.forEach((group) => {
            const label = group.querySelector<HTMLElement>('[data-option-label]');
            const checked = radios(group).find((radio) => radio.checked);
            if (label) label.textContent = checked?.dataset.label ?? '';
        });

        if (priceEl) priceEl.textContent = money(price);
        const onSale = compareAt !== null && compareAt > price;
        if (compareEl) {
            compareEl.hidden = !onSale;
            compareEl.textContent = onSale ? money(compareAt as number) : '';
        }
        if (saveEl) {
            saveEl.hidden = !onSale;
            saveEl.textContent = onSale ? `Save ${money((compareAt as number) - price)}` : '';
        }
        if (totalEl) totalEl.textContent = money(price * quantity);
        if (addLabel) addLabel.textContent = `Add to cart · ${money(price * quantity)}`;
        if (variantInput) variantInput.value = variant ? String(variant.id) : '';

        if (noteEl) {
            noteEl.textContent = soldOut
                ? hasVariants && !variant
                    ? 'This combination is not available'
                    : 'Out of stock'
                : stock !== null && stock <= 5
                  ? `Only ${stock} left`
                  : 'In stock';
            noteEl.classList.toggle('font-semibold', soldOut || (stock !== null && stock <= 5));
            noteEl.classList.toggle('text-muted', !soldOut && (stock === null || stock > 5));
        }
        if (qty) qty.disabled = soldOut;
        buyButtons.forEach((button) => (button.disabled = soldOut));
        root.querySelectorAll<HTMLButtonElement>('[data-qty-minus]').forEach((b) => (b.disabled = soldOut || quantity <= 1));
        root.querySelectorAll<HTMLButtonElement>('[data-qty-plus]').forEach((b) => (b.disabled = soldOut || (stock !== null && quantity >= stock)));

        if (moveGallery && variant?.mediaId) showMedia(variant.mediaId);
    };

    groups.forEach((group) => group.addEventListener('change', () => render(true)));
    qty?.addEventListener('input', () => {
        if (qty.value !== '') render(false);
    });
    qty?.addEventListener('blur', () => render(false));
    el<HTMLButtonElement>('[data-qty-minus]')?.addEventListener('click', () => {
        if (qty) qty.value = String((Number(qty.value) || 1) - 1);
        render(false);
    });
    el<HTMLButtonElement>('[data-qty-plus]')?.addEventListener('click', () => {
        if (qty) qty.value = String((Number(qty.value) || 1) + 1);
        render(false);
    });

    render(false);
}

init();

export {};
