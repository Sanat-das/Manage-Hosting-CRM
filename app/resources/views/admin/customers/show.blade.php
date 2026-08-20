@extends('adminlte::page')

@section('title', $customer->full_name)

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">{{ $customer->full_name }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.customers.index') }}">Customers</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $customer->display_id }}</li>
            </ol>
        </div>
    </div>
@stop

@php
    $activeTab = (string) request()->query('tab', 'profile');
    $tabs = [
        ['id' => 'profile', 'label' => 'Profile', 'icon' => 'bi bi-person'],
        ['id' => 'hosting', 'label' => 'Products/Services', 'icon' => 'bi bi-hdd-stack'],
        ['id' => 'orders', 'label' => 'Orders', 'icon' => 'bi bi-cart3'],
        ['id' => 'invoices', 'label' => 'Invoices', 'icon' => 'bi bi-receipt'],
        ['id' => 'tickets', 'label' => 'Tickets', 'icon' => 'bi bi-life-preserver'],
        ['id' => 'domains', 'label' => 'Domains', 'icon' => 'bi bi-globe2'],
        ['id' => 'contacts', 'label' => 'Contacts', 'icon' => 'bi bi-people'],
        ['id' => 'notes', 'label' => 'Notes', 'icon' => 'bi bi-sticky'],
        ['id' => 'billing', 'label' => 'Billing', 'icon' => 'bi bi-wallet2'],
        ['id' => 'activity', 'label' => 'Activity', 'icon' => 'bi bi-clock-history'],
    ];
