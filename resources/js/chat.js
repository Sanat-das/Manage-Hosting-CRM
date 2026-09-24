/**
 * The admin chat client.
 *
 * Vanilla DOM on purpose — this panel is Blade and AdminLTE everywhere else,
 * and a framework here would be the only one in the application.
 *
 * The message pane is a plain <ol> holding at most the newest 50 messages plus
 * whatever "Load older" has pulled in. No virtual scrolling: a list this size
 * does not need windowing, and hand-rolled windowing is a multi-day subproject
 * that breaks find-in-page, screen readers and Ctrl+End.
 *
 * Everything is built so the page still works with no websocket at all: the
 * first page of history is rendered server-side, and every action is a normal
 * fetch() against an endpoint that also answers when Reverb is down.
 */
import { realtime, onConnectionStateChange } from './echo.js';

// The Ctrl/Cmd+K command palette partial renders on every admin page, chat
// included. Flag chat's ownership of the shortcut at module scope (not inside
// initChat) so both keydown handlers can see it before either fires, and the
// palette can bail in whichever order the browser runs the two listeners.
window.__mhChatShortcuts = true;

const root = document.getElementById('chat-app');

if (root) {
    initChat(root);
}

function initChat(root) {
    const state = {
        conversationId: Number(root.dataset.conversationId) || null,
        userId: Number(root.dataset.userId),
        canOperate: root.dataset.canOperate === '1',
        heartbeat: Number(root.dataset.heartbeat || 30),
        threadParentId: null,
        editingId: null,
        pendingEntities: [],
        typingTimer: null,
        typingSentAt: 0,
        // Fallback bookkeeping. `pollTimer` is the single source of truth for
        // "is a timer running": every start goes through startPolling(), which
        // refuses to make a second one.
        lastMessageId: 0,
        pollTimer: null,
        fallback: false,
        retry: null,
        // Sidebar bookkeeping. `knownConversations` is seeded from the rows the
        // server rendered, so the first sync announces only rooms that really
        // are new rather than re-announcing the whole queue on page load.
        knownConversations: new Set(),
        queued: 0,
    };

    const el = {
        list: document.getElementById('chat-message-list'),
        messages: document.getElementById('chat-messages'),
        composer: document.getElementById('chat-composer'),
        body: document.getElementById('chat-body'),
        status: document.getElementById('chat-status'),
        typing: document.getElementById('chat-typing'),
        chips: document.getElementById('chat-chips'),
        mentions: document.getElementById('chat-mentions'),
        entityPicker: document.getElementById('chat-entity-picker'),
        entityType: document.getElementById('chat-entity-type'),
        entityQuery: document.getElementById('chat-entity-query'),
        entityResults: document.getElementById('chat-entity-results'),
        cannedPicker: document.getElementById('chat-canned-picker'),
        cannedQuery: document.getElementById('chat-canned-query'),
        cannedResults: document.getElementById('chat-canned-results'),
        availability: document.getElementById('chat-availability'),
        availabilityStatus: document.getElementById('chat-availability-status'),
        thread: document.getElementById('chat-thread'),
        threadBody: document.getElementById('chat-thread-body'),
        threadComposer: document.getElementById('chat-thread-composer'),
        threadInput: document.getElementById('chat-thread-body-input'),
        loadOlder: document.getElementById('chat-load-older'),
        filter: document.getElementById('chat-filter'),
        noMessages: document.getElementById('chat-no-messages'),
        banner: document.getElementById('chat-reconnect-banner'),
        skeleton: document.querySelector('[data-chat-skeleton]'),
        toast: document.getElementById('chat-toast'),
        toastMessage: document.querySelector('[data-chat-toast-message]'),
        toastRetry: document.querySelector('[data-chat-retry]'),
        toastClose: document.querySelector('[data-chat-toast-close]'),
        search: document.getElementById('chat-search'),
        searchOpen: document.getElementById('chat-search-open'),
        searchForm: document.getElementById('chat-search-form'),
        searchQuery: document.getElementById('chat-search-q'),
        searchChannel: document.getElementById('chat-search-channel'),
        searchFrom: document.getElementById('chat-search-from'),
        searchTo: document.getElementById('chat-search-to'),
        searchStatus: document.getElementById('chat-search-status'),
        searchResults: document.getElementById('chat-search-results'),
        searchPager: document.getElementById('chat-search-pager'),
        searchPageLabel: document.getElementById('chat-search-page-label'),
        switcher: document.getElementById('chat-switcher'),
        switcherInput: document.getElementById('chat-switcher-input'),
        switcherResults: document.getElementById('chat-switcher-results'),
        confirm: document.getElementById('chat-confirm'),
        confirmTitle: document.querySelector('[data-chat-confirm-title]'),
        confirmBody: document.querySelector('[data-chat-confirm-body]'),
        confirmOk: document.querySelector('[data-chat-confirm-ok]'),
    };

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const emojis = readJson('chat-emoji-set', []);
    const people = readJson2(el.mentions?.dataset.people, []);

    // --- helpers ---------------------------------------------------------

    function readJson(id, fallback) {
        try {
            return JSON.parse(document.getElementById(id)?.textContent ?? '');
        } catch {
            return fallback;
        }
    }

    function readJson2(raw, fallback) {
        try {
            return JSON.parse(raw ?? '');
        } catch {
            return fallback;
        }
    }

    async function api(url, options = {}) {
        const response = await fetch(url, {
            headers: {
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
                ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
            },
            credentials: 'same-origin',
            ...options,
        });

        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw Object.assign(new Error(payload.message || 'Something went wrong.'), {
                status: response.status,
                payload,
            });
        }

        return payload;
    }

    function say(message, tone = 'muted') {
        if (!el.status) return;
        el.status.textContent = message;
        el.status.className = `small ms-1 text-${tone === 'error' ? 'danger' : 'body-secondary'}`;
        if (message) {
            setTimeout(() => {
                if (el.status.textContent === message) el.status.textContent = '';
            }, 4000);
        }
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
    }

    /**
     * An error that survives being ignored, unlike the status line, which
     * clears itself after four seconds. `retry` is the whole action to run
     * again, so the retry button cannot drift from what actually failed.
     */
    function toast(message, retry = null) {
        if (!el.toast || !el.toastMessage) {
            say(message, 'error');
            return;
        }

        state.retry = retry;
        el.toastMessage.textContent = message;
        el.toastRetry?.classList.toggle('d-none', retry === null);
        el.toast.classList.remove('d-none');
    }

    function dismissToast() {
        state.retry = null;
        el.toast?.classList.add('d-none');
    }

    el.toastClose?.addEventListener('click', dismissToast);

    el.toastRetry?.addEventListener('click', () => {
        const again = state.retry;
        dismissToast();
        again?.();
    });

    function confirmAction(title, body) {
        if (!el.confirm || !el.confirmOk) {
            return Promise.resolve(window.confirm(`${title}\n\n${body}`));
        }

        return new Promise((resolve) => {
            const cancels = el.confirm.querySelectorAll('[data-chat-confirm-cancel]');

            const close = (answer) => {
                el.confirm.classList.add('d-none');
                el.confirmOk.removeEventListener('click', accept);
                cancels.forEach((button) => button.removeEventListener('click', reject));
                document.removeEventListener('keydown', onKey);
                resolve(answer);
            };

            const accept = () => close(true);
            const reject = () => close(false);
            const onKey = (event) => {
                if (event.key === 'Escape') close(false);
            };

            el.confirmTitle.textContent = title;
            el.confirmBody.textContent = body;
            el.confirm.classList.remove('d-none');

            el.confirmOk.addEventListener('click', accept);
            cancels.forEach((button) => button.addEventListener('click', reject));
            document.addEventListener('keydown', onKey);
            el.confirmOk.focus();
        });
    }

    function showSkeleton(on) {
        el.skeleton?.classList.toggle('d-none', !on);
    }

    function atBottom() {
        if (!el.messages) return true;
        return el.messages.scrollHeight - el.messages.scrollTop - el.messages.clientHeight < 120;
    }

    function scrollToBottom() {
        if (el.messages) el.messages.scrollTop = el.messages.scrollHeight;
    }

    // --- rendering -------------------------------------------------------

    /**
     * Mirrors resources/views/admin/chat/partials/message.blade.php. The two
     * must produce the same markup, or a message looks different depending on
     * whether it arrived over the socket or with the page.
     *
     * body_html is inserted as HTML because the server has already escaped and
     * sanitised it; every other field goes through escapeHtml().
     */
    function renderMessage(message) {
        const li = document.createElement('li');
        li.className = `chat-message${message.is_deleted ? ' is-deleted' : ''}`;
        li.id = `chat-message-${message.id}`;
        li.dataset.messageId = message.id;
        li.dataset.authorId = message.user?.id ?? '';

        const time = message.created_at
            ? new Date(message.created_at).toLocaleString(undefined, {
                  day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit',
              })
            : '';

        const cards = (message.entity_links || [])
            .map((link) =>
                link.url
                    ? `<a class="chat-card" href="${escapeHtml(link.url)}"><span class="chat-card__type">${escapeHtml(link.type)}</span>${escapeHtml(link.label)}</a>`
                    : `<span class="chat-card is-gone"><span class="chat-card__type">${escapeHtml(link.type)}</span>${escapeHtml(link.label)}</span>`,
            )
            .join('');

        const attachments = (message.attachments || [])
            .map((a) =>
                a.is_image
                    ? `<a href="${escapeHtml(a.url)}" target="_blank" rel="noopener"><img class="chat-attachment__image" src="${escapeHtml(a.url)}" alt="${escapeHtml(a.filename)}"></a>`
                    : `<a class="chat-attachment" href="${escapeHtml(a.url)}"><i class="bi bi-paperclip"></i> ${escapeHtml(a.filename)} <span class="text-body-secondary">${escapeHtml(a.size)}</span></a>`,
            )
            .join('');

        const own = message.user?.id === state.userId;

        li.innerHTML = `
            <div class="chat-message__meta">
                <span class="chat-message__author">${escapeHtml(message.author_name)}</span>
                <time class="chat-message__time" datetime="${escapeHtml(message.created_at)}">${escapeHtml(time)}</time>
                ${message.is_edited ? '<span class="chat-message__edited">(edited)</span>' : ''}
            </div>
            <div class="chat-message__body">${message.body_html ?? ''}</div>
            ${cards ? `<div class="chat-message__cards">${cards}</div>` : ''}
            ${attachments ? `<div class="chat-message__attachments">${attachments}</div>` : ''}
            <div class="chat-message__reactions" data-reactions-for="${message.id}"></div>
            ${
                message.is_deleted
                    ? ''
                    : `<div class="chat-message__actions">
                        <button type="button" class="chat-action" data-action="react" title="React"><i class="bi bi-emoji-smile"></i></button>
                        <button type="button" class="chat-action" data-action="thread" title="Reply in thread"><i class="bi bi-chat-right-text"></i></button>
                        ${own ? '<button type="button" class="chat-action" data-action="edit" title="Edit"><i class="bi bi-pencil"></i></button>' : ''}
                        <button type="button" class="chat-action" data-action="delete" title="Delete"><i class="bi bi-trash"></i></button>
                    </div>`
            }
        `;

        return li;
    }

    /**
     * The dedupe point for both transports: a message that arrives over the
     * websocket and again from the 5s poll replaces its own row instead of
     * appearing twice, because the row id is the message id.
     */
    function upsertMessage(message) {
        if (Number(message.id) > state.lastMessageId) {
            state.lastMessageId = Number(message.id);
        }

        const existing = document.getElementById(`chat-message-${message.id}`);
        const node = renderMessage(message);

        if (existing) {
            existing.replaceWith(node);
            return;
        }

        if (message.parent_id) {
            // Thread replies belong in the slideover, not the main pane.
            if (state.threadParentId === message.parent_id) {
                el.threadBody?.append(node);
            }
            return;
        }

        const stick = atBottom();
        el.list?.append(node);
        el.noMessages?.classList.add('d-none');
        if (stick) scrollToBottom();
    }

    // --- sending ---------------------------------------------------------

    async function send(body, parentId = null) {
        if (!state.conversationId || body.trim() === '') return;

        try {
            const result = await api(`/admin/chat/conversations/${state.conversationId}/messages`, {
                method: 'POST',
                body: JSON.stringify({ body, parent_id: parentId }),
            });

            upsertMessage(result.message);
            await attachPendingEntities(result.message.id);
            say('');
        } catch (error) {
            say(error.payload?.errors?.body?.[0] || error.message, 'error');
            throw error;
        }
    }

    async function attachPendingEntities(messageId) {
        for (const entity of state.pendingEntities) {
            try {
                const result = await api(`/admin/chat/messages/${messageId}/entity-links`, {
                    method: 'POST',
                    body: JSON.stringify({ type: entity.type, id: entity.id }),
                });
                upsertMessage(result.message);
            } catch (error) {
                say(`Could not attach ${entity.label}: ${error.message}`, 'error');
            }
        }

        state.pendingEntities = [];
        renderChips();
    }

    el.composer?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const body = el.body.value;

        if (state.editingId) {
            const id = state.editingId;
            state.editingId = null;
            try {
                const result = await api(`/admin/chat/messages/${id}`, {
                    method: 'PUT',
                    body: JSON.stringify({ body }),
                });
                upsertMessage(result.message);
                el.body.value = '';
            } catch (error) {
                say(error.message, 'error');
            }
            return;
        }

        try {
            await send(body);
            el.body.value = '';
            dismissToast();
        } catch (error) {
            // The message stays in the box so it is not lost, and the toast
            // offers to post exactly this body again.
            toast(error.payload?.errors?.body?.[0] || `Message not sent: ${error.message}`, () => {
                el.composer.requestSubmit();
            });
        }
    });

    el.body?.addEventListener('keydown', (event) => {
        // Escape closes the saved-reply picker without sending or clearing.
        if (event.key === 'Escape' && !el.cannedPicker?.classList.contains('d-none')) {
            event.preventDefault();
            closeCanned();

            return;
        }

        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();

            // A composer holding nothing but `/shortcut` is a reply being
            // chosen, not a message being written. Sending it would put the
            // literal "/refund" in front of the customer.
            if (slashToken() !== null && cannedRows.length > 0) {
                insertCanned(cannedRows[0]);

                return;
            }

            el.composer.requestSubmit();
        }
    });

    el.body?.addEventListener('input', () => {
        handleMentionTyping();
        handleCannedTyping();
        announceTyping();
    });

    // --- typing ----------------------------------------------------------

    function announceTyping() {
        const now = Date.now();
        if (!state.conversationId || now - state.typingSentAt < 3000) return;

        state.typingSentAt = now;
        api(`/admin/chat/conversations/${state.conversationId}/typing`, {
            method: 'POST',
            body: JSON.stringify({ typing: true }),
        }).catch(() => {});
    }

    function showTyping(name, isTyping) {
        if (!el.typing) return;
        el.typing.textContent = isTyping ? `${name} is typing...` : '';

        clearTimeout(state.typingTimer);
        if (isTyping) {
            state.typingTimer = setTimeout(() => {
                el.typing.textContent = '';
            }, 5000);
        }
    }

    // --- message actions --------------------------------------------------

    el.list?.addEventListener('click', (event) => onMessageAction(event));
    el.threadBody?.addEventListener('click', (event) => onMessageAction(event));

    async function onMessageAction(event) {
        const button = event.target.closest('[data-action]');
        if (!button) return;

        const row = button.closest('[data-message-id]');
        const id = Number(row.dataset.messageId);

        if (button.dataset.action === 'delete') {
            const confirmed = await confirmAction(
                'Delete this message?',
                'It stays in the thread as [deleted] so the conversation still reads in order.',
            );
            if (!confirmed) return;

            try {
                await api(`/admin/chat/messages/${id}`, { method: 'DELETE' });
                row.classList.add('is-deleted');
                row.querySelector('.chat-message__body').textContent = '[deleted]';
                row.querySelector('.chat-message__actions')?.remove();
            } catch (error) {
                toast(`Could not delete the message: ${error.message}`);
            }
            return;
        }

        if (button.dataset.action === 'edit') {
            state.editingId = id;
            el.body.value = row.querySelector('.chat-message__body').innerText.trim();
            el.body.focus();
            say('Editing - press Enter to save.');
            return;
        }

        if (button.dataset.action === 'thread') {
            openThread(id);
            return;
        }

        if (button.dataset.action === 'react') {
            openReactionPicker(button, id);
        }
    }

    function openReactionPicker(anchor, messageId) {
        document.querySelector('.chat-emoji-popover')?.remove();

        const popover = document.createElement('div');
        popover.className = 'chat-emoji-popover';
        popover.innerHTML = emojis
            .map((emoji) => `<button type="button" class="chat-emoji" data-emoji="${escapeHtml(emoji)}">${escapeHtml(emoji)}</button>`)
            .join('');

        popover.addEventListener('click', async (event) => {
            const choice = event.target.closest('[data-emoji]');
            if (!choice) return;
            popover.remove();

            try {
                const result = await api(`/admin/chat/messages/${messageId}/reactions`, {
                    method: 'POST',
                    body: JSON.stringify({ emoji: choice.dataset.emoji }),
                });
                applyReaction(messageId, choice.dataset.emoji, result.count);
            } catch (error) {
                say(error.message, 'error');
            }
        });

        anchor.closest('.chat-message').append(popover);
        setTimeout(() => document.addEventListener('click', () => popover.remove(), { once: true }), 0);
    }

    function applyReaction(messageId, emoji, count) {
        const holder = document.querySelector(`[data-reactions-for="${messageId}"]`);
        if (!holder) return;

        let pill = holder.querySelector(`[data-emoji="${CSS.escape(emoji)}"]`);

        if (count === 0) {
            pill?.remove();
            return;
        }

        if (!pill) {
            pill = document.createElement('button');
            pill.type = 'button';
            pill.className = 'chat-reaction';
            pill.dataset.emoji = emoji;
            pill.addEventListener('click', async () => {
                try {
                    const result = await api(`/admin/chat/messages/${messageId}/reactions`, {
                        method: 'POST',
                        body: JSON.stringify({ emoji }),
                    });
                    applyReaction(messageId, emoji, result.count);
                } catch (error) {
                    say(error.message, 'error');
                }
            });
            holder.append(pill);
        }

        pill.textContent = `${emoji} ${count}`;
    }

    // --- threads ----------------------------------------------------------

    async function openThread(parentId) {
        state.threadParentId = parentId;
        el.thread?.classList.remove('d-none');
        el.threadBody.innerHTML =
            '<div class="chat-skeleton"><div class="chat-skeleton__line"></div>' +
            '<div class="chat-skeleton__line w-75"></div><div class="chat-skeleton__line w-50"></div></div>';

        try {
            const result = await api(`/admin/chat/conversations/${state.conversationId}/threads/${parentId}`);
            el.threadBody.innerHTML = '';
            el.threadBody.append(renderMessage(result.parent));
            result.replies.forEach((reply) => el.threadBody.append(renderMessage(reply)));
        } catch (error) {
            el.threadBody.innerHTML = `<p class="text-danger p-3 mb-0">${escapeHtml(error.message)}</p>`;
        }
    }

    document.getElementById('chat-thread-close')?.addEventListener('click', () => {
        el.thread?.classList.add('d-none');
        state.threadParentId = null;
    });

    el.threadComposer?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!state.threadParentId) return;

        try {
            await send(el.threadInput.value, state.threadParentId);
            el.threadInput.value = '';
        } catch {
            // keep the text
        }
    });

    // --- history ----------------------------------------------------------

    el.loadOlder?.addEventListener('click', () => loadOlderPage());

    /**
     * Pull one page backwards from the cursor.
     *
     * Resolves true when it actually prepended something, so a caller hunting
     * for a particular message can page until it appears without guessing at how
     * long the fetch took.
     */
    async function loadOlderPage() {
        const oldest = el.messages?.dataset.oldest;
        if (!oldest || el.loadOlder.disabled) {
            el.loadOlder.disabled = true;
            return false;
        }

        el.loadOlder.disabled = true;
        el.loadOlder.textContent = 'Loading...';
        showSkeleton(true);

        try {
            const result = await api(
                `/admin/chat/conversations/${state.conversationId}/messages?before_id=${oldest}`,
            );

            if (result.messages.length === 0) {
                el.loadOlder.textContent = 'Beginning of the conversation';
                return false;
            }

            const previousHeight = el.messages.scrollHeight;
            result.messages
                .slice()
                .reverse()
                .forEach((message) => el.list.prepend(renderMessage(message)));
            el.messages.dataset.oldest = result.messages[0].id;
            // Keep the reading position where it was rather than jumping.
            el.messages.scrollTop = el.messages.scrollHeight - previousHeight;

            el.loadOlder.disabled = !result.has_more;
            el.loadOlder.textContent = result.has_more ? 'Load older messages' : 'Beginning of the conversation';

            return true;
        } catch (error) {
            el.loadOlder.disabled = false;
            el.loadOlder.textContent = 'Load older messages';
            toast(`Could not load older messages: ${error.message}`, () => el.loadOlder.click());

            return false;
        } finally {
            showSkeleton(false);
        }
    }

    // --- sidebar filter ---------------------------------------------------

    el.filter?.addEventListener('input', () => {
        const needle = el.filter.value.trim().toLowerCase();

        document.querySelectorAll('.chat-sidebar__item').forEach((item) => {
            item.classList.toggle('d-none', needle !== '' && !item.dataset.name.includes(needle));
        });

        document.querySelectorAll('details[data-closed-group]').forEach((group) => {
            if (needle === '') {
                group.removeAttribute('open');
                return;
            }
            const hasHit = Array.from(group.querySelectorAll('.chat-sidebar__item'))
                .some((item) => !item.classList.contains('d-none'));
            if (hasHit) group.setAttribute('open', '');
        });
    });

    const drawerToggles = [
        document.getElementById('chat-drawer-open'),
        document.getElementById('chat-drawer-open-empty'),
    ].filter(Boolean);
    const drawerBackdrop = document.getElementById('chat-drawer-backdrop');

    const setDrawer = (open) => {
        root.classList.toggle('drawer-open', open);
        drawerToggles.forEach((btn) => btn.setAttribute('aria-expanded', open ? 'true' : 'false'));
        drawerBackdrop?.classList.toggle('d-none', !open);
    };

    drawerToggles.forEach((btn) => btn.addEventListener('click', () => {
        setDrawer(!root.classList.contains('drawer-open'));
    }));
    drawerBackdrop?.addEventListener('click', () => setDrawer(false));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && root.classList.contains('drawer-open')) setDrawer(false);
    });

    // --- new channel ------------------------------------------------------

    document.getElementById('chat-new-channel')?.addEventListener('click', async () => {
        const name = window.prompt('Channel name');
        if (!name) return;

        const isPrivate = window.confirm('Make this channel private? OK for private, Cancel for public.');

        try {
            const result = await api('/admin/chat/channels', {
                method: 'POST',
                body: JSON.stringify({ name, is_private: isPrivate }),
            });
            window.location = `/admin/chat?c=${result.channel.id}`;
        } catch (error) {
            say(error.payload?.errors?.name?.[0] || error.message, 'error');
        }
    });

    // --- people, channels and membership ----------------------------------

    /**
     * Built from the rendered route rather than written as a literal, because
     * the app can be installed under a subdirectory and only route() knows.
     */
    const chatBase = (root.dataset.base || '/admin/chat').replace(/\/$/, '');

    const goTo = (conversationId) => {
        window.location = `${chatBase}?c=${conversationId}`;
    };

    const pick = {
        dialog: document.getElementById('chat-people'),
        title: document.getElementById('chat-people-title'),
        query: document.getElementById('chat-people-q'),
        chosen: document.getElementById('chat-people-chosen'),
        results: document.getElementById('chat-people-results'),
        error: document.getElementById('chat-people-error'),
        go: document.getElementById('chat-people-go'),
    };

    const browse = {
        dialog: document.getElementById('chat-browse'),
        query: document.getElementById('chat-browse-q'),
        results: document.getElementById('chat-browse-results'),
    };

    const members = {
        dialog: document.getElementById('chat-members'),
        list: document.getElementById('chat-members-list'),
        error: document.getElementById('chat-members-error'),
        count: document.getElementById('chat-members-count'),
        leave: document.getElementById('chat-members-leave'),
        join: document.getElementById('chat-members-join'),
        add: document.getElementById('chat-members-add'),
        settings: document.getElementById('chat-channel-settings'),
        settingsStatus: document.getElementById('chat-channel-settings-status'),
    };

    /**
     * The picker's own state.
     *
     * `rows` holds the fetched people and the DOM carries only their index —
     * the same reason the saved-reply picker does it: a real name with an
     * apostrophe in it breaks out of a single-quoted data attribute.
     */
    const roster = { mode: 'dm', rows: [], chosen: [], timer: null };

    function showDialog(node, on) {
        node?.classList.toggle('d-none', !on);
    }

    function dialogIsOpen(node) {
        return node !== null && node !== undefined && !node.classList.contains('d-none');
    }

    function openPeople(mode) {
        if (!pick.dialog) return;

        roster.mode = mode;
        roster.rows = [];
        roster.chosen = [];

        pick.title.textContent = mode === 'dm' ? 'New direct message' : 'Add people';
        pick.go.textContent = mode === 'dm' ? 'Start conversation' : 'Add to conversation';
        pick.query.value = '';
        pick.error.classList.add('d-none');

        renderChosen();
        showDialog(pick.dialog, true);
        pick.query.focus();
        searchPeople();
    }

    function closePeople() {
        showDialog(pick.dialog, false);
    }

    async function searchPeople() {
        const params = new URLSearchParams({ q: pick.query.value.trim() });

        if (roster.mode === 'members' && state.conversationId) {
            params.set('conversation', String(state.conversationId));
        }

        try {
            const result = await api(`${chatBase}/people?${params.toString()}`);
            roster.rows = result.people;
            renderPeopleResults();
        } catch (error) {
            pick.results.innerHTML = `<li class="p-2 text-danger">${escapeHtml(error.message)}</li>`;
        }
    }

    // Presence in the people picker, matching the DM rows: painted from the
    // online set the sidebar poll already maintains, so no new endpoint.
    // Painted at render time (open + each search); a poll landing while the
    // dialog is open does not rebuild the list under the operator's cursor.
    function presenceDot(person) {
        const id = Number(person.id);
        const online = state.onlineIds?.has(id) === true;
        const entry = state.availabilityStates?.[id];
        const mod = online ? (entry?.state ?? 'available') : 'offline';
        const label = online ? (entry?.label ?? 'Online') : 'Offline';

        return (
            `<span class="chat-presence__dot chat-presence__dot--${mod}" ` +
            `title="${escapeHtml(label)}" aria-hidden="true"></span> `
        );
    }

    function renderPeopleResults() {
        const taken = new Set(roster.chosen.map((person) => person.id));
        const rows = roster.rows
            .map((person, index) => ({ person, index }))
            .filter(({ person }) => !taken.has(person.id));

        if (rows.length === 0) {
            pick.results.innerHTML = '<li class="p-2 text-body-secondary">Nobody else to show.</li>';

            return;
        }

        pick.results.innerHTML = rows
            .map(
                ({ person, index }) =>
                    `<li role="option"><button type="button" class="chat-palette__result" data-person="${index}">` +
                    `${presenceDot(person)}` +
                    `<strong>${escapeHtml(person.name)}</strong> ` +
                    `<span class="text-body-secondary">${escapeHtml(person.email)}</span></button></li>`,
            )
            .join('');
    }

    function renderChosen() {
        pick.go.disabled = roster.chosen.length === 0;

        if (roster.mode === 'dm') {
            pick.go.textContent = roster.chosen.length > 1 ? 'Start group message' : 'Start conversation';
        }

        pick.chosen.innerHTML = roster.chosen
            .map(
                (person, index) =>
                    `<span class="badge text-bg-secondary chat-people-chip">${escapeHtml(person.name)}` +
                    `<button type="button" class="btn-close btn-close-white btn-sm" data-drop="${index}" ` +
                    `aria-label="Remove ${escapeHtml(person.name)}"></button></span>`,
            )
            .join('');
    }

    pick.results?.addEventListener('click', (event) => {
        const choice = event.target.closest('[data-person]');
        if (!choice) return;

        roster.chosen.push(roster.rows[Number(choice.dataset.person)]);
        renderChosen();
        renderPeopleResults();
        pick.query.focus();
    });

    pick.chosen?.addEventListener('click', (event) => {
        const drop = event.target.closest('[data-drop]');
        if (!drop) return;

        roster.chosen.splice(Number(drop.dataset.drop), 1);
        renderChosen();
        renderPeopleResults();
    });

    pick.query?.addEventListener('input', () => {
        clearTimeout(roster.timer);
        roster.timer = setTimeout(searchPeople, 250);
    });

    document.querySelectorAll('[data-chat-people-close]').forEach((button) => {
        button.addEventListener('click', closePeople);
    });

    pick.go?.addEventListener('click', async () => {
        if (roster.chosen.length === 0) return;

        pick.error.classList.add('d-none');
        pick.go.disabled = true;

        try {
            if (roster.mode === 'dm') {
                const result = await api(`${chatBase}/dms`, {
                    method: 'POST',
                    body: JSON.stringify({ user_ids: roster.chosen.map((person) => person.id) }),
                });

                goTo(result.conversation.id);

                return;
            }

            // One request per person: the endpoint takes a single user_id, and
            // a partly-failed batch has to be able to name who it could not add.
            for (const person of roster.chosen) {
                await api(`${chatBase}/channels/${state.conversationId}/members`, {
                    method: 'POST',
                    body: JSON.stringify({ user_id: person.id }),
                });
            }

            closePeople();
            say(`Added ${roster.chosen.length === 1 ? roster.chosen[0].name : `${roster.chosen.length} people`}.`);
            await loadMembers();
            showDialog(members.dialog, true);
        } catch (error) {
            pick.error.textContent = error.message;
            pick.error.classList.remove('d-none');
        } finally {
            pick.go.disabled = roster.chosen.length === 0;
        }
    });

    document.getElementById('chat-new-dm')?.addEventListener('click', () => openPeople('dm'));

    // --- browse channels ---------------------------------------------------

    document.getElementById('chat-browse-open')?.addEventListener('click', () => {
        showDialog(browse.dialog, true);
        browse.query.value = '';
        browse.query.focus();
        loadChannels();
    });

    document.querySelectorAll('[data-chat-browse-close]').forEach((button) => {
        button.addEventListener('click', () => showDialog(browse.dialog, false));
    });

    let browseTimer = null;
    browse.query?.addEventListener('input', () => {
        clearTimeout(browseTimer);
        browseTimer = setTimeout(loadChannels, 250);
    });

    async function loadChannels() {
        try {
            const result = await api(`${chatBase}/channels?q=${encodeURIComponent(browse.query.value.trim())}`);

            if (result.channels.length === 0) {
                browse.results.innerHTML = '<li class="p-2 text-body-secondary">No public channels to show.</li>';

                return;
            }

            browse.results.innerHTML = result.channels
                .map(
                    (channel) =>
                        '<li class="d-flex align-items-center gap-2 p-2 border-bottom">' +
                        `<span class="flex-grow-1"><strong>#${escapeHtml(channel.name)}</strong>` +
                        (channel.topic ? ` <span class="text-body-secondary">${escapeHtml(channel.topic)}</span>` : '') +
                        `<br><span class="small text-body-secondary">${channel.members} member${channel.members === 1 ? '' : 's'}</span></span>` +
                        `<button type="button" class="btn btn-sm ${channel.joined ? 'btn-outline-secondary' : 'btn-primary'}" ` +
                        `data-channel="${channel.id}" data-joined="${channel.joined ? '1' : '0'}">` +
                        `${channel.joined ? 'Open' : 'Join'}</button></li>`,
                )
                .join('');
        } catch (error) {
            browse.results.innerHTML = `<li class="p-2 text-danger">${escapeHtml(error.message)}</li>`;
        }
    }

    browse.results?.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-channel]');
        if (!button) return;

        const id = Number(button.dataset.channel);

        if (button.dataset.joined === '1') {
            goTo(id);

            return;
        }

        button.disabled = true;

        try {
            await api(`${chatBase}/channels/${id}/join`, { method: 'POST' });
            goTo(id);
        } catch (error) {
            button.disabled = false;
            toast(`Could not join that channel: ${error.message}`);
        }
    });

    // --- members and channel settings --------------------------------------

    document.getElementById('chat-members-open')?.addEventListener('click', async () => {
        showDialog(members.dialog, true);
        await loadMembers();
    });

    document.querySelectorAll('[data-chat-members-close]').forEach((button) => {
        button.addEventListener('click', () => showDialog(members.dialog, false));
    });

    async function loadMembers() {
        if (!members.list || !state.conversationId) return;

        members.error.classList.add('d-none');

        try {
            const result = await api(`${chatBase}/channels/${state.conversationId}/members`);

            members.list.innerHTML = result.members
                .map(
                    (member) =>
                        '<li class="d-flex align-items-center gap-2 p-2 border-bottom">' +
                        `<span class="flex-grow-1">${escapeHtml(member.name)}` +
                        (member.is_you ? ' <span class="small text-body-secondary">(you)</span>' : '') +
                        (member.role === 'admin' ? ' <span class="badge text-bg-light text-body">admin</span>' : '') +
                        '</span>' +
                        (result.can_manage && !member.is_you && member.user_id !== null
                            ? `<button type="button" class="btn btn-sm btn-outline-danger" data-remove="${member.user_id}" ` +
                              `aria-label="Remove ${escapeHtml(member.name)}">Remove</button>`
                            : '') +
                        '</li>',
                )
                .join('');

            members.count.textContent = String(result.members.length);
            members.add.classList.toggle('d-none', !result.can_manage);
            members.leave.classList.toggle('d-none', !result.can_leave);
            members.join.classList.toggle('d-none', !result.can_join);
        } catch (error) {
            members.list.innerHTML = `<li class="p-2 text-danger">${escapeHtml(error.message)}</li>`;
        }
    }

    members.list?.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-remove]');
        if (!button) return;

        const confirmed = await confirmAction(
            'Remove this person?',
            'They lose access to the conversation. What they have already said stays in it.',
        );
        if (!confirmed) return;

        try {
            await api(`${chatBase}/channels/${state.conversationId}/members/${button.dataset.remove}`, {
                method: 'DELETE',
            });
            await loadMembers();
        } catch (error) {
            members.error.textContent = error.message;
            members.error.classList.remove('d-none');
        }
    });

    members.add?.addEventListener('click', () => {
        showDialog(members.dialog, false);
        openPeople('members');
    });

    members.join?.addEventListener('click', async () => {
        try {
            await api(`${chatBase}/channels/${state.conversationId}/join`, { method: 'POST' });
            // Reloaded rather than patched: joining is what makes the composer
            // usable, and that is rendered server-side.
            window.location.reload();
        } catch (error) {
            members.error.textContent = error.message;
            members.error.classList.remove('d-none');
        }
    });

    members.leave?.addEventListener('click', async () => {
        const confirmed = await confirmAction(
            'Leave this conversation?',
            'It disappears from your sidebar. A public channel can be rejoined from Browse channels.',
        );
        if (!confirmed) return;

        try {
            await api(`${chatBase}/channels/${state.conversationId}/leave`, { method: 'POST' });
            window.location = chatBase;
        } catch (error) {
            members.error.textContent = error.message;
            members.error.classList.remove('d-none');
        }
    });

    members.settings?.addEventListener('submit', async (event) => {
        event.preventDefault();

        const payload = {};
        const name = document.getElementById('chat-channel-name')?.value.trim() ?? '';
        const topic = document.getElementById('chat-channel-topic');
        const purpose = document.getElementById('chat-channel-purpose');

        // An empty name is left out, not sent as "": the rule is
        // `sometimes|required`, so sending it empty is a validation error while
        // omitting it keeps an unnamed group message unnamed.
        if (name !== '') {
            payload.name = name;
        }

        if (topic) {
            payload.topic = topic.value.trim();
        }

        if (purpose) {
            payload.purpose = purpose.value.trim();
        }

        members.settingsStatus.textContent = 'Saving...';

        try {
            await api(`${chatBase}/channels/${state.conversationId}`, {
                method: 'PUT',
                body: JSON.stringify(payload),
            });
            window.location.reload();
        } catch (error) {
            members.settingsStatus.textContent = '';
            members.error.textContent = error.payload?.errors?.name?.[0] || error.message;
            members.error.classList.remove('d-none');
        }
    });

    document.getElementById('chat-unarchive')?.addEventListener('click', async () => {
        try {
            await api(`${chatBase}/channels/${state.conversationId}/unarchive`, { method: 'POST' });
            window.location.reload();
        } catch (error) {
            toast(`Could not unarchive the channel: ${error.message}`);
        }
    });

    document.getElementById('chat-archive')?.addEventListener('click', async () => {
        const confirmed = await confirmAction(
            'Archive this channel?',
            'It disappears from everyone\'s sidebar and nobody can post in it again.',
        );
        if (!confirmed) return;

        try {
            await api(`/admin/chat/channels/${state.conversationId}/archive`, { method: 'POST' });
            window.location = '/admin/chat';
        } catch (error) {
            toast(`Could not archive the channel: ${error.message}`);
        }
    });

    document.getElementById('chat-delete')?.addEventListener('click', async () => {
        const confirmed = await confirmAction(
            'Delete this channel?',
            'This permanently deletes the channel, every message in it and every attached file. This cannot be undone — archive instead if you only want it out of the way.',
        );
        if (!confirmed) return;

        try {
            await api(`${chatBase}/channels/${state.conversationId}`, { method: 'DELETE' });
            window.location = chatBase;
        } catch (error) {
            toast(`Could not delete the channel: ${error.message}`);
        }
    });

    // --- customer inbox actions -------------------------------------------

    document.querySelectorAll('[data-inbox-action]').forEach((button) => {
        button.addEventListener('click', async () => {
            const action = button.dataset.inboxAction;

            if (action === 'close'
                && !(await confirmAction('Close this conversation?', 'The customer can still read it, but nobody can post again.'))) {
                return;
            }

            try {
                const result = await api(`/admin/chat/inbox/${state.conversationId}/${action}`, { method: 'POST' });
                if (action === 'convert') {
                    say(`Created ticket ${result.ticket.ticket_no}.`);
                } else {
                    say(`Conversation ${result.conversation.status}.`);
                    refreshInboxRow(state.conversationId, result.conversation.status);
                }
            } catch (error) {
                say(error.message, 'error');
            }
        });
    });

    // --- attachments ------------------------------------------------------

    document.getElementById('chat-attach')?.addEventListener('click', () => {
        document.getElementById('chat-file').click();
    });

    document.getElementById('chat-file')?.addEventListener('change', async (event) => {
        const file = event.target.files[0];
        if (!file) return;

        say('Uploading...');

        try {
            // A file needs a message to hang off, so an empty composer posts the
            // filename as the body rather than inventing a bodiless message.
            const body = el.body.value.trim() || file.name;
            const message = await api(`/admin/chat/conversations/${state.conversationId}/messages`, {
                method: 'POST',
                body: JSON.stringify({ body }),
            });

            const form = new FormData();
            form.append('file', file);
            await api(`/admin/chat/messages/${message.message.id}/attachments`, { method: 'POST', body: form });

            const refreshed = await api(
                `/admin/chat/conversations/${state.conversationId}/messages?after_id=${message.message.id - 1}`,
            );
            refreshed.messages.forEach(upsertMessage);

            el.body.value = '';
            say('Attached.');
        } catch (error) {
            say(error.payload?.errors?.file?.[0] || error.message, 'error');
        } finally {
            event.target.value = '';
        }
    });

    // --- @mentions --------------------------------------------------------

    function handleMentionTyping() {
        if (!el.mentions) return;

        const upToCursor = el.body.value.slice(0, el.body.selectionStart);
        const match = upToCursor.match(/@([\w.-]*)$/);

        if (!match) {
            el.mentions.classList.add('d-none');
            return;
        }

        const needle = match[1].toLowerCase();
        const matches = people
            .filter((person) => person.name.toLowerCase().includes(needle))
            .slice(0, 6);

        if (matches.length === 0) {
            el.mentions.classList.add('d-none');
            return;
        }

        el.mentions.innerHTML = matches
            .map((person) => `<li role="option"><button type="button" data-mention="${escapeHtml(person.name)}">${escapeHtml(person.name)}</button></li>`)
            .join('');
        el.mentions.classList.remove('d-none');
    }

    el.mentions?.addEventListener('click', (event) => {
        const choice = event.target.closest('[data-mention]');
        if (!choice) return;

        el.body.value = el.body.value.replace(/@([\w.-]*)$/, `@${choice.dataset.mention} `);
        el.mentions.classList.add('d-none');
        el.body.focus();
    });

    // --- entity picker ----------------------------------------------------

    document.getElementById('chat-attach-entity')?.addEventListener('click', () => {
        el.entityPicker?.classList.toggle('d-none');
        el.entityQuery?.focus();
    });

    let entityTimer = null;
    el.entityQuery?.addEventListener('input', () => {
        clearTimeout(entityTimer);
        entityTimer = setTimeout(searchEntities, 250);
    });
    el.entityType?.addEventListener('change', searchEntities);

    async function searchEntities() {
        const q = el.entityQuery.value.trim();
        if (q.length < 2) {
            el.entityResults.innerHTML = '';
            return;
        }

        try {
            const result = await api(
                `/admin/chat/search-entities?q=${encodeURIComponent(q)}&type=${encodeURIComponent(el.entityType.value)}`,
            );

            el.entityResults.innerHTML = result.results
                .map(
                    (row) =>
                        `<li role="option"><button type="button" data-entity='${escapeHtml(JSON.stringify(row))}'>` +
                        `<strong>${escapeHtml(row.label)}</strong> <span class="text-body-secondary">${escapeHtml(row.subtitle ?? '')}</span></button></li>`,
                )
                .join('');
        } catch (error) {
            el.entityResults.innerHTML = `<li class="p-2 text-danger">${escapeHtml(error.message)}</li>`;
        }
    }

    el.entityResults?.addEventListener('click', (event) => {
        const choice = event.target.closest('[data-entity]');
        if (!choice) return;

        state.pendingEntities.push(JSON.parse(choice.dataset.entity));
        renderChips();
        el.entityPicker.classList.add('d-none');
        el.entityQuery.value = '';
        el.entityResults.innerHTML = '';
    });

    // --- saved replies ----------------------------------------------------

    /**
     * Two ways in, one list.
     *
     * The button opens the picker and focuses its search box. Typing `/word` at
     * the very start of an empty composer opens the same picker but leaves focus
     * where it is, so the operator can keep typing the shortcut — and Enter then
     * inserts the top match instead of sending. That interception is the point:
     * without it, an operator who types `/refund` and hits Enter out of habit
     * sends the literal text "/refund" to a customer.
     *
     * The fetched rows are kept in a JS array and the DOM carries only their
     * index. Serialising each row into a `data-` attribute (as the entity picker
     * above does) puts unescaped apostrophes from real names and real reply text
     * inside a single-quoted attribute.
     */
    const cannedUrl = root.dataset.cannedUrl || '';
    const cannedUsedUrl = root.dataset.cannedUsedUrl || '';
    const cannedManageUrl = root.dataset.cannedManageUrl || '';
    let cannedRows = [];
    let cannedTimer = null;

    /** The `/shortcut` being typed, or null when the composer is not in slash mode. */
    function slashToken() {
        const match = /^\/([\w-]*)$/.exec(el.body?.value ?? '');

        return match === null ? null : match[1];
    }

    function closeCanned() {
        el.cannedPicker?.classList.add('d-none');
        cannedRows = [];
    }

    function renderCanned(rows, libraryHasAny = true) {
        cannedRows = rows;

        if (!el.cannedResults) return;

        if (rows.length === 0) {
            // "Nothing matched" and "nothing exists" are different problems
            // with different next steps, and only one of them is the
            // operator's search. Blaming the filter for an empty library left
            // them with no way forward.
            el.cannedResults.innerHTML = libraryHasAny
                ? '<li class="p-2 text-body-secondary">No saved replies match.</li>'
                : `<li class="p-2 text-body-secondary">No saved replies yet.${cannedManageUrl ? ` <a href="${cannedManageUrl}">Create one</a> and it can be inserted with <code>/shortcut</code>.` : ''}</li>`;

            return;
        }

        el.cannedResults.innerHTML = rows
            .map(
                (row, index) =>
                    `<li role="option"><button type="button" data-canned-index="${index}">` +
                    `<strong>${escapeHtml(row.title)}</strong>` +
                    (row.shortcut ? ` <code>/${escapeHtml(row.shortcut)}</code>` : '') +
                    `<span class="chat-canned__preview text-body-secondary">${escapeHtml(row.body.slice(0, 90))}</span>` +
                    '</button></li>',
            )
            .join('');
    }

    async function loadCanned(query) {
        if (!cannedUrl || !el.cannedPicker) return;

        try {
            const result = await api(`${cannedUrl}?q=${encodeURIComponent(query)}`);
            renderCanned(result.replies ?? [], result.any !== false);
        } catch (error) {
            cannedRows = [];
            el.cannedResults.innerHTML = `<li class="p-2 text-danger">${escapeHtml(error.message)}</li>`;
        }
    }

    function insertCanned(row) {
        if (!row || !el.body) return;

        // In slash mode the token IS the whole composer, so the reply replaces
        // it. Otherwise the reply is appended to whatever has been written,
        // because the operator opened the picker deliberately mid-sentence.
        if (slashToken() !== null) {
            el.body.value = row.body;
        } else {
            const current = el.body.value;
            el.body.value = current === '' ? row.body : `${current.replace(/\s*$/, '')} ${row.body}`;
        }

        closeCanned();
        el.body.focus();

        // Bookkeeping, fired and forgotten: the count tells an admin which
        // snippets earn their place, and a lost increment costs nothing.
        if (cannedUsedUrl) {
            api(cannedUsedUrl.replace('__ID__', String(row.id)), { method: 'POST' }).catch(() => {});
        }
    }

    document.getElementById('chat-canned-open')?.addEventListener('click', () => {
        if (!el.cannedPicker) return;

        const opening = el.cannedPicker.classList.contains('d-none');

        el.cannedPicker.classList.toggle('d-none');

        if (opening) {
            el.cannedQuery.value = '';
            el.cannedQuery.focus();
            loadCanned('');
        }
    });

    el.cannedQuery?.addEventListener('input', () => {
        clearTimeout(cannedTimer);
        cannedTimer = setTimeout(() => loadCanned(el.cannedQuery.value.trim()), 200);
    });

    el.cannedResults?.addEventListener('click', (event) => {
        const choice = event.target.closest('[data-canned-index]');
        if (!choice) return;

        insertCanned(cannedRows[Number(choice.dataset.cannedIndex)]);
    });

    function handleCannedTyping() {
        const token = slashToken();

        if (token === null) {
            if (!el.cannedPicker?.classList.contains('d-none')) {
                closeCanned();
            }

            return;
        }

        el.cannedPicker?.classList.remove('d-none');

        clearTimeout(cannedTimer);
        cannedTimer = setTimeout(() => loadCanned(token), 200);
    }

    // --- availability -----------------------------------------------------

    /**
     * The operator's own Available / Away / Busy.
     *
     * The consequence is reported back, not just the new state: an operator who
     * was the last one accepting has, by going Away, just closed the chat to
     * customers. That is a decision they should be able to see they made.
     *
     * A failed request reverts the control. Leaving it showing "Away" while the
     * server still has "Available" is the worst outcome — the operator believes
     * they are off the queue and keeps receiving customers.
     */
    el.availability?.addEventListener('change', async () => {
        const url = root.dataset.availabilityUrl;
        const chosen = el.availability.value;
        const previous = el.availability.dataset.current || chosen;

        if (!url) return;

        try {
            const result = await api(url, {
                method: 'POST',
                body: JSON.stringify({ state: chosen }),
            });

            el.availability.dataset.current = result.state;

            if (el.availabilityStatus) {
                el.availabilityStatus.textContent =
                    result.accepting_operators === 0
                        ? 'Nobody is accepting chats.'
                        : `${result.accepting_operators} accepting chats.`;
            }
        } catch (error) {
            el.availability.value = previous;
            say(`Could not change your availability: ${error.message}`, 'error');
        }
    });

    if (el.availability) {
        el.availability.dataset.current = el.availability.value;
    }

    function renderChips() {
        if (!el.chips) return;

        el.chips.innerHTML = state.pendingEntities
            .map(
                (entity, index) =>
                    `<span class="chat-chip">${escapeHtml(entity.type)}: ${escapeHtml(entity.label)}` +
                    `<button type="button" class="chat-chip__remove" data-remove-chip="${index}" aria-label="Remove">&times;</button></span>`,
            )
            .join('');
    }

    el.chips?.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-chip]');
        if (!remove) return;

        state.pendingEntities.splice(Number(remove.dataset.removeChip), 1);
        renderChips();
    });

    // --- polling fallback ---------------------------------------------------

    /** How often the fallback asks for new messages, in milliseconds. */
    const POLL_EVERY = 5000;

    // Sidebar data (unread, presence, inbox) rides the same cadence as the
    // message poll. The presence *write* stays on the slow heartbeat below —
    // the roster only needs to know I'm here every 30s, but my screen should
    // learn about everyone else every 5s.
    const SIDEBAR_SYNC_EVERY = 5000;

    state.lastMessageId = Array.from(el.list?.querySelectorAll('[data-message-id]') ?? []).reduce(
        (highest, row) => Math.max(highest, Number(row.dataset.messageId) || 0),
        0,
    );

    async function pollOnce() {
        if (!state.conversationId) return;

        try {
            const result = await api(
                `/admin/chat/conversations/${state.conversationId}/messages?after_id=${state.lastMessageId}`,
            );
            result.messages.forEach(upsertMessage);
        } catch {
            // The banner already explains why the room is quiet; a toast on
            // every failed poll would be a toast every five seconds.
        }
    }

    /**
     * Exactly one interval can exist: the guard on `pollTimer` is what stops a
     * disconnect/reconnect cycle from stacking timers, and a hidden tab never
     * starts one at all.
     */
    function startPolling() {
        if (state.pollTimer !== null || !state.conversationId || document.hidden) return;

        state.pollTimer = setInterval(pollOnce, POLL_EVERY);
        pollOnce();
    }

    function stopPolling() {
        if (state.pollTimer === null) return;

        clearInterval(state.pollTimer);
        state.pollTimer = null;
    }

    function setFallback(active, subtle = false) {
        state.fallback = active;
        el.banner?.classList.toggle('d-none', !active);
        el.banner?.classList.toggle('is-subtle', active && subtle);

        if (active && el.banner) {
            el.banner.textContent = subtle
                ? 'Live updates every 5s — realtime off'
                : 'Realtime disconnected — polling';
        }

        if (active) {
            startPolling();
        } else {
            stopPolling();
        }
    }

    // A backgrounded tab must not poll forever. It resumes on the way back in,
    // and only if the websocket is still down.
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stopPolling();
        } else if (state.fallback) {
            startPolling();
        }
    });

    /**
     * `connecting` and `initialized` are the transient states of a socket that
     * has not answered yet: they leave the current mode alone rather than
     * flashing the banner on every page load. Anything that is not `connected`
     * after that means fall back.
     */
    onConnectionStateChange((connectionState) => {
        if (connectionState === 'connected') {
            setFallback(false);
            return;
        }

        if (connectionState === 'connecting' || connectionState === 'initialized') {
            return;
        }

        // Reverb was never configured on this install: polling is the
        // expected mode, not an outage, so show the subtle indicator.
        // A configured socket that drops gets the warning variant.
        setFallback(true, !realtime.enabled);
    });

    // --- realtime ---------------------------------------------------------

    if (realtime.enabled && state.conversationId) {
        realtime.echo
            .private(`chat.conversation.${state.conversationId}`)
            .listen('.chat.message.new', (event) => upsertMessage(event.message))
            .listen('.chat.message.edited', (event) => upsertMessage(event.message))
            .listen('.chat.message.deleted', (event) => {
                const row = document.getElementById(`chat-message-${event.id}`);
                if (!row) return;
                row.classList.add('is-deleted');
                row.querySelector('.chat-message__body').textContent = '[deleted]';
                row.querySelector('.chat-message__actions')?.remove();
            })
            .listen('.chat.reaction.toggled', (event) =>
                applyReaction(event.message_id, event.emoji, event.count),
            );

        realtime.echo
            .join(`chat.typing.${state.conversationId}`)
            .listen('.chat.typing', (event) => {
                if (event.user_id !== state.userId) showTyping(event.user_name, event.typing);
            });

        realtime.echo
            .join('chat.presence')
            .here((users) => users.forEach((user) => addPresence(user.id, user.name)))
            .joining((user) => addPresence(user.id, user.name))
            .leaving((user) => removePresence(user.id));
    }

    // Not gated on `state.conversationId`: an operator staring at an empty
    // chat page is exactly who needs to be told a customer is waiting.
    if (realtime.enabled && state.canOperate) {
        realtime.echo
            .private('chat.inbox')
            .listen('.chat.inbox.waiting', (event) => noteWaiting(event.conversation, true));
    }

    state.onlineIds = new Set(readJson('chat-online', []).map(Number));
    state.availabilityStates = {};

    function paintDmDots() {
        document.querySelectorAll('[data-dm-user]').forEach((dot) => {
            const id = Number(dot.dataset.dmUser);
            const online = state.onlineIds.has(id);
            const entry = state.availabilityStates?.[id];
            const mod = online ? (entry?.state ?? 'available') : 'offline';
            const label = online ? (entry?.label ?? 'Online') : 'Offline';

            dot.className = `chat-presence__dot chat-presence__dot--${mod}`;
            dot.title = label;
            const sr = dot.parentElement?.querySelector('[data-dm-state]');

            if (sr) sr.textContent = ` — ${label}`;
        });
    }

    function addPresence(id) {
        state.onlineIds.add(Number(id));
        paintDmDots();
    }

    function removePresence(id) {
        state.onlineIds.delete(Number(id));
        paintDmDots();
    }

    // --- sidebar sync -------------------------------------------------------

    // Seed from what the server already rendered, BEFORE the first poll runs.
    // Without this every queued room in the sidebar is announced as a new
    // arrival on every page load — chime, title counter and all — because the
    // poll cannot otherwise tell "waiting since yesterday" from "just arrived".
    document
        .querySelectorAll('.chat-sidebar__group[data-group="inbox"] [data-conversation-id]')
        .forEach((row) => state.knownConversations.add(Number(row.dataset.conversationId)));

    /**
     * Everything the chat subscribes to over the websocket is scoped to the one
     * conversation that is open. The sidebar is not: its unread badges, its DM
     * presence dots and its customer queue all describe rooms the client has no
     * subscription to, so nothing but this poll ever moves them. It runs every
     * SIDEBAR_SYNC_EVERY whether or not Echo is connected, because the default
     * install ships BROADCAST_CONNECTION=log and never connects at all.
     */
    async function syncSidebar() {
        let payload;

        try {
            payload = await api('/admin/chat/unread');
        } catch {
            // Silent by design: this runs every heartbeat, and a panel whose
            // session has expired would otherwise toast once every 30 seconds.
            return;
        }

        applyUnread(payload.unread ?? {});
        applyPresence(payload.online ?? []);
        applyAvailabilityStates(payload.availability ?? {});
        (payload.inbox ?? []).forEach((conversation) => noteWaiting(conversation, true));
        await syncInboxStatuses();
    }

    // Status transitions made by OTHER operators never arrive as events — the
    // waiting-only poll above only announces new arrivals. So once per
    // heartbeat the full inbox list is reconciled: badge repaints and section
    // moves for rows already on screen, silent inserts for rooms that appeared
    // straight into active. Never deletes: the list endpoint is capped, and a
    // cap is not proof a room is gone.
    async function syncInboxStatuses() {
        if (!state.canOperate) return;

        const group = inboxGroup();
        if (!group) return;

        let payload;

        try {
            payload = await api(`${chatBase}/inbox`);
        } catch {
            return;
        }

        (payload.conversations ?? []).forEach((conversation) => {
            const id = Number(conversation.id);
            if (!id) return;

            const row = group.querySelector(`[data-conversation-id="${id}"]`);

            if (!row) {
                if (conversation.status === 'waiting' || conversation.status === 'active') {
                    state.knownConversations.add(id);
                    insertInboxRow(conversation);
                }
                return;
            }

            if (rowStatus(row) !== conversation.status) {
                refreshInboxRow(id, conversation.status);
            }
        });
    }

    function applyAvailabilityStates(states) {
        state.availabilityStates = states ?? {};
        paintDmDots();
    }

    function applyUnread(counts) {
        document.querySelectorAll('[data-unread-for]').forEach((badge) => {
            // The open conversation is read by definition — the server marked it
            // read when it rendered the page — so never badge the room the
            // operator is currently looking at.
            const id = Number(badge.dataset.unreadFor);
            const count = id === state.conversationId ? 0 : Number(counts[id] ?? 0);

            badge.textContent = String(count);
            badge.classList.toggle('d-none', count === 0);
        });
    }

    function applyPresence(people) {
        state.onlineIds = new Set((people ?? []).map((person) => Number(person.id)));
        paintDmDots();
    }

    /**
     * Put a queued customer conversation in the sidebar, and say so out loud if
     * this is the first time we have seen it.
     *
     * Both the websocket event and the poll land here, so a conversation that
     * arrives on the socket and then shows up in the next poll is announced
     * once — `knownConversations` is the dedupe, not the transport.
     */
    function noteWaiting(conversation, announce) {
        if (!conversation) return;

        const id = Number(conversation.id);
        if (!id || state.knownConversations.has(id)) return;

        state.knownConversations.add(id);
        insertInboxRow(conversation);

        if (!announce) return;

        state.queued += 1;
        markTitle();
        say(`${conversation.name} is waiting in the queue.`);
        chime();
    }

    function inboxGroup() {
        return document.querySelector('.chat-sidebar__group[data-group="inbox"]');
    }

    function statusBadgeClass(status) {
        return status === 'waiting' ? 'warning' : (status === 'active' ? 'success' : 'secondary');
    }

    function rowStatus(row) {
        return row.dataset.status ?? row.querySelector('.chat-sidebar__status')?.textContent.trim() ?? '';
    }

    function ensureClosedDetails(group) {
        let details = group.querySelector('details[data-closed-group]');

        if (!details) {
            details = document.createElement('details');
            details.className = 'chat-inbox-closed';
            details.dataset.closedGroup = '';
            const summary = document.createElement('summary');
            summary.className = 'chat-sidebar__empty chat-inbox-closed__summary';
            details.append(summary);
            group.append(details);
        }

        return details;
    }

    function updateClosedCount(group) {
        const details = group.querySelector('details[data-closed-group]');
        if (!details) return;

        const rows = details.querySelectorAll('.chat-sidebar__item');
        const summary = details.querySelector('summary');
        if (summary) summary.textContent = `Closed (${rows.length})`;
        if (rows.length === 0) details.remove();
    }

    function buildInboxRow(conversation) {
        const status = conversation.status ?? 'waiting';
        const meta = [conversation.department, `#${conversation.id}`].filter(Boolean).join(' · ');

        const link = document.createElement('a');
        link.className = 'chat-sidebar__item';
        link.href = `${chatBase}?c=${conversation.id}`;
        link.dataset.conversationId = conversation.id;
        link.dataset.status = status;
        link.dataset.name =
            `${conversation.name ?? ''} ${conversation.department ?? ''} ${status} ${conversation.id}`.trim().toLowerCase();
        link.innerHTML =
            '<span class="chat-sidebar__icon" aria-hidden="true"><i class="bi bi-life-preserver"></i></span>' +
            '<span class="chat-sidebar__text">' +
            `<span class="chat-sidebar__name">${escapeHtml(conversation.name ?? '')}</span>` +
            (meta !== '' ? `<span class="chat-sidebar__meta">${escapeHtml(meta)}</span>` : '') +
            '</span>' +
            `<span class="badge chat-sidebar__status text-bg-${statusBadgeClass(status)}">${escapeHtml(status)}</span>` +
            `<span class="badge text-bg-danger chat-unread d-none" data-unread-for="${conversation.id}">0</span>`;

        if (Number(conversation.id) === state.conversationId) link.classList.add('is-active');

        return link;
    }

    // Waiting rows go first (newest on top), then active, then the Closed
    // archive — mirroring the server render, so a poll never un-sorts it.
    function placeInboxRow(group, link, status) {
        link.dataset.status = status;

        if (status === 'closed') {
            ensureClosedDetails(group).append(link);
            updateClosedCount(group);
            return;
        }

        const heading = group.querySelector('.chat-sidebar__heading');
        const openRows = Array.from(group.querySelectorAll(':scope > .chat-sidebar__item'));

        if (status === 'waiting' || openRows.length === 0 || !heading) {
            heading ? heading.after(link) : group.prepend(link);
            return;
        }

        const lastWaiting = openRows.filter((row) => rowStatus(row) === 'waiting').pop();
        (lastWaiting ?? heading).after(link);
    }

    function insertInboxRow(conversation) {
        const group = inboxGroup();
        if (!group || group.querySelector(`[data-conversation-id="${conversation.id}"]`)) return;

        group.querySelector('.chat-sidebar__empty')?.remove();
        placeInboxRow(group, buildInboxRow(conversation), conversation.status ?? 'waiting');
        updateClosedCount(group);
    }

    // A status change (Take/Close, by me or anyone) moves the row and repaints
    // its badge in place — the sidebar never needs a reload to tell the truth.
    function refreshInboxRow(id, status) {
        const group = inboxGroup();
        const row = group?.querySelector(`[data-conversation-id="${id}"]`);
        if (!group || !row) return;

        const badge = row.querySelector('.chat-sidebar__status');
        if (badge) {
            badge.textContent = status;
            badge.className = `badge chat-sidebar__status text-bg-${statusBadgeClass(status)}`;
        }

        placeInboxRow(group, row, status);
        updateClosedCount(group);
    }

    const baseTitle = document.title;

    function markTitle() {
        document.title = state.queued > 0 ? `(${state.queued}) ${baseTitle}` : baseTitle;
    }

    function clearTitle() {
        state.queued = 0;
        markTitle();
    }

    window.addEventListener('focus', clearTitle);

    /**
     * A short tone for a newly queued customer.
     *
     * Synthesised rather than an audio file: it needs no asset, no preload and
     * no addition to the committed build output. Failure is ignored on purpose
     * — a browser that blocks audio before a user gesture throws here, and a
     * missing chime must not take the queue notification down with it.
     */
    function chime() {
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;

            const ctx = new Ctx();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();

            osc.type = 'sine';
            osc.frequency.value = 880;
            gain.gain.setValueAtTime(0.0001, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.08, ctx.currentTime + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.35);

            osc.connect(gain).connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.36);
            osc.onended = () => ctx.close();
        } catch {
            // See the docblock.
        }
    }

    // The server-side roster is what a page with no websocket sees, so it is
    // kept warm regardless of whether Echo connected.
    function heartbeat() {
        if (document.hidden) return;
        api('/admin/chat/presence', { method: 'POST', body: JSON.stringify({ online: true }) }).catch(() => {});
        syncSidebar();
    }

    // --- search -----------------------------------------------------------

    const search = {
        page: 1,
        lastPage: 1,
        running: false,
    };

    function openSearch(prefill = '') {
        if (!el.search) return;
        closeSwitcher();
        el.search.classList.remove('d-none');
        if (prefill) el.searchQuery.value = prefill;
        el.searchQuery.focus();
        el.searchQuery.select();
    }

    function closeSearch() {
        el.search?.classList.add('d-none');
    }

    function searchIsOpen() {
        return el.search !== null && !el.search.classList.contains('d-none');
    }

    async function runSearch(page = 1) {
        if (!el.searchResults || search.running) return;

        const q = el.searchQuery.value.trim();

        if (q.length < 2) {
            el.searchResults.innerHTML = '';
            el.searchPager?.classList.add('d-none');
            el.searchStatus.textContent = 'Search for at least two characters.';
            return;
        }

        const params = new URLSearchParams({ q, page: String(page) });
        if (el.searchChannel?.value) params.set('channel', el.searchChannel.value);
        if (el.searchFrom?.value) params.set('from', el.searchFrom.value);
        if (el.searchTo?.value) params.set('to', el.searchTo.value);

        search.running = true;
        el.searchStatus.textContent = 'Searching...';

        try {
            const result = await api(`/admin/chat/search?${params.toString()}`);

            search.page = result.current_page;
            search.lastPage = result.last_page;

            renderSearchResults(result, q);
        } catch (error) {
            el.searchResults.innerHTML = '';
            el.searchPager?.classList.add('d-none');
            // A 422 carries per-field messages; anything else carries one.
            const detail = error.payload?.errors
                ? Object.values(error.payload.errors).flat().join(' ')
                : error.message;
            el.searchStatus.textContent = detail;
        } finally {
            search.running = false;
        }
    }

    function renderSearchResults(result, q) {
        el.searchResults.innerHTML = '';

        if (result.total === 0) {
            el.searchStatus.textContent = `No messages match "${q}".`;
            el.searchPager?.classList.add('d-none');
            return;
        }

        const first = (result.current_page - 1) * result.per_page + 1;
        const last = first + result.results.length - 1;
        el.searchStatus.textContent = `${first}-${last} of ${result.total} matching "${q}".`;

        result.results.forEach((hit) => {
            const li = document.createElement('li');
            const link = document.createElement('a');
            link.className = 'chat-palette__result';
            link.href = hit.url;
            link.innerHTML = `
                <span class="chat-palette__meta">
                    ${escapeHtml(hit.conversation_name)}${hit.conversation_archived ? ' (archived)' : ''}
                    &middot; ${escapeHtml(hit.author_name)}
                    &middot; ${escapeHtml(formatHitDate(hit.created_at))}
                </span>
                <span>${highlight(hit.body, q)}</span>`;
            li.append(link);
            el.searchResults.append(li);
        });

        if (result.last_page > 1) {
            el.searchPager.classList.remove('d-none');
            el.searchPageLabel.textContent = `Page ${result.current_page} of ${result.last_page}`;
            el.searchPager.querySelector('[data-search-page="prev"]').disabled = result.current_page <= 1;
            el.searchPager.querySelector('[data-search-page="next"]').disabled = result.current_page >= result.last_page;
        } else {
            el.searchPager?.classList.add('d-none');
        }
    }

    function formatHitDate(iso) {
        if (!iso) return '';
        const date = new Date(iso);
        return Number.isNaN(date.getTime()) ? '' : date.toLocaleString();
    }

    /**
     * Wrap the matched run in <mark>.
     *
     * The body is escaped FIRST and the needle is escaped the same way before
     * being looked for, so the only markup this can ever produce is the <mark>
     * pair it inserts itself — a message body can never smuggle a tag in here.
     * The needle is located by indexOf rather than a RegExp so that a query full
     * of regex metacharacters is matched literally, exactly as the server
     * matched it.
     */
    function highlight(body, q) {
        const safeBody = escapeHtml(body);
        const safeNeedle = escapeHtml(q);
        const at = safeBody.toLowerCase().indexOf(safeNeedle.toLowerCase());

        if (at === -1 || safeNeedle === '') return safeBody;

        return (
            safeBody.slice(0, at) +
            '<mark>' +
            safeBody.slice(at, at + safeNeedle.length) +
            '</mark>' +
            safeBody.slice(at + safeNeedle.length)
        );
    }

    el.searchOpen?.addEventListener('click', () => openSearch());

    el.searchForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        runSearch(1);
    });

    el.searchPager?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-search-page]');
        if (!button) return;
        runSearch(button.dataset.searchPage === 'next' ? search.page + 1 : search.page - 1);
    });

    document.querySelectorAll('[data-chat-search-close]').forEach((button) => {
        button.addEventListener('click', closeSearch);
    });

    // --- conversation switcher (Ctrl/Cmd+K) -------------------------------

    function switcherIsOpen() {
        return el.switcher !== null && !el.switcher.classList.contains('d-none');
    }

    function openSwitcher() {
        if (!el.switcher) return;
        closeSearch();
        el.switcher.classList.remove('d-none');
        el.switcherInput.value = '';
        renderSwitcher('');
        el.switcherInput.focus();
    }

    function closeSwitcher() {
        el.switcher?.classList.add('d-none');
    }

    function renderSwitcher(needle) {
        if (!el.switcherResults) return;

        el.switcherResults.innerHTML = '';

        // Built from the sidebar links already on the page — one source of
        // truth for what this user may open.
        const matches = Array.from(document.querySelectorAll('.chat-sidebar__item')).filter(
            (item) => needle === '' || item.dataset.name.includes(needle),
        );

        matches.slice(0, 20).forEach((item, index) => {
            const li = document.createElement('li');
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', index === 0 ? 'true' : 'false');

            const link = document.createElement('a');
            link.className = `chat-palette__result ${index === 0 ? 'is-active' : ''}`;
            link.href = item.href;
            link.textContent = item.querySelector('.chat-sidebar__name')?.textContent.trim() ?? '';

            li.append(link);
            el.switcherResults.append(li);
        });

        if (matches.length === 0) {
            const li = document.createElement('li');
            li.className = 'small text-body-secondary p-2';
            li.textContent = 'No conversation matches.';
            el.switcherResults.append(li);
        }
    }

    function moveSwitcherCursor(step) {
        const options = Array.from(el.switcherResults.querySelectorAll('.chat-palette__result'));
        if (options.length === 0) return;

        const current = options.findIndex((option) => option.classList.contains('is-active'));
        const next = Math.min(Math.max((current === -1 ? 0 : current) + step, 0), options.length - 1);

        options.forEach((option, index) => {
            option.classList.toggle('is-active', index === next);
            option.closest('li')?.setAttribute('aria-selected', index === next ? 'true' : 'false');
        });

        options[next].scrollIntoView({ block: 'nearest' });
    }

    el.switcherInput?.addEventListener('input', () => renderSwitcher(el.switcherInput.value.trim().toLowerCase()));

    el.switcherInput?.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            moveSwitcherCursor(1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            moveSwitcherCursor(-1);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            el.switcherResults.querySelector('.chat-palette__result.is-active')?.click();
        }
    });

    document.querySelectorAll('[data-chat-switcher-close]').forEach((button) => {
        button.addEventListener('click', closeSwitcher);
    });

    // --- keyboard shortcuts -----------------------------------------------

    /**
     * Is the user typing into something?
     *
     * THE guard for this whole section. Without it, `e` pressed mid-word in the
     * composer stops being the letter "e" and starts editing a message, which is
     * the classic way a shortcut layer ruins a chat client. Checked on the event
     * target, and also on document.activeElement so a keystroke that arrives
     * while focus sits in a field (an IME composing, say) is still treated as
     * typing.
     *
     * `isContentEditable` covers rich-text hosts; the `[contenteditable]`
     * ancestor check covers a click landing on a child node inside one.
     */
    function isTypingTarget(node) {
        if (!node || node.nodeType !== 1) return false;

        const tag = node.tagName;

        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
        if (node.isContentEditable) return true;

        return node.closest('[contenteditable]:not([contenteditable="false"])') !== null;
    }

    function isTyping(event) {
        return isTypingTarget(event.target) || isTypingTarget(document.activeElement);
    }

    function messageRows() {
        return Array.from(el.list?.querySelectorAll('.chat-message') ?? []);
    }

    function cursorRow() {
        return el.list?.querySelector('.chat-message.is-cursor') ?? null;
    }

    function moveCursor(step) {
        const rows = messageRows();
        if (rows.length === 0) return;

        const current = rows.indexOf(cursorRow());
        // No cursor yet: `j` starts at the newest message, `k` at the oldest,
        // so the first keypress always moves into the list from the right end.
        const next =
            current === -1
                ? step > 0
                    ? rows.length - 1
                    : 0
                : Math.min(Math.max(current + step, 0), rows.length - 1);

        rows.forEach((row) => row.classList.remove('is-cursor'));
        rows[next].classList.add('is-cursor');
        rows[next].scrollIntoView({ block: 'nearest' });
    }

    function actOnCursor(action) {
        const row = cursorRow();
        if (!row) return;

        // Reuse the buttons rather than reimplementing what they do: if a
        // message has no edit button (someone else wrote it, or it is deleted)
        // there is nothing to click, and `e` correctly does nothing.
        row.querySelector(`[data-action="${action}"]`)?.click();
    }

    document.addEventListener('keydown', (event) => {
        // Ctrl/Cmd+K is the one shortcut that fires while typing — it is how
        // you leave the composer for another room, and every chat client binds
        // it that way. Nothing else in this handler runs against a text field.
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            // An open command palette owns the keystroke: skipping here (rather
            // than toggling) guarantees one press never opens both overlays.
            const palette = document.getElementById('adminlteCommandPalette');
            if (palette && !palette.hidden) return;

            event.preventDefault();
            switcherIsOpen() ? closeSwitcher() : openSwitcher();
            return;
        }

        if (event.key === 'Escape') {
            if (switcherIsOpen()) closeSwitcher();
            else if (searchIsOpen()) closeSearch();
            else if (dialogIsOpen(pick.dialog)) closePeople();
            else if (dialogIsOpen(browse.dialog)) showDialog(browse.dialog, false);
            else if (dialogIsOpen(members.dialog)) showDialog(members.dialog, false);
            return;
        }

        if (isTyping(event)) return;

        // A modified keystroke belongs to the browser or the OS.
        if (event.metaKey || event.ctrlKey || event.altKey) return;

        switch (event.key) {
            case 'j':
                event.preventDefault();
                moveCursor(1);
                break;
            case 'k':
                event.preventDefault();
                moveCursor(-1);
                break;
            case 'r':
                event.preventDefault();
                actOnCursor('thread');
                break;
            case 'e':
                event.preventDefault();
                actOnCursor('edit');
                break;
            case '/':
                event.preventDefault();
                openSearch();
                break;
            default:
                break;
        }
    });

    // --- arriving from a search result -------------------------------------

    /**
     * `?m=` names a message to reveal. It may be older than the 50 rendered
     * server-side, so page backwards looking for it — bounded, because a hit
     * from three years ago is not worth walking the whole history for.
     */
    async function revealRequestedMessage() {
        const wanted = Number(new URLSearchParams(window.location.search).get('m'));
        if (!wanted || !el.list) return;

        for (let attempt = 0; attempt < 10; attempt++) {
            const row = el.list.querySelector(`[data-message-id="${wanted}"]`);

            if (row) {
                row.classList.add('is-found', 'is-cursor');
                row.scrollIntoView({ block: 'center' });
                return;
            }

            const gained = await loadOlderPage();
            if (!gained) break;
        }

        say('That message is further back in the history.');
    }

    revealRequestedMessage();

    heartbeat();
    setInterval(heartbeat, state.heartbeat * 1000);
    setInterval(() => {
        if (!document.hidden) syncSidebar();
    }, SIDEBAR_SYNC_EVERY);

    window.addEventListener('beforeunload', () => {
        navigator.sendBeacon?.(
            '/admin/chat/presence',
            new Blob([JSON.stringify({ online: false, _token: csrf })], { type: 'application/json' }),
        );
    });

    scrollToBottom();
}
