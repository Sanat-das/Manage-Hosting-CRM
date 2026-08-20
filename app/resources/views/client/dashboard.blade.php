@extends('adminlte::page')

@section('title', __('adminlte.dashboard'))

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">{{ __('adminlte.client_portal') }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ __('adminlte.dashboard') }}</li>
            </ol>
        </div>
    </div>
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

    {{-- Portal summary (mirrors reference getPortalSummary) --}}
    <div class="row">
        <div class="col-lg-3 col-6">
            <x-adminlte-small-box :title="$summary['hosting_accounts']" text="Products/Services"
                                  icon="bi bi-hdd-stack" theme="primary" />
        </div>
        <div class="col-lg-3 col-6">
            <x-adminlte-small-box :title="$summary['active_domains']" text="Active Domains"
                                  icon="bi bi-globe2" theme="info" />
        </div>
        <div class="col-lg-3 col-6">
            <x-adminlte-small-box :title="$summary['open_invoices']" text="Open Invoices"
                                  icon="bi bi-receipt" theme="warning" />
        </div>
        <div class="col-lg-3 col-6">
            <x-adminlte-small-box :title="$summary['open_tickets']" text="Open Tickets"
                                  icon="bi bi-life-preserver" theme="success" />
        </div>
    </div>

    <div class="row">
        {{-- Recent invoices --}}
        <div class="col-md-6">
            <x-adminlte-card icon="bi bi-receipt" title="Recent Invoices">
                @forelse ($customer->invoices as $invoice)
                    <div class="d-flex justify-content-between border-bottom py-2">
                        <div>
                            <a href="{{ route('client.invoices.show', $invoice) }}" class="text-decoration-none">
                                <strong>{{ $invoice->invoice_no }}</strong>
                            </a>
                            <div class="text-muted small">{{ $invoice->due_date?->format('M j, Y') }}</div>
                        </div>
                        <div class="text-end">
                            <div>{{ number_format($invoice->total, 2) }}</div>
                            @php
                                $ibadge = ['paid' => 'success', 'sent' => 'info', 'overdue' => 'danger', 'draft' => 'secondary', 'cancelled' => 'dark'][$invoice->status] ?? 'secondary';
                            @endphp
                            <span class="badge bg-{{ $ibadge }}">{{ ucfirst($invoice->status) }}</span>
                        </div>
                    </div>
                @empty
                    <p class="text-muted mb-0">No open invoices.</p>
                @endforelse
            </x-adminlte-card>
        </div>

        {{-- Recent tickets --}}
        <div class="col-md-6">
            <x-adminlte-card icon="bi bi-life-preserver" title="Recent Tickets">
                @forelse ($customer->tickets as $ticket)
                    <div class="d-flex justify-content-between border-bottom py-2">
                        <div>
                            <strong>{{ $ticket->ticket_no }}</strong>
                            <div class="text-muted small">{{ $ticket->subject }}</div>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-{{ ['low' => 'info', 'medium' => 'warning', 'high' => 'danger', 'urgent' => 'dark'][$ticket->priority] ?? 'secondary' }}">{{ ucfirst($ticket->priority) }}</span>
                        </div>
                    </div>
                @empty
                    <p class="text-muted mb-0">No open tickets.</p>
                @endforelse
            </x-adminlte-card>
        </div>
    </div>

    <div class="row">
        {{-- Account balance --}}
        <div class="col-md-4">
            <x-adminlte-card icon="bi bi-wallet2" title="Account Balance">
                <div class="text-center py-2">
                    <div class="fs-3 {{ $customer->balance < 0 ? 'text-danger' : '' }}">{{ number_format($customer->balance, 2) }}</div>
                    <div class="text-muted small">Credit: {{ number_format($customer->credit, 2) }}</div>
                </div>
            </x-adminlte-card>
        </div>

        {{-- Recent activity --}}
        <div class="col-md-8">
            <x-adminlte-card icon="bi bi-clock-history" title="Recent Activity">
                @forelse ($recentActivity as $entry)
                    <div class="d-flex justify-content-between border-bottom py-2">
                        <div>
                            <span class="badge bg-info me-2">{{ $entry->action }}</span>
                            {{ $entry->description }}
                        </div>
                        <div class="text-muted small" style="white-space: nowrap;">
                            {{ $entry->created_at?->format('M j, H:i') }}
                        </div>
                    </div>
                @empty
                    <p class="text-muted mb-0">No activity yet.</p>
                @endforelse
            </x-adminlte-card>
        </div>
    </div>

    {{-- Featured products --}}
    @if (! empty($featuredProducts) && $featuredProducts->isNotEmpty())
        <x-adminlte-card icon="bi bi-shop" title="Featured Products">
            <div class="row">
                @foreach ($featuredProducts as $fp)
                    <div class="col-md-4 mb-3">
                        <div class="card h-100 border">
                            <div class="card-body">
                                <h6 class="card-title">{{ $fp->name }}</h6>
                                <p class="card-text small text-muted">{{ Str::limit($fp->description, 70) }}</p>
                                <span class="badge bg-primary">From ₹{{ number_format($fp->price, 2) }}/mo</span>
                            </div>
                            <div class="card-footer text-end">
                                <a href="{{ route('client.store.show', $fp) }}" class="btn btn-sm btn-outline-primary">View</a>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="text-end">
                <a href="{{ route('client.store.index') }}" class="btn btn-sm btn-primary"><i class="bi bi-shop me-1"></i> Browse Store</a>
            </div>
        </x-adminlte-card>
    @endif
@stop
