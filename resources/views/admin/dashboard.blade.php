@extends('adminlte::page')

@section('title', __('adminlte.dashboard'))

@section('content_header')
    <x-ui.page-header :title="__('adminlte.dashboard')" subtitle="Overview of your hosting business" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => __('adminlte.dashboard'), 'active' => true],
    ]">
        <x-slot:actions>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="text-muted small d-none d-md-inline" style="font-size: var(--text-xs);">{{ now()->format('M j, Y') }}</span>
                @can('reports.view')
                    <a href="{{ route('admin.reports.sales') }}" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1" style="border-radius: var(--radius-md); font-size: var(--text-sm);">
                        <i class="bi bi-download"></i> Export
                    </a>
                @endcan
                <a href="{{ route('admin.customers.index') }}" class="btn btn-sm btn-primary d-inline-flex align-items-center gap-1" style="border-radius: var(--radius-md); font-size: var(--text-sm);">
                    <i class="bi bi-plus-lg"></i> Add customer
                </a>
            </div>
        </x-slot:actions>
    </x-ui.page-header>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    @php
        $m = $metrics ?? ['customers'=>0,'activeServices'=>0,'openInvoices'=>0,'overdueInvoices'=>0,'openTickets'=>0,'urgentTickets'=>0,'revenueMtd'=>0,'revenueMtdPrev'=>0];
        $revCurr = (float) ($m['revenueMtd'] ?? 0);
        $revPrev = (float) ($m['revenueMtdPrev'] ?? 0);
        $revTrend = null;
        $revTrendUp = true;
        if ($revPrev > 0) {
            $revTrend = round((($revCurr - $revPrev) / $revPrev) * 100);
            $revTrendUp = $revTrend >= 0;
        } elseif ($revCurr > 0) {
            $revTrend = 100;
            $revTrendUp = true;
        }
        $revTrendLabel = $revTrend !== null ? (($revTrendUp ? '↑' : '↓') . ' ' . abs($revTrend) . '% vs last month') : '— vs last month';
        $hasRevenue = array_sum($revenueChart['values'] ?? []) > 0;
        $hasTickets = !empty($ticketsByStatus) && array_sum($ticketsByStatus) > 0;
        // x-adminlte-chart's Chart::chartConfig() does array_merge($base, $options) —
        // a matching top-level key (e.g. our own 'chart'/'xaxis' below) replaces the
        // base entirely rather than deep-merging, so `type`/`height`/`categories`
        // must be repeated here or they silently vanish from the rendered config.
        $revenueChartOptions = [
            'colors' => ['#0d9f6e'],
            'chart' => ['type' => 'area', 'height' => 280, 'toolbar' => ['show' => false], 'zoom' => ['enabled' => false], 'foreColor' => '#64748b', 'fontFamily' => 'Instrument Sans'],
            'stroke' => ['curve' => 'smooth', 'width' => 2],
            'fill' => ['type' => 'gradient', 'gradient' => ['shadeIntensity' => 1, 'opacityFrom' => 0.28, 'opacityTo' => 0.02]],
            'dataLabels' => ['enabled' => false],
            'grid' => ['borderColor' => '#e2e8f0', 'strokeDashArray' => 3],
            'xaxis' => ['categories' => $revenueChart['labels'] ?? ['Jan','Feb','Mar','Apr','May','Jun'], 'labels' => ['style' => ['fontSize' => '11px']], 'axisBorder' => ['show' => false], 'axisTicks' => ['show' => false]],
            'yaxis' => ['labels' => ['style' => ['fontSize' => '11px']]],
        ];
        $ticketsChartOptions = [
            'labels' => array_keys($ticketsByStatus ?? []),
            'colors' => ['#5b5bd6','#0284c7','#d97706','#dc2626','#0d9f6e','#64748b'],
            'chart' => ['type' => 'donut', 'height' => 280, 'foreColor' => '#64748b', 'fontFamily' => 'Instrument Sans'],
            'legend' => ['position' => 'bottom', 'fontSize' => '11px'],
            'stroke' => ['width' => 0],
            'dataLabels' => ['enabled' => true, 'style' => ['fontSize' => '11px']],
            'plotOptions' => ['pie' => ['donut' => ['size' => '62%']]],
        ];
    @endphp

    {{-- Metrics row — enterprise 8px system --}}
    <div class="row g-4 mb-1">
        <div class="col-lg-3 col-6">
            <x-adminlte-small-box :title="number_format($m['customers'] ?? 0)" text="Total Customers" icon="bi bi-people" theme="primary" :url="route('admin.customers.index')" url-text="View all" />
            <div class="small text-muted mt-1 d-flex align-items-center gap-1" style="font-size: var(--text-xs); font-variant-numeric: tabular-nums;"><i class="bi bi-graph-up-arrow" style="font-size: 0.7rem; opacity:0.7;"></i> Active accounts overview</div>
        </div>
        <div class="col-lg-3 col-6">
            <x-adminlte-small-box :title="number_format($m['activeServices'] ?? 0)" text="Active Services" icon="bi bi-hdd-stack" theme="success" :url="route('admin.hosting.index')" url-text="Manage services" />
            <div class="small text-muted mt-1 d-flex align-items-center gap-1" style="font-size: var(--text-xs);"><i class="bi bi-check-circle" style="font-size: 0.7rem; opacity:0.7;"></i> Provisioned & running</div>
        </div>
        <div class="col-lg-3 col-6">
            <x-adminlte-small-box :title="number_format($m['openInvoices'] ?? 0)" text="Open Invoices" icon="bi bi-receipt" theme="warning" :url="route('admin.invoices.index')" url-text="View invoices" />
            <div class="small d-flex align-items-center gap-1 mt-1" style="font-size: var(--text-xs);">
                @if(($m['overdueInvoices'] ?? 0) > 0)
                    <span class="badge rounded-pill text-bg-danger" style="font-size: 0.65rem; padding: 0.2em 0.45em;">{{ $m['overdueInvoices'] }} overdue</span>
                    <span class="text-muted" style="font-variant-numeric: tabular-nums;">out of {{ number_format($m['openInvoices']) }} open</span>
                @else
                    <span class="text-muted">No overdue — all clear</span>
                @endif
            </div>
        </div>
        <div class="col-lg-3 col-6">
            <x-adminlte-small-box :title="number_format($m['openTickets'] ?? 0)" text="Open Tickets" icon="bi bi-life-preserver" theme="info" :url="route('admin.tickets.index')" url-text="View tickets" />
            <div class="small d-flex align-items-center gap-1 mt-1" style="font-size: var(--text-xs);">
                @if(($m['urgentTickets'] ?? 0) > 0)
                    <span class="badge rounded-pill text-bg-danger" style="font-size: 0.65rem; padding: 0.2em 0.45em;">{{ $m['urgentTickets'] }} urgent</span>
                    <span class="text-muted">needs attention</span>
                @else
                    <span class="text-muted">No urgent tickets</span>
                @endif
            </div>
        </div>
    </div>

    {{-- Revenue KPI stripe --}}
    <div class="card mb-4" style="border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); border-color: var(--bs-border-color); overflow:hidden;">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3 py-3 px-4" style="background: var(--color-bg-subtle);">
            <div class="d-flex align-items-center gap-3">
                <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0" style="width: 2.5rem; height: 2.5rem; background: color-mix(in srgb, var(--color-success) 14%, var(--bs-body-bg)); color: var(--color-success); font-size: 1.1rem;"><i class="bi bi-currency-rupee"></i></span>
                <div>
                    <div class="text-muted small" style="font-size: var(--text-xs); letter-spacing: 0.04em; text-transform: uppercase; font-weight: 600;">Revenue — MTD</div>
                    <div class="fw-bold" style="font-size: var(--text-xl); letter-spacing: var(--tracking-tight); line-height: 1; font-variant-numeric: tabular-nums;"><x-adminlte.partials.currency :value="$revCurr" /></div>
                </div>
            </div>
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <span class="badge rounded-pill {{ $revTrend !== null && $revTrendUp ? 'text-bg-success' : ($revTrend !== null ? 'text-bg-danger' : 'text-bg-secondary') }}" style="font-size: var(--text-xs); font-weight: 500; letter-spacing: 0.02em; padding: 0.35em 0.7em;">{{ $revTrendLabel }}</span>
                <span class="text-muted small d-none d-sm-inline" style="font-size: var(--text-xs);">Previous month: <span style="font-variant-numeric: tabular-nums;"><x-adminlte.partials.currency :value="$revPrev" /></span></span>
                @can('reports.view')
                    <a href="{{ route('admin.reports.revenue') }}" class="btn btn-sm btn-outline-secondary" style="border-radius: var(--radius-md); font-size: var(--text-xs);">View report <i class="bi bi-arrow-right ms-1"></i></a>
                @endcan
            </div>
        </div>
    </div>

    {{-- Charts row --}}
    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <x-adminlte-card icon="bi bi-graph-up" title="Revenue — Last 6 months">
                <x-slot:tools>
                    <span class="badge text-bg-light border" style="font-size: var(--text-xs); font-weight: 500;">INR</span>
                </x-slot:tools>
                @if($hasRevenue)
                    <x-adminlte-chart
                        id="mh-revenue-chart"
                        type="area"
                        height="280px"
                        :series="[['name' => 'Revenue', 'data' => $revenueChart['values'] ?? [0,0,0,0,0,0]]]"
                        :categories="$revenueChart['labels'] ?? ['Jan','Feb','Mar','Apr','May','Jun']"
                        :options="$revenueChartOptions"
                    />
                @else
                    <x-adminlte.partials.empty-state icon="bi bi-graph-up" title="No revenue data yet" message="Paid invoices will appear here once billing begins. The chart tracks the last 6 months." size="sm" />
                @endif
            </x-adminlte-card>
        </div>
        <div class="col-lg-4">
            <x-adminlte-card icon="bi bi-pie-chart" title="Tickets by status">
                <x-slot:tools>
                    <span class="text-muted small" style="font-size: var(--text-xs);">{{ array_sum($ticketsByStatus ?? []) }} open</span>
                </x-slot:tools>
                @if($hasTickets)
                    <x-adminlte-chart
                        id="mh-tickets-chart"
                        type="donut"
                        height="280px"
                        :series="array_values($ticketsByStatus)"
                        :options="$ticketsChartOptions"
                    />
                @else
                    <x-adminlte.partials.empty-state icon="bi bi-life-preserver" title="No open tickets" message="All caught up — ticket breakdown will show here when tickets are created." size="sm" />
                @endif
            </x-adminlte-card>
        </div>
    </div>

    {{-- Activity + Quick actions --}}
    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <x-adminlte-card icon="bi bi-clock-history" title="Recent Activity">
                <x-slot:tools>
                    @can('settings.view')
                        <a href="{{ route('admin.activity-log.index') }}" class="btn btn-sm btn-outline-secondary py-1 px-2" style="border-radius: var(--radius-md); font-size: var(--text-xs);">View all</a>
                    @endcan
                </x-slot:tools>
                @forelse(($recentActivity ?? collect()) as $entry)
                    <div class="d-flex gap-3 py-2 border-bottom align-items-start" style="border-color: var(--bs-border-color) !important;">
                        <span class="badge rounded-pill flex-shrink-0 mt-1" style="font-size: var(--text-xs); font-weight: 500; letter-spacing: 0.02em; padding: 0.28em 0.62em; background: color-mix(in srgb, var(--color-primary) 12%, var(--color-surface)); color: var(--color-primary); border: 1px solid color-mix(in srgb, var(--color-primary) 18%, transparent);">{{ $entry->action ?? 'activity' }}</span>
                        <div class="flex-fill min-w-0">
                            <div class="small" style="font-size: var(--text-sm); line-height: var(--leading-normal); color: var(--color-text);">{{ $entry->description ?? '—' }}</div>
                            @if($entry->user || $entry->customer)
                                <div class="text-muted small" style="font-size: var(--text-xs);">
                                    @if($entry->user) {{ $entry->user->full_name ?? $entry->user->name }} @endif
                                    @if($entry->user && $entry->customer) · @endif
                                    @if($entry->customer) {{ $entry->customer->full_name }} @endif
                                </div>
                            @endif
                        </div>
                        <div class="text-muted small flex-shrink-0" style="font-size: var(--text-xs); white-space: nowrap; font-variant-numeric: tabular-nums;">{{ $entry->created_at?->diffForHumans(null, true) ? $entry->created_at->diffForHumans(null, true).' ago' : ($entry->created_at?->format('M j, H:i') ?? '') }}</div>
                    </div>
                @empty
                    <x-adminlte.partials.empty-state icon="bi bi-clock-history" title="No activity yet" message="Actions like creating customers, invoices, and tickets will appear here." size="sm" />
                @endforelse
            </x-adminlte-card>
        </div>
        <div class="col-lg-4">
            <x-adminlte-card icon="bi bi-lightning" title="Quick actions">
                <div class="mh-quick-actions d-grid gap-2">
                    @can('customers.create')
                        <a href="{{ route('admin.customers.create') }}" class="btn btn-outline-secondary d-flex align-items-center gap-2 justify-content-start py-2 px-3" style="border-radius: var(--radius-md); font-size: var(--text-sm); font-weight: 500; border-color: var(--bs-border-color);">
                            <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0" style="width: 2rem; height: 2rem; background: color-mix(in srgb, var(--color-primary) 10%, transparent); color: var(--color-primary);"><i class="bi bi-person-plus"></i></span>
                            <span class="flex-fill text-start">Add Customer</span><i class="bi bi-chevron-right text-muted small"></i>
                        </a>
                    @endcan
                    @can('invoices.create')
                        <a href="{{ route('admin.invoices.create') }}" class="btn btn-outline-secondary d-flex align-items-center gap-2 justify-content-start py-2 px-3" style="border-radius: var(--radius-md); font-size: var(--text-sm); font-weight: 500; border-color: var(--bs-border-color);">
                            <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0" style="width: 2rem; height: 2rem; background: color-mix(in srgb, var(--color-warning) 14%, transparent); color: var(--color-warning);"><i class="bi bi-receipt"></i></span>
                            <span class="flex-fill text-start">Create Invoice</span><i class="bi bi-chevron-right text-muted small"></i>
                        </a>
                    @endcan
                    @can('tickets.create')
                        <a href="{{ route('admin.tickets.create') }}" class="btn btn-outline-secondary d-flex align-items-center gap-2 justify-content-start py-2 px-3" style="border-radius: var(--radius-md); font-size: var(--text-sm); font-weight: 500; border-color: var(--bs-border-color);">
                            <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0" style="width: 2rem; height: 2rem; background: color-mix(in srgb, var(--color-info) 12%, transparent); color: var(--color-info);"><i class="bi bi-life-preserver"></i></span>
                            <span class="flex-fill text-start">Create Ticket</span><i class="bi bi-chevron-right text-muted small"></i>
                        </a>
                    @endcan
                    @can('reports.view')
                        <a href="{{ route('admin.reports.sales') }}" class="btn btn-outline-secondary d-flex align-items-center gap-2 justify-content-start py-2 px-3" style="border-radius: var(--radius-md); font-size: var(--text-sm); font-weight: 500; border-color: var(--bs-border-color);">
                            <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0" style="width: 2rem; height: 2rem; background: color-mix(in srgb, var(--color-success) 12%, transparent); color: var(--color-success);"><i class="bi bi-bar-chart"></i></span>
                            <span class="flex-fill text-start">View Reports</span><i class="bi bi-chevron-right text-muted small"></i>
                        </a>
                    @endcan
                    @can('hosting.view')
                        <a href="{{ route('admin.hosting.index') }}" class="btn btn-outline-secondary d-flex align-items-center gap-2 justify-content-start py-2 px-3" style="border-radius: var(--radius-md); font-size: var(--text-sm); font-weight: 500; border-color: var(--bs-border-color);">
                            <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0" style="width: 2rem; height: 2rem; background: color-mix(in srgb, var(--color-neutral-500) 12%, transparent); color: var(--color-neutral-600);"><i class="bi bi-hdd-rack"></i></span>
                            <span class="flex-fill text-start">Products / Services</span><i class="bi bi-chevron-right text-muted small"></i>
                        </a>
                    @endcan
                </div>
                <div class="mt-3 pt-3 border-top small text-muted" style="font-size: var(--text-xs); border-color: var(--bs-border-color) !important; line-height: var(--leading-normal);">
                    Shortcuts respect your permissions — unavailable actions are hidden automatically.
                </div>
            </x-adminlte-card>
        </div>
    </div>

    {{-- Pending orders + Expiring domains preview --}}
    <div class="row g-4">
        <div class="col-lg-6">
            <x-adminlte-card icon="bi bi-cart" title="Pending Orders">
                <x-slot:tools>
                    @can('orders.view')
                        <a href="{{ route('admin.orders.index') }}" class="btn btn-sm btn-outline-secondary py-1 px-2" style="border-radius: var(--radius-md); font-size: var(--text-xs);">View all</a>
                    @endcan
                </x-slot:tools>
                @if(($pendingOrders ?? collect())->isNotEmpty())
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0" style="font-size: var(--text-sm);">
                            <thead>
                                <tr class="text-muted" style="font-size: var(--text-xs); letter-spacing: 0.04em; text-transform: uppercase;">
                                    <th class="border-0 fw-semibold">Order</th>
                                    <th class="border-0 fw-semibold">Customer</th>
                                    <th class="border-0 fw-semibold text-end" style="font-variant-numeric: tabular-nums;">Total</th>
                                    <th class="border-0 fw-semibold text-end">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($pendingOrders as $order)
                                    <tr>
                                        <td class="py-2">
                                            @can('orders.view')
                                                <a href="{{ route('admin.orders.index') }}" class="text-decoration-none fw-semibold" style="color: var(--color-primary);">{{ $order->order_no ?? '#'.$order->id }}</a>
                                            @else
                                                <span class="fw-semibold">{{ $order->order_no ?? '#'.$order->id }}</span>
                                            @endcan
                                            <div class="text-muted small" style="font-size: var(--text-xs);">{{ $order->created_at?->format('M j, Y') }}</div>
                                        </td>
                                        <td class="py-2" style="max-width: 10rem; overflow-wrap: anywhere;">{{ $order->customer?->full_name ?? '—' }}</td>
                                        <td class="py-2 text-end" style="font-variant-numeric: tabular-nums;"><x-adminlte.partials.currency :value="$order->total" /></td>
                                        <td class="py-2 text-end"><x-adminlte.partials.status-badge :status="$order->status" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <x-adminlte.partials.empty-state icon="bi bi-cart-check" title="No pending orders" message="New orders will appear here when customers place them." size="sm" />
                @endif
            </x-adminlte-card>
        </div>
        <div class="col-lg-6">
            <x-adminlte-card icon="bi bi-globe" title="Expiring Domains — 30 days">
                <x-slot:tools>
                    @can('domains.view')
                        <a href="{{ route('admin.domains.index') }}" class="btn btn-sm btn-outline-secondary py-1 px-2" style="border-radius: var(--radius-md); font-size: var(--text-xs);">View all</a>
                    @endcan
                </x-slot:tools>
                @if(($expiringDomains ?? collect())->isNotEmpty())
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0" style="font-size: var(--text-sm);">
                            <thead>
                                <tr class="text-muted" style="font-size: var(--text-xs); letter-spacing: 0.04em; text-transform: uppercase;">
                                    <th class="border-0 fw-semibold">Domain</th>
                                    <th class="border-0 fw-semibold">Customer</th>
                                    <th class="border-0 fw-semibold text-end">Expires</th>
                                    <th class="border-0 fw-semibold text-end">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($expiringDomains as $domain)
                                    <tr>
                                        <td class="py-2 fw-semibold" style="font-variant-numeric: tabular-nums;">{{ $domain->domain ?? $domain->name ?? '—' }}</td>
                                        <td class="py-2" style="max-width: 10rem; overflow-wrap: anywhere;">{{ $domain->customer?->full_name ?? '—' }}</td>
                                        <td class="py-2 text-end" style="font-variant-numeric: tabular-nums;">
                                            <span class="{{ $domain->expiry_date && $domain->expiry_date->diffInDays(now()) <= 7 ? 'text-danger fw-semibold' : '' }}">{{ $domain->expiry_date?->format('M j, Y') ?? '—' }}</span>
                                            @if($domain->expiry_date)
                                                <div class="text-muted small" style="font-size: var(--text-xs);">{{ $domain->expiry_date->diffForHumans(null, true) }} left</div>
                                            @endif
                                        </td>
                                        <td class="py-2 text-end"><x-adminlte.partials.status-badge :status="$domain->status" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <x-adminlte.partials.empty-state icon="bi bi-globe" title="No expiring domains" message="Domains expiring within 30 days will surface here for renewal follow-up." size="sm" />
                @endif
            </x-adminlte-card>
        </div>
    </div>

    <div class="mt-4 pt-3 border-top d-flex align-items-center justify-content-between flex-wrap gap-2 small text-muted" style="font-size: var(--text-xs); border-color: var(--bs-border-color) !important;">
        <span>Welcome back, <strong style="color: var(--color-text);">{{ auth()->user()->full_name }}</strong> — dashboard refreshed {{ now()->format('M j, H:i') }}.</span>
        <span class="d-inline-flex align-items-center gap-1"><i class="bi bi-shield-check"></i> Stripe · Linear-grade tokens — 8px system, tabular-nums, dark-mode aware charts.</span>
    </div>
