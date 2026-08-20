@extends('adminlte::page')
@section('title', 'Domain Search')
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Domain Search</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.cart.index') }}">Cart</a></li><li class="breadcrumb-item active">Domain Search</li></ol></div></div>
@stop
@section('content')
    <x-adminlte-card icon="bi bi-globe" title="Check Domain Availability">
        <form method="GET" action="{{ route('admin.cart.domain-search') }}">
            <div class="input-group mb-3">
                <input type="text" name="domain" class="form-control" placeholder="Enter domain name (e.g. mysite)" value="{{ $domain }}">
                <button type="submit" class="btn btn-primary">Check Availability</button>
            </div>
        </form>
    </x-adminlte-card>

    @if ($results)
        <x-adminlte-alert theme="warning" icon="bi bi-info-circle" dismissible>
            <strong>Simulated check — not a live lookup.</strong>
            Availability below is generated locally for demonstration; it does not query
            a real domain registry. Verify with your registrar before billing the customer.
        </x-adminlte-alert>
        <x-adminlte-card icon="bi bi-list-check" title="Results">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Domain</th><th>Status</th><th>Price/yr</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                        @foreach ($results as $r)
                            <tr>
                                <td><strong>{{ $r['domain'] }}</strong></td>
                                <td>
                                    @if ($r['available'])
                                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> Available</span>
                                    @else
                                        <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i> Taken</span>
                                    @endif
                                </td>
                                <td>${{ $r['price'] }}</td>
                                <td class="text-end">
                                    @if ($r['available'])
                                        <form method="POST" action="{{ route('admin.cart.add') }}" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="product_id" value="1">
                                            <input type="hidden" name="domain" value="{{ $r['domain'] }}">
                                            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-cart-plus me-1"></i> Add to Cart</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-adminlte-card>
    @endif
@stop
