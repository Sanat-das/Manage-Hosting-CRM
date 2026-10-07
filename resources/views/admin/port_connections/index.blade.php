@extends('adminlte::page')

@section('title', 'Port Connections')

@section('content_header')
    <x-ui.page-header title="Port Connections" subtitle="Cable links between device ports" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Inventory', 'url' => route('admin.inventory-assets.index')],['label' => 'Port Connections', 'active' => true]]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    <x-adminlte.partials.datatable
        icon="bi bi-plug"
        title="All Connections"
        :search-value="$search"
        search-placeholder="Search cable, port or asset tag..."
        :export-url="route('admin.port-connections.export')"
        :columns="[
            ['label' => 'Cable', 'sort' => 'cable_label'],
            ['label' => 'Type'],
            ['label' => 'Length'],
            ['label' => 'Port A', 'sort' => 'a_asset'],
            ['label' => 'Port B', 'sort' => 'b_asset'],
            ['label' => 'Location'],
            ['label' => 'Updated', 'sort' => 'updated_at'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$connections"
    >
        <x-slot name="filters">
            <div class="d-flex align-items-center gap-1">
                <label for="filter-datacenter" class="form-label small fw-medium mb-0">Datacenter</label>
                <select id="filter-datacenter" name="datacenter_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Filter by datacenter">
                    <option value="">All datacenters</option>
                    @foreach ($datacenters as $datacenter)
                        <option value="{{ $datacenter->id }}" @selected((string) request('datacenter_id') === (string) $datacenter->id)>{{ $datacenter->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="d-flex align-items-center gap-1">
                <label for="filter-cable-type" class="form-label small fw-medium mb-0">Cable type</label>
                <select id="filter-cable-type" name="cable_type" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Filter by cable type">
                    <option value="">All cable types</option>
                    @foreach ($cableTypes as $type)
                        <option value="{{ $type }}" @selected(request('cable_type') === $type)>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                    @endforeach
                </select>
            </div>
        </x-slot>

        @forelse ($connections as $connection)
            @php
                $assetA = $connection->portA?->inventoryAsset;
                $assetB = $connection->portB?->inventoryAsset;
                $locations = array_values(array_unique(array_filter([
                    $assetA?->datacenter?->name,
                    $assetB?->datacenter?->name,
                ], fn ($part) => $part !== null && trim((string) $part) !== '')));
            @endphp
            <tr>
                <td>
                    @if ($connection->cable_label)
                        <span class="fw-medium">{{ $connection->cable_label }}</span>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
                <td>{{ $connection->cable_type ? ucfirst(str_replace('_', ' ', $connection->cable_type)) : '—' }}</td>
                <td>{{ $connection->cable_length_m !== null ? rtrim(rtrim(number_format((float) $connection->cable_length_m, 2, '.', ''), '0'), '.').' m' : '—' }}</td>
                <td>
                    @if ($connection->portA)
                        @if ($assetA)
                            <a href="{{ route('admin.inventory-assets.show', $assetA) }}">{{ $assetA->asset_tag }}</a>
                        @endif
                        <div class="small text-muted">{{ $connection->portA->name }}</div>
                    @else
                        —
                    @endif
                </td>
                <td>
                    @if ($connection->portB)
                        @if ($assetB)
                            <a href="{{ route('admin.inventory-assets.show', $assetB) }}">{{ $assetB->asset_tag }}</a>
                        @endif
                        <div class="small text-muted">{{ $connection->portB->name }}</div>
                    @else
                        —
                    @endif
                </td>
                <td>{{ $locations ? implode(' / ', $locations) : '—' }}</td>
                <td>{{ $connection->updated_at?->format('Y-m-d H:i') ?? '—' }}</td>
                <td class="text-end">
                    @can('ports.manage')
                        <div class="table-actions">
                            <button type="button" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit cable" aria-label="Edit cable"
                                    data-bs-toggle="modal" data-bs-target="#cable-modal"
                                    data-cable-action="{{ route('admin.port-connections.update', $connection) }}"
                                    data-cable-label="{{ $connection->cable_label }}"
                                    data-cable-type="{{ $connection->cable_type }}"
                                    data-cable-length="{{ $connection->cable_length_m }}"
                                    data-cable-color="{{ $connection->cable_color }}"
                                    data-cable-notes="{{ $connection->notes }}"><i class="bi bi-pencil"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-warning btn-icon" title="Disconnect" aria-label="Disconnect"
                                    data-bs-toggle="modal" data-bs-target="#disconnect-connection-{{ $connection->id }}"><i class="bi bi-x-circle"></i></button>
                        </div>
                    @endcan
                </td>
            </tr>
        @empty
            <x-ui.empty-table-row colSpan="8" title="No port connections found." />
        @endforelse
    </x-adminlte.partials.datatable>

    @can('ports.manage')
        {{-- One reusable modal drives every row's cable edit: the row button
             carries the current values in data attributes and the initializer
             below populates it, so the page never renders one modal per row. --}}
        <x-adminlte-modal id="cable-modal" title="Edit cable">
            <form method="POST" action="" id="cable-form">
                @csrf
                <input type="hidden" name="_method" value="PUT">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="cable-label">Cable label</label>
                        <input id="cable-label" type="text" name="cable_label" class="form-control form-control-sm" maxlength="100" data-cable-field="cable_label">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="cable-type">Cable type</label>
                        <select id="cable-type" name="cable_type" class="form-select form-select-sm" data-cable-field="cable_type">
                            <option value="">—</option>
                            @foreach ($cableTypes as $type)
                                <option value="{{ $type }}">{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="cable-length">Length (m)</label>
                        <input id="cable-length" type="number" step="0.01" min="0" name="cable_length_m" class="form-control form-control-sm" data-cable-field="cable_length_m">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="cable-color">Colour</label>
                        <input id="cable-color" type="text" name="cable_color" class="form-control form-control-sm" maxlength="16" data-cable-field="cable_color">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="cable-notes">Notes</label>
                        <textarea id="cable-notes" name="notes" rows="2" class="form-control form-control-sm" maxlength="1000" data-cable-field="notes"></textarea>
                    </div>
                </div>
            </form>
            <x-slot name="footer">
                <div class="d-flex gap-2 justify-content-end w-100">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="cable-form" class="btn btn-primary">Save</button>
                </div>
            </x-slot>
        </x-adminlte-modal>

        @foreach ($connections as $connection)
            <x-adminlte.partials.confirm-modal
                :id="'disconnect-connection-' . $connection->id"
                title="Disconnect cable"
                message="Disconnect this cable? The connection will be removed and both ports freed."
                :action="route('admin.port-connections.destroy', $connection)"
                confirm-label="Disconnect"
                confirm-theme="warning"
            />
        @endforeach
    @endcan
@stop

@push('js')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var cableModal = document.getElementById('cable-modal');
            if (!cableModal) return;

            var cableForm = document.getElementById('cable-form');
            if (!cableForm) return;

            function setCableField(name, value) {
                var field = cableForm.querySelector('[data-cable-field="' + name + '"]');
                if (!field) return;
                field.value = value == null ? '' : String(value);
            }

            cableModal.addEventListener('show.bs.modal', function (event) {
                var trigger = event.relatedTarget;
                cableForm.reset();
                if (!trigger) return;

                var action = trigger.getAttribute('data-cable-action');
                if (action) cableForm.setAttribute('action', action);

                setCableField('cable_label', trigger.getAttribute('data-cable-label'));
                setCableField('cable_type', trigger.getAttribute('data-cable-type'));
                setCableField('cable_length_m', trigger.getAttribute('data-cable-length'));
                setCableField('cable_color', trigger.getAttribute('data-cable-color'));
                setCableField('notes', trigger.getAttribute('data-cable-notes'));
            });
        });
    </script>
@endpush
