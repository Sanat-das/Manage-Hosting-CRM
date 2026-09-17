@php
    use App\Support\ChatNavbar;

    // Live Chat is the mailbox: unread conversations plus the waiting
    // customer queue. Never demo names — without unread activity this is
    // simply empty. See App\Support\ChatNavbar.
    $navUser = auth()->user();
    $canUseChat = $navUser && $navUser->hasPermission('chat.view');
    $messages = $canUseChat ? ChatNavbar::messages($navUser) : [];
    $messageCount = $canUseChat ? ChatNavbar::messageCount($navUser) : 0;
    $messagesUrl = ChatNavbar::indexUrl();
@endphp
@if ($canUseChat)
@once
<style data-chat-nav-style>
    /* Compact navbar rows: stepped type scale, 38px monogram, pill never wraps. */
    [data-chat-nav-menu] .chat-nav-avatar { width: 2.375rem; height: 2.375rem; font-size: .8rem; }
    [data-chat-nav-menu] .chat-nav-name { font-size: .875rem; font-weight: 600; line-height: 1.25; }
    [data-chat-nav-menu] .chat-nav-text { font-size: .78rem; line-height: 1.3; color: var(--bs-secondary-color); }
    [data-chat-nav-menu] .chat-nav-time { font-size: .72rem; line-height: 1.3; color: var(--bs-secondary-color); }
    [data-chat-nav-menu] .chat-nav-pill { font-size: .66rem; white-space: nowrap; }
    [data-chat-nav-menu] .dropdown-item-title { margin-bottom: .125rem; }
</style>
@endonce
<li class="nav-item dropdown" data-chat-nav>
    <a class="nav-link" data-bs-toggle="dropdown" href="#" aria-label="Messages">
        <i class="bi bi-chat-text" aria-hidden="true"></i>
        <span class="navbar-badge badge text-bg-danger {{ $messageCount > 0 ? '' : 'd-none' }}" data-chat-nav-badge>{{ $messageCount > 99 ? '99+' : $messageCount }}</span>
    </a>
    <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end" data-chat-nav-menu data-empty-text="{{ __('adminlte.no_messages') }}" data-footer-text="{{ __('adminlte.see_all_messages') }}" data-title-text="{{ __('adminlte.messages') }}">
        <span class="dropdown-item dropdown-header" data-chat-nav-header>{{ __('adminlte.messages') }}{{ $messageCount > 0 ? ' ('.$messageCount.')' : '' }}</span>
        <div class="dropdown-divider"></div>
        @forelse ($messages as $msg)
            @php $monogram = in_array($msg['color'] ?? '', App\Support\ChatNavbar::COLORS, true) ? $msg['color'] : 'primary'; @endphp
            <a href="{{ $msg['url'] ?? $messagesUrl }}" class="dropdown-item">
                <div class="d-flex align-items-center gap-2">
                    <span class="chat-nav-avatar flex-shrink-0 d-inline-flex align-items-center justify-content-center rounded-circle bg-{{ $monogram }}-subtle text-{{ $monogram }}-emphasis fw-semibold" aria-hidden="true">{{ $msg['initials'] ?? '?' }}</span>
                    <div class="flex-grow-1 min-w-0">
                        <h3 class="dropdown-item-title d-flex align-items-center gap-2">
                            <span class="chat-nav-name text-truncate">{{ $msg['name'] }}</span>
                            @if (!empty($msg['waiting']))
                                <span class="badge chat-nav-pill text-bg-warning ms-auto flex-shrink-0">waiting</span>
                            @elseif (($msg['unread'] ?? 0) > 0)
                                <span class="badge chat-nav-pill text-bg-danger ms-auto flex-shrink-0">{{ ($msg['unread'] ?? 0) > 99 ? '99+' : $msg['unread'] }}</span>
                            @endif
                        </h3>
                        <p class="chat-nav-text text-truncate mb-0">{{ $msg['text'] }}</p>
                        <p class="chat-nav-time mb-0"><i class="bi bi-clock-fill me-1" aria-hidden="true"></i>{{ $msg['time'] }}</p>
                    </div>
                </div>
            </a>
            <div class="dropdown-divider"></div>
        @empty
            <span class="dropdown-item text-secondary">{{ __('adminlte.no_messages') }}</span>
            <div class="dropdown-divider"></div>
        @endforelse
        <a href="{{ $messagesUrl }}" class="dropdown-item dropdown-footer" data-chat-nav-footer>{{ __('adminlte.see_all_messages') }}</a>
    </div>
