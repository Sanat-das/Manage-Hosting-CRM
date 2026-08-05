@extends('adminlte::page')

@section('title', 'Analytics')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Analytics</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active">Analytics</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    {{-- Summary cards --}}
    <div class="row mb-4">
        <div class="col-lg-3 col-6">
            <x-adminlte-small-box :title="'₹' . number_format($totalRevenue, 0)" text="Total Revenue"
                                  icon="bi bi-currency-rupee" theme="success" />
        </div>
        <div class="col-lg-3 col-6">
            <x-adminlte-small-box :title="$totalCustomers" text="Total Customers"
                                  icon="bi bi-people" theme="primary" />
        </div>
        <div class="col-lg-3 col-6">
            <x-adminlte-small-box :title="$totalTickets" text="Open Tickets"
                                  icon="bi bi-life-preserver" theme="warning" />
        </div>
        <div class="col-lg-3 col-6">
            <x-adminlte-small-box :title="$totalDomains" text="Active Domains"
                                  icon="bi bi-globe" theme="info" />
        </div>
    </div>

    <div class="row">
        {{-- Revenue chart --}}
        <div class="col-lg-8">
            <x-adminlte-card icon="bi bi-graph-up" title="Revenue (Last 12 Months)">
                <canvas id="revenueChart" height="300"></canvas>
            </x-adminlte-card>
        </div>

        {{-- Hosting by status --}}
        <div class="col-lg-4">
            <x-adminlte-card icon="bi bi-pie-chart" title="Hosting Accounts">
                <canvas id="hostingChart" height="300"></canvas>
            </x-adminlte-card>
        </div>
    </div>

    <div class="row">
        {{-- Tickets by priority --}}
        <div class="col-lg-6">
            <x-adminlte-card icon="bi bi-bar-chart" title="Open Tickets by Priority">
                <canvas id="ticketsChart" height="250"></canvas>
            </x-adminlte-card>
        </div>

        {{-- Domain status --}}
        <div class="col-lg-6">
            <x-adminlte-card icon="bi bi-globe2" title="Domain Status Distribution">
                <canvas id="domainsChart" height="250"></canvas>
            </x-adminlte-card>
        </div>
    </div>

    {{-- Customer registrations --}}
    <div class="row">
        <div class="col-12">
            <x-adminlte-card icon="bi bi-people" title="Customer Registrations (Last 12 Months)">
                <canvas id="customersChart" height="200"></canvas>
            </x-adminlte-card>
        </div>
    </div>
@stop

@push('js')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const colors = ['#007bff', '#28a745', '#ffc107', '#dc3545', '#17a2b8', '#6f42c1', '#fd7e14', '#6c757d'];

    // Revenue chart
    const revenueData = @json($revenueByMonth);
    new Chart(document.getElementById('revenueChart'), {
        type: 'bar',
        data: {
            labels: Object.keys(revenueData),
            datasets: [{
                label: 'Revenue',
                data: Object.values(revenueData),
                backgroundColor: '#28a745',
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true } }
        }
    });

    // Hosting by status (doughnut)
    const hostingData = @json($hostingByStatus);
    new Chart(document.getElementById('hostingChart'), {
        type: 'doughnut',
        data: {
            labels: Object.keys(hostingData),
            datasets: [{ data: Object.values(hostingData), backgroundColor: colors }]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });

    // Tickets by priority (horizontal bar)
    const ticketsData = @json($ticketsByPriority);
    new Chart(document.getElementById('ticketsChart'), {
        type: 'bar',
        data: {
            labels: Object.keys(ticketsData),
            datasets: [{ label: 'Tickets', data: Object.values(ticketsData), backgroundColor: ['#17a2b8', '#ffc107', '#dc3545', '#343a40'] }]
        },
        options: { responsive: true, indexAxis: 'y', plugins: { legend: { display: false } } }
    });

    // Domain status (pie)
    const domainsData = @json($domainsByStatus);
    new Chart(document.getElementById('domainsChart'), {
        type: 'pie',
        data: {
            labels: Object.keys(domainsData),
            datasets: [{ data: Object.values(domainsData), backgroundColor: colors }]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });

    // Customer registrations (line)
    const customerData = @json($customersByMonth);
    new Chart(document.getElementById('customersChart'), {
        type: 'line',
        data: {
            labels: Object.keys(customerData),
            datasets: [{ label: 'New Customers', data: Object.values(customerData), borderColor: '#007bff', fill: true, backgroundColor: 'rgba(0,123,255,0.1)' }]
        },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });
});
</script>
@endpush
