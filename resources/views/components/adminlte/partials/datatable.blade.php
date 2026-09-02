@props([
    'title' => 'Records',
    'icon' => null,
    'searchPlaceholder' => 'Search...',
    'searchValue' => '',
    'statusField' => 'status',
    'statusOptions' => [],
    'statusValue' => '',
    'statusPlaceholder' => 'All statuses',
    'statusMultiple' => false,
    'columns' => [],
    'pagination' => null,
    'actionUrl' => null,
    'gridKey' => null,
    'stickyHeader' => false,
    'showCheckboxes' => false,
    'columnToggle' => false,
    'exportUrl' => null,
    'exportLabel' => 'Export CSV',
    'loading' => false,
    'loadingRows' => 5,
])

@php
    $actionUrl = $actionUrl ?: url()->current();
    $gridKey = $gridKey ?: (request()->route()?->getName() ?: request()->path());
    $formId = 'grid-form-'. \Illuminate\Support\Str::slug($gridKey);
    $currentSort = request()->query('sort');
    $currentDirection = strtolower((string) request()->query('direction', 'asc')) === 'desc' ? 'desc' : 'asc';
    $exportHref = null;
    if ($exportUrl) {
        // Idempotent export href: merge current request query + any query already in exportUrl,
        // deduplicate keys and always force export=csv. Works whether exportUrl is a bare
        // route (route('admin.invoices.index')) or a fullUrlWithQuery(['export'=>'csv']).
        $base = strtok($exportUrl, '?');
        if ($base === false || $base === '') {
            $base = $exportUrl;
        }
        $parsed = parse_url($exportUrl);
        parse_str($parsed['query'] ?? '', $urlQuery);
        $merged = array_merge(request()->query(), $urlQuery, ['export' => 'csv']);
        $exportHref = $base . '?' . http_build_query($merged);
    }
    // Column toggle: normalize columns for index mapping; hidden state handled via JS localStorage
    $hasColumnToggle = (bool) $columnToggle && count($columns) > 0;
    $hasExport = (bool) $exportUrl;
    $bulkBarId = 'grid-bulk-bar-' . \Illuminate\Support\Str::slug($gridKey);
@endphp

