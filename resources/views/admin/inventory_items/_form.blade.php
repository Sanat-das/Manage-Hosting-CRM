{{--
    Shared field markup for the inventory item create/edit forms.

    Expects:
      $devices          Collection of live devices (id, asset_tag, rack_u_position)
      $manufacturers    Collection of manufacturers (id, name)
      $parentItems      Collection of all live items (id, name, sku, device_id)
      $item             InventoryItem|null       (edit only; null on create)
      $selectedDeviceId int|null                 (create only; pre-selected device id)
      $statuses         array<int, string>       Device::STATUSES
--}}
<div class="row">
    <div class="col-md-6">
        <x-adminlte-select name="device_id" id="device_id" label="Device" required>
            @foreach ($devices as $device)
                <option value="{{ $device->id }}" @selected((string) old('device_id', $selectedDeviceId ?? $item?->device_id) === (string) $device->id)>{{ $device->asset_tag }}{{ $device->rack_u_position ? ' · U'.$device->rack_u_position : '' }}</option>
            @endforeach
        </x-adminlte-select>
    </div>
    <div class="col-md-6">
        {{--
            Parent dropdown: options are server-rendered for the initially
            selected device and rebuilt by the script below whenever the
            device choice changes (an item's parent must live in the same
            device). An empty value means "top level".
        --}}
        @php
            $initialDeviceId = (int) old('device_id', $selectedDeviceId ?? $item?->device_id ?? 0);
            $initialParentId = (string) old('parent_item_id', $item?->parent_item_id);
            $initialCandidates = $parentItems->filter(fn ($candidate) => (int) $candidate->device_id === $initialDeviceId);
            $parentOptionsJson = $parentItems
                ->map(fn ($candidate) => ['id' => $candidate->id, 'name' => $candidate->name, 'sku' => $candidate->sku, 'device' => (int) $candidate->device_id])
                ->values();
        @endphp
        <label for="parent_item_id" class="form-label">Parent Item</label>
        <select name="parent_item_id" id="parent_item_id" class="form-select @error('parent_item_id') is-invalid @enderror">
            <option value="">— None (top level) —</option>
            @foreach ($initialCandidates as $candidate)
                @continue($item !== null && (int) $candidate->id === (int) $item->id)
                <option value="{{ $candidate->id }}" @selected($initialParentId === (string) $candidate->id)>{{ $candidate->name }}{{ $candidate->sku ? ' · '.$candidate->sku : '' }}</option>
            @endforeach
        </select>
        @error('parent_item_id')
            <div class="invalid-feedback d-block" role="alert">{{ $message }}</div>
        @enderror
        <small class="form-text text-muted">Only items installed in the same device can be parents.</small>
    </div>
</div>
<div class="row">
    <div class="col-md-6"><x-adminlte-input name="name" label="Name" placeholder="e.g. RAID Controller" value="{{ old('name', $item?->name) }}" required /></div>
    <div class="col-md-6"><x-adminlte-input name="label" label="Label" value="{{ old('label', $item?->label) }}" /></div>
</div>
<div class="row">
    <div class="col-md-4"><x-adminlte-input name="sku" label="SKU" value="{{ old('sku', $item?->sku) }}" /></div>
    <div class="col-md-4"><x-adminlte-input name="part_number" label="Part Number" value="{{ old('part_number', $item?->part_number) }}" /></div>
    <div class="col-md-4"><x-adminlte-input name="serial_number" label="Serial Number" value="{{ old('serial_number', $item?->serial_number) }}" /></div>
</div>
<div class="row">
    <div class="col-md-6">
        <x-adminlte-select name="manufacturer_id" label="Manufacturer">
            <option value="">— None —</option>
            @foreach ($manufacturers as $manufacturer)
                <option value="{{ $manufacturer->id }}" @selected((string) old('manufacturer_id', $item?->manufacturer_id) === (string) $manufacturer->id)>{{ $manufacturer->name }}</option>
            @endforeach
        </x-adminlte-select>
    </div>
    <div class="col-md-6"><x-adminlte-input name="asset_tag" label="Asset Tag" value="{{ old('asset_tag', $item?->asset_tag) }}" /></div>
</div>
<div class="row">
    <div class="col-md-6">
        <x-adminlte-select name="status" label="Status">
            @foreach ($statuses as $s)
                <option value="{{ $s }}" @selected(old('status', $item?->status ?? 'installed') === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
            @endforeach
        </x-adminlte-select>
    </div>
    <div class="col-md-6 d-flex align-items-end pb-3">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="discovered" name="discovered" value="1" @checked(old('discovered', $item?->discovered ?? false))>
            <label class="form-check-label" for="discovered">Discovered by automated scan</label>
        </div>
    </div>
</div>
<div class="row">
    <div class="col-12"><x-adminlte-textarea name="description" label="Description" rows="2">{{ old('description', $item?->description) }}</x-adminlte-textarea></div>
</div>
<x-adminlte-textarea name="notes" label="Notes" rows="2">{{ old('notes', $item?->notes) }}</x-adminlte-textarea>

@push('js')
{{--
    Rebuilds the parent-item dropdown from the JSON blob below so it only
    lists items that belong to the currently selected device (and not this
    item itself). Plain DOM APIs — Bootstrap JS is not a global in this
    panel.
--}}
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var deviceSelect = document.getElementById('device_id');
        var parentSelect = document.getElementById('parent_item_id');
        if (!deviceSelect || !parentSelect) {
            return;
        }

        var parentItems = @json($parentOptionsJson);
        var excludeItemId = @json($item?->id);

        function rebuild() {
            var deviceId = parseInt(deviceSelect.value, 10);
            var current = parentSelect.value;

            parentSelect.innerHTML = '';

            var none = document.createElement('option');
            none.value = '';
            none.textContent = '— None (top level) —';
            parentSelect.appendChild(none);

            parentItems.forEach(function (candidate) {
                if (candidate.device !== deviceId) {
                    return;
                }
                if (excludeItemId !== null && candidate.id === excludeItemId) {
                    return;
                }
                var option = document.createElement('option');
                option.value = String(candidate.id);
                option.textContent = candidate.sku ? candidate.name + ' · ' + candidate.sku : candidate.name;
                parentSelect.appendChild(option);
            });

            if (Array.prototype.some.call(parentSelect.options, function (option) { return option.value === current; })) {
                parentSelect.value = current;
            }
        }

        deviceSelect.addEventListener('change', rebuild);
    });
</script>
@endpush
