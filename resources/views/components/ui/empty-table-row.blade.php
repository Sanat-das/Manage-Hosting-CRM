@props([
    'colSpan' => 6,
    'icon' => 'bi bi-inbox',
    'title' => 'No records found',
    'message' => null,
    'actionLabel' => null,
    'actionUrl' => null,
    'size' => 'default',
])

{{--
    Table-row wrapper for empty-state.
    Usage inside <tbody>:

        @forelse ($items as $item)
            <tr>...</tr>
        @empty
            <x-ui.empty-table-row :col-span="6" title="No products found." message="Try adjusting your filters." />
        @endforelse

    Keeps <table> semantics valid (<tr><td colspan>) while reusing
    the centered empty-state visual. Also supports @empty of x-datatable.
--}}

<tr>
    <td colspan="{{ $colSpan }}" class="p-0 border-0">
        <x-adminlte.partials.empty-state
            :icon="$icon"
            :title="$title"
            :message="$message"
            :action-label="$actionLabel"
            :action-url="$actionUrl"
            :size="$size"
        />
    </td>
</tr>
