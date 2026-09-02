@extends('adminlte::page')

@section('title', __('adminlte.edit_role'))

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">{{ __('adminlte.edit_role') }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item">{{ __('adminlte.administration') }}</li>
                <li class="breadcrumb-item"><a href="{{ route('adminlte.roles.index') }}">{{ __('adminlte.roles') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ __('adminlte.edit') }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <x-adminlte-card icon="bi bi-shield-lock" title="{{ __('adminlte.edit_role') }}">
        <form method="POST" action="{{ route('adminlte.roles.update', $role) }}">
            @csrf
            @method('PUT')

            <x-adminlte-input name="name" label="{{ __('adminlte.name') }}" :value="$role->name" required />
            <x-adminlte-input name="label" label="{{ __('adminlte.label') }}" :value="$role->label" />

            @php
                $selectedIds = old('permissions', $role->permissions->pluck('id')->all());
                $selectedIds = array_map('intval', (array) $selectedIds);
                $total = $permissions->count();
                $selectedCount = count($selectedIds);
                $groupIcons = [
                    'Dashboard & Reporting' => 'bi bi-speedometer2',
                    'Customer Management' => 'bi bi-people',
                    'Products & Catalog' => 'bi bi-box',
                    'Sales & Billing' => 'bi bi-receipt',
                    'Hosting' => 'bi bi-hdd-rack',
                    'Infrastructure' => 'bi bi-building',
                    'Network & DNS' => 'bi bi-diagram-3',
                    'Domains' => 'bi bi-globe',
                    'Support' => 'bi bi-life-preserver',
                    'System & Administration' => 'bi bi-gear',
                    'Other' => 'bi bi-three-dots',
                ];
            @endphp

            <div class="mb-3">
                <label class="form-label fw-semibold">{{ __('adminlte.permissions') }}</label>
                @error('permissions')
                    <div class="text-danger small mb-2">{{ $message }}</div>
                @enderror

                {{-- Toolbar: search + counts + bulk actions --}}
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3 p-3 rounded-3 border bg-light-subtle" style="background:var(--bs-tertiary-bg);">
                    <div class="flex-grow-1" style="min-width:240px; max-width:420px;">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-body"><i class="bi bi-search"></i></span>
                            <input type="search" id="perm-search" class="form-control" placeholder="Filter permissions… (e.g. DNS, invoice, hosting)" autocomplete="off">
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2 ms-auto">
                        <span class="badge text-bg-primary fw-normal" id="perm-selected-count">{{ $selectedCount }} / {{ $total }} selected</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="perm-select-all">Select all (filtered)</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="perm-clear-all">Clear (filtered)</button>
                    </div>
                </div>

                <div id="perm-groups" class="d-flex flex-column gap-3">
                    @forelse ($grouped as $groupName => $items)
                        @php
                            $groupSelected = $items->filter(fn($p) => in_array((int)$p->id, $selectedIds, true))->count();
                            $groupTotal = $items->count();
                            $allChecked = $groupSelected === $groupTotal;
                            $groupId = 'group-'.md5($groupName);
                        @endphp
                        <div class="card border shadow-sm perm-group" data-group="{{ strtolower($groupName) }}">
                            <div class="card-header d-flex align-items-center gap-2 py-2" style="background:var(--bs-tertiary-bg);">
                                <div class="form-check mb-0">
                                    <input class="form-check-input perm-group-toggle" type="checkbox"
                                           id="{{ $groupId }}-toggle"
                                           data-group="{{ $groupId }}"
                                           @checked($allChecked)>
                                    <label class="form-check-label fw-semibold" for="{{ $groupId }}-toggle">
                                        <i class="{{ $groupIcons[$groupName] ?? 'bi bi-shield' }} me-1 text-secondary"></i>{{ $groupName }}
                                    </label>
                                </div>
                                <span class="badge rounded-pill ms-2 perm-group-count {{ $groupSelected > 0 ? 'text-bg-primary' : 'text-bg-secondary' }}"
                                      data-group="{{ $groupId }}">{{ $groupSelected }}/{{ $groupTotal }}</span>
                                <span class="text-muted small ms-1 d-none d-md-inline">{{ $groupTotal }} permissions</span>
                                <button type="button" class="btn btn-sm btn-link ms-auto text-decoration-none p-0 perm-group-collapse" data-bs-toggle="collapse" data-bs-target="#{{ $groupId }}-body" aria-expanded="true" aria-controls="{{ $groupId }}-body">
                                    <i class="bi bi-chevron-down small"></i>
                                </button>
                            </div>
                            <div id="{{ $groupId }}-body" class="collapse show">
                                <div class="card-body py-3">
                                    <div class="row g-2">
                                        @foreach ($items as $permission)
                                            <div class="col-12 col-md-6 col-lg-4 perm-item" data-label="{{ strtolower($permission->label ?? $permission->name) }}" data-name="{{ strtolower($permission->name) }}">
                                                <div class="form-check border rounded-2 px-3 py-2 h-100 d-flex gap-2 align-items-start" style="background:var(--bs-body-bg);">
                                                    <input class="form-check-input mt-1 perm-check" type="checkbox" name="permissions[]"
                                                           value="{{ $permission->id }}" id="permission-{{ $permission->id }}"
                                                           data-group="{{ $groupId }}"
                                                           @checked(in_array((int)$permission->id, $selectedIds, true))>
                                                    <label class="form-check-label flex-grow-1" for="permission-{{ $permission->id }}" style="cursor:pointer;">
                                                        <span class="d-block small fw-medium lh-sm">{{ $permission->label ?? $permission->name }}</span>
                                                        <span class="d-block text-muted" style="font-size:0.72rem; letter-spacing:.01em;">{{ $permission->name }}</span>
                                                    </label>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-muted">{{ __('adminlte.no_permissions') }}</p>
                    @endforelse
                </div>

                <div id="perm-no-results" class="text-center text-muted py-4 d-none">
                    <i class="bi bi-search fs-4 d-block mb-2"></i>No permissions match your filter.
                </div>
            </div>

            <div class="d-flex gap-2">
                <a href="{{ route('adminlte.roles.index') }}" class="btn btn-outline-secondary">{{ __('adminlte.cancel') }}</a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1" aria-hidden="true"></i> {{ __('adminlte.save') }}
                </button>
            </div>
        </form>
    </x-adminlte-card>
@stop

@push('js')
<script>
document.addEventListener('DOMContentLoaded', function () {
  const search = document.getElementById('perm-search');
  const items = document.querySelectorAll('.perm-item');
  const noResults = document.getElementById('perm-no-results');
  const selectedBadge = document.getElementById('perm-selected-count');
  const selectAllBtn = document.getElementById('perm-select-all');
  const clearBtn = document.getElementById('perm-clear-all');

  function updateCounts() {
    const checks = document.querySelectorAll('.perm-check');
    const total = checks.length;
    const selected = document.querySelectorAll('.perm-check:checked').length;
    selectedBadge.textContent = selected + ' / ' + total + ' selected';
    selectedBadge.className = 'badge fw-normal ' + (selected > 0 ? 'text-bg-primary' : 'text-bg-secondary');
    document.querySelectorAll('.perm-group').forEach(function (group) {
      const toggle = group.querySelector('.perm-group-toggle');
      if (!toggle) return;
      const gid = toggle.dataset.group;
      const gChecks = group.querySelectorAll('.perm-check');
      const gSelected = group.querySelectorAll('.perm-check:checked').length;
      const badge = document.querySelector('.perm-group-count[data-group="'+gid+'"]');
      if (badge) {
        badge.textContent = gSelected + '/' + gChecks.length;
        badge.className = 'badge rounded-pill ms-2 perm-group-count ' + (gSelected > 0 ? 'text-bg-primary' : 'text-bg-secondary');
      }
      toggle.checked = gSelected === gChecks.length && gChecks.length > 0;
      toggle.indeterminate = gSelected > 0 && gSelected < gChecks.length;
      const visibleItems = group.querySelectorAll('.perm-item:not(.d-none)').length;
      if (search && search.value.trim() !== '') {
        group.classList.toggle('d-none', visibleItems === 0);
      } else {
        group.classList.remove('d-none');
      }
    });
    const anyVisible = document.querySelectorAll('.perm-item:not(.d-none)').length > 0;
    noResults.classList.toggle('d-none', anyVisible);
  }

  function filter() {
    const q = (search.value || '').toLowerCase().trim();
    items.forEach(function (el) {
      const label = el.dataset.label || '';
      const name = el.dataset.name || '';
      const match = !q || label.includes(q) || name.includes(q);
      el.classList.toggle('d-none', !match);
    });
    updateCounts();
  }

  search?.addEventListener('input', filter);

  document.querySelectorAll('.perm-group-toggle').forEach(function (toggle) {
    toggle.addEventListener('change', function () {
      const gid = toggle.dataset.group;
      const checked = toggle.checked;
      const isFiltering = search.value.trim() !== '';
      document.querySelectorAll('.perm-check[data-group="'+gid+'"]').forEach(function (cb) {
        const item = cb.closest('.perm-item');
        const visible = !item.classList.contains('d-none');
        if (!isFiltering || visible) cb.checked = checked;
      });
      updateCounts();
    });
  });

  document.querySelectorAll('.perm-check').forEach(function (cb) {
    cb.addEventListener('change', updateCounts);
  });

  selectAllBtn?.addEventListener('click', function () {
    document.querySelectorAll('.perm-item:not(.d-none) .perm-check').forEach(cb => cb.checked = true);
    updateCounts();
  });
  clearBtn?.addEventListener('click', function () {
    document.querySelectorAll('.perm-item:not(.d-none) .perm-check').forEach(cb => cb.checked = false);
    updateCounts();
  });

  updateCounts();
});
</script>
@endpush
