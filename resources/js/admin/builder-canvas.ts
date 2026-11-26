/**
 * Runs inside the builder's canvas (an iframe showing the real selling page).
 * It turns the page into an editing surface and talks to the builder around it with postMessage:
 * selecting blocks, the block toolbar, drag and drop, editing text in place and picking images.
 */
type ToParent =
    | { type: 'ready' }
    | { type: 'select'; id: string | null }
    | { type: 'action'; id: string; action: 'up' | 'down' | 'duplicate' | 'toggle' | 'delete' }
    | { type: 'text'; id: string; path: string; value: string }
    | { type: 'image'; id: string; path: string }
    | { type: 'drop'; index: number; blockType?: string; id?: string }
    | { type: 'history'; direction: 'undo' | 'redo' };

type FromParent =
    | { type: 'html'; html: string; selected: string | null }
    | { type: 'select'; id: string | null; scroll?: boolean }
    | { type: 'dragging'; blockType: string | null }
    | { type: 'readonly'; value: boolean };

const root = document.getElementById('builder-blocks');
const send = (message: ToParent) => window.parent.postMessage({ source: 'builder-canvas', ...message }, window.location.origin);

let selected: string | null = null;
let readOnly = false;
let draggingNew: string | null = null;
let draggingId: string | null = null;
let dropLine: HTMLElement | null = null;

const ICONS: Record<string, string> = {
    drag: '<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>',
    up: '<path d="M12 19V5M5 12l7-7 7 7"/>',
    down: '<path d="M12 5v14M5 12l7 7 7-7"/>',
    duplicate: '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/>',
    hide: '<path d="M3 3l18 18M10.6 6.1A9.8 9.8 0 0 1 12 6c5 0 9 6 9 6a15 15 0 0 1-3 3.4M6.5 7.6A15 15 0 0 0 3 12s4 6 9 6a9 9 0 0 0 3.2-.6"/><circle cx="12" cy="12" r="3"/>',
    show: '<path d="M3 12s4-6 9-6 9 6 9 6-4 6-9 6-9-6-9-6z"/><circle cx="12" cy="12" r="3"/>',
    delete: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
};

const blocks = (): HTMLElement[] => (root ? Array.from(root.querySelectorAll<HTMLElement>(':scope > [data-block-id]')) : []);
const blockOf = (target: EventTarget | null): HTMLElement | null => (target instanceof Element ? target.closest<HTMLElement>('[data-block-id]') : null);

function button(act: string, label: string, icon: string, disabled = false): string {
    return `<button type="button" data-act="${act}" aria-label="${label}" title="${label}"${disabled ? ' disabled' : ''}${act === 'drag' ? ' draggable="true"' : ''}><svg viewBox="0 0 24 24" aria-hidden="true">${ICONS[icon]}</svg></button>`;
}

/** Give every block its name tag, and the selected one its toolbar. */
function decorate(): void {
    const all = blocks();
    all.forEach((block, index) => {
        block.querySelectorAll(':scope > .bd-toolbar, :scope > .bd-tag').forEach((el) => el.remove());
        const isSelected = block.dataset.blockId === selected;
        block.classList.toggle('bd-selected', isSelected);

        const label = block.dataset.blockLabel ?? 'Block';
        const hidden = block.hasAttribute('data-block-hidden');
        const tag = document.createElement('span');
        tag.className = 'bd-tag';
        tag.textContent = hidden ? `${label} (hidden)` : label;
        block.prepend(tag);

        if (!isSelected || readOnly) return;
        const bar = document.createElement('div');
        bar.className = 'bd-toolbar';
        bar.setAttribute('role', 'toolbar');
        bar.setAttribute('aria-label', `${label} block`);
        bar.innerHTML =
            button('drag', `Drag ${label} to move it`, 'drag') +
            button('up', `Move ${label} up`, 'up', index === 0) +
            button('down', `Move ${label} down`, 'down', index === all.length - 1) +
            (block.dataset.blockType === 'order' ? '' : button('duplicate', `Duplicate ${label}`, 'duplicate')) +
            button('toggle', hidden ? `Show ${label} on the page` : `Hide ${label} from the page`, hidden ? 'hide' : 'show') +
            button('delete', `Delete ${label}`, 'delete');
        block.append(bar);
    });
}

