{{--
    The create/edit fields, shared so the two screens cannot drift.

    `$reply` is null on create. `$canManage` decides whether the Shared option is
    offered at all: the controller refuses a shared write without chat.manage, and
    a select that offers an option the server will reject is a worse form than one
    that does not.
--}}
@php
    $currentScope = old('scope', $reply === null
        ? ($canManage ? 'shared' : 'personal')
        : ($reply->isShared() ? 'shared' : 'personal'));
@endphp

<div class="row">
    <div class="col-md-6">
        <x-adminlte-input name="title" label="Title" value="{{ old('title', $reply->title ?? '') }}"
            maxlength="120" required />
    </div>
    <div class="col-md-3">
        <x-adminlte-input name="shortcut" label="Shortcut" value="{{ old('shortcut', $reply->shortcut ?? '') }}"
            maxlength="64" placeholder="refund" />
        <div class="form-text mt-n2 mb-3">
            Typed as <code>/refund</code> in the composer. Letters, numbers, dashes and underscores.
        </div>
    </div>
    <div class="col-md-3">
        <x-adminlte-select name="department" label="Department">
            <option value="">Any department</option>
            @foreach ($departments as $slug => $name)
                <option value="{{ $slug }}" @selected(old('department', $reply->department ?? '') === $slug)>{{ $name }}</option>
            @endforeach
        </x-adminlte-select>
    </div>
</div>

<x-adminlte-select name="scope" label="Available to">
    @if ($canManage)
        <option value="shared" @selected($currentScope === 'shared')>Everyone (shared library)</option>
    @endif
    <option value="personal" @selected($currentScope === 'personal')>Only me</option>
</x-adminlte-select>
@unless ($canManage)
    <div class="form-text mt-n2 mb-3">
        Adding to the shared library needs the Manage Live Chat permission.
    </div>
@endunless

<x-adminlte-textarea name="body" label="Reply text" rows="6" required
    maxlength="{{ \App\Models\ChatCannedReply::MAX_BODY_LENGTH }}">{{ old('body', $reply->body ?? '') }}</x-adminlte-textarea>
<div class="form-text mt-n2">
    Inserted into the composer as plain text, so the operator can edit it before sending.
    There are no placeholders — a half-substituted variable is worse than a blank an operator has to fill in.
</div>
