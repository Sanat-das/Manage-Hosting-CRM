@extends('adminlte::page')

@section('title', 'Register Domain')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Register Domain</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.domains.index') }}">Domains</a></li>
                <li class="breadcrumb-item active">Register</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @include('client.store._alerts')

    <x-adminlte-card icon="bi bi-search" title="Check Domain Availability">
        <form method="GET" action="{{ route('client.domains.register') }}" class="d-flex gap-2">
            <input type="text" name="q" class="form-control" placeholder="Enter a domain name (e.g. example.com)" value="{{ $query }}">
            <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i> Check</button>
        </form>
    </x-adminlte-card>

    @if ($query !== '')
        <x-adminlte-card icon="bi bi-globe" title="Search Results">
            @if ($error !== null)
                <div class="alert alert-danger mb-0">{{ $error }}</div>
            @elseif (empty($results))
                <p class="text-muted mb-0">No results found.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Domain</th><th>Availability</th><th>Price / year</th><th></th></tr></thead>
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
                                    <td>{{ $result['currency'] }} {{ number_format($result['price'], 2) }}</td>
                                    <td class="text-end">
                                        @if ($result['available'])
                                            <a href="{{ route('client.domains.register', ['q' => $query, 'domain' => $result['domain']]) }}"
                                               class="btn btn-sm btn-primary"><i class="bi bi-plus-circle me-1"></i> Register</a>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-adminlte-card>
    @endif

    @if ($selected !== null)
        <x-adminlte-card icon="bi bi-cart-check" title="Register {{ $selected['domain'] }}">
            <form method="POST" action="{{ route('client.domains.register.post') }}">
                @csrf
                <input type="hidden" name="domain" value="{{ $selected['domain'] }}">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="registration_period">Registration Period</label>
                        <select name="registration_period" id="registration_period" class="form-select" onchange="updateTotal(this)">
                            @foreach ($terms as $term)
                                <option value="{{ $term['years'] }}" data-price="{{ number_format($term['price'], 2) }}">
                                    {{ $term['years'] }} {{ $term['years'] === 1 ? 'year' : 'years' }} — {{ $selected['currency'] }} {{ number_format($term['price'], 2) }}
                                </option>
                            @endforeach
                        </select>
                        @error('registration_period')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Order Total</label>
                        <div class="border rounded p-2 fs-5 fw-bold" id="order-total">
                            {{ $selected['currency'] }} {{ number_format($terms[0]['price'] ?? $selected['price'], 2) }}
                        </div>
                    </div>
                </div>

                <div class="alert alert-info small mt-3 mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    Your domain will be registered for the selected period. After payment it appears in
                    <a href="{{ route('client.domains.index') }}">My Domains</a> and renews annually.
                </div>

                <div class="text-end mt-3">
                    <a href="{{ route('client.domains.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary btn-lg ms-2">
                        <i class="bi bi-credit-card me-1"></i> Place Order &amp; Pay
                    </button>
                </div>
            </form>
        </x-adminlte-card>

        <script>
            function updateTotal(select) {
                var option = select.options[select.selectedIndex];
                document.getElementById('order-total').textContent = '{{ $selected['currency'] ?? '' }} ' + option.dataset.price;
            }
        </script>
    @endif
@stop
