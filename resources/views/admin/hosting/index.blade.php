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
        search-placeholder="Search host name, domain, customer, product..."
        :status-options="[
            'pending' => 'Pending',
            'active' => 'Active',
            'suspended' => 'Suspended',
            'terminated' => 'Terminated',
        ]"
        :status-value="$status"
        :columns="[
            ['label' => '#', 'sort' => 'id'],
            ['label' => 'Host Name', 'sort' => 'host_name'],
            ['label' => 'Customer', 'sort' => 'customer'],
            ['label' => 'Package', 'sort' => 'package'],
            ['label' => 'Domain', 'sort' => 'domain'],
            ['label' => 'Billing Cycle', 'sort' => 'billing_cycle'],
            ['label' => 'Recurring Amount', 'sort' => 'amount', 'class' => 'text-end'],
            ['label' => 'Next Due Date', 'sort' => 'due_date', 'class' => 'text-end'],
            ['label' => 'Status', 'sort' => 'status'],
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
                    <a href="{{ route('admin.hosting.show', $account) }}"><strong>{{ $account->host_name }}</strong></a>
                </td>
                <td>
                    <a href="{{ route('admin.customers.show', $account->customer_id) }}">
                        <strong>{{ $account->customer?->full_name ?? '—' }}</strong>
                    </a>
                    @if ($account->customer?->user?->email)
                        <div class="text-muted small">{{ $account->customer->user->email }}</div>
                    @endif
                </td>
                <td>
                    <a href="{{ route('admin.hosting.show', $account) }}">{{ $account->product?->name ?? '—' }}</a>
                    @if ($account->product?->group?->name)
                        <div class="text-muted small">{{ $account->product->group->name }}</div>
                    @endif
                </td>
                <td>
                    @if($account->domain)
                        {{ $account->domain }}
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
                <td>
                    {{ ucfirst(str_replace('_', ' ', $account->order?->billing_cycle ?? $account->product?->billing_cycle ?? '—')) }}
                </td>
                <td class="text-end">
                    ₹{{ number_format((float) ($amounts[$account->id] ?? 0), 2) }}
                </td>
                <td class="text-end">
                    {{ ($account->next_due_date ?? $account->order?->next_billing_date)?->format('d M Y') ?? '—' }}
                </td>
                <td>
                    <x-adminlte.partials.status-badge :status="$account->status" />
                </td>
                <td class="text-end">
                    <div class="table-actions">
                        @can('hosting.manage')
                            <a href="{{ route('admin.hosting.edit', $account) }}"
                               class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                        @endcan
                        @can('hosting.manage')
                            <button type="button" class="btn btn-sm btn-outline-danger btn-icon" title="Terminate" aria-label="Terminate"
                                    data-bs-toggle="modal" data-bs-target="#terminate-hosting-{{ $account->id }}">
                                <i class="bi bi-trash"></i>
                            </button>
                        @endcan
                    </div>
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

    @foreach ($accounts as $account)
        @can('hosting.manage')
            <x-adminlte.partials.confirm-modal
                :id="'terminate-hosting-' . $account->id"
                title="Terminate product/service"
                :message="'Terminate product/service #' . $account->id . '? This sets the status to terminated and cannot be undone.'"
                :action="route('admin.hosting.destroy', $account)"
                confirm-label="Terminate account"
            />
        @endcan
    @endforeach
@stop