function select(id: string | null, tellParent: boolean, scroll = false): void {
    if (selected !== id) {
        selected = id;
        decorate();
        if (tellParent) send({ type: 'select', id });
    }
    if (scroll && id) {
        blocks().find((b) => b.dataset.blockId === id)?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

function startEditing(el: HTMLElement): void {
    if (el.isContentEditable) return;
    el.setAttribute('contenteditable', 'plaintext-only');
    if (!el.isContentEditable) el.setAttribute('contenteditable', 'true'); // older Firefox
    el.setAttribute('spellcheck', 'true');
    el.focus();
}

/** Where a block dragged to this height would land: its position in the list, and the line to draw. */
function dropTarget(clientY: number): { index: number; top: number } {
    const all = blocks();
    const scrollTop = window.scrollY;
    for (let i = 0; i < all.length; i++) {
        const box = all[i].getBoundingClientRect();
        if (clientY < box.top + box.height / 2) return { index: i, top: box.top + scrollTop };
    }
    const last = all[all.length - 1];
    const lastBox = last?.getBoundingClientRect() ?? root?.getBoundingClientRect();
    return { index: all.length, top: (last ? lastBox!.bottom : (lastBox?.top ?? 0)) + scrollTop };
}

function showDropLine(top: number): void {
    if (!dropLine) {
        dropLine = document.createElement('div');
        dropLine.className = 'bd-dropline';
        document.body.append(dropLine);
    }
    dropLine.style.top = `${top}px`;
}

function clearDrag(): void {
    dropLine?.remove();
    dropLine = null;
    blocks().forEach((b) => b.classList.remove('bd-dragging'));
    draggingId = null;
}

const isBlockDrag = (event: DragEvent): boolean =>
    draggingId !== null || draggingNew !== null || Array.from(event.dataTransfer?.types ?? []).includes('application/x-builder-block');

if (root) {
    document.body.style.position = 'relative';

    // Nothing on the canvas navigates or submits. Clicks select, edit text or pick an image.
    document.addEventListener(
        'click',
        (event) => {
            const target = event.target as Element;
            const act = target.closest<HTMLButtonElement>('.bd-toolbar button');
            if (act) {
                event.preventDefault();
                const id = blockOf(act)?.dataset.blockId;
                const action = act.dataset.act;
                if (id && action && action !== 'drag') send({ type: 'action', id, action: action as 'up' });
                return;
            }
            if (target.closest('a, button, summary, label, form')) event.preventDefault();

            const block = blockOf(target);
            select(block?.dataset.blockId ?? null, true);
            if (!block || readOnly) return;

            const image = target.closest<HTMLElement>('[data-edit-image]');
            if (image) {
                send({ type: 'image', id: block.dataset.blockId!, path: image.dataset.editImage! });
                return;
            }
            const text = target.closest<HTMLElement>('[data-edit]');
            if (text) startEditing(text);
        },
        true,
    );
    document.addEventListener('submit', (event) => event.preventDefault(), true);

    document.addEventListener('input', (event) => {
        const el = (event.target as Element).closest<HTMLElement>('[data-edit]');
        const block = blockOf(el);
        if (!el || !block) return;
        send({ type: 'text', id: block.dataset.blockId!, path: el.dataset.edit!, value: el.innerText.replace(/ /g, ' ').replace(/\n+$/, '') });
    });

    document.addEventListener('focusout', (event) => {
        const el = event.target instanceof HTMLElement && event.target.matches('[data-edit][contenteditable]') ? event.target : null;
        el?.removeAttribute('contenteditable');
    });

    document.addEventListener('paste', (event) => {
        const el = (event.target as Element).closest<HTMLElement>('[data-edit][contenteditable="true"]');
        if (!el) return;
        event.preventDefault();
        document.execCommand('insertText', false, event.clipboardData?.getData('text/plain') ?? '');
    });

    document.addEventListener('keydown', (event) => {
        const mod = event.ctrlKey || event.metaKey;
        const key = event.key.toLowerCase();
        if (mod && (key === 'z' || key === 'y')) {
            event.preventDefault();
            (document.activeElement as HTMLElement | null)?.blur();
            send({ type: 'history', direction: key === 'y' || event.shiftKey ? 'redo' : 'undo' });
            return;
        }
        const editing = event.target instanceof HTMLElement && event.target.isContentEditable ? event.target : null;
        if (editing) {
            if (event.key === 'Escape' || (event.key === 'Enter' && !event.shiftKey)) {
                event.preventDefault();
                editing.blur();
            } else if (event.key === ' ' && editing.closest('summary')) {
                // A space inside a question must type a space, not fold the answer away.
                event.preventDefault();
                document.execCommand('insertText', false, ' ');
            }
            return;
        }
        // Enter on a focused block's text starts editing, so the canvas works without a mouse.
        if (event.key === 'Enter' && event.target instanceof HTMLElement && event.target.matches('[data-edit]') && !readOnly) {
            event.preventDefault();
            startEditing(event.target);
        }
    });

    // Drag a block by its handle, or a new block in from the library.
    document.addEventListener('dragstart', (event) => {
        const handle = (event.target as Element).closest('.bd-toolbar [data-act="drag"]');
        const block = blockOf(handle);
        if (!handle || !block || !event.dataTransfer) {
            event.preventDefault();
            return;
        }
        draggingId = block.dataset.blockId!;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('application/x-builder-block', draggingId);
        event.dataTransfer.setDragImage(block, 24, 24);
        requestAnimationFrame(() => block.classList.add('bd-dragging'));
    });

    document.addEventListener('dragover', (event) => {
        if (readOnly || !isBlockDrag(event)) return;
        event.preventDefault();
        if (event.dataTransfer) event.dataTransfer.dropEffect = draggingId ? 'move' : 'copy';
        showDropLine(dropTarget(event.clientY).top);
        // Scroll when the pointer is near the top or bottom edge.
        const edge = 80;
        if (event.clientY < edge) window.scrollBy(0, -18);
        else if (event.clientY > window.innerHeight - edge) window.scrollBy(0, 18);
    });

    document.addEventListener('drop', (event) => {
        if (readOnly || !isBlockDrag(event)) return;
        event.preventDefault();
        const { index } = dropTarget(event.clientY);
        if (draggingId) send({ type: 'drop', index, id: draggingId });
        else {
            const blockType = draggingNew ?? event.dataTransfer?.getData('application/x-builder-block') ?? '';
            if (blockType) send({ type: 'drop', index, blockType });
        }
        draggingNew = null;
        clearDrag();
    });
    document.addEventListener('dragend', clearDrag);
    document.addEventListener('dragleave', (event) => {
        if (event.relatedTarget === null) {
            dropLine?.remove();
            dropLine = null;
        }
    });

    window.addEventListener('message', (event: MessageEvent<FromParent & { source?: string }>) => {
        if (event.origin !== window.location.origin || event.source !== window.parent || event.data?.source !== 'builder') return;
        const message = event.data;
        if (message.type === 'html') {
            const scroll = window.scrollY;
            root.innerHTML = message.html;
            selected = message.selected;
            decorate();
            window.scrollTo(0, scroll);
        } else if (message.type === 'select') {
            select(message.id, false, message.scroll);
        } else if (message.type === 'dragging') {
            draggingNew = message.blockType;
            if (!message.blockType) clearDrag();
        } else if (message.type === 'readonly') {
            readOnly = message.value;
            decorate();
        }
    });

    decorate();
    send({ type: 'ready' });
}

export {};
