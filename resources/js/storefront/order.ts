// Order confirmation: wait for Stripe's webhook to mark the order paid, then reload to show the downloads.

function init(): void {
    const box = document.querySelector<HTMLElement>('[data-order-wait]');
    if (!box) return;

    const url = box.dataset.statusUrl as string;
    const title = box.querySelector<HTMLElement>('[data-wait-title]');
    const text = box.querySelector<HTMLElement>('[data-wait-text]');
    const retry = box.querySelector<HTMLElement>('[data-wait-retry]');
    const spinner = box.querySelector<HTMLElement>('.spinner');
    const started = Date.now();

    const check = async () => {
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const data = (await response.json()) as { paid: boolean; failed: boolean; message: string | null };
            if (data.paid) {
                window.location.reload();
                return;
            }
            if (data.failed) {
                if (title) title.textContent = 'The payment did not go through';
                if (text) text.textContent = `${data.message ?? ''} Nothing was charged. You can try again with another card.`.trim();
                if (retry) retry.hidden = false;
                if (spinner) spinner.hidden = true;
                return;
            }
        } catch {
            // A dropped request is not an answer; keep waiting.
        }

        const waited = Date.now() - started;
        if (waited > 120_000) {
            if (title) title.textContent = 'Still confirming your payment';
            if (text) text.textContent = 'This is taking longer than usual. You can close this page: we will email your download links as soon as the payment is confirmed.';
            if (spinner) spinner.hidden = true;
            return;
        }
        window.setTimeout(check, waited < 20_000 ? 1500 : 4000);
    };

    window.setTimeout(check, 1200);
}

init();

export {};
