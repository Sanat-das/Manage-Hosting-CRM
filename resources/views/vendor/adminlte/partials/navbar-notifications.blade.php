@php
    use App\Support\NotificationsNavbar;

    // Unread-only notification rows for the bell dropdown, newest first.
    // Every method is defensive (guest, unsaved user or missing table degrade
    // to an empty dropdown), so the navbar never 500s. See App\Support\NotificationsNavbar.
    $navUser = auth()->user();
    $notifRows = NotificationsNavbar::rows($navUser);
    $notifCount = NotificationsNavbar::count($navUser);
    $notifIndexUrl = NotificationsNavbar::indexUrl($navUser);
    $notifMarkAllUrl = NotificationsNavbar::markAllReadUrl($navUser);
@endphp
@once
<style data-notif-nav-style>
    /* Compact navbar rows: 32px icon chip, stepped type scale, time on its own line. */
    [data-notif-nav-menu] .notif-nav-icon { width: 2rem; height: 2rem; font-size: .875rem; }
    [data-notif-nav-menu] .notif-nav-title { font-size: .875rem; font-weight: 600; line-height: 1.25; }
    /* Single-line preview: AdminLTE's `.dropdown-menu-lg p { white-space: normal }`
       (0-1-1) beats Bootstrap's `.text-truncate` (0-1-0), so the scoped rule
       (0-2-0) must own the truncation itself. */
    [data-notif-nav-menu] .notif-nav-text { font-size: .78rem; line-height: 1.3; color: var(--bs-secondary-color); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    [data-notif-nav-menu] .notif-nav-time { font-size: .72rem; line-height: 1.3; color: var(--bs-secondary-color); }
    [data-notif-nav-menu] .dropdown-item-title { margin-bottom: .125rem; }
    /* The truncating column must be allowed to shrink: the global `.min-w-0`
       utility lives in app.css, which admin pages never load, so without this
       the nowrap preview overflows the menu padding instead of ellipsizing. */
    [data-notif-nav-menu] .notif-nav-body { min-width: 0; }
    [data-notif-nav-menu] .notif-nav-markall { font-size: .8rem; line-height: 1; color: var(--bs-secondary-color); }
    [data-notif-nav-menu] .notif-nav-markall:hover { color: var(--bs-body-color); }
</style>
@endonce
<li class="nav-item dropdown" data-notif-nav>
    <a class="nav-link" data-bs-toggle="dropdown" href="#" aria-label="{{ __('adminlte.notifications') }}">
        <i class="bi bi-bell-fill" aria-hidden="true"></i>
        @if ($notifCount > 0)
            <span class="navbar-badge badge text-bg-warning">{{ $notifCount > 99 ? '99+' : $notifCount }}</span>
        @endif
    </a>
    <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end" data-notif-nav-menu>
        <div class="dropdown-item dropdown-header d-flex align-items-center justify-content-between">
            {{ __('adminlte.notifications') }}{{ $notifCount > 0 ? ' ('.$notifCount.')' : '' }}
            @if ($notifMarkAllUrl && $notifCount > 0)
                <form method="POST" action="{{ $notifMarkAllUrl }}" class="mb-0">@csrf<button type="submit" class="btn btn-link btn-sm p-0 notif-nav-markall" title="Mark all read" aria-label="Mark all read"><i class="bi bi-check2-all" aria-hidden="true"></i></button></form>
            @endif
        </div>
        <div class="dropdown-divider"></div>
        @forelse ($notifRows as $note)
            @php $c = in_array($note['color'], NotificationsNavbar::COLORS, true) ? $note['color'] : 'secondary'; @endphp
            <a href="{{ $note['url'] }}" class="dropdown-item">
                <div class="d-flex align-items-center gap-2">
                    <span class="notif-nav-icon flex-shrink-0 d-inline-flex align-items-center justify-content-center rounded-circle bg-{{ $c }}-subtle text-{{ $c }}-emphasis" aria-hidden="true"><i class="{{ $note['icon'] }}"></i></span>
                    <div class="flex-grow-1 min-w-0 notif-nav-body">
                        <h3 class="dropdown-item-title"><span class="notif-nav-title text-truncate d-block">{{ $note['title'] }}</span></h3>
                        @if ($note['text'] !== '')
                            <p class="notif-nav-text text-truncate mb-0">{{ $note['text'] }}</p>
                        @endif
                        <p class="notif-nav-time mb-0"><i class="bi bi-clock-fill me-1" aria-hidden="true"></i>{{ $note['time'] }}</p>
                    </div>
                </div>
            </a>
            <div class="dropdown-divider"></div>
        @empty
            <span class="dropdown-item text-secondary">{{ __('adminlte.no_notifications') }}</span>
            <div class="dropdown-divider"></div>
        @endforelse
        @if ($notifIndexUrl)
            <a href="{{ $notifIndexUrl }}" class="dropdown-item dropdown-footer">{{ __('adminlte.see_all_notifications') }}</a>
        @endif
    </div>
</li>