<x-adminlte-card :icon="$icon" :title="$title" bodyClass="p-0">
    @isset($tools)
        <x-slot name="tools">{{ $tools }}</x-slot>
    @endisset

    <form method="GET" action="{{ $actionUrl }}" id="{{ $formId }}" class="grid-toolbar p-3 border-bottom">
        <div class="row g-2 align-items-center">
            <div class="col-md-{{ $statusOptions ? 5 : 8 }}">
                <div class="input-group grid-toolbar-search">
                    <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                    <input type="text" name="search" value="{{ $searchValue }}"
                           class="form-control" placeholder="{{ $searchPlaceholder }}" aria-label="{{ $searchPlaceholder }}">
                    @if ($currentSort)
                        <input type="hidden" name="sort" value="{{ $currentSort }}">
                        <input type="hidden" name="direction" value="{{ $currentDirection }}">
                    @endif
                </div>
            </div>
            @if ($statusOptions)
                <div class="col-md-3">
                    @if ($statusMultiple)
                        @php $selectedStatuses = (array) $statusValue; @endphp
                        <div class="dropdown">
                            <button type="button" class="form-select text-start grid-toolbar-status" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                @if ($selectedStatuses === [])
                                    {{ $statusPlaceholder }}
                                @elseif (count($selectedStatuses) === 1)
                                    {{ $statusOptions[$selectedStatuses[0]] ?? $selectedStatuses[0] }}
                                @else
                                    {{ count($selectedStatuses) }} statuses selected
                                @endif
                            </button>
                            <ul class="dropdown-menu p-2 w-100">
                                @foreach ($statusOptions as $value => $label)
                                    <li>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox"
                                                   name="{{ $statusField }}[]" value="{{ $value }}"
                                                   id="{{ $formId }}-status-{{ \Illuminate\Support\Str::slug($value) }}"
                                                   @checked(in_array($value, $selectedStatuses, true))>
                                            <label class="form-check-label" for="{{ $formId }}-status-{{ \Illuminate\Support\Str::slug($value) }}">{{ $label }}</label>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @else
                        <select name="{{ $statusField }}" class="form-select grid-toolbar-status">
                            <option value="">{{ $statusPlaceholder }}</option>
                            @foreach ($statusOptions as $value => $label)
                                <option value="{{ $value }}" @selected($statusValue === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>
            @endif
            <div class="col-md-4 d-flex gap-2 align-items-center grid-toolbar-actions">
                <button type="submit" class="btn btn-outline-secondary btn-sm">Filter</button>
                <a href="{{ $actionUrl }}?reset=1" class="btn btn-outline-secondary btn-sm">Reset</a>
                <div class="ms-auto d-inline-flex align-items-center gap-1">
                    @if ($hasExport)
                        <a href="{{ $exportHref }}" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1" title="{{ $exportLabel }}" aria-label="{{ $exportLabel }}">
                            <i class="bi bi-download" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">{{ $exportLabel }}</span>
                        </a>
                    @endif
                    @if ($hasColumnToggle)
                        <div class="dropdown grid-column-toggle" data-grid-column-toggle-wrap="{{ $gridKey }}">
                            <button type="button" class="btn btn-outline-secondary btn-sm btn-icon" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="Columns" aria-label="Toggle columns">
                                <i class="bi bi-layout-three-columns" aria-hidden="true"></i>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end p-2 grid-column-toggle-menu" style="min-width: 200px; border-radius: var(--radius-lg); box-shadow: var(--shadow-md);">
                                <div class="small fw-semibold text-muted mb-2 px-1" style="letter-spacing: 0.02em; font-size: var(--text-xs); text-transform: uppercase;">Columns</div>
                                @foreach ($columns as $idx => $col)
                                    <label class="dropdown-item d-flex align-items-center gap-2 mb-1 rounded-2" style="cursor: pointer; font-size: var(--text-sm);">
                                        <input type="checkbox" class="form-check-input m-0" data-grid-col-toggle="{{ $idx }}" checked>
                                        <span class="flex-fill">{{ $col['label'] }}</span>
                                    </label>
                                @endforeach
                                <div class="border-top mt-2 pt-2 d-flex justify-content-end">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-grid-columns-reset="{{ $gridKey }}">Reset</button>
                                </div>
                            </div>
                        </div>
                    @endif
                    <button type="button" class="btn btn-outline-secondary btn-sm btn-icon"
                            data-grid-reset="{{ $gridKey }}"
                            title="Reset column widths" aria-label="Reset column widths">
                        <i class="bi bi-arrows-angle-contract" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </div>
    </form>

    @if ($showCheckboxes && isset($bulkActions))
        <div id="{{ $bulkBarId }}" class="grid-bulk-bar d-none align-items-center gap-2 px-3 py-2 border-bottom" data-grid-bulk-bar="{{ $gridKey }}" style="background: color-mix(in srgb, var(--color-primary) 6%, var(--bs-body-bg));">
            <span class="small fw-medium"><span data-grid-selected-count>0</span> selected</span>
            <div class="d-inline-flex align-items-center gap-2 ms-2 flex-wrap">
                {{ $bulkActions }}
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" data-grid-bulk-clear>Clear</button>
        </div>
    @elseif ($showCheckboxes)
        <div id="{{ $bulkBarId }}" class="grid-bulk-bar d-none align-items-center gap-2 px-3 py-2 border-bottom" data-grid-bulk-bar="{{ $gridKey }}" style="background: color-mix(in srgb, var(--color-primary) 6%, var(--bs-body-bg));">
            <span class="small fw-medium"><span data-grid-selected-count>0</span> selected</span>
            <span class="small text-muted ms-1">— add bulk actions via &lt;x-slot name=&quot;bulkActions&quot;&gt;</span>
            <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" data-grid-bulk-clear>Clear</button>
        </div>
    @endif

    <div class="table-responsive {{ $stickyHeader ? 'has-sticky' : '' }}">
        <table class="table table-grid align-middle m-0"
               data-grid-resizable
               data-grid-key="{{ $gridKey }}"
               @if($stickyHeader) data-sticky-header="true" @endif
               @if($hasColumnToggle) data-grid-column-toggle="true" @endif
               @if($showCheckboxes) data-grid-bulk="true" @endif>
            <thead>
                <tr>
                    @if ($showCheckboxes)
                        <th style="width: 40px; min-width: 40px; max-width: 40px;" data-col-index="__check" class="text-center">
                            <input type="checkbox" class="form-check-input" data-grid-select-all aria-label="Select all">
                        </th>
                    @endif
                    @foreach ($columns as $idx => $column)
                        @php
                            $sortKey = $column['sort'] ?? null;
                            $isSorted = $sortKey && $currentSort === $sortKey;
                            $nextDirection = $isSorted && $currentDirection === 'asc' ? 'desc' : 'asc';
                            $sortUrl = null;
                            if ($sortKey) {
                                $query = array_merge(request()->except(['page', 'sort', 'direction']), ['sort' => $sortKey, 'direction' => $nextDirection]);
                                $sortUrl = $actionUrl . '?' . http_build_query($query);
                                if (empty($query)) {
                                    $sortUrl = $actionUrl;
                                }
                            }
                            $colClass = $column['class'] ?? '';
                        @endphp
                        <th @if($colClass !== '') class="{{ $colClass }}" @endif data-col-index="{{ $idx }}" @if($isSorted) aria-sort="{{ $currentDirection === 'asc' ? 'ascending' : 'descending' }}" @endif>
                            @if ($sortUrl)
                                <a href="{{ $sortUrl }}" class="grid-sort @if($isSorted) is-active @endif">
                                    <span>{{ $column['label'] }}</span>
                                    <span class="grid-sort-icon" aria-hidden="true">
                                        @if ($isSorted)
                                            <i class="bi {{ $currentDirection === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down' }}"></i>
                                        @else
                                            <i class="bi bi-arrow-down-up"></i>
                                        @endif
                                    </span>
                                </a>
                            @else
                                {{ $column['label'] }}
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @if ($loading)
                    @for ($r = 0; $r < max(1, min(12, (int) $loadingRows)); $r++)
                        <tr>
                            @if ($showCheckboxes)
                                <td class="text-center"><span class="mh-skeleton__line d-inline-block" style="width: 16px; height: 16px; border-radius: var(--radius-sm); background: var(--bs-border-color);"></span></td>
                            @endif
                            @foreach ($columns as $col)
                                <td><span class="mh-skeleton__line d-block" style="height: 0.8rem; width: {{ $loop->last ? '4rem' : '85%' }}; border-radius: var(--radius-sm); background: var(--bs-border-color);"></span></td>
                            @endforeach
                        </tr>
                    @endfor
                @else
                    {{ $slot }}
                @endif
            </tbody>
        </table>
    </div>

    @isset($pagination)
        <x-slot name="footer">
            <div class="grid-pagination d-flex align-items-center justify-content-between flex-wrap gap-2">
                @if ($pagination instanceof \Illuminate\Contracts\Pagination\Paginator)
                    {{ $pagination->links() }}
                @else
                    {!! $pagination !!}
                @endif
            </div>
        </x-slot>
    @endisset
</x-adminlte-card>

@push('js')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var gridKey = @json($gridKey);
    var hasToggle = @json($hasColumnToggle);
    var hasBulk = @json((bool) $showCheckboxes);
    if (hasToggle) {
        (function () {
            var STORE_KEY = 'adminlte.gridHiddenCols.v1';
            function readHidden() {
                try { return JSON.parse(localStorage.getItem(STORE_KEY) || '{}'); } catch(e){ return {}; }
            }
            function writeHidden(map){ try{ localStorage.setItem(STORE_KEY, JSON.stringify(map)); }catch(e){} }
            var map = readHidden();
            var hidden = Array.isArray(map[gridKey]) ? map[gridKey] : [];
            var table = document.querySelector('table[data-grid-key="' + CSS.escape(gridKey) + '"]');
            if (!table) return;
            var wrap = document.querySelector('[data-grid-column-toggle-wrap="' + CSS.escape(gridKey) + '"]');
            if (!wrap) return;
            var checkboxes = wrap.querySelectorAll('[data-grid-col-toggle]');
            function applyHidden(){
                hidden.forEach(function(idx){
                    table.querySelectorAll('[data-col-index="' + idx + '"]').forEach(function(el){ el.classList.add('d-none'); });
                    var cb = wrap.querySelector('[data-grid-col-toggle="' + idx + '"]');
                    if (cb) cb.checked = false;
                });
                // also need to hide corresponding <col> if present
                var cols = table.querySelectorAll(':scope > colgroup > col');
                hidden.forEach(function(idx){
                    // showCheckboxes shifts col index by 1
                    var colIdx = hasBulk ? parseInt(idx,10)+1 : parseInt(idx,10);
                    if (cols[colIdx]) cols[colIdx].style.display = 'none';
                });
            }
            function setColVisible(idx, visible){
                var colIdx = hasBulk ? parseInt(idx,10)+1 : parseInt(idx,10);
                var cols = table.querySelectorAll(':scope > colgroup > col');
                table.querySelectorAll('[data-col-index="' + idx + '"]').forEach(function(el){
                    el.classList.toggle('d-none', !visible);
                });
                if (cols[colIdx]) cols[colIdx].style.display = visible ? '' : 'none';
                if (visible) { hidden = hidden.filter(function(v){ return String(v)!==String(idx); }); }
                else if (!hidden.includes(String(idx)) && !hidden.includes(parseInt(idx,10))) { hidden.push(parseInt(idx,10)); }
                // normalize to numbers
                hidden = hidden.map(function(v){ return parseInt(v,10); }).filter(function(v){ return !isNaN(v); });
                var all = readHidden(); all[gridKey]=hidden; writeHidden(all);
            }
            applyHidden();
            checkboxes.forEach(function(cb){
                cb.addEventListener('change', function(){ setColVisible(cb.getAttribute('data-grid-col-toggle'), cb.checked); });
            });
            var resetBtn = wrap.querySelector('[data-grid-columns-reset]');
            if (resetBtn) resetBtn.addEventListener('click', function(){
                hidden = []; var all = readHidden(); delete all[gridKey]; writeHidden(all);
                checkboxes.forEach(function(cb){ cb.checked = true; });
                table.querySelectorAll('[data-col-index]').forEach(function(el){ el.classList.remove('d-none'); });
                table.querySelectorAll(':scope > colgroup > col').forEach(function(c){ c.style.display=''; });
            });
        })();
    }
    if (hasBulk) {
        (function(){
            var table = document.querySelector('table[data-grid-key="' + CSS.escape(gridKey) + '"]');
            if (!table) return;
            var bar = document.querySelector('[data-grid-bulk-bar="' + CSS.escape(gridKey) + '"]');
            var selectAll = table.querySelector('[data-grid-select-all]');
            function boxes(){ return table.querySelectorAll('tbody input[type="checkbox"][data-grid-bulk], tbody input[type="checkbox"].grid-bulk-checkbox, tbody input[name="bulk_ids[]"]'); }
            function updateBar(){
                var all = boxes();
                var checked = Array.from(all).filter(function(b){ return b.checked; });
                var countEl = bar ? bar.querySelector('[data-grid-selected-count]') : null;
                if (countEl) countEl.textContent = checked.length;
                if (bar) { bar.classList.toggle('d-none', checked.length===0); bar.classList.toggle('d-flex', checked.length>0); }
                if (selectAll) {
                    selectAll.checked = all.length>0 && checked.length===all.length;
                    selectAll.indeterminate = checked.length>0 && checked.length<all.length;
                }
            }
            if (selectAll) {
                selectAll.addEventListener('change', function(){
                    boxes().forEach(function(b){ b.checked = selectAll.checked; });
                    updateBar();
                });
            }
            table.addEventListener('change', function(e){
                if (e.target.matches('tbody input[type="checkbox"]')) updateBar();
            });
            var clearBtn = bar ? bar.querySelector('[data-grid-bulk-clear]') : null;
            if (clearBtn) clearBtn.addEventListener('click', function(){
                boxes().forEach(function(b){ b.checked=false; });
                if (selectAll) { selectAll.checked=false; selectAll.indeterminate=false; }
                updateBar();
            });
            // initial
            updateBar();
        })();
    }
});
</script>
@endpush
