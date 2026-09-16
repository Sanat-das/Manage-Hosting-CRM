@extends('adminlte::page')

@section('title', 'Saved Replies')

@section('content_header')
    <x-ui.page-header title="Saved Replies" subtitle="Snippets operators can drop into a chat" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Live Chat', 'url' => route('admin.chat.index')],
        ['label' => 'Saved Replies', 'active' => true],
    ]" />
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    <x-adminlte.partials.datatable icon="bi bi-lightning" title="Saved Replies"
        search-placeholder="Search title, shortcut or text"
        :search-value="$search"
        status-field="scope"
        status-placeholder="Shared and mine"
        :status-options="['shared' => 'Shared library', 'personal' => 'My replies']"
        :status-value="$scope"
        :columns="[
            ['label' => 'Title', 'sort' => 'title'],
            ['label' => 'Shortcut', 'sort' => 'shortcut'],
            ['label' => 'Department', 'sort' => 'department'],
            ['label' => 'Scope'],
            ['label' => 'Used', 'sort' => 'uses', 'class' => 'text-end'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$replies">
        <x-slot name="tools">
            <a href="{{ route('admin.chat.canned-replies.create') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i> New Reply
            </a>
        </x-slot>

        @forelse ($replies as $reply)
            <tr>
                <td>
                    <strong>{{ $reply->title }}</strong>
                    <div class="text-muted small">{{ Str::limit($reply->body, 90) }}</div>
                </td>
                <td>
                    @if ($reply->shortcut)
                        <code>/{{ $reply->shortcut }}</code>
                    @else
                        <span class="text-muted">&mdash;</span>
                    @endif
                </td>
                <td>{{ $reply->department ?? '—' }}</td>
                <td>
                    @if ($reply->isShared())
                        <span class="badge text-bg-primary">Shared</span>
                    @else
                        <span class="badge text-bg-secondary">Mine</span>
                    @endif
                </td>
                <td class="text-end">{{ $reply->uses }}</td>
                <td class="text-end">
                    <div class="table-actions">
                        {{-- Shown only when this user may actually write it: a
                             shared reply takes chat.manage, a personal one takes
                             being its owner. The controller refuses either way;
                             this is the affordance that says so first. --}}
                        @if ($reply->isShared() ? $canManage : true)
                            <a href="{{ route('admin.chat.canned-replies.edit', $reply) }}"
                               class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="POST" action="{{ route('admin.chat.canned-replies.destroy', $reply) }}"
                                  class="d-inline" onsubmit="return confirm('Delete this saved reply?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger btn-icon"
                                        title="Delete" aria-label="Delete"><i class="bi bi-trash"></i></button>
                            </form>
                        @else
                            <span class="text-muted small">Shared</span>
                        @endif
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="6" class="text-center text-muted py-4">
                    No saved replies yet. Create one and operators can insert it with <code>/shortcut</code>.
                </td>
            </tr>
        @endforelse

        <x-slot name="pagination">{{ $replies->links() }}</x-slot>
    </x-adminlte.partials.datatable>
@stop
