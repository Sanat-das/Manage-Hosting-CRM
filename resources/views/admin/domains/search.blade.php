@extends('adminlte::page')

@section('title', 'Domain Search')

@section('content_header')
    <x-ui.page-header title="Domain Search" subtitle="Search domain availability" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Domains', 'url' => route('admin.domains.index')],
        ['label' => 'Search', 'active' => true],
    ]" />
@stop

@section('content')
    <x-adminlte-card icon="bi bi-search" title="Check Domain Availability">
        <form method="GET" action="{{ route('admin.domains.search') }}" class="d-flex gap-2">
            <input type="text" name="q" class="form-control" placeholder="Enter domain name (e.g. example.com)" value="{{ $query }}">
            <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i> Check</button>
        </form>
    </x-adminlte-card>

    @if ($query !== '')
        <x-adminlte-card title="Results for '{{ $query }}'">
            @if ($error !== null)
                <p class="text-danger mb-0">{{ $error }}</p>
            @elseif (empty($results))
                <p class="text-muted mb-0">No results found.</p>
            @else
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Domain</th><th>Available</th><th>Price/yr</th><th>Action</th></tr></thead>
                    <tbody>
                        @foreach ($results as $result)
                            <tr>
                                <td><strong>{{ $result['domain'] }}</strong></td>
                                <td>
                                    @if ($result['available'])
                                        <span class="badge text-bg-success">Available</span>
                                    @else
                                        <span class="badge text-bg-secondary">Taken</span>
                                    @endif
                                </td>
                                <td>{{ number_format($result['price'] ?? 0, 2) }}</td>
                                <td>
                                    @if ($result['available'])
                                        <button class="btn btn-sm btn-primary" disabled>Register (coming soon)</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-adminlte-card>
    @endif
@stop
