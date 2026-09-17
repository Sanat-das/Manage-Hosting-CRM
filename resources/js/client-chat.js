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
import { getReverbConfig } from './reverb-config.js';

/** How often to poll when the websocket is unavailable. */
const POLL_MS = 6000;

/**
 * Minimum gap between typing heartbeats.
 *
 * The first keystroke always goes out immediately; this only rations the ones
 * after it. At 2500ms a customer who kept typing sent 24 requests a minute —
 * on its own most of the old 30/min guest budget, before a single poll or
 * message was counted. 5000ms halves that for no perceptible difference: the
 * operator still sees "typing" within a keystroke, and their end expires the
 * indicator on TYPING_CLEAR_MS regardless.
 */
const TYPING_IDLE_MS = 5000;

/** How long to stop asking after a 429 before trying again. */
const RATE_LIMIT_BACKOFF_MS = 15000;

/**
 * How long an operator's "typing" claim stands before we assume it is stale.
 * There is no guaranteed "stopped" — a closed tab or a dropped socket ends the
 * typing without announcing it — so it expires here rather than sticking on.
 * Longer than the operator's own 3s heartbeat, so a steady typist never flickers.
 */
const TYPING_CLEAR_MS = 8000;

const root = document.getElementById('client-chat');

if (root) {
    boot(root);
}

