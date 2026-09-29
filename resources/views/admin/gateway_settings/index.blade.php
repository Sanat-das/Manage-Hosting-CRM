@extends('adminlte::page')

@section('title', 'Gateway Settings')

@section('content_header')
    <x-ui.page-header title="Gateway Settings" subtitle="Configure payment gateways and integrations" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')], ['label' => 'Gateway Settings', 'active' => true]]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

    <x-adminlte-alert theme="info" dismissible icon="bi bi-info-circle">
        Online gateways (Stripe, PayPal, Razorpay) must have their credentials configured before they can be enabled.
    </x-adminlte-alert>

    <x-adminlte-card icon="bi bi-credit-card" title="Payment Gateways" bodyClass="p-0">
        <div class="table-responsive">
            <table class="table table-grid table-striped align-middle m-0"
                   data-grid-resizable
                   data-grid-key="admin.gateway-settings.index">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Code</th>
                        <th>Mode</th>
                        <th>Status</th>
                        <th>Configuration</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($gateways as $gateway)
                        <tr>
                            <td class="fw-bold">{{ $gateway->name }}</td>
                            <td><code>{{ $gateway->code }}</code></td>
                            <td>
                                <x-adminlte.partials.status-badge :status="$gateway->mode" :label="ucfirst($gateway->mode)" :map="['live' => 'success', 'test' => 'warning', 'sandbox' => 'warning']" />
                            </td>
                            <td>
                                <x-adminlte.partials.status-badge :status="$gateway->enabled ? 'enabled' : 'disabled'" />
                            </td>
                            <td>
                                <x-adminlte.partials.status-badge :status="$gateway->isConfigured() ? 'configured' : 'inactive'" :label="$gateway->isConfigured() ? 'Configured' : 'Not configured'" />
                            </td>
                            <td class="text-end">
                        <div class="table-actions">
                            <a href="{{ route('admin.gateway-settings.edit', $gateway) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                        </div>
                    </td>
                        </tr>
                    @empty
                        <x-ui.empty-table-row colSpan="6" title="No payment gateways found." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-adminlte-card>
@stop
