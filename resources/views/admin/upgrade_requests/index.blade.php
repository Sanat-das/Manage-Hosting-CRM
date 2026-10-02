@extends('adminlte::page')

@section('title', 'Upgrade Requests')

@section('content_header')
    <x-ui.page-header title="Upgrade Requests" subtitle="Browse, approve and cancel product upgrade requests" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Upgrade Requests', 'active' => true],
    ]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    <x-adminlte.partials.datatable
        icon="bi bi-arrow-up-right-circle"
        title="All Upgrade Requests"
        :search-value="$search"
        search-placeholder="Search upgrade #, order # or customer..."
        status-placeholder="All statuses"
        :status-options="['pending' => 'Pending', 'applied' => 'Applied', 'cancelled' => 'Cancelled']"
        :status-value="$status"
        :columns="[
            ['label' => 'Upgrade', 'sort' => 'upgrade_no'],
            ['label' => 'Customer', 'sort' => 'customer'],
            ['label' => 'Order', 'sort' => 'order'],
            ['label' => 'Change'],
            ['label' => 'Type'],
            ['label' => 'Amount'],
            ['label' => 'Status', 'sort' => 'status'],
            ['label' => 'Created', 'sort' => 'created_at'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$requests"
    >
        @forelse ($requests as $upgrade)
            <tr>
                <td><a href="{{ route('admin.upgrade-requests.show', $upgrade) }}"><strong>{{ $upgrade->upgrade_no }}</strong></a></td>
                <td>
                    @if ($upgrade->customer)
                        <a href="{{ route('admin.customers.show', $upgrade->customer) }}">{{ $upgrade->customer->full_name }}</a>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
                <td>
                    @if ($upgrade->order)
                        <a href="{{ route('admin.orders.show', $upgrade->order) }}">{{ $upgrade->order->order_no }}</a>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
                <td>
                    <span class="text-muted">{{ $upgrade->fromProduct?->name ?? '—' }}</span>
                    <i class="bi bi-arrow-right text-muted mx-1"></i>
                    <strong>{{ $upgrade->toProduct?->name ?? '—' }}</strong>
                </td>
                <td>{!! $upgrade->changeTypeBadge() !!}</td>
                <td>
                    @if ((float) $upgrade->payable > 0)
                        <x-adminlte.partials.currency :value="$upgrade->payable" />
                    @elseif ((float) $upgrade->credit_amount > 0)
                        <span class="text-success">Credit <x-adminlte.partials.currency :value="$upgrade->credit_amount" /></span>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
                <td>
                    <x-adminlte.partials.status-badge :status="$upgrade->status"
                        :map="['pending' => 'warning', 'applied' => 'success', 'cancelled' => 'secondary']" />
                </td>
                <td class="text-muted">{{ $upgrade->created_at?->format('M j, Y H:i') }}</td>
                <td class="text-end">
                    <div class="table-actions">
                        <a href="{{ route('admin.upgrade-requests.show', $upgrade) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="View" aria-label="View"><i class="bi bi-eye"></i></a>
                    </div>
                </td>
            </tr>
        @empty
            <x-ui.empty-table-row colSpan="9" icon="bi bi-arrow-up-right-circle" title="No upgrade requests found." />
        @endforelse
    </x-adminlte.partials.datatable>
@stop