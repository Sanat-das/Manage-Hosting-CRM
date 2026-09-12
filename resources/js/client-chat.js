/**
 * The customer-facing chat widget.
 *
 * Its own bundle, not part of the admin chat: this file ships on public and
 * client-portal pages, where the admin bundle has no business being, and it
 * talks to a different, much smaller set of endpoints. Vanilla DOM for the same
 * reason the admin pane is — there is no framework anywhere else in this app.
 *
 * Everything it can reach is one conversation: the one whose token this browser
 * holds. There is no endpoint that lists conversations, so there is nothing
 * here that could enumerate them even by accident.
 *
 * Message bodies are inserted as `body_html`, which the server has already
 * escaped and sanitised (App\Support\ChatBodyHtml). Every other piece of
 * server data — names, filenames, entity-card labels — goes in as textContent.
 * Nothing typed by a person is ever concatenated into a markup string here.
 */
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/** How often to poll when the websocket is unavailable. */
const POLL_MS = 6000;

/** Quiet period before "stopped typing" goes out. */
const TYPING_IDLE_MS = 2500;

const root = document.getElementById('client-chat');

if (root) {
    boot(root);
}

function boot(el) {
    const state = {
        conversationId: Number(el.dataset.conversationId) || null,
        token: el.dataset.token || '',
        status: null,
        lastId: 0,
        open: false,
        unread: 0,
        typingSentAt: 0,
        channel: null,
        poller: null,
    };

    const startUrl = el.dataset.startUrl;
    const guestAuthUrl = el.dataset.guestAuthUrl;
    const urlTemplate = el.dataset.conversationUrlTemplate;

    const ui = {
        launcher: document.getElementById('client-chat-launcher'),
        panel: document.getElementById('client-chat-panel'),
        minimise: document.getElementById('client-chat-minimise'),
        unread: document.getElementById('client-chat-unread'),
        statusLabel: document.getElementById('client-chat-status'),
        intro: document.getElementById('client-chat-intro'),
        introError: document.getElementById('client-chat-intro-error'),
        messages: document.getElementById('client-chat-messages'),
        composer: document.getElementById('client-chat-composer'),
        body: document.getElementById('client-chat-body'),
        file: document.getElementById('client-chat-file'),
        filename: document.getElementById('client-chat-filename'),
        error: document.getElementById('client-chat-error'),
        rating: document.getElementById('client-chat-rating'),
        thanks: document.getElementById('client-chat-thanks'),
    };

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    /** The base URL for this conversation's endpoints. */
    const base = (id) => urlTemplate.replace('__ID__', String(id)).replace(/\/messages$/, '');

    async function call(url, { method = 'GET', body = null, json = true } = {}) {
        const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

        if (csrf) {
            headers['X-CSRF-TOKEN'] = csrf;
        }

        // The token travels in a header, never in the body: the session already
        // holds it in the ordinary case, and a header is not something a form
        // post from another origin can set.
        if (state.token) {
            headers['X-Chat-Token'] = state.token;
        }

        if (json && body !== null) {
            headers['Content-Type'] = 'application/json';
        }

        const response = await fetch(url, {
            method,
            headers,
            credentials: 'same-origin',
            body: body === null ? undefined : json ? JSON.stringify(body) : body,
        });

        let payload = null;

        try {
            payload = await response.json();
        } catch {
            payload = null;
        }

        return { ok: response.ok, status: response.status, payload };
    }

    // --- rendering ---------------------------------------------------------

    function renderMessage(message) {
        if (message.id <= state.lastId) {
            return;
        }

        state.lastId = message.id;

        const item = document.createElement('li');
        item.className = `client-chat__message client-chat__message--${message.is_guest ? 'mine' : 'theirs'}`;

        const who = document.createElement('span');
        who.className = 'client-chat__author';
        who.textContent = message.is_guest ? 'You' : message.author_name;
        item.appendChild(who);

        const bubble = document.createElement('div');
        bubble.className = 'client-chat__bubble';
        // Server-rendered and server-escaped; see the file docblock.
        bubble.innerHTML = message.body_html;
        item.appendChild(bubble);

        (message.attachments || []).forEach((attachment) => {
            const link = document.createElement('a');
            link.className = 'client-chat__attachment';
            link.href = attachment.url;
            link.rel = 'noopener';
            link.textContent = `${attachment.filename} (${attachment.size})`;
            item.appendChild(link);
        });

        // Entity cards are read-only here by construction: the payload carries a
        // type and a label and no URL, and the widget has no endpoint that
        // could create one.
        (message.entity_links || []).forEach((link) => {
            const card = document.createElement('span');
            card.className = 'client-chat__entity';
            card.textContent = `${link.type}: ${link.label}`;
            item.appendChild(card);
        });

        ui.messages.appendChild(item);
        ui.messages.scrollTop = ui.messages.scrollHeight;

        if (!state.open && !message.is_guest) {
            state.unread += 1;
            ui.unread.textContent = String(state.unread);
            ui.unread.classList.remove('d-none');
        }
    }

    function applyStatus(status, rating) {
        state.status = status;
        ui.statusLabel.textContent = status === 'waiting'
            ? 'Waiting for an agent'
            : status === 'active' ? 'Connected' : 'Closed';

        const closed = status === 'closed';
        ui.composer.classList.toggle('d-none', closed);
        ui.rating.classList.toggle('d-none', !closed || Boolean(rating));

        if (rating) {
            ui.thanks.classList.remove('d-none');
        }
    }

    function showError(node, message) {
        node.textContent = message;
        node.classList.remove('d-none');
    }

    // --- conversation ------------------------------------------------------

    async function refresh() {
        if (!state.conversationId) {
            return;
        }

        const url = `${base(state.conversationId)}/messages?after_id=${state.lastId}`;
        const { ok, payload } = await call(url);

        if (!ok || !payload) {
            return;
        }

        (payload.messages || []).forEach(renderMessage);
        applyStatus(payload.status, payload.rating);
    }

    function openTranscript() {
        ui.intro.classList.add('d-none');
        ui.messages.classList.remove('d-none');
        ui.composer.classList.remove('d-none');
    }

    ui.intro.addEventListener('submit', async (event) => {
        event.preventDefault();
        ui.introError.classList.add('d-none');

        const form = new FormData(ui.intro);
        const { ok, payload } = await call(startUrl, {
            method: 'POST',
            body: {
                name: form.get('name') || undefined,
                email: form.get('email') || undefined,
                body: form.get('body') || '',
            },
        });

        if (!ok) {
            showError(ui.introError, firstError(payload) || 'The chat could not be started.');

            return;
        }

        state.conversationId = payload.conversation_id;
        state.token = payload.token || state.token;
        openTranscript();
        await refresh();
        subscribe();
    });

    ui.composer.addEventListener('submit', async (event) => {
        event.preventDefault();
        ui.error.classList.add('d-none');

        const text = ui.body.value.trim();
        const file = ui.file.files?.[0] || null;

        if (!text) {
            showError(ui.error, 'Type a message first.');

            return;
        }

        const { ok, payload } = await call(`${base(state.conversationId)}/messages`, {
            method: 'POST',
            body: { body: text },
        });

        if (!ok) {
            showError(ui.error, firstError(payload) || 'The message could not be sent.');

            return;
        }

        ui.body.value = '';
        renderMessage(payload.message);

        if (file) {
            await upload(payload.message.id, file);
        }
    });

    async function upload(messageId, file) {
        const data = new FormData();
        data.append('file', file);

        const { ok, payload } = await call(
            `${base(state.conversationId)}/messages/${messageId}/attachments`,
            { method: 'POST', body: data, json: false },
        );

        ui.file.value = '';
        ui.filename.textContent = '';

        if (!ok) {
            showError(ui.error, firstError(payload) || 'That file could not be attached.');

            return;
        }

        // Re-fetch rather than splice the attachment into a message already on
        // screen: one code path for rendering means one place for it to be wrong.
        state.lastId = Math.max(0, state.lastId - 1);
        await refresh();
    }

    ui.file.addEventListener('change', () => {
        ui.filename.textContent = ui.file.files?.[0]?.name || '';
    });

    ui.body.addEventListener('input', () => {
        const now = Date.now();

        if (now - state.typingSentAt < TYPING_IDLE_MS || !state.conversationId) {
            return;
        }

        state.typingSentAt = now;
        call(`${base(state.conversationId)}/typing`, { method: 'POST', body: { typing: true } })
            .catch(() => {});
    });

    ui.rating.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-rating]');

        if (!button) {
            return;
        }

        const { ok } = await call(`${base(state.conversationId)}/rate`, {
            method: 'POST',
            body: { rating: Number(button.dataset.rating) },
        });

        if (ok) {
            ui.thanks.classList.remove('d-none');
        }
    });

    // --- open / minimise ---------------------------------------------------

    ui.launcher.addEventListener('click', async () => {
        state.open = true;
        state.unread = 0;
        ui.unread.classList.add('d-none');
        ui.panel.classList.remove('d-none');
        ui.launcher.setAttribute('aria-expanded', 'true');

        if (state.conversationId) {
            openTranscript();
            await refresh();
            subscribe();
        }

        ui.body.focus();
    });

    ui.minimise.addEventListener('click', () => {
        state.open = false;
        ui.panel.classList.add('d-none');
        ui.launcher.setAttribute('aria-expanded', 'false');
    });

    // --- realtime ----------------------------------------------------------

    /**
     * Subscribe to this conversation's channel and nothing else.
     *
     * The channel name is built from the id we already hold, and the guest auth
     * endpoint refuses anything that is not `private-chat.conversation.{id}`
     * for a matching token — so even a tampered-with name here buys nothing.
     *
     * Any failure falls back to polling rather than throwing: a widget that
     * stops working because Reverb is not running would be worse than a slow one.
     */
    function subscribe() {
        if (state.channel || !state.conversationId) {
            return;
        }

        startPolling();

        const key = import.meta.env.VITE_REVERB_APP_KEY;

        if (!key) {
            return;
        }

        try {
            window.Pusher = Pusher;

            const scheme = import.meta.env.VITE_REVERB_SCHEME || 'https';
            const forceTLS = scheme === 'https';
            const port = Number(import.meta.env.VITE_REVERB_PORT || (forceTLS ? 443 : 80));

            const echo = new Echo({
                broadcaster: 'reverb',
                key,
                wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
                wsPort: port,
                wssPort: port,
                forceTLS,
                enabledTransports: ['ws', 'wss'],
                // The customer has no panel session, so the ordinary
                // /broadcasting/auth endpoint cannot authorise them. This one
                // authorises from the conversation's own token.
                authorizer: (channel) => ({
                    authorize: (socketId, callback) => {
                        call(guestAuthUrl, {
                            method: 'POST',
                            body: {
                                socket_id: socketId,
                                channel_name: channel.name,
                                token: state.token,
                            },
                        })
                            .then(({ ok, payload }) => callback(!ok, ok ? payload : null))
                            .catch((error) => callback(true, error));
                    },
                }),
            });

            state.channel = echo.private(`chat.conversation.${state.conversationId}`);
            state.channel.listen('.chat.message.new', (event) => {
                renderMessage(event.message);
                stopPolling();
            });
        } catch {
            // Polling is already running; nothing else to do.
            state.channel = null;
        }
    }

    function startPolling() {
        if (state.poller) {
            return;
        }

        state.poller = window.setInterval(refresh, POLL_MS);
    }

    function stopPolling() {
        if (!state.poller) {
            return;
        }

        window.clearInterval(state.poller);
        state.poller = null;
    }

    function firstError(payload) {
        if (!payload) {
            return null;
        }

        const errors = payload.errors ? Object.values(payload.errors)[0] : null;

        return (Array.isArray(errors) ? errors[0] : errors) || payload.message || null;
    }

    // A conversation already in the session means this is a reload, not a first
    // visit: show the unread badge without opening the panel over the page the
    // customer actually asked for.
    if (state.conversationId) {
        refresh().then(subscribe);
    }
}
