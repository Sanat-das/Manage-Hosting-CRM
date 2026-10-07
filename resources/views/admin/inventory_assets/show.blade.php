@extends('adminlte::page')
@section('title', 'Asset — '.$inventoryAsset->asset_tag)
@section('content_header')
    <x-ui.page-header title="{{ $inventoryAsset->asset_tag }}" subtitle="View inventory asset details" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Inventory','url' => route('admin.inventory-assets.index')],['label' => $inventoryAsset->asset_tag,'active' => true]]" />
@stop
@section('content')
    <x-adminlte.partials.flash-alert />
    @can('inventory.manage')
        <div class="d-flex justify-content-end mb-3">
            <a href="{{ route('admin.inventory-assets.edit', $inventoryAsset) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
        </div>
    @endcan

    {{-- Single-column stack: Details, then relationships, then IP history. --}}
    <div class="row">
        <div class="col-12">
            <x-adminlte-card icon="bi bi-info-circle" title="Details">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr><th class="text-muted w-25">Asset Tag</th><td>{{ $inventoryAsset->asset_tag }}</td></tr>
                        <tr><th class="text-muted">Type</th><td><span class="badge text-bg-info">{{ ucfirst($inventoryAsset->asset_type) }}</span></td></tr>
                        <tr><th class="text-muted">Serial</th><td>{{ $inventoryAsset->serial_number ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Model</th><td>{{ $inventoryAsset->model ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Manufacturer</th><td>{{ $inventoryAsset->manufacturer ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Vendor</th><td>{{ $inventoryAsset->vendor ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Datacenter</th><td>{{ $inventoryAsset->datacenter?->name ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Rack</th><td>{{ $inventoryAsset->rack?->name ?? '—' }}</td></tr>
                        <tr><th class="text-muted">U Position</th><td>{{ $inventoryAsset->rack_u_position ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Purchase Date</th><td>{{ $inventoryAsset->purchase_date?->format('Y-m-d') ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Purchase Cost</th><td>@if ($inventoryAsset->purchase_cost !== null)<x-adminlte.partials.currency :value="$inventoryAsset->purchase_cost" />@else — @endif</td></tr>
                        <tr><th class="text-muted">Warranty Expiry</th><td>{{ $inventoryAsset->warranty_expiry?->format('Y-m-d') ?? '—' }}</td></tr>
                        <tr><th class="text-muted">Status</th><td><x-adminlte.partials.status-badge :status="$inventoryAsset->status" /></td></tr>
                        <tr><th class="text-muted">Notes</th><td>{{ $inventoryAsset->notes ?? '—' }}</td></tr>
                    </tbody>
                </table>
            </x-adminlte-card>
        </div>
    </div>

    @if (in_array($inventoryAsset->asset_type, \App\Models\InventoryAsset::PORT_BEARING_TYPES, true) || $ports->isNotEmpty())
        @include('admin.inventory_assets._ports')
    @endif

    <div class="row">
        <div class="col-12">
            <x-adminlte-card icon="bi bi-diagram-3" title="Child Assets">
                @if ($childLinks->isEmpty())
                    <p class="text-muted mb-0">No child assets linked.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Asset</th>
                                    <th>Type</th>
                                    <th>Serial</th>
                                    <th>Model</th>
                                    <th>Manufacturer</th>
                                    <th>Status</th>
                                    <th>Location</th>
                                    <th>Relationship</th>
                                    @can('asset-relationships.manage')
                                        <th class="text-end">Actions</th>
                                    @endcan
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($childLinks as $link)
                                    @php
                                        $asset = $relatedAssets[(int) $link->child_id] ?? null;
                                        $locationParts = $asset ? array_filter([
                                            $asset->datacenter?->name,
                                            $asset->rack?->name,
                                            $asset->rack_u_position,
                                        ], fn ($part) => $part !== null && $part !== '') : [];
                                    @endphp
                                    <tr>
                                        <td><a href="{{ route('admin.inventory-assets.show', $link->child_id) }}">{{ $asset->asset_tag ?? 'Asset #'.$link->child_id }}</a></td>
                                        <td>
                                            @if ($asset)
                                                <span class="badge text-bg-info">{{ ucfirst($asset->asset_type) }}</span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>@if ($asset?->serial_number)<code>{{ $asset->serial_number }}</code>@else — @endif</td>
                                        <td>{{ $asset->model ?? '—' }}</td>
                                        <td>{{ $asset->manufacturer ?? '—' }}</td>
                                        <td>
                                            @if ($asset)
                                                <x-adminlte.partials.status-badge :status="$asset->status" />
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="text-muted">{{ $locationParts ? implode(' · ', $locationParts) : '—' }}</td>
                                        <td>
                                            <span class="badge text-bg-secondary">{{ ucfirst(str_replace('_', ' ', $link->relationship_type)) }}</span>
                                            @if ($link->label)
                                                <div class="small text-muted">{{ $link->label }}</div>
                                            @endif
                                        </td>
                                        @can('asset-relationships.manage')
                                            <td class="text-end">
                                                <button type="button" class="btn btn-sm btn-outline-danger" title="Remove child link" aria-label="Remove child link"
                                                        data-bs-toggle="modal" data-bs-target="#remove-child-{{ $link->id }}"><i class="bi bi-x-lg"></i></button>
                                            </td>
                                        @endcan
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @can('asset-relationships.manage')
                    <hr class="my-3">
                    <form method="POST" action="{{ route('admin.asset-relationships.store') }}">
                        @csrf
                        <input type="hidden" name="parent_kind" value="inventory_asset">
                        <input type="hidden" name="parent_id" value="{{ $inventoryAsset->id }}">
                        <input type="hidden" name="child_kind" value="inventory_asset">
                        <div class="mb-2 position-relative inventory-asset-search" data-search-url="{{ route('admin.inventory-assets.search', ['exclude' => $inventoryAsset->id]) }}">
                            <label class="form-label small text-muted mb-1" for="child-asset-search">Add child asset</label>
                            <input type="text" id="child-asset-search" class="form-control form-control-sm" data-asset-search-input placeholder="Search tag, serial, model, manufacturer…" autocomplete="off">
                            <input type="hidden" name="child_id" data-asset-search-value value="">
                            <div class="dropdown-menu border shadow rounded p-1" data-asset-search-dropdown role="listbox" aria-label="Asset suggestions" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:1050; max-height:240px; overflow-y:auto;"></div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small text-muted mb-1" for="child-relationship-type">Relationship</label>
                            <select id="child-relationship-type" name="relationship_type" class="form-select form-select-sm" required>
                                @foreach (\App\Models\AssetRelationship::RELATIONSHIP_TYPES as $type)
                                    <option value="{{ $type }}" @selected($type === 'contains')>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small text-muted mb-1" for="child-label">Label (optional)</label>
                            <input id="child-label" type="text" name="label" maxlength="255" class="form-control form-control-sm" placeholder="e.g. Mounted in">
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary" disabled><i class="bi bi-link-45deg me-1"></i> Add child</button>
                    </form>
                @endcan
            </x-adminlte-card>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <x-adminlte-card icon="bi bi-diagram-2" title="Parent Assets">
                @if ($parentLinks->isEmpty())
                    <p class="text-muted mb-0">No parent assets linked.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Asset</th>
                                    <th>Type</th>
                                    <th>Serial</th>
                                    <th>Model</th>
                                    <th>Manufacturer</th>
                                    <th>Status</th>
                                    <th>Location</th>
                                    <th>Relationship</th>
                                    @can('asset-relationships.manage')
                                        <th class="text-end">Actions</th>
                                    @endcan
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($parentLinks as $link)
                                    @php
                                        $asset = $relatedAssets[(int) $link->parent_id] ?? null;
                                        $locationParts = $asset ? array_filter([
                                            $asset->datacenter?->name,
                                            $asset->rack?->name,
                                            $asset->rack_u_position,
                                        ], fn ($part) => $part !== null && $part !== '') : [];
                                    @endphp
                                    <tr>
                                        <td><a href="{{ route('admin.inventory-assets.show', $link->parent_id) }}">{{ $asset->asset_tag ?? 'Asset #'.$link->parent_id }}</a></td>
                                        <td>
                                            @if ($asset)
                                                <span class="badge text-bg-info">{{ ucfirst($asset->asset_type) }}</span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>@if ($asset?->serial_number)<code>{{ $asset->serial_number }}</code>@else — @endif</td>
                                        <td>{{ $asset->model ?? '—' }}</td>
                                        <td>{{ $asset->manufacturer ?? '—' }}</td>
                                        <td>
                                            @if ($asset)
                                                <x-adminlte.partials.status-badge :status="$asset->status" />
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="text-muted">{{ $locationParts ? implode(' · ', $locationParts) : '—' }}</td>
                                        <td>
                                            <span class="badge text-bg-secondary">{{ ucfirst(str_replace('_', ' ', $link->relationship_type)) }}</span>
                                            @if ($link->label)
                                                <div class="small text-muted">{{ $link->label }}</div>
                                            @endif
                                        </td>
                                        @can('asset-relationships.manage')
                                            <td class="text-end">
                                                <button type="button" class="btn btn-sm btn-outline-danger" title="Remove parent link" aria-label="Remove parent link"
                                                        data-bs-toggle="modal" data-bs-target="#remove-parent-{{ $link->id }}"><i class="bi bi-x-lg"></i></button>
                                            </td>
                                        @endcan
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @can('asset-relationships.manage')
                    <hr class="my-3">
                    <form method="POST" action="{{ route('admin.asset-relationships.store') }}">
                        @csrf
                        <input type="hidden" name="parent_kind" value="inventory_asset">
                        <input type="hidden" name="child_kind" value="inventory_asset">
                        <input type="hidden" name="child_id" value="{{ $inventoryAsset->id }}">
                        <div class="mb-2 position-relative inventory-asset-search" data-search-url="{{ route('admin.inventory-assets.search', ['exclude' => $inventoryAsset->id]) }}">
                            <label class="form-label small text-muted mb-1" for="parent-asset-search">Add parent asset</label>
                            <input type="text" id="parent-asset-search" class="form-control form-control-sm" data-asset-search-input placeholder="Search tag, serial, model, manufacturer…" autocomplete="off">
                            <input type="hidden" name="parent_id" data-asset-search-value value="">
                            <div class="dropdown-menu border shadow rounded p-1" data-asset-search-dropdown role="listbox" aria-label="Asset suggestions" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:1050; max-height:240px; overflow-y:auto;"></div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small text-muted mb-1" for="parent-relationship-type">Relationship</label>
                            <select id="parent-relationship-type" name="relationship_type" class="form-select form-select-sm" required>
                                @foreach (\App\Models\AssetRelationship::RELATIONSHIP_TYPES as $type)
                                    <option value="{{ $type }}" @selected($type === 'hosted_on')>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small text-muted mb-1" for="parent-label">Label (optional)</label>
                            <input id="parent-label" type="text" name="label" maxlength="255" class="form-control form-control-sm" placeholder="e.g. Host node">
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary" disabled><i class="bi bi-link-45deg me-1"></i> Add parent</button>
                    </form>
                @endcan
            </x-adminlte-card>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <x-adminlte-card icon="bi bi-hdd-network" title="IP Addresses">
                @if ($inventoryAsset->ipAddresses->isEmpty())
                    <x-adminlte.partials.empty-state icon="bi bi-hdd-network" title="No IP addresses linked.">
                        @if (in_array($inventoryAsset->asset_type, \App\Models\InventoryAsset::IP_TRACKING_TYPES, true))
                            @can('inventory.manage')
                                <a href="{{ route('admin.inventory-assets.edit', $inventoryAsset) }}">Attach IP addresses</a>
                            @endcan
                        @endif
                    </x-adminlte.partials.empty-state>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>IP Address</th>
                                    <th>Status</th>
                                    <th>Subnet</th>
                                    <th>VLAN</th>
                                    <th>Last Seen</th>
                                    @can('inventory.manage')
                                        <th class="text-end">Actions</th>
                                    @endcan
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($inventoryAsset->ipAddresses as $ip)
                                    <tr>
                                        <td><a href="{{ route('admin.ip-addresses.show', $ip) }}"><code>{{ $ip->ip_address }}</code></a></td>
                                        <td>
                                            <x-adminlte.partials.status-badge :status="$ip->type" variant="subtle" />
                                            @if ($ip->status !== $ip->type)
                                                <x-adminlte.partials.status-badge :status="$ip->status" variant="subtle" />
                                            @endif
                                        </td>
                                        <td class="text-muted">{{ $ip->subnet?->name ?? '—' }}</td>
                                        <td class="text-muted">{{ $ip->subnet?->vlan ? ($ip->subnet->vlan->name ?: 'VLAN '.$ip->subnet->vlan->vlan_id).' ('.$ip->subnet->vlan->vlan_id.')' : '—' }}</td>
                                        <td class="text-muted">{{ $ip->last_seen_at?->format('Y-m-d H:i') ?? 'never' }}</td>
                                        @can('inventory.manage')
                                            <td class="text-end">
                                                <button type="button" class="btn btn-sm btn-outline-danger" title="Unlink IP" aria-label="Unlink IP"
                                                        data-bs-toggle="modal" data-bs-target="#detach-ip-{{ $ip->id }}"><i class="bi bi-x-lg"></i></button>
                                            </td>
                                        @endcan
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-adminlte-card>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <x-adminlte-card icon="bi bi-clock-history" title="Recent IP allocation history">
                @if ($recentIpHistory->isEmpty())
                    <x-adminlte.partials.empty-state icon="bi bi-clock-history" title="No IP history yet." />
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Changed at</th>
                                    <th>IP</th>
                                    <th>Action</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recentIpHistory as $entry)
                                    <tr>
                                        <td>{{ $entry->changed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                        <td><code>{{ $entry->ipAddress?->ip_address ?? $entry->ip_address_snapshot }}</code></td>
                                        <td><x-adminlte.partials.status-badge :status="$entry->action" variant="subtle" /></td>
                                        <td class="text-muted">{{ $entry->notes ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-adminlte-card>
        </div>
    </div>

    {{-- Remove-link confirm modals: rendered outside the cards so they stay
         functional regardless of layout. --}}
    @can('asset-relationships.manage')
        @foreach ($childLinks as $link)
            <x-adminlte.partials.confirm-modal
                :id="'remove-child-' . $link->id"
                title="Remove child link"
                :message="'Remove this ' . $link->relationship_type . ' link to ' . ($relatedAssetTags[(int) $link->child_id] ?? 'Asset #'.$link->child_id) . '?'"
                :action="route('admin.asset-relationships.destroy', $link)"
                confirm-label="Remove link"
            />
        @endforeach
        @foreach ($parentLinks as $link)
            <x-adminlte.partials.confirm-modal
                :id="'remove-parent-' . $link->id"
                title="Remove parent link"
                :message="'Remove this ' . $link->relationship_type . ' link to ' . ($relatedAssetTags[(int) $link->parent_id] ?? 'Asset #'.$link->parent_id) . '?'"
                :action="route('admin.asset-relationships.destroy', $link)"
                confirm-label="Remove link"
            />
        @endforeach
    @endcan

    {{-- Unlink-IP confirm modals, rendered outside the IP card like the
         relationship modals above. --}}
    @can('inventory.manage')
        @foreach ($inventoryAsset->ipAddresses as $ip)
            <x-adminlte.partials.confirm-modal
                :id="'detach-ip-' . $ip->id"
                title="Unlink IP"
                :message="'Unlink ' . $ip->ip_address . ' from this asset? The IP stays in IP Manager.'"
                :action="route('admin.inventory-assets.detach-ip', [$inventoryAsset, $ip])"
                confirm-label="Unlink"
            />
        @endforeach
    @endcan
@stop

@push('js')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var fields = document.querySelectorAll('[data-search-url]');
            if (!fields.length) return;

            fields.forEach(function (container) {
                var input = container.querySelector('[data-asset-search-input]');
                var hidden = container.querySelector('[data-asset-search-value]');
                var dropdown = container.querySelector('[data-asset-search-dropdown]');
                var searchUrl = container.getAttribute('data-search-url');
                if (!input || !hidden || !dropdown || !searchUrl) return;

                var form = container.closest('form');
                var submit = form ? form.querySelector('button[type="submit"]') : null;

                input.setAttribute('autocomplete', 'off');
                input.setAttribute('aria-autocomplete', 'list');
                input.setAttribute('aria-expanded', 'false');
                input.setAttribute('role', 'combobox');
                if (!dropdown.id) dropdown.id = input.id + '-listbox';
                input.setAttribute('aria-controls', dropdown.id);

                var debounceTimer = null;
                var abortController = null;
                var activeIndex = -1;
                var currentItems = [];

                function syncSubmit() {
                    if (submit) submit.disabled = hidden.value === '';
                }
                syncSubmit();

                function closeDropdown() {
                    dropdown.style.display = 'none';
                    dropdown.innerHTML = '';
                    input.setAttribute('aria-expanded', 'false');
                    activeIndex = -1;
                    currentItems = [];
                }

                function render(items) {
                    currentItems = items.slice(0, 8);
                    dropdown.innerHTML = '';
                    if (!currentItems.length) { closeDropdown(); return; }
                    currentItems.forEach(function (item, idx) {
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'dropdown-item d-flex flex-column align-items-start py-2' + (idx === activeIndex ? ' active' : '');
                        btn.setAttribute('role', 'option');
                        btn.setAttribute('id', dropdown.id + '-opt-' + idx);
                        btn.setAttribute('aria-selected', idx === activeIndex ? 'true' : 'false');
                        if (idx === activeIndex) btn.setAttribute('aria-current', 'true');

                        var label = document.createElement('span');
                        label.className = 'fw-medium small text-truncate w-100';
                        label.textContent = item.label;
                        btn.appendChild(label);

                        if (item.meta) {
                            var meta = document.createElement('span');
                            meta.className = 'text-muted small text-truncate w-100';
                            meta.textContent = item.meta;
                            btn.appendChild(meta);
                        }

                        btn.addEventListener('mousedown', function (e) {
                            e.preventDefault();
                            applySelection(item);
                        });
                        btn.addEventListener('click', function (e) {
                            e.preventDefault();
                            applySelection(item);
                        });
                        dropdown.appendChild(btn);
                    });
                    dropdown.style.display = 'block';
                    input.setAttribute('aria-expanded', 'true');
                }

                function updateActive(newIndex) {
                    var buttons = dropdown.querySelectorAll('[role="option"]');
                    buttons.forEach(function (b, i) {
                        b.classList.toggle('active', i === newIndex);
                        b.setAttribute('aria-selected', i === newIndex ? 'true' : 'false');
                        if (i === newIndex) b.setAttribute('aria-current', 'true'); else b.removeAttribute('aria-current');
                    });
                    activeIndex = newIndex;
                    if (activeIndex >= 0 && buttons[activeIndex]) {
                        buttons[activeIndex].scrollIntoView({ block: 'nearest' });
                    }
                }

                function applySelection(item) {
                    hidden.value = item.id;
                    // The visible input carries the tag; the label is "TAG — Type".
                    input.value = String(item.label).split(' — ')[0];
                    syncSubmit();
                    closeDropdown();
                    input.focus();
                }

                function fetchAndRender(query) {
                    if (abortController) abortController.abort();
                    abortController = new AbortController();
                    var url = new URL(searchUrl, window.location.origin);
                    url.searchParams.set('q', query);
                    fetch(url.toString(), {
                        headers: { 'Accept': 'application/json' },
                        signal: abortController.signal
                    }).then(function (resp) {
                        if (!resp.ok) throw new Error('bad status');
                        return resp.json();
                    }).then(function (data) {
                        var items = (data && Array.isArray(data.results)) ? data.results : [];
                        render(items);
                    }).catch(function (err) {
                        if (err && err.name === 'AbortError') return;
                        closeDropdown();
                    });
                }

                function debouncedFetch() {
                    var token = input.value.trim();
                    if (debounceTimer) clearTimeout(debounceTimer);
                    if (token.length < 1) { closeDropdown(); return; }
                    debounceTimer = setTimeout(function () { fetchAndRender(token); }, 250);
                }

                input.addEventListener('input', function () {
                    // Typing after a selection invalidates the chosen id.
                    hidden.value = '';
                    syncSubmit();
                    debouncedFetch();
                });

                input.addEventListener('keydown', function (e) {
                    var isOpen = dropdown.style.display !== 'none' && currentItems.length > 0;
                    if (!isOpen) {
                        if (e.key === 'ArrowDown') {
                            e.preventDefault();
                            debouncedFetch();
                        }
                        return;
                    }
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        var next = activeIndex + 1;
                        if (next >= currentItems.length) next = 0;
                        updateActive(next);
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        var prev = activeIndex - 1;
                        if (prev < 0) prev = currentItems.length - 1;
                        updateActive(prev);
                    } else if (e.key === 'Enter') {
                        e.preventDefault();
                        if (activeIndex >= 0 && activeIndex < currentItems.length) {
                            applySelection(currentItems[activeIndex]);
                        } else {
                            closeDropdown();
                        }
                    } else if (e.key === 'Escape') {
                        e.preventDefault();
                        closeDropdown();
                    }
                });

                document.addEventListener('click', function (e) {
                    if (!container.contains(e.target)) closeDropdown();
                });
            });
        });
    </script>
@endpush