@stop

@push('js')
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
document.addEventListener('DOMContentLoaded', function () {
    function applySemanticChartColors() {
        try {
            var cs = getComputedStyle(document.documentElement);
            var tok = function (name, fallback) { return (cs.getPropertyValue(name) || fallback).trim(); };
            var primary = tok('--color-primary', '#5b5bd6');
            var success = tok('--color-success', '#0d9f6e');
            var warning = tok('--color-warning', '#d97706');
            var danger = tok('--color-danger', '#dc2626');
            var info = tok('--color-info', '#0284c7');
            var muted = tok('--bs-secondary-color', tok('--color-text-muted', '#64748b'));

            var revenue = document.getElementById('mh-revenue-chart');
            if (revenue && revenue.apexChart) revenue.apexChart.updateOptions({ colors: [success] }, false, true);

            var tickets = document.getElementById('mh-tickets-chart');
            if (tickets && tickets.apexChart) {
                tickets.apexChart.updateOptions({ colors: [primary, info, warning, danger, success, muted] }, false, true);
            }
        } catch (e) {}
    }
    // adminlte.js already handles foreColor/grid/axis reactively; this only
    // owns the series colors specific to these two charts. Charts render
    // asynchronously, so wait for adminlte:charts-ready rather than assuming
    // el.apexChart exists here.
    document.addEventListener('adminlte:charts-ready', applySemanticChartColors);
    document.addEventListener('adminlte:theme-changed', applySemanticChartColors);
});
</script>
@endpush
