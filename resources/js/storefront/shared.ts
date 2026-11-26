// Shared by every storefront script: prices in the store's chosen format, and interface text the owner may have reworded.

const format = document.documentElement.dataset.currency ?? 'symbol';

/** Cents to a price, written the way Settings, Store says (the server formats prices the same way). */
export const money = (cents: number): string => {
    const sign = cents < 0 ? '-' : '';
    const amount = (Math.abs(cents) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (format === 'code') return sign + amount + ' USD';
    return format === 'us_symbol' ? sign + 'US$' + amount : sign + '$' + amount;
};

let reworded: Record<string, string> | null = null;

/** Interface text. The page carries only the strings the owner changed; everything else is the text written here. */
/** Tell the tracking layer that something happened. It decides which services hear about it. */
export const track = (name: string, data?: Record<string, unknown>, id?: string): void => {
    document.dispatchEvent(new CustomEvent('store:track', { detail: { name, data, id } }));
};

export const t = (text: string, replace: Record<string, string | number> = {}): string => {
    if (reworded === null) {
        try {
            reworded = JSON.parse(document.getElementById('ui-text')?.textContent ?? '{}') as Record<string, string>;
        } catch {
            reworded = {};
        }
    }
    let out = reworded[text] ?? text;
    Object.entries(replace).forEach(([name, value]) => {
        out = out.replace(':' + name, String(value));
    });
    return out;
};
