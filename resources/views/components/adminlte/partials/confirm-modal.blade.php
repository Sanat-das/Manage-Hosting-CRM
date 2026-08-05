@props([
    'id' => 'confirm-modal',
    'title' => 'Confirm action',
    'message' => 'Are you sure? This cannot be undone.',
    'action' => '#',
    'method' => 'DELETE',
    'confirmLabel' => 'Delete',
    'confirmTheme' => 'danger',
    'cancelLabel' => 'Cancel',
])

<x-adminlte-modal :id="$id" :title="$title" size="sm">
    <p class="mb-0">{{ $message }}</p>

    <form method="POST" action="{{ $action }}" id="{{ $id }}-form">
        @csrf
        @unless (in_array($method, ['GET', 'POST'], true))
            @method($method)
        @endunless
    </form>

    <x-slot name="footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ $cancelLabel }}</button>
        <button type="submit" form="{{ $id }}-form" class="btn btn-{{ $confirmTheme }}">{{ $confirmLabel }}</button>
    </x-slot>
</x-adminlte-modal>
