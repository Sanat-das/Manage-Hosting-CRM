@extends('adminlte::page')

@section('title', 'Products/Services')

@section('content_header')
    <x-ui.page-header title="Products/Services" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Dashboard', 'url' => route('client.dashboard')],
        ['label' => 'Products/Services', 'active' => true],
    ]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    {{-- Status summary cards (click to filter) --}}
    @php
        $statusCards = [
            'active'     => ['label' => 'Active',     'theme' => 'success'],
            'suspended'  => ['label' => 'Suspended',  'theme' => 'danger'],
            'pending'    => ['label' => 'Pending',    'theme' => 'warning'],
            'terminated' => ['label' => 'Terminated', 'theme' => 'secondary'],
        ];
    @endphp
    <div class="row">
        @foreach ($statusCards as $key => $card)
            <div class="col-lg-3 col-6 mb-3">
                <a href="{{ route('client.hosting.index', ['status' => $key] + request()->except(['status', 'page'])) }}"
                   class="d-block text-decoration-none"
                   aria-label="Show {{ $card['label'] }} services ({{ $counts[$key] ?? 0 }})"
                   @if ($status === $key) aria-current="true" style="outline:2px solid var(--color-primary);outline-offset:2px;" @endif>
                    <x-adminlte-small-box :title="$counts[$key] ?? 0" :text="$card['label']"
                                          icon="bi bi-hdd-stack" :theme="$card['theme']" />
                </a>
            </div>
        @endforeach
    </div>

    <x-adminlte.partials.datatable
        icon="bi bi-hdd-stack"
        title="Products/Services"
        :search-value="$search"
        search-placeholder="Search domain, product or server..."
        :status-options="array_map(fn ($card) => $card['label'], $statusCards)"
        :status-value="$status ?? ''"
        :sticky-header="true"
        :column-toggle="true"
        :columns="[
            ['label' => 'Service', 'sort' => 'id'],
            ['label' => 'Product', 'sort' => 'product'],
            ['label' => 'Server', 'sort' => 'server'],
            ['label' => 'IP address'],
            ['label' => 'Domain', 'sort' => 'domain'],
            ['label' => 'Cycle', 'sort' => 'cycle'],
            ['label' => 'Amount', 'sort' => 'amount', 'class' => 'text-end'],
            ['label' => 'Next billing', 'sort' => 'next_due'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$accounts"
    >
                @forelse ($accounts as $account)
                    @php
                        $info = $billing[$account->id] ?? null;
                        $serviceOrder = $account->order;
                        $serviceItem = $serviceOrder?->items?->first(fn ($i) => $i->product_addon_id === null && (int) $i->product_id === (int) $account->product_id)
                            ?? $serviceOrder?->items?->first(fn ($i) => $i->product_addon_id === null);
                        $cycle = $info['cycle'] ?? $serviceOrder?->billing_cycle ?? $account->product?->billing_cycle;
                        $amount = $info['amount'] ?? null;
                        $nextBilling = $info['next_billing_date'] ?? $serviceOrder?->next_billing_date ?? $account->next_due_date;
                        $domainName = $account->domain ?? $serviceItem?->domain_name ?? $serviceOrder?->domain_name;
                        $domainModel = $domainName ? $customerDomains->firstWhere('name', $domainName) : null;
                        $cycleInvoice = $serviceInvoiceByAccount[(int) $account->id] ?? null;
                    @endphp
                    <tr data-row-link="{{ route('client.hosting.show', $account) }}">
                        <td><a href="{{ route('client.hosting.show', $account) }}"><strong>#{{ $account->id }}</strong></a></td>
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
                            @if ($domainModel)
                                <a href="{{ route('client.domains.show', $domainModel->id) }}">{{ $domainName }}</a>
                            @elseif ($domainName)
                                {{ $domainName }}
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-muted">{{ $cycle ? ucfirst(str_replace('_', ' ', $cycle)) : '—' }}</td>
                        <td class="text-end">
                            @if ($amount !== null)
                                <x-adminlte.partials.currency :value="$amount" />
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="{{ $nextBilling && $nextBilling->isPast() ? 'text-danger fw-bold' : 'text-muted' }}">
                            {{ $nextBilling?->format('M j, Y') ?? '—' }}
                            @if ($cycleInvoice)
                                <div class="small">
                                    <a href="{{ route('client.invoices.show', $cycleInvoice->id) }}">{{ $cycleInvoice->invoice_no }}</a>
                                    <x-adminlte.partials.status-badge :status="$cycleInvoice->status" />
                                    @if ($cycleInvoice->status === 'sent')
                                        <span class="text-danger">unpaid</span>
                                    @endif
                                </div>
                            @else
                                <div class="small text-muted">not invoiced yet</div>
                            @endif
                        </td>
                        <td>
                            <x-adminlte.partials.status-badge :status="$account->status" />
                        </td>
                        <td class="text-end">
                            <div class="table-actions">
                                <a href="{{ route('client.hosting.show', $account) }}" class="btn btn-sm btn-outline-primary btn-icon" title="Details" aria-label="Details for service #{{ $account->id }}"><i class="bi bi-eye"></i></a>
                            </div>
                        </td>
                    </tr>
                @empty
                    @if ($search !== '' || $status !== null)
                        <x-ui.empty-table-row colSpan="10" icon="bi bi-hdd-stack" title="No products/services match your filters." />
                    @else
                        <x-ui.empty-table-row colSpan="10" icon="bi bi-hdd-stack" title="You don't have any products or services yet." actionLabel="Browse the Store" :actionUrl="route('client.store.index')" />
                    @endif
                @endforelse
    </x-adminlte.partials.datatable>

    <x-adminlte-card icon="bi bi-globe2" title="Domains">
        <div class="table-responsive has-sticky">
            <table class="table table-grid align-middle m-0" data-grid-resizable data-grid-key="client.hosting.domains">
                <thead>
                    <tr>
                        <th scope="col">Domain</th>
                        <th scope="col">Type</th>
                        <th scope="col">Expiry</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($customerDomains as $domain)
                        @php $expiring = $domain->status === 'active' && $domain->expiry_date?->isBefore(now()->addDays(30)); @endphp
                        <tr>
                            <td><a href="{{ route('client.domains.show', $domain->id) }}">{{ $domain->name }}</a></td>
                            <td class="text-muted">{{ $domain->type ? ucfirst($domain->type) : '—' }}</td>
                            <td class="{{ $expiring ? 'text-danger fw-bold' : 'text-muted' }}">
                                {{ $domain->expiry_date?->format('M j, Y') ?? '—' }}
                                @if ($expiring)
                                    <span class="badge text-bg-warning ms-1">Expiring soon</span>
                                @endif
                            </td>
                            <td><x-adminlte.partials.status-badge :status="$domain->status" /></td>
                            <td class="text-end">
                                <a href="{{ route('client.domains.show', $domain->id) }}" class="btn btn-sm btn-outline-primary">View</a>
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty-table-row colSpan="5" icon="bi bi-globe2" title="No domains yet." message="Domains you register or add appear here." actionLabel="Manage domains" :actionUrl="route('client.domains.index')" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-adminlte-card>

    <x-adminlte-card icon="bi bi-puzzle" title="Add-ons">
        <div class="table-responsive has-sticky">
            <table class="table table-grid align-middle m-0" data-grid-resizable data-grid-key="client.hosting.addons">
                <thead>
                    <tr>
                        <th scope="col">Add-on</th>
                        <th scope="col">Parent service</th>
                        <th scope="col">Order</th>
                        <th scope="col">Cycle</th>
                        <th scope="col" class="text-end">Qty</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col">Next billing</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($addonItems as $item)
                        @php
                            $parentAccountId = $serviceAccountByOrderProduct[($item->order_id).':'.($item->parentItem?->product_id)] ?? null;
                            // A recurring line without a next billing date was cancelled;
                            // a one-time line (a setup fee) never recurs, so it is not cancelled.
                            $addonStatus = ($item->billing_cycle !== 'one_time' && $item->next_billing_date === null)
                                ? 'cancelled'
                                : ($item->order?->status ?? 'active');
                        @endphp
                        <tr>
                            <td>{{ $item->product_name }}</td>
                            <td class="text-muted">
                                @if ($parentAccountId)
                                    #{{ $parentAccountId }} · {{ $item->parentItem?->product_name ?? '—' }}
                                @else
                                    {{ $item->parentItem?->product_name ?? '—' }}
                                @endif
                            </td>
                            <td>
                                @if ($item->order)
                                    <a href="{{ route('client.orders.show', $item->order->id) }}">{{ $item->order->order_number }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-muted">{{ $item->billing_cycle ? ucfirst(str_replace('_', ' ', $item->billing_cycle)) : '—' }}</td>
                            <td class="text-end">{{ $item->quantity }}</td>
                            <td class="text-end"><x-adminlte.partials.currency :value="$item->total" /></td>
                            <td class="{{ $item->next_billing_date?->isPast() ? 'text-danger fw-bold' : 'text-muted' }}">
                                {{ $item->next_billing_date?->format('M j, Y') ?? '—' }}
                            </td>
                            <td><x-adminlte.partials.status-badge :status="$addonStatus" /></td>
                            <td class="text-end">
                                @if ($parentAccountId)
                                    <a href="{{ route('client.hosting.show', $parentAccountId) }}" class="btn btn-sm btn-outline-primary" aria-label="Open service #{{ $parentAccountId }}">View</a>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty-table-row colSpan="9" icon="bi bi-puzzle" title="No add-ons on your services yet." message="Add-ons you buy for a service appear here." actionLabel="Browse the Store" :actionUrl="route('client.store.index')" />
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3 pt-3 border-top">
            <h6 class="mb-2"><i class="bi bi-plus-circle me-1"></i> Buy an add-on</h6>

            @if (empty($addonPurchaseOptions))
                <p class="text-muted small mb-0">{{ $purchaseNotice }}</p>
            @else
                <div style="max-height: 26rem; overflow-y: auto;">
                    @foreach ($addonPurchaseOptions as $entry)
                        <form method="POST" action="{{ route('client.hosting.addons.store', $entry['account']->id) }}" class="row g-2 align-items-end mb-3 pb-3 border-bottom">
                            @csrf
                            <input type="hidden" name="account_id" value="{{ $entry['account']->id }}">
                            <fieldset class="col-12 border-0 p-0 m-0">
                                <legend class="visually-hidden">Buy an add-on for service #{{ $entry['account']->id }}</legend>
                                <div class="row g-2 align-items-end">
                                    <div class="col-12 col-lg-3">
                                        <div class="small text-muted">Service</div>
                                        <div>#{{ $entry['account']->id }} · {{ $entry['account']->product?->name ?? '—' }}</div>
                                    </div>
                                    <div class="col-12 col-lg-4">
                                        <label class="form-label small mb-1" for="addon-{{ $entry['account']->id }}">Add-on <span class="text-danger" aria-hidden="true">*</span></label>
                                        <select id="addon-{{ $entry['account']->id }}" name="addon_id" class="form-select form-select-sm" required aria-required="true" data-addon-select>
                                            <option value="">Select an add-on…</option>
                                            @foreach ($entry['options'] as $option)
                                                <option value="{{ $option['addon']->id }}"
                                                    data-price="{{ (float) $option['unit_price'] }}"
                                                    data-setup="{{ (float) $option['setup_fee'] }}"
                                                    @selected((int) old('account_id') === (int) $entry['account']->id && (int) old('addon_id') === (int) $option['addon']->id)>{{ $option['addon']->name }} — ₹{{ number_format((float) $option['unit_price'], 2) }} / {{ ucfirst(str_replace('_', ' ', $option['cycle'])) }}@if ((float) $option['setup_fee'] > 0) + ₹{{ number_format((float) $option['setup_fee'], 2) }} once @endif</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-6 col-lg-2">
                                        <label class="form-label small mb-1" for="qty-{{ $entry['account']->id }}">Quantity <span class="text-danger" aria-hidden="true">*</span></label>
                                        <input id="qty-{{ $entry['account']->id }}" type="number" name="quantity" min="1" max="{{ \App\Models\Order::MAX_QUANTITY }}" value="{{ (int) old('account_id') === (int) $entry['account']->id ? old('quantity', 1) : 1 }}" class="form-control form-control-sm" required aria-required="true" data-addon-qty>
                                    </div>
                                    <div class="col-6 col-lg-2">
                                        <div class="small text-muted mb-1">Total</div>
                                        @php
                                            $restoredAddonId = (int) old('account_id') === (int) $entry['account']->id ? (int) old('addon_id') : 0;
                                            $restoredOption = $restoredAddonId > 0
                                                ? collect($entry['options'])->first(fn ($option) => (int) $option['addon']->id === $restoredAddonId)
                                                : null;
                                            $restoredQuantity = max(1, (int) old('quantity', 1));
                                            $initialTotal = $restoredOption !== null
                                                ? ((float) $restoredOption['unit_price'] * $restoredQuantity) + (float) $restoredOption['setup_fee']
                                                : null;
                                        @endphp
                                        <div data-addon-total>{{ $initialTotal === null ? '—' : '₹'.number_format($initialTotal, 2) }}</div>
                                    </div>
                                    <div class="col-12 col-lg-1 text-lg-end">
                                        <button type="submit" class="btn btn-sm btn-primary" aria-label="Buy add-on for service #{{ $entry['account']->id }}">Buy add-on</button>
                                    </div>
                                </div>
                            </fieldset>
                        </form>
                    @endforeach
                </div>
            @endif
        </div>
    </x-adminlte-card>

    @push('css')
        <style>
            @media (max-width: 1199.98px) {
                table[data-grid-key="client.hosting.index"] { min-width: 1240px; }
                table[data-grid-key="client.hosting.addons"] { min-width: 1040px; }
            }
            @media (max-width: 767.98px) {
                table[data-grid-key="client.hosting.domains"] { min-width: 560px; }
            }
        </style>
    @endpush

    @push('js')
        <script>
            document.querySelectorAll('tr[data-row-link]').forEach(function (row) {
                row.style.cursor = 'pointer';
                row.addEventListener('click', function (e) {
                    if (e.target.closest('a, button, input, select, .badge')) return;
                    window.location = row.dataset.rowLink;
                });
            });

            // Keep each purchase form's total in sync with the chosen add-on
            // and the quantity: price * quantity + the one-time setup fee.
            document.querySelectorAll('[data-addon-select]').forEach(function (select) {
                var form = select.closest('form');
                if (!form) return;

                var qty = form.querySelector('[data-addon-qty]');
                var total = form.querySelector('[data-addon-total]');
                if (!qty || !total) return;

                function updateTotal() {
                    var option = select.options[select.selectedIndex];

                    if (!option || !option.value) {
                        total.textContent = '—';
                        return;
                    }

                    var price = parseFloat(option.getAttribute('data-price') || '0');
                    var setup = parseFloat(option.getAttribute('data-setup') || '0');
                    var quantity = parseInt(qty.value, 10);

                    if (!isFinite(quantity) || quantity < 1) {
                        quantity = 1;
                    }

                    total.textContent = '₹' + (price * quantity + setup).toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                }

                select.addEventListener('change', updateTotal);
                qty.addEventListener('input', updateTotal);
                updateTotal();
            });
        </script>
    @endpush
@stop
