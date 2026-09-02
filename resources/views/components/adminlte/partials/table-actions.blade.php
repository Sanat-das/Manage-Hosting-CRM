@props([
    'editUrl' => null,
    'editCan' => true,
    'deleteCan' => true,
    'editTitle' => 'Edit',
    'deleteTitle' => 'Delete',
    'modalTarget' => null,
])

{{--
    Row actions for a datatable.

    Destructive actions are confirmed with <x-adminlte.partials.confirm-modal>,
    never with the browser's native confirm() — pass `modalTarget` and render
    the matching modal outside the table (see admin/products/index for the
    reference pattern). The native-confirm fallback was removed so the whole
    app has one confirmation UI.
--}}
<div class="table-actions">
    @if($editUrl && $editCan)
        <a href="{{ $editUrl }}" class="btn btn-sm btn-outline-secondary btn-icon" title="{{ $editTitle }}" aria-label="{{ $editTitle }}">
            <i class="bi bi-pencil"></i>
        </a>
    @endif
    @if($modalTarget && $deleteCan)
        <button type="button" class="btn btn-sm btn-outline-danger btn-icon" title="{{ $deleteTitle }}" aria-label="{{ $deleteTitle }}" data-bs-toggle="modal" data-bs-target="{{ $modalTarget }}">
            <i class="bi bi-trash"></i>
        </button>
    @endif
</div>
