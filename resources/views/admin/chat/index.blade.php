@extends('adminlte::page')

@section('title', 'Live Chat')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Live Chat</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active">Live Chat</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <div class="row mb-3">
        <div class="col-lg-3 col-6">
            <x-adminlte-small-box :title="$stats->get('waiting', 0)" text="Waiting" icon="bi bi-hourglass-split" theme="warning" />
        </div>
        <div class="col-lg-3 col-6">
            <x-adminlte-small-box :title="$stats->get('active', 0)" text="Active" icon="bi bi-chat-dots" theme="success" />
        </div>
        <div class="col-lg-3 col-6">
            <x-adminlte-small-box :title="$stats->get('closed', 0)" text="Closed" icon="bi bi-check-circle" theme="secondary" />
        </div>
    </div>

    <x-adminlte.partials.datatable
        icon="bi bi-chat-dots"
        title="Chat Sessions"
        :search-value="$search"
        search-placeholder="Search name, email, department..."
        :status-options="collect($statuses)->mapWithKeys(fn ($s) => [$s => ucfirst($s)])->all()"
        :status-value="$status ?? ''"
        :columns="[
            ['label' => 'Session', 'sort' => 'id'],
            ['label' => 'Customer', 'sort' => 'name'],
            ['label' => 'Department', 'sort' => 'department'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Started', 'sort' => 'started_at'],
        ]"
        :pagination="$sessions"
    >
        @forelse ($sessions as $session)
            <tr>
                <td><a href="{{ route('admin.chat.show', $session) }}"><strong>#{{ $session->id }}</strong></a></td>
                <td><a href="{{ route('admin.chat.show', $session) }}" class="table-link">{{ $session->name ?? $session->customer_id ?? '—' }}</a></td>
                <td class="text-muted">{{ $session->department ?? '—' }}</td>
                <td><x-adminlte.partials.status-badge :status="$session->status" /></td>
                <td class="text-muted small">{{ $session->started_at?->format('M j, H:i') ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-center text-muted py-4">No chat sessions.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
