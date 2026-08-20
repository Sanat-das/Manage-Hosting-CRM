<?php

use App\Support\AdminLte\ActiveRouteFilter;
use App\Support\AdminLte\NotificationBadgeFilter;
use App\Support\AdminLte\RoleFilter;
use App\Support\AdminLte\RouteExistsFilter;
use ColorlibHQ\AdminLte\Menu\Filters\GateFilter;
use ColorlibHQ\AdminLte\Menu\Filters\HrefFilter;
use ColorlibHQ\AdminLte\Menu\Filters\SearchFilter;

return [

    /*
    |--------------------------------------------------------------------------
    | Title
    |--------------------------------------------------------------------------
    |
    | The default page title, and an optional prefix/postfix applied to every
    | page title set with @section('title', ...).
    |
    */

    'title' => 'Hosting CRM',
    'title_prefix' => '',
    'title_postfix' => '',

    /*
    |--------------------------------------------------------------------------
    | Favicon
    |--------------------------------------------------------------------------
    */

    'use_ico_only' => false,
    'use_full_favicon' => false,

    /*
    |--------------------------------------------------------------------------
    | Google Fonts
    |--------------------------------------------------------------------------
    |
    | AdminLTE 4 uses Source Sans 3. Set to false to self-host or skip.
    |
    */

    'google_fonts' => [
        'allowed' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Logo
    |--------------------------------------------------------------------------
    |
    | The brand logo shown in the sidebar. `logo` accepts HTML and is
    | rendered UNESCAPED ({!! !!}) — only ever put trusted, hardcoded
    | markup here, never user-supplied or database-driven content.
    |
    */

    'logo' => '<svg class="brand-mark" viewBox="0 0 24 24" width="26" height="26" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="3" y="2.5" width="18" height="19" rx="2.5" stroke="currentColor" stroke-width="1.6"/><path d="M7 7.5h10M7 12h10M7 16.5h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="16.5" cy="16.5" r="1.5" fill="#F5A524"/></svg><span class="brand-text brand-word">Hosting CRM</span>',
    'logo_img' => false,
    'logo_img_class' => 'brand-image opacity-75 shadow',
    'logo_img_alt' => 'AdminLTE Logo',

    /*
    |--------------------------------------------------------------------------
    | Authentication logo
    |--------------------------------------------------------------------------
    */

    'auth_logo' => [
        'enabled' => false,
        'img' => [
            'path' => 'vendor/adminlte/img/AdminLTELogo.png',
            'alt' => 'Auth Logo',
            'class' => '',
            'width' => 50,
            'height' => 50,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User menu (topbar dropdown)
    |--------------------------------------------------------------------------
    */

    'usermenu_enabled' => true,
    'usermenu_header' => false,
    'usermenu_header_class' => 'bg-primary',
    'usermenu_image' => false,
    'usermenu_desc' => false,
    'usermenu_profile_url' => false,

    /*
    |--------------------------------------------------------------------------
    | Layout
    |--------------------------------------------------------------------------
    |
    | Body-level layout switches. These map directly to AdminLTE 4 body classes.
    |
    */

    'layout_topnav' => null,
    'layout_boxed' => null,
    'layout_fixed_sidebar' => true,   // .layout-fixed
    'layout_fixed_navbar' => true,    // .fixed-header
    'layout_fixed_footer' => null,    // .fixed-footer
    'layout_dark_mode' => null,       // null = respect system / user toggle
    'layout_rtl' => false,            // Enable right-to-left layout

    /*
    |--------------------------------------------------------------------------
    | Footer & Preloader
    |--------------------------------------------------------------------------
    |
    | `footer_left` / `footer_right` accept HTML and are rendered UNESCAPED
    | ({!! !!}) — only ever put trusted, hardcoded markup here, never
    | user-supplied or database-driven content.
    |
    */

    'footer_left' => 'Copyright &copy; '.date('Y').' Hosting CRM. All rights reserved.',
    'footer_right' => 'Hosting CRM <span class="text-muted">· Infrastructure Control</span>',
    'preloader' => false,
    'control_sidebar' => false,
    'control_sidebar_theme' => 'dark',

    // Documentation URL used by the navbar "Documentation" link and the sidebar
    // "View documentation" CTA (false to hide the CTA). Defaults to the in-app
    // docs viewer served at /docs (see the `docs` keys below).
    'sidebar_docs_url' => '/docs',

    // Bundled demo/showcase pages (Dashboard v2/v3, Widgets, UI, Forms, Tables,
    // Layout Options, Theme Generate, auth variants, error pages). Set false to
    // skip registering their routes in production.
    'demo' => false,
    'demo_middleware' => ['web', 'auth'],

    // In-app documentation viewer: renders this package's docs/*.md files at
    // /docs and /docs/{page}. Set 'docs' => false to disable the route.
    'docs' => false,
    'docs_middleware' => ['web'],

    'sidebar_breakpoint' => 'lg',     // sidebar-expand-{breakpoint}
    'sidebar_mini' => true,           // .sidebar-mini
    'sidebar_collapse' => false,      // start collapsed
    'sidebar_collapse_auto_size' => false,
    'sidebar_scrollbar_theme' => 'os-theme-light',
    'sidebar_scrollbar_auto_hide' => 'leave',

    /*
    |--------------------------------------------------------------------------
    | Color theme
    |--------------------------------------------------------------------------
    |
    | The sidebar uses data-bs-theme="dark" by default (dark sidebar on a
    | light page, matching the AdminLTE 4 demos). Set to 'light' for a light
    | sidebar.
    |
    */

    'sidebar_theme' => 'dark',  // 'dark' | 'light'

    /*
    |--------------------------------------------------------------------------
    | Custom body / element classes
    |--------------------------------------------------------------------------
    */

    'classes_body' => '',
    'classes_brand' => '',
    'classes_brand_text' => 'fw-light',
    'classes_content_wrapper' => '',
    'classes_content_header' => '',
    'classes_content' => '',
    'classes_sidebar' => 'bg-body-secondary shadow',
    'classes_sidebar_nav' => '',
    'classes_topnav' => 'navbar-expand bg-body',
    'classes_topnav_nav' => 'navbar',
    'classes_topnav_container' => 'container-fluid',

    /*
    |--------------------------------------------------------------------------
    | Color mode toggle
    |--------------------------------------------------------------------------
    |
    | Shows the Light/Dark/Auto dropdown in the topbar (AdminLTE 4 feature).
    |
    */

    'color_mode_toggle' => true,

    /*
    |--------------------------------------------------------------------------
    | Menu
    |--------------------------------------------------------------------------
    |
    | The sidebar (and optional top-nav) menu. Each item is an array. Supported
    | keys:
    |
    |   'header'      => 'SECTION LABEL'            // a section header
    |   'text'        => 'Dashboard'               // link label (required for links)
    |   'route'       => 'dashboard'               // named route  -> url
    |   'url'         => 'admin/users'             // raw url (relative or absolute)
    |   'icon'        => 'bi bi-speedometer'       // Bootstrap Icons class
    |   'icon_color'  => 'primary'                 // optional text-{color}
    |   'label'       => 5                         // badge value
    |   'label_color' => 'primary'                 // badge color
    |   'active'      => ['admin/users*']          // url patterns that mark active
    |   'target'      => '_blank'                  // anchor target
    |   'can'         => 'view-users'              // gate/permission to show item
    |   'submenu'     => [ ...child items... ]     // nested items (treeview)
    |
    */

    'menu' => [
        ['header' => 'NAVIGATION', 'can' => 'admin.panel'],
        [
            'text' => 'Dashboard',
            'route' => 'admin.dashboard',
            'icon' => 'bi bi-speedometer2',
            'can' => 'dashboard.view',
        ],
        [
            'text' => 'Notifications',
            'route' => 'admin.notifications.index',
            'icon' => 'bi bi-bell',
        ],

        ['header' => 'MANAGEMENT', 'can' => 'admin.panel'],
        [
            'text' => 'Customers',
            'icon' => 'bi bi-people',
            'can' => 'customers.view',
            'submenu' => [
                ['text' => 'All Customers', 'route' => 'admin.customers.index', 'icon' => 'bi bi-circle', 'can' => 'customers.view'],
                ['text' => 'Add Customer', 'route' => 'admin.customers.create', 'icon' => 'bi bi-circle', 'can' => 'customers.create'],
                ['text' => 'Customer Groups', 'route' => 'admin.customer-groups.index', 'icon' => 'bi bi-circle', 'can' => 'customers.view'],
            ],
        ],
        [
            'text' => 'Manage Products',
            'icon' => 'bi bi-box',
            'can' => 'products.view',
            'submenu' => [
                ['text' => 'All Products', 'route' => 'admin.products.index', 'icon' => 'bi bi-circle', 'can' => 'products.view'],
                ['text' => 'Add Product', 'route' => 'admin.products.create', 'icon' => 'bi bi-circle', 'can' => 'products.create'],
                ['text' => 'Product Groups', 'route' => 'admin.product-groups.index', 'icon' => 'bi bi-circle', 'can' => 'products.groups'],
                ['text' => 'Configurable Options', 'route' => 'admin.product-options.index', 'icon' => 'bi bi-circle', 'can' => 'products.options'],
                ['text' => 'Addons', 'route' => 'admin.addons.index', 'icon' => 'bi bi-circle', 'can' => 'products.addons'],
            ],
        ],
        [
            'text' => 'Orders',
            'icon' => 'bi bi-cart',
            'can' => 'orders.view',
            'submenu' => [
                ['text' => 'All Orders', 'route' => 'admin.orders.index', 'icon' => 'bi bi-circle', 'can' => 'orders.view'],
                ['text' => 'New Order', 'route' => 'admin.orders.create', 'icon' => 'bi bi-circle', 'can' => 'orders.create'],
            ],
        ],
        [
            'text' => 'Products/Services',
            'icon' => 'bi bi-hdd-rack',
            'can' => 'hosting.view',
            'submenu' => [
                ['text' => 'All Products/Services', 'route' => 'admin.hosting.index', 'icon' => 'bi bi-circle', 'can' => 'hosting.view'],
                ['text' => 'Servers', 'route' => 'admin.servers.index', 'icon' => 'bi bi-circle', 'can' => 'hosting.manage'],
                ['text' => 'Server Groups', 'route' => 'admin.server-groups.index', 'icon' => 'bi bi-circle', 'can' => 'hosting.server_groups'],
            ],
        ],
        [
            'text' => 'Domains',
            'icon' => 'bi bi-globe',
            'can' => 'domains.view',
            'submenu' => [
                ['text' => 'All Domains', 'route' => 'admin.domains.index', 'icon' => 'bi bi-circle', 'can' => 'domains.view'],
                ['text' => 'Register Domain', 'route' => 'admin.domains.create', 'icon' => 'bi bi-circle', 'can' => 'domains.manage'],
                ['text' => 'Domain Search', 'route' => 'admin.domains.search', 'icon' => 'bi bi-circle', 'can' => 'domains.manage'],
                ['text' => 'Registrar Settings', 'route' => 'admin.registrar-settings.index', 'icon' => 'bi bi-circle', 'can' => 'settings.edit'],
            ],
        ],

        ['header' => 'BILLING', 'can' => 'admin.panel'],
        [
            'text' => 'Invoices',
            'icon' => 'bi bi-receipt',
            'can' => 'invoices.view',
            'submenu' => [
                ['text' => 'All Invoices', 'route' => 'admin.invoices.index', 'icon' => 'bi bi-circle', 'can' => 'invoices.view'],
                ['text' => 'Create Invoice', 'route' => 'admin.invoices.create', 'icon' => 'bi bi-circle', 'can' => 'invoices.create'],
            ],
        ],
        [
            'text' => 'Payments',
            'icon' => 'bi bi-credit-card',
            'can' => 'payments.view',
            'submenu' => [
                ['text' => 'All Payments', 'route' => 'admin.payments.index', 'icon' => 'bi bi-circle', 'can' => 'payments.view'],
                ['text' => 'Record Payment', 'route' => 'admin.payments.create', 'icon' => 'bi bi-circle', 'can' => 'payments.create'],
                ['text' => 'Gateway Settings', 'route' => 'admin.gateway-settings.index', 'icon' => 'bi bi-circle', 'can' => 'settings.edit'],
            ],
        ],
        [
            'text' => 'Quotes',
            'route' => 'admin.quotes.index',
            'icon' => 'bi bi-file-text',
            'can' => 'invoices.view',
        ],
        [
            'text' => 'Transactions',
            'route' => 'admin.transactions.index',
            'icon' => 'bi bi-arrow-left-right',
            'can' => 'invoices.view',
        ],
        [
            'text' => 'GST Settings',
            'route' => 'admin.gst-settings.edit',
            'icon' => 'bi bi-percent',
            'can' => 'settings.edit',
        ],

        ['header' => 'SUPPORT', 'can' => 'admin.panel'],
        [
            'text' => 'Tickets',
            'icon' => 'bi bi-ticket',
            'can' => 'tickets.view',
            'submenu' => [
                ['text' => 'All Tickets', 'route' => 'admin.tickets.index', 'icon' => 'bi bi-circle', 'can' => 'tickets.view'],
                ['text' => 'Open Tickets', 'route' => 'admin.tickets.index', 'icon' => 'bi bi-circle', 'can' => 'tickets.view', 'active' => ['admin/tickets/open*']],
                ['text' => 'Create Ticket', 'route' => 'admin.tickets.create', 'icon' => 'bi bi-circle', 'can' => 'tickets.create'],
            ],
        ],
        [
            'text' => 'Knowledge Base',
            'icon' => 'bi bi-book',
            'can' => 'kb.view',
            'submenu' => [
                ['text' => 'All Articles', 'route' => 'admin.kb.index', 'icon' => 'bi bi-circle', 'can' => 'kb.view'],
                ['text' => 'Add Article', 'route' => 'admin.kb.create', 'icon' => 'bi bi-circle', 'can' => 'kb.create'],
            ],
        ],
        [
            'text' => 'Live Chat',
            'route' => 'admin.chat.index',
            'icon' => 'bi bi-chat-dots',
            'can' => 'tickets.view',
        ],

        ['header' => 'ANALYTICS', 'can' => 'admin.panel'],
        [
            'text' => 'Reports',
            'icon' => 'bi bi-bar-chart',
            'can' => 'reports.view',
            'submenu' => [
                ['text' => 'Sales Report', 'route' => 'admin.reports.sales', 'icon' => 'bi bi-circle', 'can' => 'reports.view'],
                ['text' => 'Revenue Report', 'route' => 'admin.reports.revenue', 'icon' => 'bi bi-circle', 'can' => 'reports.view'],
                ['text' => 'Export', 'route' => 'admin.reports.export', 'icon' => 'bi bi-circle', 'can' => 'reports.export'],
            ],
        ],
        [
            'text' => 'Analytics',
            'route' => 'admin.analytics.index',
            'icon' => 'bi bi-graph-up',
            'can' => 'analytics.view',
        ],

        ['header' => 'INFRASTRUCTURE', 'can' => 'admin.panel'],
        [
            'text' => 'Datacenters',
            'route' => 'admin.datacenters.index',
            'icon' => 'bi bi-building',
            'can' => 'hosting.manage',
        ],
        [
            'text' => 'IP Manager',
            'icon' => 'bi bi-diagram-3',
            'can' => 'hosting.manage',
            'submenu' => [
                ['text' => 'Subnets', 'route' => 'admin.ip-subnets.index', 'icon' => 'bi bi-circle'],
                ['text' => 'IP Addresses', 'route' => 'admin.ip-addresses.index', 'icon' => 'bi bi-circle'],
                ['text' => 'VLANs', 'route' => 'admin.vlans.index', 'icon' => 'bi bi-circle'],
                ['text' => 'DNS Zones', 'route' => 'admin.dns-zones.index', 'icon' => 'bi bi-circle'],
            ],
        ],
        [
            'text' => 'Inventory',
            'route' => 'admin.inventory-assets.index',
            'icon' => 'bi bi-boxes',
            'can' => 'hosting.manage',
        ],

        ['header' => 'SYSTEM', 'can' => 'admin.panel'],
        [
            'text' => 'Users',
            'route' => 'admin.users.index',
            'icon' => 'bi bi-person-badge',
            'can' => 'users.view',
        ],
        [
            'text' => 'Roles & Permissions',
            'route' => 'adminlte.roles.index',
            'icon' => 'bi bi-shield-lock',
            'can' => 'manage-roles',
        ],
        [
            'text' => 'Email Templates',
            'route' => 'admin.email-templates.index',
            'icon' => 'bi bi-envelope-paper',
            'can' => 'settings.view',
        ],
        [
            'text' => 'Settings',
            'route' => 'admin.settings.index',
            'icon' => 'bi bi-gear',
            'can' => 'settings.view',
        ],
        [
            'text' => 'Activity Log',
            'route' => 'admin.activity-log.index',
            'icon' => 'bi bi-clock-history',
            'can' => 'settings.view',
        ],

        ['header' => 'CLIENT PORTAL', 'role' => 'client'],
        [
            'text' => 'Dashboard',
            'route' => 'client.dashboard',
            'icon' => 'bi bi-speedometer2',
            'role' => 'client',
        ],
        [
            'text' => 'Notifications',
            'route' => 'client.notifications.index',
            'icon' => 'bi bi-bell',
            'role' => 'client',
        ],
        [
            'text' => 'Products/Services',
            'route' => 'client.hosting.index',
            'icon' => 'bi bi-hdd-rack',
            'role' => 'client',
        ],
        [
            'text' => 'Store',
            'route' => 'client.store.index',
            'icon' => 'bi bi-shop',
            'role' => 'client',
        ],
        [
            'text' => 'Domains',
            'route' => 'client.domains.index',
            'icon' => 'bi bi-globe2',
            'role' => 'client',
        ],
        [
            'text' => 'Invoices',
            'route' => 'client.invoices.index',
            'icon' => 'bi bi-receipt',
            'role' => 'client',
        ],
        [
            'text' => 'Tickets',
            'route' => 'client.tickets.index',
            'icon' => 'bi bi-life-preserver',
            'role' => 'client',
        ],
        [
            'text' => 'Knowledge Base',
            'route' => 'client.kb.index',
            'icon' => 'bi bi-book',
            'role' => 'client',
        ],
        [
            'text' => 'Wallet',
            'route' => 'client.wallet.index',
            'icon' => 'bi bi-wallet2',
            'role' => 'client',
        ],
        [
            'text' => 'Profile',
            'route' => 'client.profile',
            'icon' => 'bi bi-person-circle',
            'role' => 'client',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Menu filters
    |--------------------------------------------------------------------------
    |
    | Filters transform each menu item before rendering. Add your own classes
    | here (must implement ColorlibHQ\AdminLte\Menu\Filters\FilterInterface).
    | The defaults handle gates, active state, hrefs, and search items.
    |
    */

    // ActiveRouteFilter replaces the package's stock ActiveFilter: this menu is
    // built from named `route` keys, which the stock filter cannot match. See
    // App\Support\AdminLte\ActiveRouteFilter (and its Feature test) before
    // changing this. Kept in the same position the stock filter occupied.
    'filters' => [
        RoleFilter::class,
        GateFilter::class,
        RouteExistsFilter::class,
        HrefFilter::class,
        NotificationBadgeFilter::class,
        ActiveRouteFilter::class,
        SearchFilter::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Plugins
    |--------------------------------------------------------------------------
    |
    | Optional JavaScript libraries integrated with AdminLTE 4. Disable plugins
    | you don't use to avoid loading unnecessary assets.
    |
    */

    'plugins' => [
        'flatpickr' => [
            'enabled' => false,
            'css' => 'vendor/flatpickr/flatpickr.min.css',
            'js' => 'vendor/flatpickr/flatpickr.min.js',
        ],
        'tom_select' => [
            'enabled' => false,
            'css' => 'vendor/tom-select/tom-select.bootstrap5.min.css',
            'js' => 'vendor/tom-select/tom-select.complete.min.js',
        ],
        'tabulator' => [
            'enabled' => false,
            'css' => 'vendor/tabulator-tables/tabulator.min.css',
            'js' => 'vendor/tabulator-tables/tabulator.min.js',
        ],
        'quill' => [
            'enabled' => false,
            'css' => 'vendor/quill/quill.snow.css',
            'js' => 'vendor/quill/quill.min.js',
        ],
        'apexcharts' => [
            'enabled' => false,
            'js' => 'vendor/apexcharts/apexcharts.min.js',
        ],
        'jsvectormap' => [
            'enabled' => false,
            'css' => 'vendor/jsvectormap/jsvectormap.min.css',
            // The library first, then the world map data (registers the 'world' map).
            'js' => [
                'vendor/jsvectormap/jsvectormap.min.js',
                'vendor/jsvectormap/maps/world.js',
            ],
        ],
        'fullcalendar' => [
            'enabled' => false,
            'css' => 'vendor/fullcalendar/index.global.min.css',
            'js' => 'vendor/fullcalendar/index.global.min.js',
        ],
        'sortablejs' => [
            'enabled' => false,
            'js' => 'vendor/sortablejs/sortablejs.min.js',
        ],
    ],

];
