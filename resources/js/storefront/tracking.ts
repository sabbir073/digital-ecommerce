// The storefront's event layer (PRD section 14). Pages and scripts report what happened in one neutral
// shape; each adapter below turns that into its service's own event. A tag is only loaded once the
// visitor's cookie choice allows its category, and only after the page is usable.
// The rules each adapter follows are written down in docs/integrations.md.

interface Item {
    id: string;
    name: string;
    variant?: string | null;
    category?: string | null;
    brand?: string | null;
    price: number;
    quantity: number;
}
interface EventData {
    currency?: string;
    value?: number;
    tax?: number;
    shipping?: number;
    coupon?: string | null;
    order?: string;
    term?: string;
    list?: string;
    rating?: number;
    method?: string;
    email?: string;
    items?: Item[];
}
interface StoreEvent {
    name: string;
    id: string;
    data: EventData;
}
interface Service {
    category: 'analytics' | 'marketing';
    ids: string[];
    [key: string]: unknown;
}
interface Config {
    services: Record<string, Service>;
    consent: { required: boolean | null; cookie: string; url: string };
    relay: string | null;
    events: StoreEvent[];
    currency: string;
}
interface Adapter {
    load(): void;
    send(event: StoreEvent): void;
    consent?(state: State): void;
}
type State = { analytics: boolean; marketing: boolean };
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Queue = any;

const w = window as unknown as Record<string, any>;
const node = document.getElementById('tracking-config');
const config = node ? (JSON.parse(node.textContent ?? '{}') as Config) : null;

