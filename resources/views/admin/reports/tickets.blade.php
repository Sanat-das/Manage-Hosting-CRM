@extends('adminlte::page')

@section('title', 'Ticket Report')

@section('content_header')
    <x-ui.page-header title="Ticket Report" subtitle="Support ticket volume and resolution trends" :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')],['label' => 'Reports'],['label' => 'Ticket Report','active' => true]]" />
@stop

@section('content')
    <div class="row">
        <div class="col-md-4">
            <x-adminlte-card icon="bi bi-bar-chart" title="By Priority">
                <table class="table table-sm mb-0">
                    @foreach ($byPriority as $p => $c)
                        <tr><td class="text-capitalize">{{ $p }}</td><td class="text-end fw-bold">{{ $c }}</td></tr>
                    @endforeach
                </table>
            </x-adminlte-card>
        </div>
        <div class="col-md-4">
            <x-adminlte-card icon="bi bi-pie-chart" title="By Status">
                <table class="table table-sm mb-0">
                    @foreach ($byStatus as $s => $c)
                        <tr><td class="text-capitalize">{{ $s }}</td><td class="text-end fw-bold">{{ $c }}</td></tr>
                    @endforeach
                </table>
            </x-adminlte-card>
        </div>
        <div class="col-md-4">
            <x-adminlte-card icon="bi bi-diagram-3" title="By Department">
                <table class="table table-sm mb-0">
                    @foreach ($byDepartment as $d => $c)
                        <tr><td class="text-capitalize">{{ $d ?: 'General' }}</td><td class="text-end fw-bold">{{ $c }}</td></tr>
                    @endforeach
                </table>
            </x-adminlte-card>
        </div>
    </div>
@stop

