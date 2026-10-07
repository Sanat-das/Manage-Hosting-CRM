@extends('adminlte::page')

@section('title', 'Inventory Dependency Tree')

@section('content_header')
    <x-ui.page-header title="Inventory Dependency Tree" subtitle="How inventory assets host and manage one another" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Inventory Assets', 'url' => route('admin.inventory-assets.index')],
        ['label' => 'Dependency Tree', 'active' => true],
    ]" />
@stop

@section('content')
    {{-- Client-side row filter (progressive enhancement — the page is fully readable without JS). --}}
    <x-adminlte-card class="mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-6">
                <label for="inventory-tree-search" class="form-label small fw-medium mb-1">Search</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                    <input type="search" id="inventory-tree-search" class="form-control form-control-sm"
                           placeholder="Filter by asset tag, model, name or relationship type…"
                           autocomplete="off" aria-label="Filter inventory dependency rows"
                           data-inventory-tree-search>
                </div>
            </div>
            <div class="col-md-6 text-md-end">
                <span class="text-muted small" data-inventory-tree-count aria-live="polite"></span>
            </div>
        </div>
    </x-adminlte-card>

    <x-adminlte-card icon="bi bi-diagram-3" title="Inventory Asset Dependencies" bodyClass="p-0">
        <div class="p-3 border-bottom text-muted small">
            Relationships between inventory assets, nested under the asset they depend on.
        </div>
        <div class="table-responsive">
            <table class="table table-grid table-striped align-middle m-0"
                   data-grid-resizable
                   data-inventory-tree-table
                   data-grid-key="admin.inventory-tree.index">
                <thead>
                    <tr>
                        <th>Asset</th>
                        <th>Relationship Type</th>
                        <th>Label</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr data-depth="{{ $row['depth'] }}">
                            <td style="padding-left: {{ 0.75 + $row['depth'] * 1.5 }}rem;">
                                @if ($row['asset_tag'] !== null)
                                    <a href="{{ route('admin.inventory-assets.show', $row['asset_id']) }}"><strong>{{ $row['asset_tag'] }}</strong></a>
                                @else
                                    <span class="text-muted fst-italic">Asset #{{ $row['asset_id'] }} (missing)</span>
                                @endif
                                @if ($row['repeated'])
                                    <span class="badge text-bg-warning ms-1">already shown</span>
                                @endif
                            </td>
                            <td>
                                @if ($row['relationship_type'] !== null)
                                    <code>{{ $row['relationship_type'] }}</code>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-muted">{{ $row['label'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <x-ui.empty-table-row colSpan="3" title="No inventory asset dependencies found." />
                    @endforelse
                    <tr class="d-none" data-inventory-tree-no-results>
                        <td colspan="3" class="text-center text-muted py-4">No matching rows.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </x-adminlte-card>
@stop

@push('js')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var input = document.querySelector('[data-inventory-tree-search]');
            var table = document.querySelector('[data-inventory-tree-table]');
            if (!input || !table) return;

            var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-depth]'));
            if (!rows.length) return;

            var countEl = document.querySelector('[data-inventory-tree-count]');
            var noResultsRow = table.querySelector('[data-inventory-tree-no-results]');

            function depthOf(row) {
                var depth = parseInt(row.getAttribute('data-depth'), 10);
                return isNaN(depth) ? 0 : depth;
            }

            function apply() {
                var query = input.value.trim().toLowerCase();
                var matches = 0;

                if (query === '') {
                    rows.forEach(function (row) { row.classList.remove('d-none'); });
                    if (countEl) countEl.textContent = '';
                    if (noResultsRow) noResultsRow.classList.add('d-none');
                    return;
                }

                // Rows are depth-first; a matching row keeps its descendants (deeper rows) visible.
                var matchDepth = null;
                rows.forEach(function (row) {
                    var depth = depthOf(row);
                    var isMatch = row.textContent.toLowerCase().indexOf(query) !== -1;
                    var isDescendant = matchDepth !== null && depth > matchDepth;

                    row.classList.toggle('d-none', !isMatch && !isDescendant);

                    if (isMatch) {
                        matchDepth = depth;
                        matches++;
                    } else if (!isDescendant) {
                        matchDepth = null;
                    }
                });

                if (countEl) countEl.textContent = matches + (matches === 1 ? ' match' : ' matches');
                if (noResultsRow) noResultsRow.classList.toggle('d-none', matches !== 0);
            }

            input.addEventListener('input', apply);
            apply();
        });
    </script>
@endpush
