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
    // Brand palette — "datacenter control room at night"
    const brandColors = ['#2DD4BF', '#F5A524', '#818CF8', '#F87171', '#34D399', '#E879F9'];
    // Priority semantics: low=info, medium=warning, high=danger, urgent=secondary
    const priorityColors = ['#22D3EE', '#F5A524', '#F87171', '#64748B'];

    // Chart.js colors are static strings, so pick text/grid tones from the
    // active mode (set by the no-flash script / ColorMode toggle).
    const isDark = document.documentElement.getAttribute('data-bs-theme') !== 'light';
    const chartText = isDark ? '#8A97AD' : '#5B6B84';
    const chartGrid = isDark ? 'rgba(148, 163, 184, 0.14)' : 'rgba(148, 163, 184, 0.25)';
    const legendLabels = { color: chartText };

    // Revenue chart
    const revenueData = @json($revenueByMonth);
    new Chart(document.getElementById('revenueChart'), {
        type: 'bar',
        data: {
            labels: Object.keys(revenueData),
            datasets: [{
                label: 'Revenue',
                data: Object.values(revenueData),
                backgroundColor: '#2DD4BF',
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { color: chartText }, grid: { color: chartGrid } },
                x: { ticks: { color: chartText }, grid: { color: chartGrid } }
            }
        }
    });

    // Hosting by status (doughnut)
    const hostingData = @json($hostingByStatus);
    new Chart(document.getElementById('hostingChart'), {
        type: 'doughnut',
        data: {
            labels: Object.keys(hostingData),
            datasets: [{ data: Object.values(hostingData), backgroundColor: brandColors }]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom', labels: legendLabels } } }
    });

    // Tickets by priority (horizontal bar)
    const ticketsData = @json($ticketsByPriority);
    new Chart(document.getElementById('ticketsChart'), {
        type: 'bar',
        data: {
            labels: Object.keys(ticketsData),
            datasets: [{ label: 'Tickets', data: Object.values(ticketsData), backgroundColor: priorityColors }]
        },
        options: {
            responsive: true,
            indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: {
                x: { beginAtZero: true, ticks: { color: chartText }, grid: { color: chartGrid } },
                y: { ticks: { color: chartText }, grid: { color: chartGrid } }
            }
        }
    });

    // Domain status (pie)
    const domainsData = @json($domainsByStatus);
    new Chart(document.getElementById('domainsChart'), {
        type: 'pie',
        data: {
            labels: Object.keys(domainsData),
            datasets: [{ data: Object.values(domainsData), backgroundColor: brandColors }]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom', labels: legendLabels } } }
    });

    // Customer registrations (line)
    const customerData = @json($customersByMonth);
    new Chart(document.getElementById('customersChart'), {
        type: 'line',
        data: {
            labels: Object.keys(customerData),
            datasets: [{ label: 'New Customers', data: Object.values(customerData), borderColor: '#2DD4BF', fill: true, backgroundColor: 'rgba(45,212,191,0.12)' }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { color: chartText }, grid: { color: chartGrid } },
                x: { ticks: { color: chartText }, grid: { color: chartGrid } }
            }
        }
    });
});
</script>
@endpush
