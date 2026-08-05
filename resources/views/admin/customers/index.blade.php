@extends('adminlte::page')

@section('title', 'Customers')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Customers</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Customers</li>
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
        icon="bi bi-people"
        title="All Customers"
        :search-value="$search"
        search-placeholder="Search name, email, company..."
        :status-options="['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended']"
        :status-value="$status"
        :columns="[
            ['label' => '#'],
            ['label' => 'Name'],
            ['label' => 'Email'],
            ['label' => 'Company'],
            ['label' => 'Balance', 'class' => 'text-end'],
            ['label' => 'Status'],
            ['label' => 'Registered'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$customers"
    >
        <x-slot name="tools">
            @can('customers.create')
                <a href="{{ route('admin.customers.create') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Add Customer
                </a>
            @endcan
        </x-slot>

        @forelse ($customers as $customer)
            <tr>
                <td class="text-muted">{{ $customer->display_id }}</td>
                <td>
                    <a href="{{ route('admin.customers.show', $customer) }}"><strong>{{ $customer->full_name }}</strong></a>
                </td>
                <td class="text-muted">{{ $customer->user?->email }}</td>
                <td>{{ $customer->company ?? '—' }}</td>
                <td class="text-end">
                    <span class="{{ $customer->balance < 0 ? 'text-danger fw-bold' : '' }}">
                        {{ number_format($customer->balance, 2) }}
                    </span>
                </td>
                <td>
                    <x-adminlte.partials.status-badge :status="$customer->status" />
                </td>
                <td class="text-muted">{{ $customer->created_at?->format('M j, Y') }}</td>
                <td class="text-end">
                    <a href="{{ route('admin.customers.show', $customer) }}"
                       class="btn btn-sm btn-outline-secondary" title="View">
                        <i class="bi bi-eye"></i>
                    </a>
                    @can('customers.edit')
                        <a href="{{ route('admin.customers.edit', $customer) }}"
                           class="btn btn-sm btn-outline-secondary" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </a>
                    @endcan
                    @can('customers.delete')
                        <form method="POST" action="{{ route('admin.customers.destroy', $customer) }}"
                              onsubmit="return confirm('Delete customer {{ $customer->display_id }}? This cannot be undone.');"
                              class="d-inline">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    @endcan
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="8" class="text-center text-muted py-4">
                    No customers found.
                </td>
            </tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
