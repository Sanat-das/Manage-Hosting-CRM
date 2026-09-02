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
    <x-adminlte.partials.flash-alert />

    <x-adminlte.partials.datatable
        icon="bi bi-people"
        title="All Customers"
        :search-value="$search"
        search-placeholder="Search name, email, company..."
        :status-options="['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended']"
        :status-value="$status"
        :columns="[
            ['label' => '#', 'sort' => 'id'],
            ['label' => 'Name', 'sort' => 'name'],
            ['label' => 'Email', 'sort' => 'email'],
            ['label' => 'Company', 'sort' => 'company'],
            ['label' => 'Balance', 'sort' => 'balance', 'class' => 'text-end'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Registered', 'sort' => 'created_at'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$customers"
        :export-url="route('admin.customers.index')"
        export-label="Export CSV"
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
                        ₹{{ number_format($customer->balance, 2) }}
                    </span>
                </td>
                <td>
                    <x-adminlte.partials.status-badge :status="$customer->status" />
                </td>
                <td class="text-muted">{{ $customer->created_at?->format('M j, Y') }}</td>
                <td class="text-end">
                    <div class="table-actions">
                        @can('customers.edit')
                            <a href="{{ route('admin.customers.edit', $customer) }}"
                               class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                        @endcan
                        @can('customers.delete')
                            <button type="button" class="btn btn-sm btn-outline-danger btn-icon" title="Delete" aria-label="Delete"
                                    data-bs-toggle="modal" data-bs-target="#delete-customer-{{ $customer->id }}">
                                <i class="bi bi-trash"></i>
                            </button>
                        @endcan
                    </div>
                </td>
            </tr>
        @empty
            <x-ui.empty-table-row
                :col-span="8"
                icon="bi bi-people"
                title="No customers found"
                message="Try adjusting your search or filters, or add a new customer."
            />
        @endforelse
    </x-adminlte.partials.datatable>

    @foreach ($customers as $customer)
        @can('customers.delete')
            <x-adminlte.partials.confirm-modal
                :id="'delete-customer-' . $customer->id"
                title="Delete customer"
                :message="'Delete customer ' . $customer->display_id . '? This cannot be undone.'"
                :action="route('admin.customers.destroy', $customer)"
                confirm-label="Delete customer"
            />
        @endcan
    @endforeach
@stop
