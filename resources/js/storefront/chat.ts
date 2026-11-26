// Live chat widget. It asks the server for new messages every few seconds while a chat exists
// (plain requests, so it works on any shared hosting), and stays silent for visitors who never open it.

interface Message {
    id: number;
    from: 'visitor' | 'admin' | 'system';
    body: string;
    at: string | null;
    by: string | null;
}

interface State {
    online: boolean;
    conversation: { status: string; hasEmail: boolean; unread: number } | null;
    messages: Message[];
}

const root = document.querySelector<HTMLElement>('[data-chat]');

if (root) {
    const $ = <T extends HTMLElement>(selector: string) => root.querySelector<T>(selector)!;
    const launcher = $<HTMLButtonElement>('[data-chat-open]');
    const panel = $<HTMLElement>('[data-chat-panel]');
    const badge = $<HTMLElement>('[data-chat-badge]');
    const status = $<HTMLElement>('[data-chat-status]');
    const intro = $<HTMLElement>('[data-chat-intro]');
    const list = $<HTMLOListElement>('[data-chat-list]');
    const body = $<HTMLElement>('[data-chat-body]');
    const form = $<HTMLFormElement>('[data-chat-form]');
    const details = $<HTMLElement>('[data-chat-details]');
    const emailNote = $<HTMLElement>('[data-chat-email-note]');
    const error = $<HTMLElement>('[data-chat-error]');
    const input = $<HTMLTextAreaElement>('#chat-message');
    const email = $<HTMLInputElement>('#chat-email');
    const send = $<HTMLButtonElement>('.chat-send');

    const FLAG = 'chat.active';
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    let lastId = 0;
    let started = localStorage.getItem(FLAG) === '1';
    let online = false;
    let timer: number | undefined;
    let busy = false;

    const isOpen = () => !panel.hidden;
    const time = (iso: string | null) => (iso ? new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '');

    function add(message: Message): void {
        if (message.id <= lastId) return;
        lastId = message.id;
        const item = document.createElement('li');
        item.className = `chat-msg chat-msg-${message.from}`;
        const bubble = document.createElement('p');
        bubble.className = 'chat-bubble';
        bubble.textContent = message.body; // text only: nothing a sender types is treated as HTML
        item.append(bubble);
        if (message.from !== 'system') {
            const meta = document.createElement('span');
            meta.className = 'chat-meta';
            meta.textContent = [message.from === 'admin' ? message.by : 'You', time(message.at)].filter(Boolean).join(' · ');
            item.append(meta);
        }
        list.append(item);
    }

    function apply(state: State): void {
        online = state.online;
        const hadMessages = lastId > 0;
        state.messages.forEach(add);
        if (state.messages.length > 0 && (isOpen() || !hadMessages)) body.scrollTop = body.scrollHeight;

        started = state.conversation !== null;
        if (started) localStorage.setItem(FLAG, '1');
        else localStorage.removeItem(FLAG);

        status.textContent = online ? 'We are online' : 'We are away';
        status.classList.toggle('chat-status-on', online);
        intro.textContent = (online ? intro.dataset.welcome : intro.dataset.away) ?? '';
        // Name and email are asked once, before the first message.
        details.hidden = started;
        emailNote.textContent = online ? '(optional)' : '(so we can reply)';
        email.required = !online && !started;

        const unread = state.conversation?.unread ?? 0;
        badge.hidden = unread === 0 || isOpen();
        badge.textContent = String(unread);
        launcher.setAttribute('aria-label', unread > 0 ? `Chat with us, ${unread} new ${unread === 1 ? 'message' : 'messages'}` : 'Chat with us');
    }

    async function call(url: string, payload?: Record<string, unknown>): Promise<State | null> {
        const query = `after=${lastId}&open=${isOpen() ? 1 : 0}`;
        const response = await fetch(payload ? url : `${url}?${query}`, {
            method: payload ? 'POST' : 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(payload ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf } : {}) },
            body: payload ? JSON.stringify({ ...payload, after: lastId, open: isOpen() }) : undefined,
        });
        if (response.status === 422) {
            const data = (await response.json()) as { errors?: Record<string, string[]> };
            throw new Error(Object.values(data.errors ?? {})[0]?.[0] ?? 'Please check your message.');
        }
        if (response.status === 429) throw new Error('You are sending messages very quickly. Please wait a moment.');
        if (!response.ok) throw new Error('The message could not be sent. Please try again.');
        return (await response.json()) as State;
    }

    async function refresh(): Promise<void> {
        if (busy || document.hidden) return;
        try {
            const state = await call(root!.dataset.stateUrl!);
            if (state) apply(state);
        } catch {
            // A missed check is harmless: the next one catches up.
        }
    }

    /** Every 3 seconds while the panel is open, every 15 while a chat exists but the panel is closed. */
    function schedule(): void {
        window.clearInterval(timer);
        if (isOpen() || started) timer = window.setInterval(refresh, isOpen() ? 3000 : 15000);
    }

    function setOpen(open: boolean): void {
        panel.hidden = !open;
        launcher.setAttribute('aria-expanded', String(open));
        root!.classList.toggle('chat-is-open', open);
        if (open) {
            badge.hidden = true;
            void refresh().then(() => {
                body.scrollTop = body.scrollHeight;
                (details.hidden ? input : $<HTMLInputElement>('#chat-name')).focus();
            });
        } else {
            launcher.focus();
        }
        schedule();
    }

    launcher.addEventListener('click', () => setOpen(!isOpen()));
    $<HTMLButtonElement>('[data-chat-close]').addEventListener('click', () => setOpen(false));
    panel.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') setOpen(false);
    });

    input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = `${Math.min(input.scrollHeight, 120)}px`;
    });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            form.requestSubmit();
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const message = input.value.trim();
        if (message === '' || busy) return;
        error.hidden = true;
        busy = true;
        send.disabled = true;
        try {
            const data = new FormData(form);
            const state = started
                ? await call(root!.dataset.sendUrl!, { message })
                : await call(root!.dataset.startUrl!, { message, name: data.get('name'), email: data.get('email'), website: data.get('website'), page_url: location.href });
            input.value = '';
            input.style.height = 'auto';
            if (state) apply(state);
            body.scrollTop = body.scrollHeight;
        } catch (problem) {
            error.textContent = (problem as Error).message;
            error.hidden = false;
        } finally {
            busy = false;
            send.disabled = false;
            schedule();
            input.focus();
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && (isOpen() || started)) void refresh();
    });

    // Returning visitors with a chat get their unread count; everyone else causes no requests until they open the panel.
    if (started) void refresh().then(schedule);
}

export {};
