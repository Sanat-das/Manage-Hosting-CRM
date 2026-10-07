{{--
    Ports card for an inventory asset: the manual port registry. The connect /
    disconnect surface is added by Slice B2; this partial already ships the
    reusable peer-port combobox initializer it will drop into its connect modal.

    Expects:
      $inventoryAsset    InventoryAsset
      $ports             Collection<int, DevicePort>  ordered by name
      $connectionMap     array<int, array{connection: PortConnection, peerPort: ?DevicePort, peerAssetTag: ?string}>
      $recentCableEvents Collection<int, PortConnectionEvent>
      $cableEventUsers   Collection<int, string>  user id => display name
--}}
@php
    $speedOptions = [
        '10000000' => '10 Mbps',
        '100000000' => '100 Mbps',
        '1000000000' => '1 Gbps',
        '2500000000' => '2.5 Gbps',
        '5000000000' => '5 Gbps',
        '10000000000' => '10 Gbps',
        '25000000000' => '25 Gbps',
        '40000000000' => '40 Gbps',
        '100000000000' => '100 Gbps',
    ];
@endphp

<div class="row">
    <div class="col-12">
        <x-adminlte-card icon="bi bi-ethernet" title="Ports">
            <x-slot name="tools">
                <span class="badge text-bg-light border me-2">{{ $ports->count() }} {{ \Illuminate\Support\Str::plural('port', $ports->count()) }} · {{ count($connectionMap) }} connected</span>
                @can('ports.manage')
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#port-modal"
                            data-port-mode="create" data-port-action="{{ route('admin.inventory-assets.ports.store', $inventoryAsset) }}">
                        <i class="bi bi-plus-lg me-1"></i> Add Port
                    </button>
                @endcan
            </x-slot>

            @if ($ports->isEmpty())
                <p class="text-muted mb-0">No ports recorded.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Port</th>
                                <th>Type</th>
                                <th>Media</th>
                                <th>Speed</th>
                                <th>Status</th>
                                <th>MAC</th>
                                <th>Connected to</th>
                                <th>Cable</th>
                                @can('ports.manage')
                                    <th class="text-end">Actions</th>
                                @endcan
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($ports as $port)
                                @php
                                    $entry = $connectionMap[$port->id] ?? null;
                                    $peerPort = $entry['peerPort'] ?? null;
                                    $peerTag = $entry['peerAssetTag'] ?? null;
                                    $connection = $entry['connection'] ?? null;
                                    $cableText = '';
                                    if ($connection) {
                                        $cableDetails = array_filter([
                                            $connection->cable_type !== null ? str_replace('_', ' ', $connection->cable_type) : null,
                                            $connection->cable_length_m !== null ? rtrim(rtrim(number_format((float) $connection->cable_length_m, 2, '.', ''), '0'), '.').' m' : null,
                                        ], fn ($part) => $part !== null && $part !== '');
                                        $cableText = trim((string) $connection->cable_label);
                                        if ($cableDetails) {
                                            $cableText = $cableText !== '' ? $cableText.' ('.implode(' · ', $cableDetails).')' : implode(' · ', $cableDetails);
                                        }
                                    }
                                @endphp
                                <tr>
                                    <td>
                                        <span class="fw-medium">{{ $port->name }}</span>
                                        @if ($port->port_number)
                                            <div class="small text-muted">{{ $port->port_number }}</div>
                                        @endif
                                    </td>
                                    <td>{{ ucfirst(str_replace('_', ' ', $port->port_type)) }}</td>
                                    <td>{{ $port->media ? ucfirst($port->media) : '—' }}</td>
                                    <td>{{ $port->speed_label }}</td>
                                    <td><x-adminlte.partials.status-badge :status="$port->status" /></td>
                                    <td>@if ($port->mac_address)<code>{{ $port->mac_address }}</code>@else — @endif</td>
                                    <td>
                                        @if ($peerPort)
                                            @if ($peerTag)
                                                <a href="{{ route('admin.inventory-assets.show', $peerPort->inventory_asset_id) }}">{{ $peerTag }}</a>
                                            @endif
                                            <div class="small text-muted">{{ $peerPort->name }}</div>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>{{ $cableText !== '' ? $cableText : '—' }}</td>
                                    @can('ports.manage')
                                        <td class="text-end">
                                            <div class="table-actions">
                                                <button type="button" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit port" aria-label="Edit port"
                                                        data-bs-toggle="modal" data-bs-target="#port-modal"
                                                        data-port-mode="edit"
                                                        data-port-action="{{ route('admin.inventory-assets.ports.update', [$inventoryAsset, $port]) }}"
                                                        data-port-name="{{ $port->name }}"
                                                        data-port-port-number="{{ $port->port_number }}"
                                                        data-port-port-type="{{ $port->port_type }}"
                                                        data-port-media="{{ $port->media }}"
                                                        data-port-speed-bps="{{ $port->speed_bps }}"
                                                        data-port-speed-label="{{ $port->speed_label }}"
                                                        data-port-status="{{ $port->status }}"
                                                        data-port-mac-address="{{ $port->mac_address }}"
                                                        data-port-notes="{{ $port->notes }}"><i class="bi bi-pencil"></i></button>
                                                @if ($connection)
                                                    <button type="button" class="btn btn-sm btn-outline-warning btn-icon" title="Disconnect cable" aria-label="Disconnect cable"
                                                            data-bs-toggle="modal" data-bs-target="#disconnect-port-{{ $port->id }}"><i class="bi bi-x-circle"></i></button>
                                                @else
                                                    <button type="button" class="btn btn-sm btn-outline-primary btn-icon" title="Connect port" aria-label="Connect port"
                                                            data-bs-toggle="modal" data-bs-target="#connect-port-modal"
                                                            data-connect-source-id="{{ $port->id }}"
                                                            data-connect-source-label="{{ $inventoryAsset->asset_tag }} · {{ $port->name }}"><i class="bi bi-plug"></i></button>
                                                @endif
                                                <button type="button" class="btn btn-sm btn-outline-danger btn-icon" title="Delete port" aria-label="Delete port"
                                                        data-bs-toggle="modal" data-bs-target="#delete-port-{{ $port->id }}"><i class="bi bi-trash"></i></button>
                                            </div>
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

