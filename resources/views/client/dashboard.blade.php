@extends('adminlte::page')

@section('title', __('adminlte.dashboard'))

@section('content_header')
    <x-ui.page-header :title="__('adminlte.client_portal')" subtitle="Your services, invoices, and support at a glance" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => __('adminlte.dashboard'), 'active' => true],
    ]">
        <x-slot:actions>
            <div class="d-flex align-items-center gap-2">
                <a href="{{ route('client.store.index') }}" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1" style="border-radius: var(--radius-md); font-size: var(--text-sm);">
                    <i class="bi bi-shop"></i> Browse store
                </a>
                <a href="{{ route('client.profile') }}" class="btn btn-sm btn-primary d-inline-flex align-items-center gap-1" style="border-radius: var(--radius-md); font-size: var(--text-sm);">
                    <i class="bi bi-person-circle"></i> My profile
                </a>
            </div>
        </x-slot:actions>
    </x-ui.page-header>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    @if (session()->has('impersonator_id'))
        <x-adminlte-alert theme="warning" dismissible>
            You are currently viewing the portal as <strong>{{ auth()->user()->full_name }}</strong>.
            <a href="{{ route('admin.impersonate.stop') }}" class="alert-link">Stop impersonation</a>.
        </x-adminlte-alert>
    @endif

    {{-- Portal summary --}}
    <div class="row g-4 mb-1">
        <div class="col-lg-3 col-6">
            <x-adminlte-small-box :title="$summary['hosting_accounts']" text="Products/Services" icon="bi bi-hdd-stack" theme="primary" />
        </div>
        <div class="col-lg-3 col-6">
            <x-adminlte-small-box :title="$summary['active_domains']" text="Active Domains" icon="bi bi-globe2" theme="info" />
        </div>
        <div class="col-lg-3 col-6">
            <x-adminlte-small-box :title="$summary['open_invoices']" text="Open Invoices" icon="bi bi-receipt" theme="warning" />
        </div>
        <div class="col-lg-3 col-6">
            <x-adminlte-small-box :title="$summary['open_tickets']" text="Open Tickets" icon="bi bi-life-preserver" theme="success" />
        </div>
    </div>

    <div class="row g-4">
        {{-- Recent invoices --}}
        <div class="col-md-6">
            <x-adminlte-card icon="bi bi-receipt" title="Recent Invoices">
                <x-slot:tools>
                    <a href="{{ route('client.invoices.index') }}" class="btn btn-sm btn-outline-secondary py-1 px-2" style="border-radius: var(--radius-md); font-size: var(--text-xs);">View all</a>
                </x-slot:tools>
                @forelse ($customer->invoices as $invoice)
                    <div class="d-flex justify-content-between align-items-center border-bottom py-2" style="border-color: var(--bs-border-color) !important; transition: background var(--duration-fast) var(--ease-default);">
                        <div class="min-w-0">
                            <a href="{{ route('client.invoices.show', $invoice) }}" class="text-decoration-none fw-semibold" style="color: var(--color-primary); font-size: var(--text-sm);">{{ $invoice->invoice_no }}</a>
                            <div class="text-muted small" style="font-size: var(--text-xs); font-variant-numeric: tabular-nums;">Due {{ $invoice->due_date?->format('M j, Y') ?? '—' }}</div>
                        </div>
                        <div class="text-end flex-shrink-0 ms-3">
                            <div style="font-size: var(--text-sm); font-variant-numeric: tabular-nums; font-weight: 500; letter-spacing: -0.01em;">₹{{ number_format($invoice->total, 2) }}</div>
                            <x-adminlte.partials.status-badge :status="$invoice->status" />
                        </div>
                    </div>
                @empty
                    <x-adminlte.partials.empty-state icon="bi bi-receipt" title="No open invoices" message="You're all caught up — new invoices will appear here." size="sm" />
                @endforelse
            </x-adminlte-card>
        </div>

        {{-- Recent tickets --}}
        <div class="col-md-6">
            <x-adminlte-card icon="bi bi-life-preserver" title="Recent Tickets">
                <x-slot:tools>
                    <a href="{{ route('client.tickets.index') }}" class="btn btn-sm btn-outline-secondary py-1 px-2" style="border-radius: var(--radius-md); font-size: var(--text-xs);">View all</a>
                </x-slot:tools>
                @forelse ($customer->tickets as $ticket)
                    <div class="d-flex justify-content-between align-items-center border-bottom py-2" style="border-color: var(--bs-border-color) !important;">
                        <div class="min-w-0">
                            <div class="fw-semibold" style="font-size: var(--text-sm); color: var(--color-text);">{{ $ticket->ticket_no }}</div>
                            <div class="text-muted small text-truncate" style="font-size: var(--text-xs); max-width: 18rem;">{{ $ticket->subject }}</div>
                        </div>
                        <div class="text-end flex-shrink-0 ms-3">
                            <x-adminlte.partials.status-badge :status="$ticket->priority" />
                            <div class="text-muted small mt-1" style="font-size: var(--text-xs);"><x-adminlte.partials.status-badge :status="$ticket->status" variant="subtle" /></div>
                        </div>
                    </div>
                @empty
                    <x-adminlte.partials.empty-state icon="bi bi-life-preserver" title="No open tickets" message="Support tickets will show here when you need help." size="sm" />
                @endforelse
            </x-adminlte-card>
        </div>
    </div>

    <div class="row g-4 mt-1">
        {{-- Account balance --}}
        <div class="col-md-4">
            <x-adminlte-card icon="bi bi-wallet2" title="Account Balance">
                <div class="text-center py-3">
                    <div class="fw-bold {{ $customer->balance < 0 ? 'text-danger' : '' }}" style="font-size: var(--text-2xl); letter-spacing: var(--tracking-tight); line-height: var(--leading-tight); font-variant-numeric: tabular-nums;">₹{{ number_format($customer->balance, 2) }}</div>
                    <div class="text-muted small mt-1" style="font-size: var(--text-sm); font-variant-numeric: tabular-nums;">Credit: <span style="color: var(--color-text); font-weight: 500;">₹{{ number_format($customer->credit, 2) }}</span></div>
                    <div class="mt-3">
                        <a href="{{ route('client.wallet.index') }}" class="btn btn-sm btn-outline-primary" style="border-radius: var(--radius-md); font-size: var(--text-sm);"><i class="bi bi-wallet2 me-1"></i> Manage wallet</a>
                    </div>
                </div>
            </x-adminlte-card>
        </div>

        {{-- Recent activity --}}
        <div class="col-md-8">
            <x-adminlte-card icon="bi bi-clock-history" title="Recent Activity">
                @forelse ($recentActivity as $entry)
                    <div class="d-flex justify-content-between align-items-start border-bottom py-2 gap-3" style="border-color: var(--bs-border-color) !important;">
                        <div class="d-flex align-items-start gap-2 min-w-0">
                            <span class="badge rounded-pill text-bg-info flex-shrink-0 mt-1" style="font-size: var(--text-xs); font-weight: 500; letter-spacing: 0.02em; padding: 0.28em 0.55em;">{{ $entry->action }}</span>
                            <span class="small" style="font-size: var(--text-sm); line-height: var(--leading-normal); color: var(--color-text);">{{ $entry->description }}</span>
                        </div>
                        <div class="text-muted small flex-shrink-0" style="font-size: var(--text-xs); white-space: nowrap; font-variant-numeric: tabular-nums;">{{ $entry->created_at?->format('M j, H:i') }}</div>
                    </div>
                @empty
                    <x-adminlte.partials.empty-state icon="bi bi-clock-history" title="No activity yet" message="Your recent actions will appear here." size="sm" />
                @endforelse
            </x-adminlte-card>
        </div>
    </div>

    {{-- Featured products --}}
    @if (! empty($featuredProducts) && $featuredProducts->isNotEmpty())
        <x-adminlte-card icon="bi bi-shop" title="Featured Products">
            <div class="row g-4">
                @foreach ($featuredProducts as $fp)
                    <div class="col-md-4">
                        <div class="card h-100" style="border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); border-color: var(--bs-border-color); overflow: hidden;">
                            <div class="card-body" style="padding: var(--space-4); display: flex; flex-direction: column; gap: var(--space-2);">
                                <h6 class="card-title mb-0" style="font-size: var(--text-base); font-weight: 600; letter-spacing: var(--tracking-tight); line-height: var(--leading-tight); color: var(--color-text);">{{ $fp->name }}</h6>
                                <p class="card-text small text-muted mb-0" style="font-size: var(--text-sm); line-height: var(--leading-normal);">{{ Str::limit($fp->description, 80) }}</p>
                                <div class="mt-auto pt-2">
                                    <span class="badge text-bg-primary" style="font-size: var(--text-xs); font-weight: 500; letter-spacing: 0.02em; padding: 0.32em 0.6em; border-radius: var(--radius-full);">From ₹{{ number_format($fp->price, 2) }}/mo</span>
                                </div>
                            </div>
                            <div class="card-footer d-flex justify-content-end align-items-center gap-2" style="background: var(--color-bg-subtle); border-top-color: var(--bs-border-color); padding: var(--space-3) var(--space-4);">
                                <a href="{{ route('client.store.show', $fp) }}" class="btn btn-sm btn-outline-primary" style="border-radius: var(--radius-md); font-size: var(--text-sm);">View</a>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="text-end mt-4">
                <a href="{{ route('client.store.index') }}" class="btn btn-sm btn-primary d-inline-flex align-items-center gap-1" style="border-radius: var(--radius-md); font-size: var(--text-sm);"><i class="bi bi-shop"></i> Browse Store</a>
            </div>
        </x-adminlte-card>
    @endif
@stop