const script = (src: string): void => {
    const el = document.createElement('script');
    el.async = true;
    el.src = src;
    document.head.appendChild(el);
};
const uuid = (): string => (crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`);
const items = (event: StoreEvent): Item[] => event.data.items ?? [];
const count = (event: StoreEvent): number => items(event).reduce((sum, item) => sum + item.quantity, 0);
const clean = <T extends Record<string, unknown>>(object: T): T => {
    Object.keys(object).forEach((key) => (object[key] === undefined || object[key] === null) && delete object[key]);
    return object;
};

// ---- Google: one data layer for the Google tag (Analytics, Ads) and Tag Manager ----

const gtag: Queue = function () {
    // The Google tag reads the arguments object itself, not an array.
    // eslint-disable-next-line prefer-rest-params
    (w.dataLayer = w.dataLayer || []).push(arguments);
};
const googleConsent = (state: State) => ({
    ad_storage: state.marketing ? 'granted' : 'denied',
    ad_user_data: state.marketing ? 'granted' : 'denied',
    ad_personalization: state.marketing ? 'granted' : 'denied',
    analytics_storage: state.analytics ? 'granted' : 'denied',
});
let googleDefaultSet = false;
let googleTagLoaded = false;
const googleDefault = (state: State): void => {
    if (googleDefaultSet) return;
    googleDefaultSet = true;
    // Must come before any tag: the default first, the tag after it.
    gtag('consent', 'default', googleConsent(state));
};
const googleTag = (id: string, state: State): void => {
    googleDefault(state);
    if (!googleTagLoaded) {
        googleTagLoaded = true;
        script(`https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(id)}`);
        gtag('js', new Date());
    }
    gtag('config', id);
};
const googleItems = (event: StoreEvent) =>
    items(event).map((item, index) => clean({ item_id: item.id, item_name: item.name, item_variant: item.variant, item_category: item.category, item_brand: item.brand, price: item.price, quantity: item.quantity, index }));
const googleParams = (event: StoreEvent): Record<string, unknown> => {
    const d = event.data;
    switch (event.name) {
        case 'view_item_list':
            return { item_list_name: d.list, items: googleItems(event) };
        case 'search':
            return { search_term: d.term };
        case 'purchase':
            return clean({ transaction_id: d.order, value: d.value, tax: d.tax, shipping: d.shipping, currency: d.currency, coupon: d.coupon, items: googleItems(event) });
        case 'sign_up':
            return clean({ method: d.method });
        case 'submit_review':
            return clean({ rating: d.rating, items: googleItems(event) });
        default:
            return clean({ currency: d.currency, value: d.value, coupon: d.coupon, items: googleItems(event) });
    }
};

const adapters: Record<string, (service: Service, state: () => State) => Adapter> = {
    ga4: (service, state) => ({
        load: () => service.ids.forEach((id) => googleTag(id, state())),
        send: (event) => {
            // The config call already counts the page view.
            if (event.name !== 'page_view') gtag('event', event.name, { ...googleParams(event), send_to: service.ids });
        },
        consent: (now) => gtag('consent', 'update', googleConsent(now)),
    }),

    google_ads: (service, state) => {
        const id = service.conversion_id as string;
        const labels: Record<string, string> = { purchase: service.purchase_label as string, add_to_cart: service.add_to_cart_label as string, begin_checkout: service.begin_checkout_label as string, sign_up: service.sign_up_label as string };
        return {
            load: () => googleTag(id, state()),
            send: (event) => {
                if (event.name === 'page_view') return;
                // The ecommerce event feeds remarketing audiences.
                gtag('event', event.name, { ...googleParams(event), send_to: id });
                const label = labels[event.name];
                if (!label) return;
                if (event.name === 'purchase' && service.enhanced_conversions && event.data.email) gtag('set', 'user_data', { email: event.data.email });
                gtag('event', 'conversion', clean({ send_to: `${id}/${label}`, value: event.data.value, currency: event.data.currency, transaction_id: event.data.order }));
            },
            consent: (now) => gtag('consent', 'update', googleConsent(now)),
        };
    },

    gtm: (service, state) => ({
        load: () => {
            googleDefault(state());
            (w.dataLayer = w.dataLayer || []).push({ 'gtm.start': Date.now(), event: 'gtm.js' });
            script(`https://www.googletagmanager.com/gtm.js?id=${encodeURIComponent(service.container_id as string)}`);
        },
        send: (event) => {
            if (event.name === 'page_view') {
                w.dataLayer.push({ event: 'page_view', event_id: event.id, page_location: location.href, page_title: document.title });
                return;
            }
            w.dataLayer.push({ ecommerce: null });
            w.dataLayer.push({ event: event.name, event_id: event.id, ecommerce: googleParams(event) });
        },
        consent: (now) => gtag('consent', 'update', googleConsent(now)),
    }),

    // ---- Meta Pixel. The server sends the same events with the same ID (Conversions API). ----
    meta_pixel: (service) => {
        const names: Record<string, string> = { page_view: 'PageView', view_item: 'ViewContent', search: 'Search', add_to_cart: 'AddToCart', begin_checkout: 'InitiateCheckout', add_payment_info: 'AddPaymentInfo', purchase: 'Purchase', sign_up: 'CompleteRegistration' };
        const custom: Record<string, string> = { view_item_list: 'ViewCategory', submit_review: 'SubmitReview' };
        return {
            load: () => {
                if (!w.fbq) {
                    const fbq: Queue = function (...args: unknown[]) {
                        if (fbq.callMethod) (fbq.callMethod as (...a: unknown[]) => void)(...args);
                        else (fbq.queue as unknown[]).push(args);
                    };
                    fbq.push = fbq;
                    fbq.loaded = true;
                    fbq.version = '2.0';
                    fbq.queue = [];
                    w.fbq = fbq;
                    w._fbq = w._fbq || fbq;
                    script('https://connect.facebook.net/en_US/fbevents.js');
                }
                w.fbq('consent', 'grant');
                service.ids.forEach((id) => w.fbq('init', id));
            },
            send: (event) => {
                const d = event.data;
                const params = clean({
                    value: d.value,
                    currency: d.currency,
                    content_type: items(event).length ? 'product' : undefined,
                    content_ids: items(event).length ? items(event).map((item) => item.id) : undefined,
                    contents: items(event).length ? items(event).map((item) => ({ id: item.id, quantity: item.quantity, item_price: item.price })) : undefined,
                    order_id: d.order,
                    num_items: event.name === 'begin_checkout' ? count(event) : undefined,
                    search_string: d.term,
                });
                if (names[event.name]) w.fbq('track', names[event.name], params, { eventID: event.id });
                else if (custom[event.name]) w.fbq('trackCustom', custom[event.name], params, { eventID: event.id });
            },
            consent: (now) => w.fbq?.('consent', now.marketing ? 'grant' : 'revoke'),
        };
    },

    // ---- TikTok Pixel ----
    tiktok: (service) => {
        const names: Record<string, string> = { view_item_list: 'ViewItemList', view_item: 'ViewContent', search: 'Search', add_to_cart: 'AddToCart', begin_checkout: 'InitiateCheckout', add_payment_info: 'AddPaymentInfo', purchase: 'Purchase', sign_up: 'CompleteRegistration', submit_review: 'SubmitReview' };
        return {
            load: () => {
                if (!w.ttq) {
                    const ttq: any[] & Record<string, any> = (w.ttq = []);
                    w.TiktokAnalyticsObject = 'ttq';
                    ttq.methods = ['page', 'track', 'identify', 'instances', 'debug', 'on', 'off', 'once', 'ready', 'alias', 'group', 'enableCookie', 'disableCookie', 'holdConsent', 'revokeConsent', 'grantConsent'];
                    ttq.setAndDefer = (target: Record<string, any>, method: string) => {
                        target[method] = (...args: unknown[]) => target.push([method, ...args]);
                    };
                    ttq.methods.forEach((method: string) => ttq.setAndDefer(ttq, method));
                    ttq.load = (id: string) => {
                        const url = 'https://analytics.tiktok.com/i18n/pixel/events.js';
                        ttq._i = ttq._i || {};
                        ttq._i[id] = [];
                        ttq._i[id]._u = url;
                        ttq._t = ttq._t || {};
                        ttq._t[id] = Date.now();
                        ttq._o = ttq._o || {};
                        ttq._o[id] = {};
                        script(`${url}?sdkid=${encodeURIComponent(id)}&lib=ttq`);
                    };
                }
                service.ids.forEach((id) => w.ttq.load(id));
            },
            send: (event) => {
                if (event.name === 'page_view') return w.ttq.page();
                const d = event.data;
                w.ttq.track(names[event.name] ?? event.name, clean({
                    value: d.value,
                    currency: d.currency,
                    content_type: items(event).length ? 'product' : undefined,
                    contents: items(event).length ? items(event).map((item) => ({ content_id: item.id, content_type: 'product', content_name: item.name, quantity: item.quantity, price: item.price })) : undefined,
                    search_string: d.term,
                }), { event_id: event.id });
            },
            consent: (now) => (now.marketing ? w.ttq?.grantConsent?.() : w.ttq?.revokeConsent?.()),
        };
    },

    // ---- Pinterest tag ----
    pinterest: (service) => {
        const names: Record<string, string> = { page_view: 'pagevisit', view_item_list: 'viewcategory', search: 'search', add_to_cart: 'addtocart', purchase: 'checkout', sign_up: 'signup' };
        return {
            load: () => {
                if (!w.pintrk) {
                    const pintrk: Queue = function (...args: unknown[]) {
                        (pintrk.queue as unknown[]).push(args);
                    };
                    pintrk.queue = [];
                    pintrk.version = '3.0';
                    w.pintrk = pintrk;
                    script('https://s.pinimg.com/ct/core.js');
                }
                service.ids.forEach((id) => w.pintrk('load', id));
                w.pintrk('page');
            },
            send: (event) => {
                const name = names[event.name];
                if (!name) return;
                const d = event.data;
                w.pintrk('track', name, clean({
                    event_id: event.id,
                    value: d.value,
                    currency: d.currency,
                    order_id: d.order,
                    order_quantity: items(event).length ? count(event) : undefined,
                    search_query: d.term,
                    line_items: items(event).length ? items(event).map((item) => clean({ product_id: item.id, product_name: item.name, product_price: item.price, product_quantity: item.quantity, product_category: item.category, product_brand: item.brand })) : undefined,
                }));
            },
        };
    },

    // ---- Snapchat Pixel ----
    snapchat: (service) => {
        const names: Record<string, string> = { page_view: 'PAGE_VIEW', view_item: 'VIEW_CONTENT', search: 'SEARCH', add_to_cart: 'ADD_CART', begin_checkout: 'START_CHECKOUT', add_payment_info: 'ADD_BILLING', purchase: 'PURCHASE', sign_up: 'SIGN_UP' };
        return {
            load: () => {
                if (!w.snaptr) {
                    const snaptr: Queue = function (...args: unknown[]) {
                        if (snaptr.handleRequest) (snaptr.handleRequest as (...a: unknown[]) => void)(...args);
                        else (snaptr.queue as unknown[]).push(args);
                    };
                    snaptr.queue = [];
                    w.snaptr = snaptr;
                    script('https://sc-static.net/scevent.min.js');
                }
                service.ids.forEach((id) => w.snaptr('init', id, {}));
            },
            send: (event) => {
                const name = names[event.name];
                if (!name) return;
                const d = event.data;
                w.snaptr('track', name, clean({
                    client_dedup_id: event.id,
                    price: d.value,
                    currency: d.currency,
                    transaction_id: d.order,
                    item_ids: items(event).length ? items(event).map((item) => item.id) : undefined,
                    number_items: items(event).length ? count(event) : undefined,
                    search_string: d.term,
                }));
            },
        };
    },

    // ---- Microsoft Advertising (UET) ----
    microsoft_ads: (service) => ({
        load: () => {
            w.uetq = w.uetq || [];
            w.uetq.push('consent', 'default', { ad_storage: 'granted' });
            const el = document.createElement('script');
            el.async = true;
            el.src = 'https://bat.bing.com/bat.js';
            el.onload = () => {
                const options = { ti: service.tag_id, enableAutoSpaTracking: true, q: w.uetq };
                w.uetq = new w.UET(options);
                w.uetq.push('pageLoad');
            };
            document.head.appendChild(el);
        },
        send: (event) => {
            if (!['add_to_cart', 'begin_checkout', 'purchase', 'search', 'sign_up'].includes(event.name)) return;
            const d = event.data;
            w.uetq.push('event', event.name, clean({
                revenue_value: d.value,
                currency: d.currency ? d.currency.toUpperCase() : undefined,
                transaction_id: d.order,
                search_term: d.term,
                ecomm_prodid: items(event).length ? items(event).map((item) => item.id) : undefined,
            }));
        },
        consent: (now) => w.uetq?.push('consent', 'update', { ad_storage: now.marketing ? 'granted' : 'denied' }),
    }),
};

