@extends('adminlte::page')

@section('title', 'Products/Services')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Products/Services</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Products/Services</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif
    @if (session('error'))
        <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert>
    @endif

    <x-adminlte.partials.datatable
        icon="bi bi-hdd-stack"
        title="All Products/Services"
        :search-value="$search"
        search-placeholder="Search username, domain, customer, product..."
        :status-options="[
            'pending' => 'Pending',
            'active' => 'Active',
            'suspended' => 'Suspended',
            'terminated' => 'Terminated',
        ]"
        :status-value="$status"
        :columns="[
            ['label' => '#'],
            ['label' => 'Customer'],
            ['label' => 'Package'],
            ['label' => 'Domain'],
            ['label' => 'Billing Cycle'],
            ['label' => 'Recurring Amount', 'class' => 'text-end'],
            ['label' => 'Next Due Date', 'class' => 'text-end'],
            ['label' => 'Registration Date', 'class' => 'text-end'],
            ['label' => 'Status'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$accounts"
    >
        <x-slot name="tools">
            @can('hosting.manage')
                <a href="{{ route('admin.hosting.create') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Add Product/Service
                </a>
            @endcan
        </x-slot>

        @forelse ($accounts as $account)
            <tr>
                <td class="text-muted">#{{ $account->id }}</td>
                <td>
                    <a href="{{ route('admin.customers.show', $account->customer_id) }}">
                        <strong>{{ $account->customer?->full_name ?? '—' }}</strong>
                    </a>
                    @if ($account->customer?->user?->email)
                        <div class="text-muted small">{{ $account->customer->user->email }}</div>
                    @endif
                </td>
                <td>
                    {{ $account->product?->name ?? '—' }}
                    @if ($account->product)
                        <div class="text-muted small">{{ $account->product->group?->name ?? '—' }}</div>
                    @endif
                </td>
                <td>
                    {{ $account->domain ?? '—' }}
                    @if ($account->username)
                        <div class="text-muted small">{{ $account->username }}</div>
                    @endif
                </td>
                <td>
                    {{ ucfirst(str_replace('_', ' ', $account->order?->billing_cycle ?? $account->product?->billing_cycle ?? '—')) }}
                </td>
                <td class="text-end">
                    {{ number_format((float) ($amounts[$account->id] ?? 0), 2) }}
                </td>
                <td class="text-end">
                    {{ $account->next_due_date?->format('d M Y') ?? '—' }}
                </td>
                <td class="text-end">
                    {{ $account->created_at?->format('d M Y') ?? '—' }}
                </td>
                <td>
                    <x-adminlte.partials.status-badge :status="$account->status" />
                </td>
                <td class="text-end">
                    <a href="{{ route('admin.hosting.show', $account) }}"
                       class="btn btn-sm btn-outline-secondary" title="View">
                        <i class="bi bi-eye"></i>
                    </a>
                    @can('hosting.manage')
                        <a href="{{ route('admin.hosting.edit', $account) }}"
                           class="btn btn-sm btn-outline-secondary" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </a>
                    @endcan
                    @can('hosting.manage')
                        <form method="POST" action="{{ route('admin.hosting.destroy', $account) }}"
                              onsubmit="return confirm('Terminate product/service #{{ $account->id }} ({{ $account->username }})? This sets the status to terminated and cannot be undone.');"
                              class="d-inline">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger" title="Terminate">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    @endcan
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="10" class="text-center text-muted py-4">
                    No products/services found.
                </td>
            </tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