@endphp

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif
    @if (session('error'))
        <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert>
    @endif

    {{-- Profile header (reference: customer-profile-v2) --}}
    <x-adminlte-card>
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center rounded-circle bg-primary text-white"
                 style="width: 56px; height: 56px; font-size: 1.25rem; flex-shrink: 0;">
                {{ strtoupper(substr($customer->user?->first_name ?? 'C', 0, 1)) }}{{ strtoupper(substr($customer->user?->last_name ?? '', 0, 1)) }}
            </div>
            <div class="flex-grow-1">
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <h4 class="mb-0">{{ $customer->full_name }}</h4>
                    <span class="text-muted">{{ $customer->display_id }}</span>
                    <x-adminlte.partials.status-badge :status="$customer->status" />
                    @if ($customer->company)
                        <span class="badge bg-info">{{ $customer->company }}</span>
                    @endif
                </div>
                <div class="text-muted mt-1">
                    <i class="bi bi-envelope me-1"></i>{{ $customer->user?->email }}
                    @if ($customer->user?->phone)
                        <span class="mx-2">|</span><i class="bi bi-telephone me-1"></i>{{ $customer->user->phone }}
                    @endif
                </div>
            </div>
            <div class="d-flex gap-2">
                @canany(['orders.create', 'invoices.create', 'tickets.create'])
                    <div class="btn-group">
                        <button type="button" class="btn btn-sm btn-primary dropdown-toggle" data-bs-toggle="dropdown"
                                aria-expanded="false">
                            <i class="bi bi-plus-lg me-1"></i> Create
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            @can('orders.create')
                                <li>
                                    <a class="dropdown-item" href="{{ route('admin.orders.create', ['customer_id' => $customer->id]) }}">
                                        <i class="bi bi-cart3 me-2"></i> New Order
                                    </a>
                                </li>
                            @endcan
                            @can('invoices.create')
                                <li>
                                    <a class="dropdown-item" href="{{ route('admin.invoices.create', ['customer_id' => $customer->id]) }}">
                                        <i class="bi bi-receipt me-2"></i> New Invoice
                                    </a>
                                </li>
                            @endcan
                            @can('tickets.create')
                                <li>
                                    <a class="dropdown-item" href="{{ route('admin.tickets.create', ['customer_id' => $customer->id]) }}">
                                        <i class="bi bi-life-preserver me-2"></i> New Ticket
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </div>
                @endcanany
                @can('customers.edit')
                    <a href="{{ route('admin.customers.edit', $customer) }}" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-pencil me-1"></i> Edit
                    </a>
                @endcan
                @if (\Illuminate\Support\Facades\Route::has('admin.impersonate.start'))
                    <form method="POST" action="{{ route('admin.impersonate.start', $customer->user_id) }}" class="d-inline"
                          onsubmit="return confirm('Log in as {{ $customer->full_name }}?')">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Log in as this customer">
                            <i class="bi bi-incognito me-1"></i> Login As
                        </button>
                    </form>
                @endif
                @can('customers.delete')
                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                            data-bs-target="#delete-customer-modal">
                        <i class="bi bi-trash me-1"></i> Delete
                    </button>
                @endcan
            </div>
        </div>
    </x-adminlte-card>

    {{-- Metric row --}}
    <x-adminlte.partials.metric-cards :items="[
        ['title' => count($customer->hostingAccounts), 'text' => 'Hosting Accounts', 'icon' => 'bi bi-hdd-stack', 'theme' => 'primary'],
        ['title' => count($customer->invoices), 'text' => 'Invoices', 'icon' => 'bi bi-receipt', 'theme' => 'warning'],
        ['title' => count($customer->tickets), 'text' => 'Tickets', 'icon' => 'bi bi-life-preserver', 'theme' => 'success'],
        ['title' => '₹'.number_format($customer->balance, 2), 'text' => 'Account Balance', 'icon' => 'bi bi-wallet2', 'theme' => 'info'],
    ]" />

    {{-- Tabbed detail --}}
    <x-adminlte-card>
        <x-adminlte.partials.detail-tabs :tabs="$tabs" :active-tab="$activeTab">
            {{-- Profile --}}
            <div class="tab-pane fade {{ $activeTab === 'profile' ? 'show active' : '' }}" id="profile" role="tabpanel" aria-labelledby="profile-tab">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tbody>
                                <tr><th class="w-25 text-muted">Name</th><td>{{ $customer->full_name }}</td></tr>
                                <tr><th class="text-muted">Email</th><td>{{ $customer->user?->email }}</td></tr>
                                <tr><th class="text-muted">Phone</th><td>{{ $customer->user?->phone ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Company</th><td>{{ $customer->company ?? '—' }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tbody>
                                <tr><th class="w-25 text-muted">Tax ID</th><td>{{ $customer->tax_id ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Address</th><td>{{ $customer->user?->address ?? '—' }}</td></tr>
                                <tr><th class="text-muted">Registered</th><td>{{ $customer->created_at?->format('M j, Y H:i') }}</td></tr>
                                <tr><th class="text-muted">Credit</th><td>₹{{ number_format($customer->credit, 2) }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Products/Services --}}
            <div class="tab-pane fade {{ $activeTab === 'hosting' ? 'show active' : '' }}" id="hosting" role="tabpanel" aria-labelledby="hosting-tab">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Service</th><th>Domain</th><th>Product</th><th>Server</th><th>IP address</th><th>Order</th><th>Cycle</th><th class="text-end">Amount</th><th>Next billing</th><th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($customer->hostingAccounts as $account)
                                @php
                                    $serviceOrder = $account->order;
                                    $serviceItem = $serviceOrder?->items
                                        ?->first(fn ($item) => $item->product_id === $account->product_id)
                                        ?? $serviceOrder?->items?->first();
                                    $cycle = $serviceItem?->billing_cycle
                                        ?? $serviceOrder?->billing_cycle
                                        ?? $account->product?->billing_cycle;
                                    $recurringAmount = $serviceItem?->total ?? $serviceOrder?->total;
                                    $nextBilling = $serviceItem?->next_billing_date
                                        ?? $serviceOrder?->next_billing_date
                                        ?? $account->next_due_date;
                                    $domainName = $account->domain
                                        ?? $serviceItem?->domain_name
                                        ?? $serviceOrder?->domain_name
                                        ?? $serviceOrder?->domain?->name;
                                @endphp
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.hosting.show', $account) }}">
                                            <strong>#{{ $account->id }}</strong>
                                            <div class="text-muted small">{{ $account->username }}</div>
                                        </a>
                                    </td>
                                    <td>
                                        @if ($domainName)
                                            @if ($serviceOrder?->domain)
                                                <a href="{{ route('admin.domains.show', $serviceOrder->domain) }}">{{ $domainName }}</a>
                                            @else
                                                {{ $domainName }}
                                            @endif
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="text-muted">
                                        {{ $account->product?->name ?? '—' }}
                                        @if ($serviceItem && (int) $serviceItem->quantity > 1)
                                            <span class="text-muted small">&times;{{ $serviceItem->quantity }}</span>
                                        @endif
                                    </td>
                                    <td class="text-muted">{{ $account->server?->name ?? '—' }}</td>
                                    <td>
                                        @forelse ($account->ipAddresses as $ip)
                                            <div><code>{{ $ip->ip_address }}</code></div>
                                        @empty
                                            <span class="text-muted">—</span>
                                        @endforelse
                                    </td>
                                    <td>
                                        @if ($serviceOrder)
                                            <a href="{{ route('admin.orders.show', $serviceOrder) }}"><strong>{{ $serviceOrder->order_no }}</strong></a>
                                            <x-adminlte.partials.status-badge :status="$serviceOrder->status" />
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="text-muted">{{ $cycle ? ucfirst(str_replace('_', ' ', $cycle)) : '—' }}</td>
                                    <td class="text-end">{{ $recurringAmount !== null ? '₹'.number_format((float) $recurringAmount, 2) : '—' }}</td>
                                    <td class="{{ $nextBilling && $nextBilling->isPast() ? 'text-danger fw-bold' : 'text-muted' }}">
                                        {{ $nextBilling?->format('M j, Y') ?? '—' }}
                                    </td>
                                    <td><x-adminlte.partials.status-badge :status="$account->status" /></td>
                                </tr>
                            @empty
                                <tr><td colspan="10" class="text-center text-muted py-3">No hosting accounts yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Orders --}}
            <div class="tab-pane fade {{ $activeTab === 'orders' ? 'show active' : '' }}" id="orders" role="tabpanel" aria-labelledby="orders-tab">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Order</th><th>Product</th><th>Cycle</th><th class="text-end">Total</th><th>Status</th><th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($orders as $order)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.orders.show', $order) }}"><strong>{{ $order->order_no }}</strong></a>
                                        @if ($order->domain_name)
                                            <div class="text-muted small">{{ $order->domain_name }}</div>
                                        @endif
                                    </td>
                                    <td class="text-muted">{{ $order->product?->name ?? '—' }}</td>
                                    <td class="text-muted">{{ ucfirst(str_replace('_', ' ', $order->billing_cycle)) }}</td>
                                    <td class="text-end">₹{{ number_format((float) $order->total, 2) }}</td>
                                    <td><x-adminlte.partials.status-badge :status="$order->status" /></td>
                                    <td class="text-muted">{{ $order->created_at?->format('M j, Y') ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted py-3">No orders yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $orders->appends(['tab' => 'orders'])->links() }}
            </div>

            {{-- Invoices --}}
            <div class="tab-pane fade {{ $activeTab === 'invoices' ? 'show active' : '' }}" id="invoices" role="tabpanel" aria-labelledby="invoices-tab">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Invoice</th><th>Total</th><th>Status</th><th>Due date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($invoices as $invoice)
                                <tr>
                                    <td><a href="{{ route('admin.invoices.show', $invoice) }}"><strong>{{ $invoice->invoice_no }}</strong></a></td>
                                    <td>₹{{ number_format($invoice->total, 2) }}</td>
                                    <td><x-adminlte.partials.status-badge :status="$invoice->status" /></td>
                                    <td class="text-muted">{{ $invoice->due_date?->format('M j, Y') ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">No invoices yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $invoices->appends(['tab' => 'invoices'])->links() }}
            </div>

            {{-- Tickets --}}
            <div class="tab-pane fade {{ $activeTab === 'tickets' ? 'show active' : '' }}" id="tickets" role="tabpanel" aria-labelledby="tickets-tab">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Ticket</th><th>Subject</th><th>Priority</th><th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($customer->tickets as $ticket)
                                <tr>
                                    <td>{{ $ticket->ticket_no }}</td>
                                    <td>{{ $ticket->subject }}</td>
                                    <td><x-adminlte.partials.status-badge :status="$ticket->priority" /></td>
                                    <td><x-adminlte.partials.status-badge :status="$ticket->status" /></td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">No tickets yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Domains --}}
            <div class="tab-pane fade {{ $activeTab === 'domains' ? 'show active' : '' }}" id="domains" role="tabpanel" aria-labelledby="domains-tab">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Domain</th><th>Type</th><th>Expiry</th><th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($customer->domains as $domain)
                                @php $expiring = $domain->status === 'active' && $domain->expiry_date?->isBefore(now()->addDays(30)); @endphp
                                <tr>
                                    <td>{{ $domain->name }}</td>
                                    <td class="text-muted">{{ ucfirst($domain->type) }}</td>
                                    <td class="{{ $expiring ? 'text-danger fw-bold' : 'text-muted' }}">
                                        {{ $domain->expiry_date?->format('M j, Y') ?? '—' }}
                                        @if ($expiring)
                                            <span class="badge bg-warning text-dark ms-1">Expiring soon</span>
                                        @endif
                                    </td>
                                    <td><x-adminlte.partials.status-badge :status="$domain->status" /></td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">No domains yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Contacts --}}
            <div class="tab-pane fade {{ $activeTab === 'contacts' ? 'show active' : '' }}" id="contacts" role="tabpanel" aria-labelledby="contacts-tab">
                @can('customers.edit')
                    <button class="btn btn-sm btn-primary mb-3" type="button" data-bs-toggle="collapse"
                            data-bs-target="#add-contact-form" aria-expanded="false">
                        <i class="bi bi-plus-lg me-1"></i> Add Contact
                    </button>
                    <div class="collapse mb-3" id="add-contact-form">
                        <div class="border rounded p-3 bg-light">
                            <form method="POST" action="{{ route('admin.customers.contacts.store', $customer) }}">
                                @csrf
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <input type="text" name="first_name" class="form-control form-control-sm"
                                               placeholder="First name" required>
                                    </div>
                                    <div class="col-md-4">
                                        <input type="text" name="last_name" class="form-control form-control-sm"
                                               placeholder="Last name" required>
                                    </div>
                                    <div class="col-md-4">
                                        <input type="email" name="email" class="form-control form-control-sm"
                                               placeholder="Email" required>
                                    </div>
                                    <div class="col-md-4">
                                        <input type="text" name="phone" class="form-control form-control-sm"
                                               placeholder="Phone">
                                    </div>
                                    <div class="col-md-4">
                                        <input type="text" name="role" class="form-control form-control-sm"
                                               placeholder="Role (e.g. Technical)">
                                    </div>
                                    <div class="col-md-4 d-flex align-items-center gap-2">
                                        <input type="checkbox" name="is_primary" value="1" id="contact-primary"
                                               class="form-check-input">
                                        <label for="contact-primary" class="form-check-label">Primary contact</label>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-sm btn-primary">Save Contact</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                @endcan

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Type</th><th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($customer->contacts as $contact)
                                <tr>
                                    <td>
                                        {{ $contact->first_name }} {{ $contact->last_name }}
                                        @if ($contact->is_primary)
                                            <span class="badge bg-primary ms-1">Primary</span>
                                        @endif
                                    </td>
                                    <td class="text-muted">{{ $contact->email }}</td>
                                    <td class="text-muted">{{ $contact->phone ?? '—' }}</td>
                                    <td class="text-muted">{{ $contact->role ?? '—' }}</td>
                                    <td><x-adminlte.partials.status-badge :status="$contact->status" /></td>
                                    <td class="text-end">
                                        @can('customers.edit')
                                            <button type="button" class="btn btn-sm btn-outline-primary" title="Edit"
                                                    onclick="document.getElementById('edit-contact-{{ $contact->id }}').classList.toggle('d-none')">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" action="{{ route('admin.customers.contacts.destroy', [$customer, $contact]) }}"
                                                  class="d-inline" onsubmit="return confirm('Delete this contact?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        @endcan
                                    </td>
                                </tr>
                                @can('customers.edit')
                                    <tr class="d-none" id="edit-contact-{{ $contact->id }}">
                                        <td colspan="6" class="bg-light">
                                            <form method="POST" action="{{ route('admin.customers.contacts.update', [$customer, $contact]) }}">
                                                @csrf
                                                @method('PUT')
                                                <div class="row g-2">
                                                    <div class="col-md-3">
                                                        <input type="text" name="first_name" value="{{ $contact->first_name }}"
                                                               class="form-control form-control-sm" required>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <input type="text" name="last_name" value="{{ $contact->last_name }}"
                                                               class="form-control form-control-sm" required>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <input type="email" name="email" value="{{ $contact->email }}"
                                                               class="form-control form-control-sm" required>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <input type="text" name="phone" value="{{ $contact->phone }}"
                                                               class="form-control form-control-sm" placeholder="Phone">
                                                    </div>
                                                    <div class="col-md-3">
                                                        <input type="text" name="role" value="{{ $contact->role }}"
                                                               class="form-control form-control-sm" placeholder="Role (e.g. Technical)">
                                                    </div>
                                                    <div class="col-md-3">
                                                        <select name="status" class="form-select form-select-sm">
                                                            <option value="active" {{ $contact->status === 'active' ? 'selected' : '' }}>Active</option>
                                                            <option value="inactive" {{ $contact->status === 'inactive' ? 'selected' : '' }}>Inactive</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-3 d-flex align-items-center gap-2">
                                                        <input type="checkbox" name="is_primary" value="1"
                                                               id="contact-primary-{{ $contact->id }}" class="form-check-input"
                                                               {{ $contact->is_primary ? 'checked' : '' }}>
                                                        <label for="contact-primary-{{ $contact->id }}" class="form-check-label">Primary contact</label>
                                                    </div>
                                                    <div class="col-md-3 d-flex align-items-center">
                                                        <button type="submit" class="btn btn-sm btn-primary">Save</button>
                                                    </div>
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                @endcan
                            @empty
                                <tr><td colspan="6" class="text-center text-muted py-3">No contacts yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Notes --}}
            <div class="tab-pane fade {{ $activeTab === 'notes' ? 'show active' : '' }}" id="notes" role="tabpanel" aria-labelledby="notes-tab">
                @can('customers.edit')
                    <form method="POST" action="{{ route('admin.customers.notes.store', $customer) }}" class="mb-3">
                        @csrf
                        <div class="input-group">
                            <textarea name="note" class="form-control" rows="2" placeholder="Add an internal note..."
                                      required></textarea>
                            <div class="input-group-append d-flex flex-column">
                                <div class="form-check ms-2 mb-1">
                                    <input type="checkbox" name="is_important" value="1" id="note-important" class="form-check-input">
                                    <label for="note-important" class="form-check-label">Important</label>
                                </div>
                                <button type="submit" class="btn btn-sm btn-primary ms-2">
                                    <i class="bi bi-plus-lg me-1"></i> Add Note
                                </button>
                            </div>
                        </div>
                    </form>
                @endcan

                <div class="list-group">
                    @forelse ($customer->notes as $note)
                        <div class="list-group-item {{ $note->is_important ? 'list-group-item-warning' : '' }}">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <p class="mb-1">{{ $note->note }}</p>
                                    <small class="text-muted">
                                        {{ $note->user?->full_name ?? 'Unknown' }}
                                        &middot; {{ $note->created_at?->diffForHumans() }}
                                        @if ($note->is_important)
                                            <span class="badge bg-warning text-dark ms-1">Important</span>
                                        @endif
                                    </small>
                                </div>
                                @can('customers.edit')
                                    <div class="d-flex gap-1">
                                        <button type="button" class="btn btn-sm btn-outline-primary" title="Edit"
                                                onclick="document.getElementById('edit-note-{{ $note->id }}').classList.toggle('d-none')">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST"
                                              action="{{ route('admin.customers.notes.destroy', [$customer, $note]) }}"
                                              onsubmit="return confirm('Delete this note?');">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </div>
                                @endcan
                            </div>
                            @can('customers.edit')
                                <div class="d-none mt-2" id="edit-note-{{ $note->id }}">
                                    <form method="POST" action="{{ route('admin.customers.notes.update', [$customer, $note]) }}">
                                        @csrf
                                        @method('PUT')
                                        <textarea name="note" class="form-control" rows="2" required>{{ $note->note }}</textarea>
                                        <div class="d-flex align-items-center justify-content-between mt-2">
                                            <div class="form-check">
                                                <input type="checkbox" name="is_important" value="1"
                                                       id="note-important-{{ $note->id }}" class="form-check-input"
                                                       {{ $note->is_important ? 'checked' : '' }}>
                                                <label for="note-important-{{ $note->id }}" class="form-check-label">Important</label>
                                            </div>
                                            <button type="submit" class="btn btn-sm btn-primary">Save Note</button>
                                        </div>
                                    </form>
                                </div>
                            @endcan
                        </div>
                    @empty
                        <p class="text-muted mb-0">No notes yet.</p>
                    @endforelse
                </div>
            </div>

            {{-- Billing / Wallet --}}
            <div class="tab-pane fade {{ $activeTab === 'billing' ? 'show active' : '' }}" id="billing" role="tabpanel" aria-labelledby="billing-tab">
                @can('customers.edit')
                    <button class="btn btn-sm btn-primary mb-3" type="button" data-bs-toggle="collapse"
                            data-bs-target="#wallet-form" aria-expanded="false">
                        <i class="bi bi-plus-lg me-1"></i> Adjust Wallet
                    </button>
                    <div class="collapse mb-3" id="wallet-form">
                        <div class="border rounded p-3 bg-light">
                            <form method="POST" action="{{ route('admin.customers.wallet.store', $customer) }}">
                                @csrf
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <select name="type" class="form-select form-select-sm">
                                            <option value="deposit">Deposit (account)</option>
                                            <option value="debit">Debit (account)</option>
                                            <option value="credit">Credit (credit limit)</option>
                                            <option value="invoice_payment">Invoice payment</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <input type="number" name="amount" step="0.01" min="0.01"
                                               class="form-control form-control-sm" placeholder="Amount" required>
                                    </div>
                                    <div class="col-md-5">
                                        <input type="text" name="description" class="form-control form-control-sm"
                                               placeholder="Description (optional)">
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-sm btn-primary">Apply</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                @endcan

                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="border rounded p-3 text-center">
                            <div class="text-muted small">Account Balance</div>
                            <div class="fs-4 {{ $customer->balance < 0 ? 'text-danger' : '' }}">₹{{ number_format($customer->balance, 2) }}</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded p-3 text-center">
                            <div class="text-muted small">Credit</div>
                            <div class="fs-4">₹{{ number_format($customer->credit, 2) }}</div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Date</th><th>Type</th><th class="text-end">Amount</th><th>Description</th><th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($walletTransactions as $tx)
                                <tr>
                                    <td class="text-muted">{{ $tx->created_at?->format('M j, Y H:i') }}</td>
                                    <td>{{ ucfirst(str_replace('_', ' ', $tx->type)) }}</td>
                                    <td class="text-end {{ in_array($tx->type, ['deposit', 'credit'], true) ? 'text-success' : 'text-danger' }}">
                                        {{ in_array($tx->type, ['deposit', 'credit'], true) ? '+₹' : '−₹' }}{{ number_format($tx->amount, 2) }}
                                    </td>
                                    <td class="text-muted">{{ $tx->description ?? '—' }}</td>
                                    <td class="text-muted">{{ $tx->adminUser?->full_name ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted py-3">No wallet transactions yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $walletTransactions->appends(['tab' => 'billing'])->links() }}
            </div>

            {{-- Activity --}}
            <div class="tab-pane fade {{ $activeTab === 'activity' ? 'show active' : '' }}" id="activity" role="tabpanel" aria-labelledby="activity-tab">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>When</th><th>Action</th><th>Description</th><th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($activity as $entry)
                                <tr>
                                    <td class="text-muted" style="white-space: nowrap;">{{ $entry->created_at?->format('M j, Y H:i') }}</td>
                                    <td><span class="badge bg-info">{{ $entry->action }}</span></td>
                                    <td>{{ $entry->description }}</td>
                                    <td class="text-muted">{{ $entry->user?->full_name ?? 'System' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">No activity recorded.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $activity->appends(['tab' => 'activity'])->links() }}
            </div>
        </x-adminlte.partials.detail-tabs>
    </x-adminlte-card>

    @can('customers.delete')
        <x-adminlte.partials.confirm-modal
            id="delete-customer-modal"
            title="Delete customer"
            :message="'Delete ' . $customer->display_id . '? This permanently removes the customer, their user account and all related records.'"
            :action="route('admin.customers.destroy', $customer)"
            confirm-label="Delete customer"
        />
    @endcan
@stop