function boot(el) {
    const state = {
        conversationId: Number(el.dataset.conversationId) || null,
        token: el.dataset.token || '',
        // Seeded from what the server rendered, so a failed availability check
        // falls back to the truth at page load rather than to `undefined`.
        chatOpen: el.dataset.open === '1',
        status: null,
        lastId: 0,
        open: false,
        unread: 0,
        typingSentAt: 0,
        typingTimer: null,
        echo: null,
        channel: null,
        poller: null,
        connected: false,
        oldestId: 0,
        hasMore: false,
        loadingEarlier: false,
        backoffUntil: 0,
    };

    const startUrl = el.dataset.startUrl;
    const offlineUrl = el.dataset.offlineUrl;
    const availabilityUrl = el.dataset.availabilityUrl;
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
        closed: document.getElementById('client-chat-closed'),
        closedMessage: document.getElementById('client-chat-closed-message'),
        reopens: document.getElementById('client-chat-reopens'),
        offline: document.getElementById('client-chat-offline'),
        offlineError: document.getElementById('client-chat-offline-error'),
        offlineThanks: document.getElementById('client-chat-offline-thanks'),
        messages: document.getElementById('client-chat-messages'),
        earlier: document.getElementById('client-chat-earlier'),
        typing: document.getElementById('client-chat-typing'),
        composer: document.getElementById('client-chat-composer'),
        body: document.getElementById('client-chat-body'),
        file: document.getElementById('client-chat-file'),
        filename: document.getElementById('client-chat-filename'),
        error: document.getElementById('client-chat-error'),
        rating: document.getElementById('client-chat-rating'),
        thanks: document.getElementById('client-chat-thanks'),
        restart: document.getElementById('client-chat-restart'),
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

    /**
     * Render one message, at either end of the transcript.
     *
     * Dedupe is by element id, not by comparing against the newest id seen.
     * That comparison worked only while messages could arrive in one direction;
     * "load earlier" fetches ids BELOW everything on screen, and every one of
     * them would have been discarded as already-seen.
     */
    function renderMessage(message, prepend = false) {
        const domId = `client-chat-message-${message.id}`;

        if (document.getElementById(domId)) {
            return;
        }

        state.lastId = Math.max(state.lastId, message.id);
        state.oldestId = state.oldestId === 0 ? message.id : Math.min(state.oldestId, message.id);

        const item = document.createElement('li');
        item.id = domId;
        // `is_operator`, never `is_guest`. A guest has no user id, but a
        // SIGNED-IN customer posts under their own, so "has no user id" is not
        // "this is mine" — reading is_guest here put a logged-in customer's own
        // messages on the support side of the panel, labelled "Support".
        item.className = `client-chat__message client-chat__message--${message.is_operator ? 'theirs' : 'mine'}`;

        const who = document.createElement('span');
        who.className = 'client-chat__author';
        who.textContent = message.is_operator ? message.author_name : 'You';
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

        if (prepend) {
            // Hold the reading position: inserting above the viewport otherwise
            // shunts whatever the customer was reading off the bottom of it.
            const before = ui.messages.scrollHeight;
            ui.messages.prepend(item);
            ui.messages.scrollTop += ui.messages.scrollHeight - before;

            return;
        }

        ui.messages.appendChild(item);
        ui.messages.scrollTop = ui.messages.scrollHeight;

        // Only a reply from support is unread news. Echoing the customer's own
        // message back as an unread badge is the same conflation as above.
        if (!state.open && message.is_operator) {
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

        // Offered on every closed conversation, rated or not: the rating is the
        // end of the last chat, this is the start of the next one.
        ui.restart?.classList.toggle('d-none', !closed);

        if (rating) {
            ui.thanks.classList.remove('d-none');
        }
    }

    /**
     * Put the panel back to the state it was in before any of this started, so
     * a customer whose chat was closed can open another one.
     *
     * The server needs no help here: start() already discards a CLOSED
     * conversation held in the session and mints a new one, overwriting both
     * session keys. What was missing was any way to ask it a second time — the
     * conversation id rendered into the markup survives the close, and it is
     * what hides the intro form and short-circuits the availability check.
     */
    function resetForNewChat() {
        // Leave the old room before forgetting which one it was, or its socket
        // would keep delivering a closed conversation into the new transcript.
        if (state.echo && state.conversationId) {
            try {
                state.echo.leave(`chat.conversation.${state.conversationId}`);
            } catch {
                // A connection that never came up has nothing to leave.
            }
        }

        stopPolling();
        window.clearTimeout(state.typingTimer);

        state.channel = null;
        state.conversationId = null;
        // The old token authorises only the old conversation; start() returns
        // the new one, and until then there is nothing to present.
        state.token = '';
        state.status = null;
        state.lastId = 0;
        state.oldestId = 0;
        state.hasMore = false;
        state.loadingEarlier = false;
        state.typingSentAt = 0;
        state.backoffUntil = 0;
        state.unread = 0;

        ui.messages.replaceChildren();
        ui.messages.classList.add('d-none');
        ui.unread.classList.add('d-none');
        ui.composer.classList.add('d-none');
        ui.rating.classList.add('d-none');
        ui.thanks.classList.add('d-none');
        ui.restart?.classList.add('d-none');
        ui.earlier?.classList.add('d-none');
        ui.typing?.classList.add('d-none');
        ui.error.classList.add('d-none');
        ui.introError.classList.add('d-none');
        ui.offlineThanks?.classList.add('d-none');
        ui.statusLabel.textContent = '';
        // The availability check that follows will correct this; showing the
        // form on the last known state is what keeps a dropped request from
        // stranding the customer a second time.
        ui.intro.classList.toggle('d-none', !state.chatOpen);

        const firstMessage = document.getElementById('client-chat-first');

        if (firstMessage) {
            firstMessage.value = '';
        }
    }

    function showError(node, message) {
        node.textContent = message;
        node.classList.remove('d-none');
    }

    // --- conversation ------------------------------------------------------

    /**
     * Fetch the transcript.
     *
     * With nothing on screen this asks for the newest page; afterwards it asks
     * only for what has arrived since. The first call is what puts a returning
     * customer at the END of their conversation rather than the beginning.
     */
    async function refresh() {
        if (!state.conversationId || Date.now() < state.backoffUntil) {
            return;
        }

        const url = state.lastId > 0
            ? `${base(state.conversationId)}/messages?after_id=${state.lastId}`
            : `${base(state.conversationId)}/messages`;

        const { ok, status, payload } = await call(url);

        if (!ok) {
            handleRateLimit(status);

            return;
        }

        if (!payload) {
            return;
        }

        (payload.messages || []).forEach((message) => renderMessage(message));
        setHasMore(payload.has_more);
        applyStatus(payload.status, payload.rating);
    }

    async function loadEarlier() {
        if (!state.conversationId || state.loadingEarlier || state.oldestId === 0) {
            return;
        }

        state.loadingEarlier = true;
        ui.earlier.disabled = true;

        try {
            const { ok, status, payload } = await call(
                `${base(state.conversationId)}/messages?before_id=${state.oldestId}`,
            );

            if (!ok) {
                handleRateLimit(status);

                return;
            }

            // Newest-first on the wire, so walk it backwards: each prepend goes
            // immediately above the one before it and the block lands in order.
            [...(payload?.messages || [])].reverse().forEach((message) => renderMessage(message, true));
            setHasMore(payload?.has_more);
        } finally {
            state.loadingEarlier = false;
            ui.earlier.disabled = false;
        }
    }

    function setHasMore(hasMore) {
        // Absent means "this answer does not know" (the polling mode never
        // does), which must not be read as "there is nothing older".
        if (hasMore === undefined) {
            return;
        }

        state.hasMore = Boolean(hasMore);
        ui.earlier?.classList.toggle('d-none', !state.hasMore);
    }

    /**
     * A 429 is the one failure the customer has to be told about.
     *
     * Every other error here is transient and the next poll fixes it, so it is
     * swallowed. Rate limiting is different: the widget caused it, polling
     * harder makes it worse, and saying nothing leaves a window that has simply
     * stopped updating with no explanation — which is indistinguishable from
     * the dead-socket bug this file used to have.
     */
    function handleRateLimit(status) {
        if (status !== 429) {
            return;
        }

        state.backoffUntil = Date.now() + RATE_LIMIT_BACKOFF_MS;
        showError(ui.error, 'Too many requests just now — reconnecting in a moment.');

        window.setTimeout(() => {
            ui.error.classList.add('d-none');
            refresh();
        }, RATE_LIMIT_BACKOFF_MS);
    }

    function openTranscript() {
        ui.intro.classList.add('d-none');
        // Whatever the desk is doing, a conversation that already exists wins:
        // its transcript and composer are what this panel is for, and a "we are
        // closed" notice over a live conversation would be a lie the customer
        // can disprove by scrolling.
        ui.offline?.classList.add('d-none');
        ui.closed?.classList.add('d-none');
        ui.messages.classList.remove('d-none');
        ui.composer.classList.remove('d-none');
        ui.earlier?.classList.toggle('d-none', !state.hasMore);
    }

    // --- office hours ------------------------------------------------------

    /**
     * Show the closed notice, and whichever of the two forms applies.
     *
     * Called with what the server just said, so the three elements can never
     * disagree: a closed notice above a live "Start chat" button is the exact
     * confusion this function exists to prevent.
     */
    function applyAvailability({ open, message, offline_form: offlineForm, next_opens_at: nextOpensAt }) {
        state.chatOpen = Boolean(open);

        // Never touch the panel of a conversation that is already running.
        if (state.conversationId) {
            return;
        }

        if (ui.closed) {
            ui.closed.classList.toggle('d-none', state.chatOpen);

            if (ui.closedMessage && message) {
                ui.closedMessage.textContent = message;
            }

            if (ui.reopens) {
                ui.reopens.classList.toggle('d-none', !nextOpensAt);
                ui.reopens.textContent = nextOpensAt ? `We reopen ${nextOpensAt}.` : '';
            }
        }

        ui.intro.classList.toggle('d-none', !state.chatOpen);

        // The offline form is only offered when there is nothing better: closed
        // AND the admin has left the form switched on. Otherwise the visitor
        // gets the notice and no form, which is the honest answer when nobody
        // will read a message either.
        ui.offline?.classList.toggle('d-none', state.chatOpen || !offlineForm);
    }

    /**
     * Ask whether the desk is open, when the panel is opened.
     *
     * Not on page load: the answer is already rendered into the markup, and one
     * request per page view for a widget most visitors never open would be a
     * request per page view for nothing. This runs when it matters — and it is
     * the check that catches a tab left open across closing time.
     */
    async function checkAvailability() {
        if (!availabilityUrl || state.conversationId) {
            return;
        }

        const { ok, payload } = await call(availabilityUrl);

        if (ok) {
            applyAvailability(payload);
        }
        // A failed check leaves the server-rendered state alone. Guessing
        // "closed" would shut the chat because of a dropped request.
    }

    ui.earlier?.addEventListener('click', loadEarlier);

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
            // The desk shut between this panel being opened and this form being
            // submitted. The server refuses and says so; swapping to the
            // offline form keeps what the visitor typed reachable instead of
            // handing them an error and a dead button.
            if (payload?.closed) {
                applyAvailability({ open: false, ...payload });

                const typed = String(form.get('body') || '');
                const target = document.getElementById('client-chat-offline-body');

                if (target && !target.value) {
                    target.value = typed;
                }

                showError(ui.introError, payload.message || 'Support has just gone offline.');

                return;
            }

            showError(ui.introError, firstError(payload) || 'The chat could not be started.');

            return;
        }

        state.conversationId = payload.conversation_id;
        state.token = payload.token || state.token;
        openTranscript();
        await refresh();
        subscribe();
    });

    ui.offline?.addEventListener('submit', async (event) => {
        event.preventDefault();
        ui.offlineError.classList.add('d-none');

        const form = new FormData(ui.offline);
        const { ok, payload } = await call(offlineUrl, {
            method: 'POST',
            body: {
                name: form.get('name') || undefined,
                email: form.get('email') || undefined,
                body: form.get('body') || '',
            },
        });

        if (!ok) {
            showError(ui.offlineError, firstError(payload) || 'The message could not be sent.');

            return;
        }

        // Swapped, not merely emptied: leaving the form up invites a second
        // submission of the same message, which becomes a second ticket.
        ui.offline.classList.add('d-none');
        ui.offlineThanks.textContent = payload.ticket_no
            ? `${payload.message} Your reference is ${payload.ticket_no}.`
            : payload.message;
        ui.offlineThanks.classList.remove('d-none');
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

        const { ok, status, payload } = await call(`${base(state.conversationId)}/messages`, {
            method: 'POST',
            body: { body: text },
        });

        if (!ok) {
            // Not left to firstError(): Laravel's own 429 body is "Too Many
            // Requests", which tells a customer nothing and reads like a fault
            // on their side.
            showError(
                ui.error,
                status === 429
                    ? 'Sending too quickly — wait a moment and press Send again.'
                    : firstError(payload) || 'The message could not be sent.',
            );

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

    /**
     * Show or hide "Support is typing...".
     *
     * The label is static markup the server rendered, so nothing from the event
     * is ever written into the DOM — the payload carries no name to write.
     */
    function showTyping(isTyping) {
        if (!ui.typing) {
            return;
        }

        window.clearTimeout(state.typingTimer);
        ui.typing.classList.toggle('d-none', !isTyping);

        if (!isTyping) {
            return;
        }

        state.typingTimer = window.setTimeout(
            () => ui.typing.classList.add('d-none'),
            TYPING_CLEAR_MS,
        );
    }

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

    ui.restart?.addEventListener('click', async () => {
        resetForNewChat();

        // Asked again rather than assumed: the desk may have shut during the
        // conversation that just ended, and the intro form must not be offered
        // for a chat the server is about to refuse.
        await checkAvailability();

        document.getElementById('client-chat-first')?.focus();
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
        } else {
            await checkAvailability();
        }

        // Focus follows whatever is actually usable. Focusing the message box of
        // a hidden composer moves nothing and leaves the visitor typing into a
        // page that ignores them.
        if (state.conversationId) {
            ui.body.focus();
        } else if (!ui.offline?.classList.contains('d-none')) {
            document.getElementById('client-chat-offline-body')?.focus();
        } else if (!ui.intro.classList.contains('d-none')) {
            document.getElementById('client-chat-first')?.focus();
        }
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

        // A second conversation in the same tab reuses the socket the first one
        // opened. Building another Echo would leave two connections up, both
        // authorising as this browser, for one panel.
        if (state.echo) {
            listenOnConversation(state.echo);

            return;
        }

        const cfg = getReverbConfig();

        if (!cfg) {
            return;
        }

        try {
            window.Pusher = Pusher;

            const forceTLS = cfg.scheme === 'https';

            const echo = new Echo({
                broadcaster: 'reverb',
                key: cfg.key,
                wsHost: cfg.host,
                wsPort: cfg.port,
                wssPort: cfg.port,
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

            watchConnection(echo);

            state.echo = echo;
            listenOnConversation(echo);
        } catch {
            // Polling is already running; nothing else to do.
            state.channel = null;
        }
    }

    function listenOnConversation(echo) {
        state.channel = echo.private(`chat.conversation.${state.conversationId}`);
        state.channel.listen('.chat.message.new', (event) => {
            showTyping(false);
            renderMessage(event.message);
            stopPolling();
        });

        // Published here only for a customer inbox and only for a staff
        // typer: our own heartbeat is never echoed back, and it names no one.
        state.channel.listen('.chat.typing', (event) => showTyping(event.typing));
    }

    /**
     * Put polling back when the socket stops carrying messages, so a dropped
     * connection cannot leave the widget with no working transport at all.
     *
     * Two deliberate choices, both of which look wrong until you hit them:
     * `connecting` resumes polling, because a socket trying to come back is not
     * one delivering messages; and returning to `connected` does NOT stop the
     * timer — only the next message over the socket does, which keeps a
     * flapping connection from toggling the one transport that still works.
     */
    function watchConnection(echo) {
        const connection = echo?.connector?.pusher?.connection;

        if (!connection) {
            startPolling();

            return;
        }

        connection.bind('state_change', ({ current }) => {
            state.connected = current === 'connected';

            if (!state.connected) {
                startPolling();
            }
        });
    }

    // A hidden tab does not need a 6-second poll; a visible one with no live
    // socket does. Without this a widget left open in a background tab polls
    // for as long as the browser is running.
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stopPolling();
        } else if (state.conversationId && !state.connected) {
            startPolling();
            refresh();
        }
    });

    function startPolling() {
        if (state.poller || document.hidden) {
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
