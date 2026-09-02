@php
    $items = app('adminlte')->menu('sidebar');
    $sidebarTheme = config('adminlte.sidebar_theme', 'dark');
    $sidebarClasses = config('adminlte.classes_sidebar', 'bg-body-secondary shadow');

    // Hide section headers that have no visible items beneath them.
    // GateFilter/RoleFilter already removed unauthorised items; any header
    // left with nothing following it (before the next header or end-of-list)
    // is dropped here so empty labelled sections never show.
    $filteredItems = [];
    $pendingHeader = null;
    foreach ($items as $_item) {
        if (isset($_item['header'])) {
            $pendingHeader = $_item;
        } else {
            if ($pendingHeader !== null) {
                $filteredItems[] = $pendingHeader;
                $pendingHeader = null;
            }
            $filteredItems[] = $_item;
        }
    }
    $items = $filteredItems;
    unset($filteredItems, $pendingHeader, $_item);
@endphp
<aside class="app-sidebar {{ $sidebarClasses }}" @if ($sidebarTheme === 'dark') data-bs-theme="dark" @endif>
    {{-- Brand --}}
    <div class="sidebar-brand {{ config('adminlte.classes_brand') }}">
        <a href="{{ url('/') }}" class="brand-link">
            @if (config('adminlte.logo_img'))
                <img src="{{ asset(config('adminlte.logo_img')) }}"
                     alt="{{ config('adminlte.logo_img_alt', 'Logo') }}"
                     class="{{ config('adminlte.logo_img_class', 'brand-image opacity-75 shadow') }}">
            @endif
            <span class="brand-text {{ config('adminlte.classes_brand_text', 'fw-light') }}">
                {!! config('adminlte.logo', '<b>Admin</b>LTE') !!}
            </span>
        </a>
    </div>

    {{-- Menu --}}
    <div class="sidebar-wrapper">
        <nav class="mt-2" aria-label="{{ __('Main navigation') }}">
            <ul class="nav sidebar-menu flex-column {{ config('adminlte.classes_sidebar_nav') }}"
                data-lte-toggle="treeview"
                data-accordion="false"
                role="menu"
                id="navigation">
                @foreach ($items as $item)
                    @include('adminlte::partials.menu-item', ['item' => $item])
                @endforeach
            </ul>

            @if (config('adminlte.sidebar_docs_url'))
                <div class="sidebar-docs-cta mt-3 border-top border-secondary border-opacity-25">
                    <a href="{{ config('adminlte.sidebar_docs_url') }}"
                       class="btn btn-sm btn-outline-light w-100 d-flex align-items-center justify-content-center gap-2"
                       target="_blank" rel="noopener"
                       title="{{ __('adminlte.view_documentation') }}">
                        <i class="bi bi-book" aria-hidden="true"></i>
                        <span class="sidebar-docs-cta__text">{{ __('adminlte.view_documentation') }}</span>
                    </a>
                </div>
            @endif
        </nav>
    </div>
</aside>

@once
    {{-- Inline (not @push('css'): this partial renders in the body, after the head's @stack('css')). --}}
    <style>
        /* Brand — Instrument Sans + tight tracking, enterprise weight */
        .app-sidebar .sidebar-brand .brand-link {
            font-family: var(--font-sans);
            letter-spacing: var(--tracking-tight);
            font-weight: var(--font-weight-semibold);
            gap: var(--space-2);
            padding-block: var(--space-3);
            transition: opacity var(--duration-base) var(--ease-default);
        }
        .app-sidebar .sidebar-brand .brand-text { font-size: var(--text-md); line-height: var(--leading-tight); }
        .app-sidebar .sidebar-brand .brand-text b { font-weight: var(--font-weight-bold); }

        /* Docs CTA — tokens for spacing / radius / transition; collapsed rail hides label */
        .sidebar-docs-cta {
            padding: var(--space-4);
            border-color: var(--bs-border-color) !important;
            transition: padding var(--duration-base) var(--ease-default);
        }
        .sidebar-docs-cta .btn {
            border-radius: var(--radius-md);
            font-size: var(--text-sm);
            transition: all var(--duration-base) var(--ease-default);
        }
        .sidebar-docs-cta__text {
            transition: opacity var(--duration-base) var(--ease-default);
        }
        /* When the sidebar is collapsed to icons (and not hovered open), shrink the
           docs button to icon-only so it doesn't overflow the narrow rail. */
        .sidebar-mini.sidebar-collapse .app-sidebar:not(:hover) .sidebar-docs-cta { padding: var(--space-2); }
        .sidebar-mini.sidebar-collapse .app-sidebar:not(:hover) .sidebar-docs-cta__text { display: none; }
        /* Fully-collapsed (non-mini) sidebars hide off-canvas, so hide the CTA outright. */
        .sidebar-collapse:not(.sidebar-mini) .sidebar-docs-cta { display: none; }

        /* Sidebar nav — 8px rhythm, token transition, subtle active polish */
        .app-sidebar .nav-sidebar .nav-link {
            gap: var(--space-2);
            border-radius: var(--radius-sm);
            transition: background-color var(--duration-base) var(--ease-default), color var(--duration-base) var(--ease-default);
        }
        .app-sidebar .nav-sidebar .nav-link.active {
            background-color: color-mix(in srgb, var(--color-primary) 14%, transparent);
            color: var(--color-primary);
        }
        [data-bs-theme="dark"] .app-sidebar .nav-sidebar .nav-link.active {
            background-color: color-mix(in srgb, var(--color-primary-400) 18%, transparent);
            color: var(--color-primary-300);
        }
    </style>
@endonce
