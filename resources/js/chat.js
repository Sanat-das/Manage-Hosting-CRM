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
import { realtime } from './echo.js';

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
        thread: document.getElementById('chat-thread'),
        threadBody: document.getElementById('chat-thread-body'),
        threadComposer: document.getElementById('chat-thread-composer'),
        threadInput: document.getElementById('chat-thread-body-input'),
        loadOlder: document.getElementById('chat-load-older'),
        filter: document.getElementById('chat-filter'),
        presence: document.getElementById('chat-presence-list'),
        noMessages: document.getElementById('chat-no-messages'),
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

    function upsertMessage(message) {
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
        } catch {
            // The message stays in the box so it is not lost.
        }
    });

    el.body?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            el.composer.requestSubmit();
        }
    });

    el.body?.addEventListener('input', () => {
        handleMentionTyping();
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
            if (!window.confirm('Delete this message? It will show as [deleted] in the thread.')) return;
            try {
                await api(`/admin/chat/messages/${id}`, { method: 'DELETE' });
                row.classList.add('is-deleted');
                row.querySelector('.chat-message__body').textContent = '[deleted]';
                row.querySelector('.chat-message__actions')?.remove();
            } catch (error) {
                say(error.message, 'error');
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
        el.threadBody.innerHTML = '<p class="text-body-secondary p-3 mb-0">Loading...</p>';

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

    el.loadOlder?.addEventListener('click', async () => {
        const oldest = el.messages?.dataset.oldest;
        if (!oldest) {
            el.loadOlder.disabled = true;
            return;
        }

        el.loadOlder.disabled = true;
        el.loadOlder.textContent = 'Loading...';

        try {
            const result = await api(
                `/admin/chat/conversations/${state.conversationId}/messages?before_id=${oldest}`,
            );

            if (result.messages.length === 0) {
                el.loadOlder.textContent = 'Beginning of the conversation';
                return;
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
        } catch (error) {
            el.loadOlder.disabled = false;
            el.loadOlder.textContent = 'Load older messages';
            say(error.message, 'error');
        }
    });

    // --- sidebar filter ---------------------------------------------------

    el.filter?.addEventListener('input', () => {
        const needle = el.filter.value.trim().toLowerCase();

        document.querySelectorAll('.chat-sidebar__item').forEach((item) => {
            item.classList.toggle('d-none', needle !== '' && !item.dataset.name.includes(needle));
        });
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

    // --- customer inbox actions -------------------------------------------

    document.querySelectorAll('[data-inbox-action]').forEach((button) => {
        button.addEventListener('click', async () => {
            const action = button.dataset.inboxAction;

            if (action === 'close' && !window.confirm('Close this conversation?')) return;

            try {
                const result = await api(`/admin/chat/inbox/${state.conversationId}/${action}`, { method: 'POST' });
                if (action === 'convert') {
                    say(`Created ticket ${result.ticket.ticket_no}.`);
                } else {
                    say(`Conversation ${result.conversation.status}.`);
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

    function addPresence(id, name) {
        if (!el.presence || id === state.userId) return;
        el.presence.querySelector('[data-empty]')?.remove();
        if (el.presence.querySelector(`[data-user-id="${id}"]`)) return;

        const li = document.createElement('li');
        li.dataset.userId = id;
        li.innerHTML = `<span class="chat-presence__dot"></span>${escapeHtml(name)}`;
        el.presence.append(li);
    }

    function removePresence(id) {
        el.presence?.querySelector(`[data-user-id="${id}"]`)?.remove();
    }

    // The server-side roster is what a page with no websocket sees, so it is
    // kept warm regardless of whether Echo connected.
    function heartbeat() {
        if (document.hidden) return;
        api('/admin/chat/presence', { method: 'POST', body: JSON.stringify({ online: true }) }).catch(() => {});
    }

    heartbeat();
    setInterval(heartbeat, state.heartbeat * 1000);

    window.addEventListener('beforeunload', () => {
        navigator.sendBeacon?.(
            '/admin/chat/presence',
            new Blob([JSON.stringify({ online: false, _token: csrf })], { type: 'application/json' }),
        );
    });

    scrollToBottom();
}
