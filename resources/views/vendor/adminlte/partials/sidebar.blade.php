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
<aside class="app-sidebar {{ $sidebarClasses }}" @if ($sidebarTheme === 'dark') data-bs-theme="dark" @endif aria-label="{{ __('Main sidebar') }}">
    {{-- Brand --}}
    <div class="sidebar-brand {{ config('adminlte.classes_brand') }}">
        <a href="{{ url('/') }}" class="brand-link" aria-label="{{ config('app.name') }} home">
            @php
                $_b = $branding ?? \App\Support\Branding::all();
                // When the wordmark is rendered inside brand-text (custom upload OR
                // shipped default PNG), hide the separate brand-image to enforce
                // "one at a time" and avoid duplicate logos side-by-side.
                // Branding::logoHtml() now always returns an <img> (fallback to
                // DEFAULT_LOGO), so we detect that via config('adminlte.logo').
                $_logoPath = $_b['logo_path'] ?? '';
                $_markUrl = $_b['mark_url'] ?? '';
                $_hasStorageLogo = is_string($_logoPath) && $_logoPath !== '' && (str_starts_with($_logoPath, 'branding/') || str_starts_with($_logoPath, 'storage/') || str_contains($_logoPath, 'branding/'));
                $_hasWordmarkImage = str_contains((string) config('adminlte.logo'), '<img');
                if ($_hasStorageLogo || $_hasWordmarkImage) {
                    $_logoImgSrc = '';
                } else {
                    $_logoImgSrc = config('adminlte.logo_img') ? asset(config('adminlte.logo_img')) : '';
                }
                $_logoAlt = $_b['app_name'] ?? config('adminlte.logo_img_alt', 'Logo');
            @endphp
            @if ($_logoImgSrc !== '')
                <img src="{{ $_logoImgSrc }}"
                     alt="{{ $_logoAlt }}"
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
                id="navigation">
                @foreach ($items as $item)
                    @include('adminlte::partials.menu-item', ['item' => $item])
                @endforeach
                @if (empty($items))
                    <p class="px-3 py-2 text-body-secondary small">No navigation items available.</p>
                @endif
            </ul>

            @if (config('adminlte.sidebar_docs_url') && config('adminlte.docs') !== false)
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

{{-- styles moved to adminlte.css --}}
