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
    'query' => [],
    'frameless' => false,
])

@php
    $actionUrl = $actionUrl ?: url()->current();
    $query = is_array($query) ? $query : (array) $query;
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

@if ($frameless)
    <div class="grid-embed">
        {{ $tools ?? '' }}
        @include('components.adminlte.partials.datatable-inner', get_defined_vars())
    </div>
@else
    <x-adminlte-card :icon="$icon" :title="$title" bodyClass="p-0">
        @isset($tools)
            <x-slot name="tools">{{ $tools }}</x-slot>
        @endisset
        @include('components.adminlte.partials.datatable-inner', get_defined_vars())
    </x-adminlte-card>
@endif

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
            // Stamp data-col-index on body cells by position so column toggles hide them too.
            table.querySelectorAll('tbody > tr').forEach(function(tr){
                var cells = tr.querySelectorAll(':scope > td');
                var offset = hasBulk ? 1 : 0;
                cells.forEach(function(td, i){
                    if (i < offset) return;
                    if (!td.hasAttribute('data-col-index')) {
                        td.setAttribute('data-col-index', i - offset);
                    }
                });
            });
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