// ---- Consent ----

const europe = (): boolean => {
    try {
        const zone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
        return zone.startsWith('Europe/') || ['Atlantic/Reykjavik', 'Atlantic/Canary', 'Atlantic/Madeira', 'Atlantic/Faroe', 'Africa/Ceuta', 'Arctic/Longyearbyen'].includes(zone);
    } catch {
        return true; // when in doubt, ask
    }
};

function start(settings: Config): void {
    const banner = document.getElementById('cookie-banner');
    const cookieName = settings.consent.cookie;
    const stored = new RegExp(`(?:^|; )${cookieName}=a([01])m([01])\\.([a-z0-9]{8,40})`).exec(document.cookie);
    const visitor = stored?.[3] ?? uuid().replace(/[^a-z0-9]/g, '').slice(0, 24).padEnd(8, '0');
    const mustAsk = settings.consent.required ?? europe();
    let state: State | null = stored ? { analytics: stored[1] === '1', marketing: stored[2] === '1' } : mustAsk ? null : { analytics: true, marketing: true };

    const remember = (now: State): void => {
        const secure = location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = `${cookieName}=a${now.analytics ? 1 : 0}m${now.marketing ? 1 : 0}.${visitor}; Max-Age=${180 * 86400}; Path=/; SameSite=Lax${secure}`;
    };
    // The server reads this cookie before it sends anything on the visitor's behalf.
    if (state && !stored) remember(state);

    const active: Record<string, Adapter> = {};
    const history: StoreEvent[] = [];
    let ready = false;
    const current = (): State => state ?? { analytics: false, marketing: false };

    const activate = (): void => {
        if (!ready) return;
        Object.entries(settings.services).forEach(([name, service]) => {
            if (active[name] || !adapters[name] || !current()[service.category]) return;
            const adapter = adapters[name](service, current);
            try {
                adapter.load();
                history.forEach((event) => adapter.send(event));
                active[name] = adapter;
            } catch {
                // One broken tag must not stop the others, or the page.
            }
        });
    };

    const relay = (event: StoreEvent, sent: string[]): void => {
        if (!settings.relay || sent.length === 0 || event.name === 'purchase') return;
        const token = /(?:^|; )XSRF-TOKEN=([^;]+)/.exec(document.cookie);
        const data = { ...event.data, email: undefined, items: items(event).map((item) => ({ id: item.id, name: item.name, price: item.price, quantity: item.quantity })) };
        void fetch(settings.relay, {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token ? decodeURIComponent(token[1] ?? '') : '' },
            body: JSON.stringify({ name: event.name, id: event.id, url: location.href, sent, data }),
        }).catch(() => undefined);
    };

    let lastCheckout: EventData | null = null;
    const emit = (name: string, data?: EventData, id?: string): void => {
        if (name === 'begin_checkout' && data) lastCheckout = data;
        // "Payment details completed" carries what the checkout it belongs to carried.
        const event: StoreEvent = { name, id: id ?? uuid(), data: data ?? (name === 'add_payment_info' ? lastCheckout : null) ?? {} };
        history.push(event);
        Object.values(active).forEach((adapter) => {
            try {
                adapter.send(event);
            } catch {
                // Keep going with the other services.
            }
        });
        if (ready) relay(event, Object.keys(active));
    };

    const choose = (now: State): void => {
        const changed = !state || state.analytics !== now.analytics || state.marketing !== now.marketing;
        state = now;
        remember(now);
        if (banner) banner.hidden = true;
        if (!changed) return;
        Object.values(active).forEach((adapter) => adapter.consent?.(now));
        const already = Object.keys(active);
        activate();
        // Services switched on by this choice have just received the page's events: tell the server too.
        const added = Object.keys(active).filter((name) => !already.includes(name));
        if (added.length) history.forEach((event) => relay(event, added));
        const token = /(?:^|; )XSRF-TOKEN=([^;]+)/.exec(document.cookie);
        void fetch(settings.consent.url, {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token ? decodeURIComponent(token[1] ?? '') : '' },
            body: JSON.stringify({ visitor, analytics: now.analytics, marketing: now.marketing }),
        }).catch(() => undefined);
    };

    if (banner) {
        const box = (name: string) => banner.querySelector<HTMLInputElement>(`input[name="${name}"]`);
        const options = banner.querySelector<HTMLElement>('[data-consent-options]');
        const show = (withOptions: boolean): void => {
            const analytics = box('analytics');
            const marketing = box('marketing');
            if (analytics) analytics.checked = state?.analytics ?? false;
            if (marketing) marketing.checked = state?.marketing ?? false;
            if (options) options.hidden = !withOptions;
            banner.querySelectorAll<HTMLElement>('[data-consent="choose"]').forEach((button) => (button.hidden = withOptions));
            banner.querySelectorAll<HTMLElement>('[data-consent="save"]').forEach((button) => (button.hidden = !withOptions));
            banner.hidden = false;
        };
        banner.addEventListener('click', (click) => {
            const action = (click.target as HTMLElement).closest<HTMLElement>('[data-consent]')?.dataset.consent;
            if (action === 'accept') choose({ analytics: true, marketing: true });
            if (action === 'reject') choose({ analytics: false, marketing: false });
            if (action === 'choose') show(true);
            if (action === 'save') choose({ analytics: box('analytics')?.checked ?? false, marketing: box('marketing')?.checked ?? false });
        });
        // A link in the footer lets a visitor change their mind at any time.
        document.querySelectorAll<HTMLElement>('[data-consent-open]').forEach((link) => {
            link.hidden = !mustAsk;
            link.addEventListener('click', (click) => {
                click.preventDefault();
                show(true);
                banner.querySelector<HTMLElement>('button, input')?.focus();
            });
        });
        if (!state && mustAsk) show(false);
    }

    document.addEventListener('store:track', (event) => {
        const detail = (event as CustomEvent<{ name: string; data?: EventData; id?: string }>).detail;
        if (detail?.name) emit(detail.name, detail.data, detail.id);
    });

    emit('page_view');
    settings.events.forEach((event) => emit(event.name, event.data, event.id));

    // Tags wait until the page is usable (PRD section 9).
    const begin = (): void => {
        ready = true;
        activate();
        history.forEach((event) => relay(event, Object.keys(active)));
    };
    const idle = (): void => {
        if (typeof w.requestIdleCallback === 'function') w.requestIdleCallback(begin, { timeout: 2000 });
        else setTimeout(begin, 200);
    };
    if (document.readyState === 'complete') idle();
    else window.addEventListener('load', idle, { once: true });
}

if (config) start(config);

export {};
