@extends('adminlte::page')

@section('title', 'Product/Service #'.$hostingAccount->id.' — '.$hostingAccount->username)

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Product/Service #{{ $hostingAccount->id }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.hosting.index') }}">Products/Services</a></li>
                <li class="breadcrumb-item active" aria-current="page">#{{ $hostingAccount->id }}</li>
            </ol>
        </div>
    </div>
@stop

@php
    $activeTab = (string) request()->query('tab', 'info');
    $tabs = [
        ['id' => 'info', 'label' => 'Info', 'icon' => 'bi bi-info-circle'],
        ['id' => 'billing', 'label' => 'Billing', 'icon' => 'bi bi-credit-card'],
        ['id' => 'domain', 'label' => 'Domain', 'icon' => 'bi bi-globe'],
        ['id' => 'assets', 'label' => 'Assets', 'icon' => 'bi bi-diagram-3', 'badge' => $assetRelationships->count()],
        ['id' => 'history', 'label' => 'History', 'icon' => 'bi bi-clock-history', 'badge' => count($audit)],
    ];
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

    {{-- Account header --}}
    <x-adminlte-card>
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center rounded-circle bg-primary text-white"
                 style="width: 56px; height: 56px; font-size: 1.25rem; flex-shrink: 0;">
                {{ strtoupper(substr($hostingAccount->username, 0, 1)) }}
            </div>
            <div class="flex-grow-1">
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <h4 class="mb-0">{{ $hostingAccount->username }}</h4>
                    <x-adminlte.partials.status-badge :status="$hostingAccount->status" />
                    @if ($hostingAccount->domain)
                        <span class="badge bg-info">{{ $hostingAccount->domain }}</span>
                    @endif
                </div>
                <div class="text-muted mt-1">
                    @if ($hostingAccount->customer)
                        <i class="bi bi-person me-1"></i>
                        <a href="{{ route('admin.customers.show', $hostingAccount->customer_id) }}">
                            {{ $hostingAccount->customer->full_name }}
                        </a>
                        <span class="mx-2">|</span>
                    @endif
                    <i class="bi bi-box-seam me-1"></i>{{ $hostingAccount->product?->name ?? 'No package' }}
                    @if ($hostingAccount->server)
                        <span class="mx-2">|</span>
                        <i class="bi bi-server me-1"></i>
                        <a href="{{ route('admin.servers.show', $hostingAccount->server_id) }}">
                            {{ $hostingAccount->server->name }}
                        </a>
                    @endif
                </div>
            </div>
            <div class="d-flex gap-2">
                @can('hosting.manage')
                    <a href="{{ route('admin.hosting.edit', $hostingAccount) }}" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-pencil me-1"></i> Edit
                    </a>
                    @if ($hostingAccount->status !== 'terminated')
                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                                data-bs-target="#terminate-hosting-modal">
                            <i class="bi bi-trash me-1"></i> Terminate
                        </button>
                    @endif
                @endcan
            </div>
        </div>
    </x-adminlte-card>

    {{-- Metric row --}}
    <x-adminlte.partials.metric-cards :items="[
        ['title' => $hostingAccount->disk_used.' / '.$hostingAccount->disk_quota.' MB', 'text' => 'Disk Usage', 'icon' => 'bi bi-hdd', 'theme' => 'primary'],
        ['title' => $hostingAccount->bandwidth_used.' / '.$hostingAccount->bandwidth_quota.' MB', 'text' => 'Bandwidth Usage', 'icon' => 'bi bi-arrow-down-up', 'theme' => 'info'],
        ['title' => $hostingAccount->product?->group?->name ?? 'none', 'text' => 'Package Group', 'icon' => 'bi bi-box-seam', 'theme' => 'success'],
        ['title' => $hostingAccount->server?->name ?? 'Unassigned', 'text' => 'Server', 'icon' => 'bi bi-server', 'theme' => 'warning'],
    ]" />

    {{-- Tabbed detail --}}
    <x-adminlte-card>
        <x-adminlte.partials.detail-tabs :tabs="$tabs" :active-tab="$activeTab">
            {{-- Info --}}
            <div class="tab-pane fade {{ $activeTab === 'info' ? 'show active' : '' }}" id="info" role="tabpanel" aria-labelledby="info-tab">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tbody>
                                <tr><th class="w-25 text-muted">Username</th><td>{{ $hostingAccount->username }}</td></tr>
                                <tr><th class="text-muted">Domain</th><td>{{ $hostingAccount->domain ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Customer</th><td>{{ $hostingAccount->customer?->full_name ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Product / Package</th><td>{{ $hostingAccount->product?->name ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Server</th><td>{{ $hostingAccount->server?->name ?? 'Unassigned' }}</td></tr>
                                <tr><th class="text-muted">Panel account ID</th><td>{{ $hostingAccount->panel_account_id ?? '—' }}</td></tr>
                                <tr>
                                    <th class="text-muted">IP address</th>
                                    <td>
                                        @forelse ($assignedIps as $ip)
                                            <div class="mb-1">
                                                <code>{{ $ip->ip_address }}</code>
                                                @php
                                                    $ipMeta = collect(array_filter([
                                                        $ip->type ? ucfirst(str_replace('_', ' ', $ip->type)) : null,
                                                        $ip->subnet?->name,
                                                        $ip->ptr_record ? 'PTR: '.$ip->ptr_record : null,
                                                    ]))->implode(' · ');
                                                @endphp
                                                @if ($ipMeta !== '')
                                                    <span class="text-muted small d-block">{{ $ipMeta }}</span>
                                                @endif
                                            </div>
                                        @empty
                                            —
                                        @endforelse
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tbody>
                                <tr><th class="w-25 text-muted">Username prefix</th><td>{{ $hostingAccount->username_prefix ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Order</th><td>{{ $hostingAccount->order_id ? '#'.$hostingAccount->order_id : '—' }}</td></tr>
                                <tr><th class="text-muted">Created</th><td>{{ $hostingAccount->created_at?->format('M j, Y H:i') }}</td></tr>
                                <tr><th class="text-muted">Last updated</th><td>{{ $hostingAccount->updated_at?->format('M j, Y H:i') }}</td></tr>
                                @if ($hostingAccount->suspended_at)
                                    <tr><th class="text-muted">Suspended at</th><td>{{ $hostingAccount->suspended_at->format('M j, Y H:i') }}</td></tr>
                                @endif
                                @if ($hostingAccount->suspended_reason)
                                    <tr><th class="text-muted">Suspension reason</th><td>{{ $hostingAccount->suspended_reason }}</td></tr>
                                @endif
                                @if ($hostingAccount->notes)
                                    <tr><th class="text-muted">Admin notes</th><td>{{ $hostingAccount->notes }}</td></tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Package resources panel: product + group + attached option groups (EAV) --}}
                @if ($hostingAccount->product)
                    <div class="row mt-2">
                        <div class="col-12">
                            <div class="border rounded p-3">
                                <h6 class="text-primary mb-3">
                                    <i class="bi bi-box-seam me-1"></i>
                                    Package resources
                                </h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless mb-0">
                                            <tbody>
                                                <tr><th class="w-25 text-muted">Package</th><td>{{ $hostingAccount->product->name }}</td></tr>
                                                <tr>
                                                    <th class="text-muted">Group</th>
                                                    <td>
                                                        @if ($packageSnapshot !== null)
                                                            {{ $packageSnapshot['product_group_name'] ?? '—' }}
                                                        @else
                                                            {{ $hostingAccount->product->group?->name ?? '—' }}
                                                        @endif
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <th class="text-muted">Provisioning module</th>
                                                    <td>
                                                        @if ($packageSnapshot !== null)
                                                            {{ ucfirst($packageSnapshot['provisioning_module'] ?? 'manual') }}
                                                        @else
                                                            {{ ucfirst($hostingAccount->product->provisioning_module ?? 'manual') }}
                                                        @endif
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        @if ($packageSnapshot !== null)
                                            @php $snapshotOptions = $packageSnapshot['options'] ?? []; @endphp
                                            @if (count($snapshotOptions) > 0)
                                                <table class="table table-sm table-borderless mb-0">
                                                    <tbody>
                                                        @foreach ($snapshotOptions as $option)
                                                            <tr>
                                                                <th class="w-25 text-muted">{{ $option['group'] ?? '—' }}</th>
                                                                <td>
                                                                    @if (! empty($option['selected']))
                                                                        {{ is_array($option['selected']) ? implode(', ', $option['selected']) : $option['selected'] }}
                                                                        @if (($option['customer_editable'] ?? false) === true)
                                                                            <span class="text-muted small">(customer editable)</span>
                                                                        @endif
                                                                    @else
                                                                        {{ is_array($option['values'] ?? null) ? implode(', ', $option['values']) : '—' }}
                                                                    @endif
                                                                </td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            @else
                                                <p class="text-muted mb-0">No configurable options were captured for this package.</p>
                                            @endif
                                        @elseif ($hostingAccount->product->options->isNotEmpty())
                                            <table class="table table-sm table-borderless mb-0">
                                                <tbody>
                                                    @foreach ($hostingAccount->product->options as $option)
                                                        <tr>
                                                            <th class="w-25 text-muted">{{ $option->name }}</th>
                                                            <td>{{ $option->values->isNotEmpty() ? $option->values->pluck('label')->implode(', ') : '—' }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        @else
                                            <p class="text-muted mb-0">No configurable options are attached to this package.</p>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Usage --}}
                <div class="row mt-2">
                    <div class="col-md-6">
                        <div class="border rounded p-3">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted small">Disk usage</span>
                                <span class="small">{{ $hostingAccount->diskUsagePercent() }}%</span>
                            </div>
                            <div class="progress">
                                <div class="progress-bar bg-primary" style="width: {{ min(100, $hostingAccount->diskUsagePercent()) }}%"></div>
                            </div>
                            <div class="text-muted small mt-1">{{ $hostingAccount->disk_used }} MB used of {{ $hostingAccount->disk_quota }} MB</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded p-3">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted small">Bandwidth usage</span>
                                <span class="small">{{ $hostingAccount->bandwidthUsagePercent() }}%</span>
                            </div>
                            <div class="progress">
                                <div class="progress-bar bg-info" style="width: {{ min(100, $hostingAccount->bandwidthUsagePercent()) }}%"></div>
                            </div>
                            <div class="text-muted small mt-1">{{ $hostingAccount->bandwidth_used }} MB used of {{ $hostingAccount->bandwidth_quota }} MB</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Billing --}}
            <div class="tab-pane fade {{ $activeTab === 'billing' ? 'show active' : '' }}" id="billing" role="tabpanel" aria-labelledby="billing-tab">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="text-muted mb-0"><i class="bi bi-credit-card me-1"></i> Billing details</h6>
                    @can('hosting.manage')
                        <a href="{{ route('admin.hosting.edit', ['hostingAccount' => $hostingAccount, 'tab' => 'billing']) }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-pencil me-1"></i> Edit billing info
                        </a>
                    @endcan
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless mb-3">
                            <tbody>
                                <tr><th class="w-25 text-muted">Recurring amount</th><td>{{ $recurringAmount !== null ? '$'.number_format($recurringAmount, 2) : '—' }}</td></tr>
                                <tr><th class="text-muted">Billing cycle</th><td>{{ ucfirst(str_replace('_', ' ', $order?->billing_cycle ?? $hostingAccount->product?->billing_cycle ?? 'none')) }}</td></tr>
                                <tr><th class="text-muted">Next due date</th><td>{{ $hostingAccount->next_due_date?->format('M j, Y') ?? '—' }}</td></tr>
                                @if ($order && (int) $order->quantity > 1)
                                    <tr><th class="text-muted">Quantity</th><td>{{ $order->quantity }}</td></tr>
                                @endif
                                <tr>
                                    <th class="text-muted">Order</th>
                                    <td>
                                        @if ($order)
                                            <a href="{{ route('admin.orders.show', $order) }}">{{ $order->order_no }}</a>
                                            <x-adminlte.partials.status-badge :status="$order->status" class="ms-1" />
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                                <tr><th class="text-muted">Order total</th><td>{{ $order ? '$'.number_format((float) $order->total, 2) : '—' }}</td></tr>
                                <tr><th class="text-muted">Payment method</th><td>{{ $order?->payment_method ? ucfirst(str_replace('_', ' ', $order->payment_method)) : '—' }}</td></tr>
                                <tr><th class="text-muted">Subscription ID</th><td><code>{{ $order?->subscription_id ?? '—' }}</code></td></tr>
                                <tr><th class="text-muted">Next billing date</th><td>{{ $order?->next_billing_date?->format('M j, Y') ?? '—' }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-muted mb-2"><i class="bi bi-receipt me-1"></i> Invoices</h6>
                        @if ($invoices->isEmpty())
                            <p class="text-muted mb-0">No invoices linked to this order.</p>
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr><th>Invoice</th><th>Status</th><th class="text-end">Total</th><th>Due</th></tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($invoices as $invoice)
                                            <tr>
                                                <td><a href="{{ route('admin.invoices.show', $invoice) }}">{{ $invoice->invoice_no }}</a></td>
                                                <td><x-adminlte.partials.status-badge :status="$invoice->status" /></td>
                                                <td class="text-end">${{ number_format((float) $invoice->total, 2) }}</td>
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

            {{-- Domain --}}
            <div class="tab-pane fade {{ $activeTab === 'domain' ? 'show active' : '' }}" id="domain" role="tabpanel" aria-labelledby="domain-tab">
                @if ($domain)
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-sm table-borderless">
                                <tbody>
                                    <tr>
                                        <th class="w-25 text-muted">Domain</th>
                                        <td>
                                            <code>{{ $domain->name }}</code>
                                            @if ($hostingAccount->domain && $hostingAccount->domain !== $domain->name)
                                                <span class="text-muted small">(account field: {{ $hostingAccount->domain }})</span>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr><th class="text-muted">Status</th><td><x-adminlte.partials.status-badge :status="$domain->status" /></td></tr>
                                    <tr><th class="text-muted">Type</th><td>{{ $domain->type ? ucfirst($domain->type) : '—' }}</td></tr>
                                    <tr><th class="text-muted">Registrar</th><td>{{ $domain->registrar ?? '—' }}</td></tr>
                                    <tr><th class="text-muted">Registration date</th><td>{{ $domain->registration_date?->format('M j, Y') ?? '—' }}</td></tr>
                                    <tr><th class="text-muted">Expiry date</th><td>{{ $domain->expiry_date?->format('M j, Y') ?? '—' }}</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-sm table-borderless">
                                <tbody>
                                    <tr><th class="w-25 text-muted">Next due date</th><td>{{ $domain->next_due_date?->format('M j, Y') ?? '—' }}</td></tr>
                                    <tr><th class="text-muted">Recurring amount</th><td>{{ $domain->recurring_amount !== null ? '$'.number_format((float) $domain->recurring_amount, 2) : '—' }}</td></tr>
                                    <tr><th class="text-muted">Auto renew</th><td>{{ $domain->auto_renew ? 'Yes' : 'No' }}</td></tr>
                                    <tr><th class="text-muted">Lock status</th><td>{{ $domain->lock_status ? 'Yes' : 'No' }}</td></tr>
                                    <tr><th class="text-muted">Nameservers</th><td>{{ $domain->nameservers ?? '—' }}</td></tr>
                                    <tr><th class="text-muted">Privacy enabled</th><td>{{ $domain->privacy_enabled ? 'Yes' : 'No' }}</td></tr>
                                </tbody>
                            </table>
                            <div class="d-flex gap-2">
                                <a href="http://{{ $domain->name }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-box-arrow-up-right me-1"></i> Visit website
                                </a>
                                <a href="{{ route('admin.domains.show', $domain) }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-gear me-1"></i> Manage domain
                                </a>
                            </div>
                        </div>
                    </div>
                @elseif ($hostingAccount->domain)
                    <x-adminlte-alert theme="warning" dismissible>
                        No domain registration record is linked to this account. Only the free-text value
                        <code>{{ $hostingAccount->domain }}</code> is stored on the account.
                    </x-adminlte-alert>
                @else
                    <p class="text-muted mb-0">No domain is associated with this product/service.</p>
                @endif

                {{-- SSL monitoring (WHMCS-style) --}}
                @if ($sslCerts->isNotEmpty())
                    <div class="mt-3 border rounded p-3">
                        <h6 class="text-muted mb-2"><i class="bi bi-shield-lock me-1"></i> SSL Certificates</h6>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr><th>Domain</th><th>Type</th><th>Status</th><th>Issued</th><th>Expires</th><th></th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($sslCerts as $cert)
                                        <tr>
                                            <td><code>{{ $cert->domain_name }}</code></td>
                                            <td>{{ $cert->certificate_type ? ucfirst(str_replace('_', ' ', $cert->certificate_type)) : '—' }}</td>
                                            <td>
                                                <x-adminlte.partials.status-badge :status="$cert->status" />
                                                @if ($cert->isExpired())
                                                    <span class="badge bg-danger ms-1">Expired</span>
                                                @elseif ($cert->isExpiringSoon())
                                                    <span class="badge bg-warning ms-1">Expiring soon</span>
                                                @endif
                                            </td>
                                            <td class="text-muted">{{ $cert->issue_date?->format('M j, Y') ?? '—' }}</td>
                                            <td class="text-muted">{{ $cert->expiry_date?->format('M j, Y') ?? '—' }}</td>
                                            <td class="text-end">
                                                <a href="{{ route('admin.ssl.show', $cert) }}" class="btn btn-sm btn-outline-secondary" title="View certificate">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Assets --}}
            <div class="tab-pane fade {{ $activeTab === 'assets' ? 'show active' : '' }}" id="assets" role="tabpanel" aria-labelledby="assets-tab">
                {{-- Linking / unlinking inventory assets moved to the edit page. --}}
                @if ($assetRelationships->isEmpty())
                    <p class="text-muted mb-0">No inventory assets are linked to this account.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Parent</th>
                                    <th>Relationship</th>
                                    <th>Child</th>
                                    <th>Label</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($assetRelationships as $relationship)
                                    <tr>
                                        <td>
                                            <span class="badge bg-secondary">{{ $assetKinds[$relationship->parent_kind] ?? $relationship->parent_kind }}</span>
                                            @if ($relationship->parent_kind === 'inventory_asset')
                                                <a class="ms-1" href="{{ route('admin.inventory-assets.show', $relationship->parent_id) }}">
                                                    {{ $assetNames[$relationship->id]['parent'] ?? 'Asset #'.$relationship->parent_id }}
                                                </a>
                                            @elseif ($assetNames[$relationship->id]['parent'])
                                                <span class="ms-1">{{ $assetNames[$relationship->id]['parent'] }}</span>
                                            @endif
                                            <code class="ms-1">#{{ $relationship->parent_id }}</code>
                                        </td>
                                        <td><code>{{ $relationship->relationship_type }}</code></td>
                                        <td>
                                            <span class="badge bg-secondary">{{ $assetKinds[$relationship->child_kind] ?? $relationship->child_kind }}</span>
                                            @if ($relationship->child_kind === 'inventory_asset')
                                                <a class="ms-1" href="{{ route('admin.inventory-assets.show', $relationship->child_id) }}">
                                                    {{ $assetNames[$relationship->id]['child'] ?? 'Asset #'.$relationship->child_id }}
                                                </a>
                                            @elseif ($assetNames[$relationship->id]['child'])
                                                <span class="ms-1">{{ $assetNames[$relationship->id]['child'] }}</span>
                                            @endif
                                            <code class="ms-1">#{{ $relationship->child_id }}</code>
                                        </td>
                                        <td class="text-muted">{{ $relationship->label ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- History --}}
            <div class="tab-pane fade {{ $activeTab === 'history' ? 'show active' : '' }}" id="history" role="tabpanel" aria-labelledby="history-tab">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>When</th><th>Action</th><th>Details</th><th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($audit as $entry)
                                @php
                                    $decoded = json_decode((string) $entry->details, true);
                                    $details = is_array($decoded) ? $decoded : null;
                                @endphp
                                <tr>
                                    <td class="text-muted" style="white-space: nowrap;">{{ $entry->created_at?->format('M j, Y H:i') }}</td>
                                    <td><span class="badge bg-info">{{ $entry->action }}</span></td>
                                    <td>
                                        @if ($details)
                                            @foreach ($details as $key => $value)
                                                <span class="text-muted small">{{ $key }}:</span>
                                                <code class="small">{{ is_scalar($value) ? $value : json_encode($value) }}</code>
                                                @if (! $loop->last)<br>@endif
                                            @endforeach
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="text-muted">{{ $entry->user?->full_name ?? 'System' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">No history recorded.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </x-adminlte.partials.detail-tabs>
    </x-adminlte-card>

    @can('hosting.manage')
        @if ($hostingAccount->status !== 'terminated')
            <x-adminlte.partials.confirm-modal
                id="terminate-hosting-modal"
                title="Terminate product/service"
                :message="'Terminate #' . $hostingAccount->id . ' (' . $hostingAccount->username . ')? This sets the status to terminated and cannot be undone.'"
                :action="route('admin.hosting.destroy', $hostingAccount)"
                confirm-label="Terminate account"
            />
        @endif
    @endcan
@stop