{{-- Recent cable changes: hidden until the connection ledger has rows (B2). --}}
@if ($recentCableEvents->isNotEmpty())
    <div class="row">
        <div class="col-12">
            <x-adminlte-card icon="bi bi-clock-history" title="Recent cable changes">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Changed at</th>
                                <th>Action</th>
                                <th>Port A</th>
                                <th>Port B</th>
                                <th>Cable</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recentCableEvents as $event)
                                @php
                                    $eventCable = array_filter([
                                        $event->cable_label,
                                        $event->cable_type !== null ? str_replace('_', ' ', $event->cable_type) : null,
                                        $event->cable_length_m !== null ? rtrim(rtrim(number_format((float) $event->cable_length_m, 2, '.', ''), '0'), '.').' m' : null,
                                    ], fn ($part) => $part !== null && trim((string) $part) !== '');
                                @endphp
                                <tr>
                                    <td>{{ $event->changed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                    <td><x-adminlte.partials.status-badge :status="$event->action" variant="subtle" /></td>
                                    <td>{{ $event->a_asset_tag }} <span class="text-muted small">{{ $event->a_port_name }}</span></td>
                                    <td>{{ $event->b_asset_tag }} <span class="text-muted small">{{ $event->b_port_name }}</span></td>
                                    <td>{{ $eventCable ? implode(' · ', $eventCable) : '—' }}</td>
                                    <td>{{ $cableEventUsers[$event->changed_by_user_id] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-adminlte-card>
        </div>
    </div>
@endif

@can('ports.manage')
    {{-- One reusable modal drives both Add and Edit: the row buttons carry the
         field values in data attributes and the initializer below populates it,
         so a 48-port switch never renders 48 modals (plan risk R8). --}}
    <x-adminlte-modal id="port-modal" title="Add Port" size="lg">
        <form method="POST" action="{{ route('admin.inventory-assets.ports.store', $inventoryAsset) }}" id="port-form">
            @csrf
            <input type="hidden" name="_method" value="POST" data-port-method>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="port-name">Name <span class="text-danger">*</span></label>
                    <input id="port-name" type="text" name="name" class="form-control form-control-sm" maxlength="100" required data-port-field="name">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="port-number">Port number</label>
                    <input id="port-number" type="text" name="port_number" class="form-control form-control-sm" maxlength="50" placeholder="e.g. Gi1/0/24" data-port-field="port_number">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="port-type">Type <span class="text-danger">*</span></label>
                    <select id="port-type" name="port_type" class="form-select form-select-sm" required data-port-field="port_type">
                        @foreach (\App\Models\DevicePort::PORT_TYPES as $type)
                            <option value="{{ $type }}" @selected($type === 'ethernet')>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="port-media">Media</label>
                    <select id="port-media" name="media" class="form-select form-select-sm" data-port-field="media">
                        <option value="">—</option>
                        @foreach (\App\Models\DevicePort::MEDIA as $media)
                            <option value="{{ $media }}">{{ ucfirst($media) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="port-speed">Speed</label>
                    <select id="port-speed" name="speed_bps" class="form-select form-select-sm" data-port-field="speed_bps">
                        <option value="">Unknown</option>
                        @foreach ($speedOptions as $bps => $label)
                            <option value="{{ $bps }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="port-status">Status</label>
                    <select id="port-status" name="status" class="form-select form-select-sm" data-port-field="status">
                        @foreach (\App\Models\DevicePort::STATUSES as $status)
                            <option value="{{ $status }}" @selected($status === 'unknown')>{{ ucfirst($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="port-mac">MAC address</label>
                    <input id="port-mac" type="text" name="mac_address" class="form-control form-control-sm" maxlength="17" placeholder="AA:BB:CC:DD:EE:FF" data-port-field="mac_address">
                </div>
                <div class="col-12">
                    <label class="form-label" for="port-notes">Notes</label>
                    <textarea id="port-notes" name="notes" rows="2" class="form-control form-control-sm" maxlength="1000" data-port-field="notes"></textarea>
                </div>
            </div>
        </form>
        <x-slot name="footer">
            <div class="d-flex gap-2 justify-content-end w-100">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="port-form" class="btn btn-primary">Save</button>
            </div>
        </x-slot>
    </x-adminlte-modal>

    {{-- One reusable connect modal: the row button carries the source port, and
         the peer picker (B1's [data-peer-port-picker] contract) supplies the
         peer. The submit button lives inside the form so the picker initializer
         can gate it until a peer is chosen. --}}
    <x-adminlte-modal id="connect-port-modal" title="Connect Port" size="lg">
        <form method="POST" action="{{ route('admin.inventory-assets.port-connections.store', $inventoryAsset) }}" id="connect-port-form">
            @csrf
            <input type="hidden" name="source_port_id" value="" data-connect-source-id>
            <div class="mb-3">
                <span class="text-muted small d-block">Source port</span>
                <span class="fw-medium" data-connect-source-label>—</span>
            </div>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label" for="connect-peer-input">Peer port <span class="text-danger">*</span></label>
                    <div class="position-relative" data-peer-port-picker data-peer-port-search-url="{{ route('admin.ports.search') }}" data-peer-port-include-connected="1">
                        <input id="connect-peer-input" type="text" class="form-control form-control-sm" data-peer-port-input placeholder="Search by asset tag, port name or MAC…" autocomplete="off">
                        <input type="hidden" name="peer_port_id" data-peer-port-value>
                        <div class="dropdown-menu w-100" data-peer-port-dropdown style="display: none; max-height: 16rem; overflow-y: auto;"></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="connect-cable-label">Cable label</label>
                    <input id="connect-cable-label" type="text" name="cable_label" class="form-control form-control-sm" maxlength="100">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="connect-cable-type">Cable type</label>
                    <select id="connect-cable-type" name="cable_type" class="form-select form-select-sm">
                        <option value="">—</option>
                        @foreach (\App\Models\PortConnection::CABLE_TYPES as $cableType)
                            <option value="{{ $cableType }}">{{ ucfirst(str_replace('_', ' ', $cableType)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="connect-cable-length">Length (m)</label>
                    <input id="connect-cable-length" type="number" step="0.01" min="0" name="cable_length_m" class="form-control form-control-sm">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="connect-cable-color">Colour</label>
                    <input id="connect-cable-color" type="text" name="cable_color" class="form-control form-control-sm" maxlength="16">
                </div>
                <div class="col-12">
                    <label class="form-label" for="connect-notes">Notes</label>
                    <textarea id="connect-notes" name="notes" rows="2" class="form-control form-control-sm" maxlength="1000"></textarea>
                </div>
                <div class="col-12 d-flex gap-2 justify-content-end">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Connect</button>
                </div>
            </div>
        </form>
    </x-adminlte-modal>

    @foreach ($ports as $port)
        <x-adminlte.partials.confirm-modal
            :id="'delete-port-' . $port->id"
            title="Delete port"
            :message="'Delete port ' . $port->name . '?'"
            :action="route('admin.inventory-assets.ports.destroy', [$inventoryAsset, $port])"
            confirm-label="Delete"
        />
    @endforeach

    {{-- Disconnect uses the shared confirm modal; only connected ports render one. --}}
    @foreach ($ports as $port)
        @php($disconnectEntry = $connectionMap[$port->id] ?? null)
        @if ($disconnectEntry && $disconnectEntry['connection'])
            <x-adminlte.partials.confirm-modal
                :id="'disconnect-port-' . $port->id"
                title="Disconnect cable"
                :message="'Disconnect ' . $port->name . ' from ' . ($disconnectEntry['peerPort']?->name ?? 'its peer') . '?'"
                :action="route('admin.port-connections.destroy', $disconnectEntry['connection'])"
                confirm-label="Disconnect"
                confirm-theme="warning"
            />
        @endif
    @endforeach
@endcan

@push('js')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            @can('ports.manage')
            // --- Reusable Add/Edit port modal --------------------------------
            var portModal = document.getElementById('port-modal');
            if (portModal) {
                var portForm = document.getElementById('port-form');
                var methodInput = portForm ? portForm.querySelector('[data-port-method]') : null;
                var titleEl = portModal.querySelector('.modal-title');
                var createAction = portForm ? portForm.getAttribute('action') : null;

                function setPortField(name, value, label) {
                    var field = portForm.querySelector('[data-port-field="' + name + '"]');
                    if (!field) return;
                    var stringValue = value == null ? '' : String(value);
                    if (field.tagName === 'SELECT') {
                        field.value = stringValue;
                        if (field.value !== stringValue && stringValue !== '') {
                            var opt = document.createElement('option');
                            opt.value = stringValue;
                            opt.textContent = label || stringValue;
                            opt.setAttribute('data-port-custom', '');
                            field.appendChild(opt);
                            field.value = stringValue;
                        }
                    } else {
                        field.value = stringValue;
                    }
                }

                portModal.addEventListener('show.bs.modal', function (event) {
                    var trigger = event.relatedTarget;
                    var isEdit = trigger && trigger.getAttribute('data-port-mode') === 'edit';

                    portForm.querySelectorAll('[data-port-custom]').forEach(function (option) { option.remove(); });
                    portForm.reset();

                    if (isEdit) {
                        if (titleEl) titleEl.textContent = 'Edit Port';
                        if (methodInput) methodInput.value = 'PUT';
                        if (trigger.getAttribute('data-port-action')) portForm.setAttribute('action', trigger.getAttribute('data-port-action'));
                        setPortField('name', trigger.getAttribute('data-port-name'));
                        setPortField('port_number', trigger.getAttribute('data-port-port-number'));
                        setPortField('port_type', trigger.getAttribute('data-port-port-type'));
                        setPortField('media', trigger.getAttribute('data-port-media'));
                        setPortField('speed_bps', trigger.getAttribute('data-port-speed-bps'), trigger.getAttribute('data-port-speed-label'));
                        setPortField('status', trigger.getAttribute('data-port-status'));
                        setPortField('mac_address', trigger.getAttribute('data-port-mac-address'));
                        setPortField('notes', trigger.getAttribute('data-port-notes'));
                    } else {
                        if (titleEl) titleEl.textContent = 'Add Port';
                        if (methodInput) methodInput.value = 'POST';
                        if (createAction) portForm.setAttribute('action', createAction);
                        setPortField('port_type', 'ethernet');
                        setPortField('status', 'unknown');
                    }
                });
            }
            @endcan

            // --- Peer-port combobox (dropped into B2's connect modal) --------
            // Self-contained: no-ops when no [data-peer-port-picker] exists.
            var peerPickers = document.querySelectorAll('[data-peer-port-picker]');
            if (peerPickers.length) {
                peerPickers.forEach(function (container) {
                    var input = container.querySelector('[data-peer-port-input]');
                    var hidden = container.querySelector('[data-peer-port-value]');
                    var dropdown = container.querySelector('[data-peer-port-dropdown]');
                    var searchUrl = container.getAttribute('data-peer-port-search-url');
                    if (!input || !hidden || !dropdown || !searchUrl) return;

                    var form = container.closest('form');
                    var submit = form ? form.querySelector('button[type="submit"]') : null;

                    input.setAttribute('autocomplete', 'off');
                    input.setAttribute('aria-autocomplete', 'list');
                    input.setAttribute('aria-expanded', 'false');
                    input.setAttribute('role', 'combobox');
                    if (!dropdown.id) dropdown.id = (input.id || 'peer-port') + '-listbox';
                    input.setAttribute('aria-controls', dropdown.id);

                    var debounceTimer = null;
                    var abortController = null;
                    var activeIndex = -1;
                    var currentItems = [];
                    var focusFirstOnOpen = false;

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
                        focusFirstOnOpen = false;
                    }

                    // Index of the next enabled entry in a direction, wrapping
                    // around; -1 when every entry is disabled.
                    function nextEnabledIndex(from, step) {
                        var total = currentItems.length;
                        if (!total) return -1;
                        var idx = from;
                        for (var i = 0; i < total; i++) {
                            idx += step;
                            if (idx < 0) idx = total - 1;
                            if (idx >= total) idx = 0;
                            if (!currentItems[idx].disabled) return idx;
                        }
                        return -1;
                    }

                    function render(items) {
                        currentItems = items.slice(0, 8);
                        dropdown.innerHTML = '';
                        if (!currentItems.length) { closeDropdown(); return; }
                        currentItems.forEach(function (item, idx) {
                            var isDisabled = item.disabled === true;
                            var isActive = idx === activeIndex && !isDisabled;
                            var btn = document.createElement('button');
                            btn.type = 'button';
                            btn.className = 'dropdown-item d-flex flex-column align-items-start py-2'
                                + (isActive ? ' active' : '')
                                + (isDisabled ? ' disabled opacity-75' : '');
                            btn.setAttribute('role', 'option');
                            btn.setAttribute('id', dropdown.id + '-opt-' + idx);
                            btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
                            if (isDisabled) {
                                btn.setAttribute('aria-disabled', 'true');
                                btn.disabled = true;
                            }
                            if (isActive) btn.setAttribute('aria-current', 'true');

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

                            if (!isDisabled) {
                                btn.addEventListener('mousedown', function (e) { e.preventDefault(); applySelection(item); });
                                btn.addEventListener('click', function (e) { e.preventDefault(); applySelection(item); });
                            }
                            dropdown.appendChild(btn);
                        });
                        dropdown.style.display = 'block';
                        input.setAttribute('aria-expanded', 'true');
                        if (focusFirstOnOpen) {
                            focusFirstOnOpen = false;
                            var first = nextEnabledIndex(-1, 1);
                            if (first >= 0) updateActive(first);
                        }
                    }

                    function updateActive(newIndex) {
                        var buttons = dropdown.querySelectorAll('[role="option"]');
                        if (newIndex >= 0 && currentItems[newIndex] && currentItems[newIndex].disabled) return;
                        buttons.forEach(function (b, i) {
                            var isDisabled = currentItems[i] && currentItems[i].disabled;
                            var isActive = i === newIndex && !isDisabled;
                            b.classList.toggle('active', isActive);
                            b.setAttribute('aria-selected', isActive ? 'true' : 'false');
                            if (isActive) b.setAttribute('aria-current', 'true'); else b.removeAttribute('aria-current');
                        });
                        activeIndex = newIndex;
                        if (activeIndex >= 0 && buttons[activeIndex]) buttons[activeIndex].scrollIntoView({ block: 'nearest' });
                    }

                    function applySelection(item) {
                        if (!item || item.disabled) return;
                        hidden.value = item.id;
                        input.value = item.label;
                        syncSubmit();
                        closeDropdown();
                        input.focus();
                    }

                    function fetchAndRender(query) {
                        if (abortController) abortController.abort();
                        abortController = new AbortController();
                        var url = new URL(searchUrl, window.location.origin);
                        url.searchParams.set('q', query);
                        var excludePort = container.getAttribute('data-peer-port-exclude-port-id');
                        var excludeAsset = container.getAttribute('data-peer-port-exclude-asset-id');
                        var includeConnected = container.getAttribute('data-peer-port-include-connected');
                        if (excludePort) url.searchParams.set('exclude_port_id', excludePort);
                        if (excludeAsset) url.searchParams.set('exclude_asset_id', excludeAsset);
                        if (includeConnected) url.searchParams.set('include_connected', includeConnected);
                        fetch(url.toString(), {
                            headers: { 'Accept': 'application/json' },
                            signal: abortController.signal
                        }).then(function (resp) {
                            if (!resp.ok) throw new Error('bad status');
                            return resp.json();
                        }).then(function (data) {
                            render(data && Array.isArray(data.results) ? data.results : []);
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
                        hidden.value = '';
                        syncSubmit();
                        debouncedFetch();
                    });

                    input.addEventListener('keydown', function (e) {
                        var isOpen = dropdown.style.display !== 'none' && currentItems.length > 0;
                        if (!isOpen) {
                            if (e.key === 'ArrowDown') { e.preventDefault(); focusFirstOnOpen = true; debouncedFetch(); }
                            return;
                        }
                        if (e.key === 'ArrowDown') {
                            e.preventDefault();
                            var next = nextEnabledIndex(activeIndex, 1);
                            if (next >= 0) updateActive(next);
                        } else if (e.key === 'ArrowUp') {
                            e.preventDefault();
                            var prev = nextEnabledIndex(activeIndex, -1);
                            if (prev >= 0) updateActive(prev);
                        } else if (e.key === 'Enter') {
                            e.preventDefault();
                            if (activeIndex >= 0 && activeIndex < currentItems.length) applySelection(currentItems[activeIndex]);
                            else closeDropdown();
                        } else if (e.key === 'Escape') {
                            e.preventDefault();
                            closeDropdown();
                        }
                    });

                    document.addEventListener('click', function (e) {
                        if (!container.contains(e.target)) closeDropdown();
                    });
                });
            }
        });
    </script>
@endpush

@can('ports.manage')
    @push('js')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                // --- Connect modal: seed the source port and reset the picker ---
                var connectModal = document.getElementById('connect-port-modal');
                if (!connectModal) return;
                var connectForm = document.getElementById('connect-port-form');
                if (!connectForm) return;

                var sourceInput = connectForm.querySelector('[data-connect-source-id]');
                var sourceLabel = connectModal.querySelector('[data-connect-source-label]');
                var picker = connectModal.querySelector('[data-peer-port-picker]');
                var peerInput = connectModal.querySelector('[data-peer-port-input]');
                var submitBtn = connectForm.querySelector('button[type="submit"]');

                connectModal.addEventListener('show.bs.modal', function (event) {
                    var trigger = event.relatedTarget;
                    var sourceId = trigger ? trigger.getAttribute('data-connect-source-id') : '';
                    var sourceText = trigger ? trigger.getAttribute('data-connect-source-label') : '';

                    connectForm.reset();
                    if (sourceInput) sourceInput.value = sourceId || '';
                    if (sourceLabel) sourceLabel.textContent = sourceText || '—';
                    if (picker) {
                        if (sourceId) picker.setAttribute('data-peer-port-exclude-port-id', sourceId);
                        else picker.removeAttribute('data-peer-port-exclude-port-id');
                    }
                    if (submitBtn) submitBtn.disabled = true;
                    // Clears the hidden peer value, re-disables submit and closes
                    // any dropdown left open from a previous source port.
                    if (peerInput) peerInput.dispatchEvent(new Event('input'));
                });
            });
        </script>
    @endpush
@endcan
