// Checkout: Stripe's embedded Payment Element (cards, Apple Pay, Google Pay, 3D Secure).
// Card details go from the browser straight to Stripe. The amount is always set by the server.

interface Config {
    key: string | null;
    ready: boolean;
    total: number;
    buy: boolean;
    quoteUrl: string;
    startUrl: string;
}

/* Minimal shapes of the parts of Stripe.js used here. */
interface StripeElements {
    create(type: 'payment', options: Record<string, unknown>): { mount(target: HTMLElement): void };
    update(options: { amount: number }): void;
    submit(): Promise<{ error?: { message?: string } }>;
}
interface StripeInstance {
    elements(options: Record<string, unknown>): StripeElements;
    confirmPayment(options: Record<string, unknown>): Promise<{ error?: { message?: string } }>;
}
declare global {
    interface Window {
        Stripe?: (key: string) => StripeInstance;
    }
}

const money = (cents: number): string => (cents / 100).toLocaleString('en-US', { style: 'currency', currency: 'USD' });

const csrf = (): string => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

function loadStripe(): Promise<void> {
    return new Promise((resolve, reject) => {
        if (window.Stripe) return resolve();
        const script = document.createElement('script');
        script.src = 'https://js.stripe.com/v3/';
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Stripe could not be loaded'));
        document.head.appendChild(script);
    });
}

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

