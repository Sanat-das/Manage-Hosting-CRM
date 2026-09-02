@extends('adminlte::page')

@section('title', $domain->name)

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0 text-truncate" title="{{ $domain->name }}">{{ $domain->name }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.domains.index') }}">Domains</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $domain->name }}</li>
            </ol>
        </div>
    </div>
@stop

@php
    $activeTab = (string) request()->query('tab', 'details');
    $tabs = [
        ['id' => 'details', 'label' => 'Details', 'icon' => 'bi bi-info-circle'],
        ['id' => 'nameservers', 'label' => 'Nameservers', 'icon' => 'bi bi-diagram-3'],
        ['id' => 'billing', 'label' => 'Billing', 'icon' => 'bi bi-credit-card'],
        ['id' => 'history', 'label' => 'History', 'icon' => 'bi bi-clock-history'],
    ];
    $isExpiringSoon = $domain->isExpiringSoon();
    $daysUntilExpiry = $domain->daysUntilExpiry();
    $isExpired = $domain->isExpired();
@endphp

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif
    @if (session('error'))
        <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert>
    @endif
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-adminlte-alert>
    @endif

    {{-- Domain header card --}}
    <x-adminlte-card>
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center rounded-circle bg-primary text-white"
                 style="width: 56px; height: 56px; font-size: 1.25rem; flex-shrink: 0;">
                <i class="bi bi-globe"></i>
            </div>
            <div class="flex-grow-1" style="min-width: 240px;">
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <h4 class="mb-0 text-break" style="word-break: break-all;">{{ $domain->name }}</h4>
                    <x-adminlte.partials.status-badge :status="$domain->status" />
                    @if ($domain->type)
                        <span class="badge text-bg-secondary">{{ ucfirst($domain->type) }}</span>
                    @endif
                    @if ($domain->registrar)
                        <span class="badge text-bg-info">{{ $domain->registrar }}</span>
                    @endif
                    @if ($isExpired)
                        <span class="badge text-bg-danger">Expired</span>
                    @elseif ($isExpiringSoon)
                        <span class="badge text-bg-warning">Expiring in {{ $daysUntilExpiry }} day{{ $daysUntilExpiry === 1 ? '' : 's' }}</span>
                    @endif
                </div>
                <div class="text-muted mt-1 d-flex flex-wrap align-items-center gap-2" style="font-size: var(--text-sm);">
                    @if ($domain->customer)
                        <span>
                            <i class="bi bi-person me-1"></i>
                            <a href="{{ route('admin.customers.show', $domain->customer) }}" class="text-decoration-none">
                                {{ $domain->customer->full_name }}
                            </a>
                        </span>
                        <span class="d-none d-sm-inline mx-1">|</span>
                    @else
                        <span><i class="bi bi-person me-1"></i><span class="text-muted">No customer</span></span>
                        <span class="d-none d-sm-inline mx-1">|</span>
                    @endif
                    <span>
                        <i class="bi bi-building me-1"></i>{{ $domain->registrar ?? 'No registrar' }}
                    </span>
                    <span class="d-none d-sm-inline mx-1">|</span>
                    <span>
                        <i class="bi bi-calendar3 me-1"></i>
                        @if ($domain->registration_date)
                            Reg. {{ $domain->registration_date->format('M j, Y') }}
                        @else
                            No registration date
                        @endif
                    </span>
                    <span class="d-none d-sm-inline mx-1">|</span>
                    <span class="{{ $isExpired ? 'text-danger fw-semibold' : ($isExpiringSoon ? 'text-warning fw-semibold' : '') }}">
                        <i class="bi bi-calendar-x me-1"></i>
                        @if ($domain->expiry_date)
                            Exp. {{ $domain->expiry_date->format('M j, Y') }}
                        @else
                            No expiry date
                        @endif
                    </span>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <a href="http://{{ $domain->name }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-box-arrow-up-right me-1"></i> Visit website
                </a>
                @can('domains.manage')
                    @if ($domain->status === 'active')
                        <form method="POST" action="{{ route('admin.domains.update', $domain) }}" class="d-inline">
                            @csrf @method('PUT')
                            <input type="hidden" name="status" value="suspended">
                            <button type="submit" class="btn btn-sm btn-outline-warning">
                                <i class="bi bi-pause-circle me-1"></i> Suspend
                            </button>
                        </form>
                    @elseif ($domain->status === 'suspended')
                        <form method="POST" action="{{ route('admin.domains.update', $domain) }}" class="d-inline">
                            @csrf @method('PUT')
                            <input type="hidden" name="status" value="active">
                            <button type="submit" class="btn btn-sm btn-outline-success">
                                <i class="bi bi-play-circle me-1"></i> Unsuspend
                            </button>
                        </form>
                    @endif
                    <a href="{{ route('admin.domains.edit', $domain) }}" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-pencil me-1"></i> Edit
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                            data-bs-target="#delete-domain-modal">
                        <i class="bi bi-trash me-1"></i> Delete
                    </button>
                @endcan
            </div>
        </div>
    </x-adminlte-card>

    {{-- Metric row --}}
    <x-adminlte.partials.metric-cards :items="[
        ['title' => $domain->registration_date?->format('M j, Y') ?? '—', 'text' => 'Registration Date', 'icon' => 'bi bi-calendar-check', 'theme' => 'primary'],
        ['title' => $domain->expiry_date?->format('M j, Y') ?? '—', 'text' => 'Expiry Date', 'icon' => 'bi bi-calendar-x', 'theme' => $isExpired ? 'danger' : ($isExpiringSoon ? 'warning' : 'info')],
        ['title' => ($domain->recurring_amount !== null ? '₹'.number_format((float) $domain->recurring_amount, 2) : '—'), 'text' => 'Recurring Amount', 'icon' => 'bi bi-currency-rupee', 'theme' => 'warning'],
        ['title' => ucfirst(str_replace('_',' ', (string) $domain->status)), 'text' => 'Status', 'icon' => 'bi bi-check-circle', 'theme' => 'success'],
    ]" />

    {{-- Tabbed detail --}}
    <x-adminlte-card>
        <x-adminlte.partials.detail-tabs :tabs="$tabs" :active-tab="$activeTab">
            {{-- Details --}}
            <div class="tab-pane fade {{ $activeTab === 'details' ? 'show active' : '' }}" id="details" role="tabpanel" aria-labelledby="details-tab">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless mb-0">
                            <tbody>
                                <tr><th class="w-25 text-muted">Domain</th><td><strong class="text-break" style="word-break: break-all;">{{ $domain->name }}</strong></td></tr>
                                <tr>
                                    <th class="text-muted">Customer</th>
                                    <td>
                                        @if ($domain->customer)
                                            <a href="{{ route('admin.customers.show', $domain->customer) }}" class="text-decoration-none">{{ $domain->customer->full_name }}</a>
                                            @if ($domain->customer->user?->email)
                                                <div class="text-muted small">{{ $domain->customer->user->email }}</div>
                                            @endif
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr><th class="text-muted">Registrar</th><td>{{ $domain->registrar ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Type</th><td>{{ $domain->type ? ucfirst($domain->type) : '—' }}</td></tr>
                                <tr>
                                    <th class="text-muted">Registration date</th>
                                    <td>{{ $domain->registration_date?->format('M j, Y') ?? '—' }}</td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Expiry date</th>
                                    <td class="{{ $isExpired ? 'text-danger fw-bold' : ($isExpiringSoon ? 'text-warning fw-bold' : '') }}">
                                        {{ $domain->expiry_date?->format('M j, Y') ?? '—' }}
                                        @if ($isExpired)
                                            <span class="badge text-bg-danger ms-1">Expired</span>
                                        @elseif ($isExpiringSoon)
                                            <span class="badge text-bg-warning ms-1">Expiring in {{ $daysUntilExpiry }}d</span>
                                        @endif
                                    </td>
                                </tr>
                                @if ($domain->registration_period)
                                    <tr><th class="text-muted">Registration period</th><td>{{ $domain->registration_period }} year{{ $domain->registration_period === 1 ? '' : 's' }}</td></tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless mb-0">
                            <tbody>
                                <tr>
                                    <th class="w-25 text-muted">Recurring amount</th>
                                    <td>{{ $domain->recurring_amount !== null ? '₹'.number_format((float) $domain->recurring_amount, 2) : '—' }}</td>
                                </tr>
                                <tr><th class="text-muted">Auto-renew</th><td>{{ $domain->auto_renew ? 'Yes' : 'No' }}</td></tr>
                                <tr><th class="text-muted">Privacy enabled</th><td>{{ $domain->privacy_enabled ? 'Yes' : 'No' }}</td></tr>
                                <tr><th class="text-muted">Lock status</th><td>{{ $domain->lock_status ? 'Locked' : 'Unlocked' }}</td></tr>
                                <tr><th class="text-muted">DNS management</th><td>{{ $domain->dns_management ? 'Yes' : 'No' }}</td></tr>
                                <tr><th class="text-muted">Email forwarding</th><td>{{ $domain->email_forwarding ? 'Yes' : 'No' }}</td></tr>
                                <tr><th class="text-muted">ID protection</th><td>{{ $domain->id_protection ? 'Yes' : 'No' }}</td></tr>
                                <tr><th class="text-muted">Next due date</th><td>{{ $domain->next_due_date?->format('M j, Y') ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Created at</th><td>{{ $domain->created_at?->format('M j, Y H:i') ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Updated at</th><td>{{ $domain->updated_at?->format('M j, Y H:i') ?? '—' }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                @if ($domain->order)
                    <div class="mt-3 border rounded p-3">
                        <h6 class="text-muted mb-2"><i class="bi bi-cart3 me-1"></i> Linked order</h6>
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <a href="{{ route('admin.orders.show', $domain->order) }}" class="text-decoration-none">
                                    <strong>{{ $domain->order->order_no }}</strong>
                                </a>
                                <span class="text-muted small ms-2">{{ $domain->order->created_at?->format('M j, Y') }}</span>
                            </div>
                            <x-adminlte.partials.status-badge :status="$domain->order->status" />
                        </div>
                    </div>
                @endif
            </div>

            {{-- Nameservers --}}
            <div class="tab-pane fade {{ $activeTab === 'nameservers' ? 'show active' : '' }}" id="nameservers" role="tabpanel" aria-labelledby="nameservers-tab">
                @if ($domain->nameservers)
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="text-muted mb-0"><i class="bi bi-diagram-3 me-1"></i> Nameservers</h6>
                        <span class="text-muted small">{{ collect(preg_split('/\r\n|\r|\n/', (string) $domain->nameservers))->filter()->count() }} entries</span>
                    </div>
                    <div class="border rounded p-3 bg-light" style="font-family: var(--font-mono, monospace); font-size: var(--text-sm);">
                        <pre class="mb-0" style="white-space: pre-wrap; word-break: break-all; background: transparent; border: 0; padding: 0; margin: 0; font-family: inherit;">{{ $domain->nameservers }}</pre>
                    </div>
                    @if ($domain->dns_records)
                        <div class="mt-3">
                            <h6 class="text-muted mb-2"><i class="bi bi-hdd-network me-1"></i> DNS records</h6>
                            <div class="border rounded p-3 bg-light" style="font-family: var(--font-mono, monospace); font-size: var(--text-sm);">
                                <pre class="mb-0" style="white-space: pre-wrap; word-break: break-all; background: transparent; border: 0; padding: 0; margin: 0; font-family: inherit;">{{ $domain->dns_records }}</pre>
                            </div>
                        </div>
                    @endif
                @else
                    <div class="text-center py-4 border rounded bg-light">
                        <i class="bi bi-diagram-3 text-muted" style="font-size: 1.5rem;"></i>
                        <p class="text-muted mb-1 mt-2">No nameservers configured.</p>
                        <p class="text-muted small mb-0">Nameservers will appear here once the domain is provisioned.</p>
                    </div>
                @endif
            </div>

            {{-- Billing --}}
            <div class="tab-pane fade {{ $activeTab === 'billing' ? 'show active' : '' }}" id="billing" role="tabpanel" aria-labelledby="billing-tab">
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-muted mb-3"><i class="bi bi-credit-card me-1"></i> Billing details</h6>
                        <table class="table table-sm table-borderless mb-0">
                            <tbody>
                                <tr><th class="w-25 text-muted">Recurring amount</th><td>{{ $domain->recurring_amount !== null ? '₹'.number_format((float) $domain->recurring_amount, 2) : '—' }}</td></tr>
                                <tr><th class="text-muted">Auto-renew</th><td>{{ $domain->auto_renew ? 'Yes' : 'No' }}</td></tr>
                                <tr><th class="text-muted">Payment method</th><td>{{ $domain->payment_method ? ucfirst(str_replace('_', ' ', $domain->payment_method)) : '—' }}</td></tr>
                                <tr><th class="text-muted">Next due date</th><td>{{ $domain->next_due_date?->format('M j, Y') ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Next invoice date</th><td>{{ $domain->next_invoice_date?->format('M j, Y') ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Subscription ID</th><td><code>{{ $domain->subscription_id ?? '—' }}</code></td></tr>
                                <tr>
                                    <th class="text-muted">Order</th>
                                    <td>
                                        @if ($domain->order)
                                            <a href="{{ route('admin.orders.show', $domain->order) }}" class="text-decoration-none">
                                                <strong>{{ $domain->order->order_no }}</strong>
                                            </a>
                                            <x-adminlte.partials.status-badge :status="$domain->order->status" class="ms-1" />
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-muted mb-3"><i class="bi bi-receipt me-1"></i> Related hosting</h6>
                        @php
                            $relatedHosting = null;
                            if (isset($domain->order) && $domain->order) {
                                $relatedHosting = $domain->order->hostingAccount ?? null;
                            }
                        @endphp
                        @if ($relatedHosting)
                            <div class="border rounded p-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <a href="{{ route('admin.hosting.show', $relatedHosting) }}" class="text-decoration-none">
                                        <strong>#{{ $relatedHosting->id }} — {{ $relatedHosting->host_name }}</strong>
                                    </a>
                                    @if ($relatedHosting->domain)
                                        <div class="text-muted small">{{ $relatedHosting->domain }}</div>
                                    @endif
                                </div>
                                <x-adminlte.partials.status-badge :status="$relatedHosting->status" />
                            </div>
                        @else
                            <div class="text-center py-4 border rounded bg-light">
                                <i class="bi bi-hdd-stack text-muted" style="font-size: 1.5rem;"></i>
                                <p class="text-muted mb-0 mt-2">No hosting account linked.</p>
                                <p class="text-muted small mb-0">A hosting account will appear here when linked via an order.</p>
                            </div>
                        @endif

                        @if ($domain->order && $domain->order->invoices && $domain->order->invoices->isNotEmpty())
                            <h6 class="text-muted mt-3 mb-2"><i class="bi bi-receipt me-1"></i> Invoices</h6>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr><th>Invoice</th><th>Status</th><th class="text-end">Total</th><th>Due</th></tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($domain->order->invoices as $invoice)
                                            <tr>
                                                <td><a href="{{ route('admin.invoices.show', $invoice) }}" class="text-decoration-none">{{ $invoice->invoice_no }}</a></td>
                                                <td><x-adminlte.partials.status-badge :status="$invoice->status" /></td>
                                                <td class="text-end">₹{{ number_format((float) $invoice->total, 2) }}</td>
                                                <td class="text-muted">{{ $invoice->due_date?->format('M j, Y') ?? '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- History --}}
            <div class="tab-pane fade {{ $activeTab === 'history' ? 'show active' : '' }}" id="history" role="tabpanel" aria-labelledby="history-tab">
                @php
                    $historyEntries = isset($history) ? $history : (isset($audit) ? $audit : collect());
                    if ($historyEntries instanceof \Illuminate\Contracts\Pagination\Paginator || $historyEntries instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator) {
                        $historyCollection = $historyEntries->getCollection();
                    } else {
                        $historyCollection = $historyEntries;
                    }
                @endphp
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>When</th><th>Action</th><th>Description</th><th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($historyCollection as $entry)
                                <tr>
                                    <td class="text-muted" style="white-space: nowrap;">{{ $entry->created_at?->format('M j, Y H:i') ?? '—' }}</td>
                                    <td>
                                        @if (isset($entry->action))
                                            <span class="badge text-bg-info">{{ $entry->action }}</span>
                                        @elseif (isset($entry->from_status) || isset($entry->to_status))
                                            <span class="badge text-bg-info">{{ $entry->from_status ?? '—' }} → {{ $entry->to_status }}</span>
                                        @else
                                            <span class="badge text-bg-secondary">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if (isset($entry->description))
                                            {{ $entry->description }}
                                        @elseif (isset($entry->notes))
                                            {{ $entry->notes }}
                                        @elseif (isset($entry->details))
                                            @php $decoded = is_string($entry->details) ? json_decode($entry->details, true) : $entry->details; @endphp
                                            @if (is_array($decoded))
                                                @foreach ($decoded as $k => $v)
                                                    <span class="text-muted small">{{ $k }}:</span> <code class="small">{{ is_scalar($v) ? $v : json_encode($v) }}</code>@if (! $loop->last)<br>@endif
                                                @endforeach
                                            @else
                                                {{ $entry->details ?? '—' }}
                                            @endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="text-muted">{{ $entry->user?->full_name ?? $entry->user?->email ?? 'System' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">No history recorded.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if (isset($history) && $history instanceof \Illuminate\Contracts\Pagination\Paginator)
                    <div class="mt-3">{{ $history->appends(['tab' => 'history'])->links() }}</div>
                @endif
                {{-- Fallback timeline when no history relation exists --}}
                @if ($historyCollection->isEmpty())
                    <div class="mt-3 border rounded p-3 bg-light">
                        <div class="row g-2 small">
                            <div class="col-md-4">
                                <span class="text-muted">Created</span>
                                <div>{{ $domain->created_at?->format('M j, Y H:i') ?? '—' }}</div>
                            </div>
                            <div class="col-md-4">
                                <span class="text-muted">Updated</span>
                                <div>{{ $domain->updated_at?->format('M j, Y H:i') ?? '—' }}</div>
                            </div>
                            <div class="col-md-4">
                                <span class="text-muted">Status</span>
                                <div><x-adminlte.partials.status-badge :status="$domain->status" /></div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </x-adminlte.partials.detail-tabs>
    </x-adminlte-card>

    @can('domains.manage')
        <x-adminlte.partials.confirm-modal
            id="delete-domain-modal"
            title="Delete domain"
            :message="'Delete ' . $domain->name . '? This cannot be undone.'"
            :action="route('admin.domains.destroy', $domain)"
            confirm-label="Delete domain"
        />
    @endcan
@stop
