import { extraProblems, extras } from './checkout-extras';
// One-page selling page: Order-now links, the mobile order bar, countdown, click-to-play video
// and the inline checkout. Card details go from the browser straight to Stripe.

interface Config {
    key: string | null;
    ready: boolean;
    preview: boolean;
    quoteUrl: string;
    startUrl: string;
    couponUrl: string;
    confirmUrl: string | null;
    payLabel: string;
}

interface Totals {
    label?: string;
    subtotal?: number;
    discount?: number;
    tax?: number | null;
    total?: number;
    coupon?: string | null;
    couponProblem?: string | null;
    soldOut?: boolean;
    errors?: Record<string, string[]>;
}

interface StripeElements {
    create(type: 'payment', options: Record<string, unknown>): { mount(target: HTMLElement): void };
    update(options: { amount: number }): void;
    submit(): Promise<{ error?: { message?: string } }>;
}
interface StripeInstance {
    elements(options: Record<string, unknown>): StripeElements;
    confirmPayment(options: Record<string, unknown>): Promise<{ error?: { message?: string } }>;
}
type StripeFactory = (key: string) => StripeInstance;

const money = (cents: number): string => (cents / 100).toLocaleString('en-US', { style: 'currency', currency: 'USD' });
const csrf = (): string => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
const reducedMotion = (): boolean => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

async function post<T>(url: string, body: Record<string, unknown>): Promise<{ ok: boolean; status: number; data: T }> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify(body),
    });
    let data = {} as T;
    try {
        data = (await response.json()) as T;
    } catch {
        // an empty or non-JSON body is handled by the caller through `ok`
    }
    return { ok: response.ok, status: response.status, data };
}

function loadStripe(): Promise<void> {
    return new Promise((resolve, reject) => {
        if ((window as unknown as { Stripe?: StripeFactory }).Stripe) return resolve();
        const script = document.createElement('script');
        script.src = 'https://js.stripe.com/v3/';
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Stripe could not be loaded'));
        document.head.appendChild(script);
    });
}

/** Every "Order now" button scrolls to the order block. */
function orderLinks(): void {
    const target = document.getElementById('order');
    if (!target) return;
    document.querySelectorAll<HTMLAnchorElement>('[data-order-link]').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            target.scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' });
            target.querySelector<HTMLElement>('input:checked, input, select')?.focus({ preventScroll: true });
        });
    });
}

/** Mobile bar: shown once the hero has scrolled away, hidden while the order block is on screen. */
function orderBar(): void {
    const bar = document.querySelector<HTMLElement>('[data-orderbar]');
    const order = document.getElementById('order');
    if (!bar || !order || !('IntersectionObserver' in window)) return;

    const hero = document.querySelector<HTMLElement>('[data-hero]');
    let heroVisible = hero !== null;
    let orderVisible = false;
    bar.hidden = false;

    const update = () => {
        const show = !heroVisible && !orderVisible;
        bar.classList.toggle('is-visible', show);
        bar.setAttribute('aria-hidden', String(!show));
        bar.querySelectorAll<HTMLElement>('a').forEach((link) => (link.tabIndex = show ? 0 : -1));
    };
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.target === hero) heroVisible = entry.isIntersecting;
            if (entry.target === order) orderVisible = entry.isIntersecting;
        });
        update();
    });
    if (hero) observer.observe(hero);
    observer.observe(order);
    update();
}

/** Counts down to a real end date set in admin. At zero the bar disappears for good. */
function countdown(): void {
    document.querySelectorAll<HTMLElement>('[data-countdown]').forEach((node) => {
        const end = Date.parse(node.dataset.countdown ?? '');
        if (Number.isNaN(end)) return;
        const pad = (n: number) => String(n).padStart(2, '0');
        const tick = () => {
            const left = Math.floor((end - Date.now()) / 1000);
            if (left <= 0) {
                node.closest<HTMLElement>('[data-offer-bar]')?.remove();
                return;
            }
            const days = Math.floor(left / 86400);
            const text = `${pad(Math.floor((left % 86400) / 3600))}:${pad(Math.floor((left % 3600) / 60))}:${pad(left % 60)}`;
            node.textContent = `Ends in ${days > 0 ? `${days}d ` : ''}${text}`;
            window.setTimeout(tick, 1000);
        };
        tick();
    });
}