async function init(): Promise<void> {
    const form = document.querySelector<HTMLFormElement>('[data-checkout]');
    const configNode = document.getElementById('checkout-config');
    if (!form || !configNode) return;
    const config = JSON.parse(configNode.textContent ?? '{}') as Config;

    const field = (name: string) => form.elements.namedItem(name) as HTMLInputElement | HTMLSelectElement | null;
    const payButton = form.querySelector<HTMLButtonElement>('[data-pay]');
    const payLabel = form.querySelector<HTMLElement>('[data-pay-label]');
    const totalEl = document.querySelector<HTMLElement>('[data-total]');
    const taxEl = document.querySelector<HTMLElement>('[data-tax]');
    const mount = form.querySelector<HTMLElement>('[data-payment-element]');
    let total = config.total;
    let busy = false;
    let stripe: StripeInstance | null = null;
    let elements: StripeElements | null = null;

    const setError = (name: string, message: string) => {
        const target = form.querySelector<HTMLElement>(`[data-error="${name}"]`) ?? form.querySelector<HTMLElement>('[data-error="checkout"]');
        if (target) target.textContent = message;
        field(name)?.setAttribute('aria-invalid', message ? 'true' : 'false');
    };
    const clearErrors = () => {
        form.querySelectorAll<HTMLElement>('[data-error]').forEach((node) => (node.textContent = ''));
        form.querySelectorAll('[aria-invalid="true"]').forEach((node) => node.setAttribute('aria-invalid', 'false'));
    };
    const showErrors = (errors: Record<string, string[]>) => {
        let first: HTMLElement | null = null;
        Object.entries(errors).forEach(([name, messages]) => {
            setError(name, messages[0] ?? '');
            first ??= field(name) as HTMLElement | null;
        });
        (first ?? form.querySelector<HTMLElement>('[data-error="checkout"]'))?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        (first as HTMLElement | null)?.focus({ preventScroll: true });
    };
    const setTotal = (cents: number, tax: number | null) => {
        total = cents;
        if (totalEl) totalEl.textContent = money(cents);
        if (payLabel) payLabel.textContent = cents > 0 ? `Pay ${money(cents)}` : 'Place order';
        if (taxEl && tax !== null) taxEl.textContent = money(tax);
        if (cents > 0) elements?.update({ amount: cents });
        if (mount) mount.hidden = cents === 0;
    };
    const setBusy = (on: boolean) => {
        busy = on;
        if (payButton) {
            payButton.disabled = on || !config.ready;
            payButton.classList.toggle('is-busy', on);
            payButton.setAttribute('aria-busy', String(on));
        }
    };

    // Optional account
    const toggle = form.querySelector<HTMLInputElement>('[data-account-toggle]');
    const accountFields = form.querySelector<HTMLElement>('[data-account-fields]');
    toggle?.addEventListener('change', () => {
        if (accountFields) accountFields.hidden = !toggle.checked;
    });

    // Tax depends on where the customer is, so the total is refreshed when that changes.
    let quoteTimer = 0;
    const quote = () => {
        window.clearTimeout(quoteTimer);
        quoteTimer = window.setTimeout(async () => {
            const country = field('country')?.value ?? '';
            if (!country) return;
            const result = await post<{ total?: number; tax?: number | null; errors?: Record<string, string[]> }>(config.quoteUrl, {
                country,
                postcode: field('postcode')?.value ?? '',
                buy: config.buy,
            });
            setError('postcode', '');
            if (result.ok && typeof result.data.total === 'number') {
                setTotal(result.data.total, result.data.tax ?? 0);
            } else if (result.data.errors) {
                Object.entries(result.data.errors).forEach(([name, messages]) => setError(name, messages[0] ?? ''));
            }
        }, 350);
    };
    field('country')?.addEventListener('change', quote);
    field('postcode')?.addEventListener('change', quote);
    if (field('country')?.value) quote();

    if (config.ready && config.key && total > 0 && mount) {
        try {
            await loadStripe();
            stripe = (window.Stripe as (key: string) => StripeInstance)(config.key);
            elements = stripe.elements({
                mode: 'payment',
                amount: total,
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
            // Name, email, country and postcode are asked once, in our own form, and passed on at confirmation.
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
        if (busy) return; // a second click while paying does nothing
        clearErrors();

        const values = {
            email: (field('email')?.value ?? '').trim(),
            name: (field('name')?.value ?? '').trim(),
            country: field('country')?.value ?? '',
            postcode: (field('postcode')?.value ?? '').trim(),
            create_account: toggle?.checked ?? false,
            password: field('password')?.value ?? '',
            buy: config.buy,
        };

        const problems: Record<string, string[]> = {};
        if (!/^\S+@\S+\.\S+$/.test(values.email)) problems.email = ['Enter a valid email so we can send your download links.'];
        if (!values.name) problems.name = ['Enter your name.'];
        if (!values.country) problems.country = ['Choose your country.'];
        if (['US', 'CA', 'GB'].includes(values.country) && !values.postcode) problems.postcode = ['Enter your ZIP or postal code.'];
        if (values.create_account && values.password.length < 8) problems.password = ['Use at least 8 characters for your password.'];
        if (Object.keys(problems).length > 0) return showErrors(problems);

        setBusy(true);
        try {
            if (total > 0) {
                if (!stripe || !elements) throw new Error('The payment form is not ready. Reload the page and try again.');
                const submitted = await elements.submit();
                if (submitted.error) throw new Error(submitted.error.message ?? 'Check your payment details.');
            }

            const started = await post<{ client_secret?: string | null; paid?: boolean; total?: number; tax?: number; order_url?: string; errors?: Record<string, string[]>; message?: string }>(
                config.startUrl,
                values,
            );
            if (!started.ok) {
                if (started.status === 429) throw new Error('Too many attempts. Wait a minute and try again.');
                if (started.status === 419) throw new Error('This page expired. Reload it and try again.');
                if (started.data.errors) {
                    showErrors(started.data.errors);
                    return;
                }
                throw new Error(started.data.message ?? 'Something went wrong. Please try again.');
            }

            const orderUrl = started.data.order_url as string;
            if (started.data.paid || !started.data.client_secret) {
                window.location.assign(orderUrl);
                return;
            }
            if (typeof started.data.total === 'number' && started.data.total !== total) {
                setTotal(started.data.total, started.data.tax ?? 0);
            }

            const confirmed = await (stripe as StripeInstance).confirmPayment({
                elements,
                clientSecret: started.data.client_secret,
                confirmParams: {
                    return_url: orderUrl,
                    payment_method_data: {
                        billing_details: {
                            name: values.name,
                            email: values.email,
                            address: { country: values.country, postal_code: values.postcode },
                        },
                    },
                },
            });
            // Reaching this line means the customer was not redirected: the payment was declined or needs fixing.
            if (confirmed.error) throw new Error(confirmed.error.message ?? 'The payment did not go through. Try another card.');
            window.location.assign(orderUrl);
        } catch (error) {
            setError('checkout', error instanceof Error ? error.message : 'Something went wrong. Please try again.');
            form.querySelector<HTMLElement>('[data-error="checkout"]')?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        } finally {
            setBusy(false);
        }
    });
}

void init();

export {};