</li>
@once
<script data-chat-nav-poll
        data-unread-url="{{ route('admin.chat.unread') }}"
        data-navbar-url="{{ route('admin.chat.navbar') }}">
(function () {
    // Navbar messages: the badge polls every 30s, and the list re-fetches
    // each time the dropdown opens so it matches the live badge.
    var root = document.querySelector('[data-chat-nav]');
    var badge = document.querySelector('[data-chat-nav-badge]');
    var menu = document.querySelector('[data-chat-nav-menu]');
    var pollTag = document.querySelector('[data-chat-nav-poll]');
    if (!root || !badge || !menu || !pollTag) return;

    var unreadUrl = pollTag.dataset.unreadUrl;
    var navbarUrl = pollTag.dataset.navbarUrl;
    var footerHref = menu.querySelector('[data-chat-nav-footer]')?.getAttribute('href') || '#';
    var emptyText = menu.dataset.emptyText || 'No messages';
    var footerText = menu.dataset.footerText || 'See all messages';
    var titleText = menu.dataset.titleText || 'Messages';
    var COLORS = ['primary', 'success', 'danger', 'warning', 'info', 'secondary'];

    function esc(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
        });
    }

    function renderBadge(total) {
        badge.textContent = total > 99 ? '99+' : total;
        badge.classList.toggle('d-none', total <= 0);
    }

    function pill(m) {
        if (m.waiting) return '<span class="badge chat-nav-pill text-bg-warning ms-auto flex-shrink-0">waiting</span>';
        if ((m.unread || 0) > 0) return '<span class="badge chat-nav-pill text-bg-danger ms-auto flex-shrink-0">' + (m.unread > 99 ? '99+' : m.unread) + '</span>';
        return '';
    }

    function row(m) {
        var color = COLORS.includes(m.color) ? m.color : 'primary';
        return '<a href="' + esc(m.url || footerHref) + '" class="dropdown-item">'
            + '<div class="d-flex align-items-center gap-2">'
            + '<span class="chat-nav-avatar flex-shrink-0 d-inline-flex align-items-center justify-content-center rounded-circle bg-' + color + '-subtle text-' + color + '-emphasis fw-semibold" aria-hidden="true">' + esc(m.initials || '?') + '</span>'
            + '<div class="flex-grow-1 min-w-0">'
            + '<h3 class="dropdown-item-title d-flex align-items-center gap-2">'
            + '<span class="chat-nav-name text-truncate">' + esc(m.name) + '</span>' + pill(m)
            + '</h3>'
            + '<p class="chat-nav-text text-truncate mb-0">' + esc(m.text) + '</p>'
            + '<p class="chat-nav-time mb-0"><i class="bi bi-clock-fill me-1" aria-hidden="true"></i>' + esc(m.time) + '</p>'
            + '</div></div></a>'
            + '<div class="dropdown-divider"></div>';
    }

    function header(count) {
        return '<span class="dropdown-item dropdown-header" data-chat-nav-header>' + esc(titleText) + (count > 0 ? ' (' + count + ')' : '') + '</span><div class="dropdown-divider"></div>';
    }

    function renderList(messages, count) {
        var html = messages.map(row).join('');
        if (!html) html = '<span class="dropdown-item text-secondary">' + esc(emptyText) + '</span><div class="dropdown-divider"></div>';
        menu.innerHTML = header(count) + html + '<a href="' + esc(footerHref) + '" class="dropdown-item dropdown-footer" data-chat-nav-footer>' + esc(footerText) + '</a>';
    }

    async function refresh() {
        if (document.hidden) return;
        try {
            var res = await fetch(navbarUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
            if (!res.ok) return;
            var data = await res.json();
            renderList(data.messages || [], Number(data.count) || 0);
            renderBadge(Number(data.count) || 0);
        } catch (e) { /* server-rendered list stays */ }
    }

    async function poll() {
        if (document.hidden) return;
        try {
            var res = await fetch(unreadUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
            if (!res.ok) return;
            var data = await res.json();
            var unread = Object.values(data.unread || {}).reduce(function (sum, n) { return sum + (Number(n) || 0); }, 0);
            renderBadge(unread + ((data.inbox || []).length));
        } catch (e) { /* badge keeps its server-rendered value */ }
    }

    root.addEventListener('show.bs.dropdown', refresh);
    setInterval(poll, 30000);
})();
</script>
@endonce
@endif