/** Videos load nothing from the video host until the customer presses play. */
function videos(): void {
    document.querySelectorAll<HTMLElement>('[data-video]').forEach((box) => {
        box.querySelector('[data-video-play]')?.addEventListener('click', () => {
            const src = box.dataset.src ?? '';
            let player: HTMLIFrameElement | HTMLVideoElement;
            if (box.dataset.kind === 'iframe') {
                player = document.createElement('iframe');
                player.allow = 'autoplay; fullscreen; picture-in-picture';
                player.allowFullscreen = true;
                player.title = box.dataset.title ?? 'Video';
            } else {
                player = document.createElement('video');
                player.controls = true;
                player.autoplay = true;
                player.playsInline = true;
            }
            player.src = src;
            box.replaceChildren(player);
            player.focus();
        });
    });
}

async function checkout(): Promise<void> {
    const root = document.querySelector<HTMLElement>('[data-order-root]');
    const configNode = document.getElementById('selling-config');
    if (!root || !configNode) return;
    const config = JSON.parse(configNode.textContent ?? '{}') as Config;

    /** Wait for Stripe's confirmation to reach the server, then swap the order block for the confirmation. */
    const awaitConfirmation = (url: string, onFailed: (message: string) => void) => {
        const started = Date.now();
        const check = async () => {
            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                const data = (await response.json()) as { state: string; html?: string; message?: string };
                if (data.state === 'paid' && data.html) {
                    root.innerHTML = data.html;
                    document.querySelector<HTMLElement>('[data-orderbar]')?.remove();
                    const box = root.querySelector<HTMLElement>('[data-order-confirmation]');
                    box?.scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'center' });
                    box?.focus({ preventScroll: true });
                    return;
                }
                if (data.state === 'failed') {
                    onFailed(data.message ?? 'The payment did not go through. Nothing was charged.');
                    return;
                }
            } catch {
                // A dropped request is not an answer; keep waiting.
            }
            const waited = Date.now() - started;
            if (waited > 120_000) {
                onFailed('Your payment is taking longer than usual to confirm. You can close this page: we will email your download links as soon as it is confirmed.');
                return;
            }
            window.setTimeout(check, waited < 20_000 ? 1500 : 4000);
        };
        void check();
    };

    const form = root.querySelector<HTMLFormElement>('[data-order-form]');

    if (config.confirmUrl) {
        // Back from a bank's own payment page.
        const errorNode = form?.querySelector<HTMLElement>('[data-error="checkout"]');
        const pay = form?.querySelector<HTMLButtonElement>('[data-pay]');
        if (pay) {
            pay.disabled = true;
            pay.classList.add('is-busy');
        }
        document.getElementById('order')?.scrollIntoView({ block: 'start' });
        awaitConfirmation(config.confirmUrl, (message) => {
            if (errorNode) errorNode.textContent = message;
            if (pay) {
                pay.disabled = !config.ready;
                pay.classList.remove('is-busy');
            }
        });
    }
    if (!form) return;

    const field = (name: string) => form.elements.namedItem(name) as HTMLInputElement | HTMLSelectElement | null;
    const one = <T extends HTMLElement>(selector: string) => root.querySelector<T>(selector);
    const payButton = one<HTMLButtonElement>('[data-pay]');
    const payLabel = one('[data-pay-label]');
    const mount = one('[data-payment-element]');
    const barPrice = document.querySelector<HTMLElement>('[data-orderbar-price]');
    let total = 0;
    let busy = false;
    let soldOut = false;
    let stripe: StripeInstance | null = null;
    let elements: StripeElements | null = null;

    const chosen = () => form.querySelector<HTMLInputElement>('input[name="offer_id"]:checked');
    const setError = (name: string, message: string) => {
        const target = one(`[data-error="${name}"]`) ?? one('[data-error="checkout"]');
        if (target) target.textContent = message;
        field(name)?.setAttribute('aria-invalid', message ? 'true' : 'false');
    };
    const clearErrors = () => {
        root.querySelectorAll<HTMLElement>('[data-error]').forEach((node) => (node.textContent = ''));
        root.querySelectorAll('[aria-invalid="true"]').forEach((node) => node.setAttribute('aria-invalid', 'false'));
    };
    const refreshPay = () => {
        if (payButton) payButton.disabled = busy || !config.ready || config.preview || soldOut || !chosen();
    };
    const setBusy = (on: boolean) => {
        busy = on;
        payButton?.classList.toggle('is-busy', on);
        payButton?.setAttribute('aria-busy', String(on));
        refreshPay();
    };

    const show = (data: Totals) => {
        if (typeof data.total !== 'number') return;
        total = data.total;
        soldOut = data.soldOut === true;
        const text = (selector: string, value: string) => {
            const node = one(selector);
            if (node) node.textContent = value;
        };
        text('[data-sum-label]', data.label ?? '');
        text('[data-sum-subtotal]', money(data.subtotal ?? 0));
        text('[data-sum-discount]', `-${money(data.discount ?? 0)}`);
        text('[data-sum-code]', data.coupon ? `(${data.coupon})` : '');
        text('[data-sum-total]', money(total));
        const discountRow = one('[data-sum-discount-row]');
        if (discountRow) discountRow.hidden = !data.discount;
        const taxRow = one('[data-sum-tax-row]');
        if (taxRow) taxRow.hidden = data.tax === null || data.tax === undefined;
        if (typeof data.tax === 'number') text('[data-sum-tax]', money(data.tax));

        const applied = one('[data-coupon-applied]');
        const entry = one('[data-coupon-entry]');
        if (applied && entry) {
            applied.hidden = !data.coupon;
            entry.hidden = Boolean(data.coupon);
            text('[data-coupon-code]', data.coupon ?? '');
        }
        if (data.couponProblem) setError('coupon', data.couponProblem);

        if (payLabel) payLabel.textContent = total > 0 ? `${config.payLabel} ${money(total)}` : 'Place order';
        if (barPrice) barPrice.textContent = money(total);
        if (mount) mount.hidden = total === 0;
        if (total > 0) elements?.update({ amount: total });
        refreshPay();
    };

    // The summary follows the pack, the country (for tax) and the coupon.
    let quoteTimer = 0;
    let quoteRun = 0;
    const context = () => ({ offer_id: Number(chosen()?.value ?? 0), country: field('country')?.value || null, postcode: field('postcode')?.value || null });
    const quote = (delay = 0) => {
        window.clearTimeout(quoteTimer);
        quoteTimer = window.setTimeout(async () => {
            if (!chosen()) return;
            const run = ++quoteRun;
            const result = await post<Totals>(config.quoteUrl, context());
            if (run !== quoteRun) return; // a newer choice was made while this one was on its way
            setError('postcode', '');
            if (result.ok) show(result.data);
            else if (result.data.errors) Object.entries(result.data.errors).forEach(([name, messages]) => setError(name, messages[0] ?? ''));
        }, delay);
    };

    form.querySelectorAll<HTMLInputElement>('input[name="offer_id"]').forEach((radio) => {
        radio.addEventListener('change', () => {
            // Show the new price at once; the server's figures (discount, tax) follow.
            const price = Number(radio.dataset.price ?? 0);
            const node = one('[data-sum-label]');
            if (node) node.textContent = radio.dataset.label ?? '';
            if (payLabel && !one('[data-coupon-applied]:not([hidden])')) payLabel.textContent = `${config.payLabel} ${money(price)}`;
            quote();
        });
    });
    field('country')?.addEventListener('change', () => quote());
    field('postcode')?.addEventListener('change', () => quote());

    // Coupon
    const couponInput = one<HTMLInputElement>('[data-coupon-input]');
    const sendCoupon = async (code: string) => {
        setError('coupon', '');
        const result = await post<Totals>(config.couponUrl, { ...context(), code });
        if (result.ok) {
            if (couponInput) couponInput.value = '';
            show(result.data);
        } else {
            setError('coupon', result.status === 429 ? 'Too many tries. Wait a minute and try again.' : (result.data.errors?.coupon?.[0] ?? 'That code could not be applied.'));
        }
    };
    one('[data-coupon-apply]')?.addEventListener('click', () => {
        const code = couponInput?.value.trim() ?? '';
        if (code === '') setError('coupon', 'Enter a code first.');
        else void sendCoupon(code);
    });
    couponInput?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            one<HTMLButtonElement>('[data-coupon-apply]')?.click();
        }
    });
    one('[data-coupon-remove]')?.addEventListener('click', () => void sendCoupon(''));

    // Field errors appear when the customer leaves a field, not only on submit.
    const problemWith = (name: string): string => {
        const value = (field(name)?.value ?? '').trim();
        switch (name) {
            case 'email':
                return /^\S+@\S+\.\S+$/.test(value) ? '' : 'Enter a valid email so we can send your download links.';
            case 'name':
                return value ? '' : 'Enter your name.';
            case 'country':
                return value ? '' : 'Choose your country.';
            case 'postcode':
                return ['US', 'CA', 'GB'].includes(field('country')?.value ?? '') && !value ? 'Enter your ZIP or postal code.' : '';
            default:
                return '';
        }
    };
    const FIELDS = ['email', 'name', 'country', 'postcode'];
    FIELDS.forEach((name) => {
        field(name)?.addEventListener('blur', () => setError(name, problemWith(name)));
        field(name)?.addEventListener('input', () => {
            if (field(name)?.getAttribute('aria-invalid') === 'true' && !problemWith(name)) setError(name, '');
        });
    });

    total = Number(chosen()?.dataset.price ?? 0);
    quote();

    if (config.ready && config.key && !config.preview && mount && chosen()) {
        try {
            await loadStripe();
            stripe = (window as unknown as { Stripe: StripeFactory }).Stripe(config.key);
            elements = stripe.elements({
                mode: 'payment',
                amount: Math.max(total, 50),
                currency: 'usd',
                appearance: {
                    theme: 'stripe',
                    variables: {
                        colorPrimary: '#1D4ED8',
                        colorText: '#111317',
                        colorTextSecondary: '#5B616E',
                        colorDanger: '#B42318',
                        borderRadius: '10px',
                        fontFamily: 'Inter, system-ui, sans-serif',
                        fontSizeBase: '15px',
                    },
                },
            });
            // Wallet buttons show first where the device supports them, then the card fields.
            elements
                .create('payment', {
                    layout: 'tabs',
                    fields: { billingDetails: { name: 'never', email: 'never', address: { country: 'never', postalCode: 'never' } } },
                })
                .mount(mount);
        } catch {
            setError('checkout', 'The payment form could not be loaded. Check your connection and reload the page.');
        }
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy) return; // a second press while paying does nothing
        clearErrors();

        let first: HTMLElement | null = null;
        FIELDS.forEach((name) => {
            const problem = problemWith(name);
            if (problem) {
                setError(name, problem);
                first ??= field(name);
            }
        });
        if (!chosen()) setError('checkout', 'Choose a pack to continue.');
        if (first || !chosen()) {
            (first as HTMLElement | null)?.scrollIntoView({ block: 'center', behavior: reducedMotion() ? 'auto' : 'smooth' });
            (first as HTMLElement | null)?.focus({ preventScroll: true });
            return;
        }

        const values = {
            ...context(),
            email: (field('email')?.value ?? '').trim(),
            name: (field('name')?.value ?? '').trim(),
            country: field('country')?.value ?? '',
            postcode: (field('postcode')?.value ?? '').trim(),
            ...extras(form),
        };
        const extraErrors = extraProblems(form);
        if (Object.keys(extraErrors).length > 0) {
            Object.entries(extraErrors).forEach(([name, messages]) => setError(name, messages[0] ?? ''));
            return;
        }

        let waiting = false;
        setBusy(true);
        try {
            if (total > 0) {
                if (!stripe || !elements) throw new Error('The payment form is not ready. Reload the page and try again.');
                const submitted = await elements.submit();
                if (submitted.error) throw new Error(submitted.error.message ?? 'Check your payment details.');
            }

            const started = await post<{ client_secret?: string | null; paid?: boolean; total?: number; tax?: number; confirm_url?: string; return_url?: string; errors?: Record<string, string[]>; message?: string }>(
                config.startUrl,
                values,
            );
            if (!started.ok) {
                if (started.status === 429) throw new Error('Too many attempts. Wait a minute and try again.');
                if (started.status === 419) throw new Error('This page expired. Reload it and try again.');
                if (started.data.errors) {
                    Object.entries(started.data.errors).forEach(([name, messages]) => setError(name, messages[0] ?? ''));
                    if (started.data.errors.stock) quote();
                    return;
                }
                throw new Error(started.data.message ?? 'Something went wrong. Please try again.');
            }

            if (!started.data.paid && started.data.client_secret) {
                if (typeof started.data.total === 'number' && started.data.total !== total) {
                    total = started.data.total;
                    elements?.update({ amount: total });
                    if (payLabel) payLabel.textContent = `${config.payLabel} ${money(total)}`;
                    const totalNode = one('[data-sum-total]');
                    if (totalNode) totalNode.textContent = money(total);
                }
                // Stay on the page unless the customer's bank insists on its own page.
                const confirmed = await (stripe as StripeInstance).confirmPayment({
                    elements,
                    clientSecret: started.data.client_secret,
                    redirect: 'if_required',
                    confirmParams: {
                        return_url: started.data.return_url,
                        payment_method_data: {
                            billing_details: { name: values.name, email: values.email, address: { country: values.country, postal_code: values.postcode } },
                        },
                    },
                });
                if (confirmed.error) throw new Error(confirmed.error.message ?? 'The payment did not go through. Try another card.');
            }

            // Paid at Stripe. The order turns Paid when Stripe's signed confirmation reaches the server.
            waiting = true;
            awaitConfirmation(started.data.confirm_url as string, (message) => {
                setError('checkout', message);
                setBusy(false);
            });
        } catch (error) {
            // Everything the customer typed stays in place.
            setError('checkout', error instanceof Error ? error.message : 'Something went wrong. Please try again.');
            one('[data-error="checkout"]')?.scrollIntoView({ block: 'center', behavior: reducedMotion() ? 'auto' : 'smooth' });
        } finally {
            if (!waiting) setBusy(false);
        }
    });
}

orderLinks();
orderBar();
countdown();
videos();
void checkout();

export {};
