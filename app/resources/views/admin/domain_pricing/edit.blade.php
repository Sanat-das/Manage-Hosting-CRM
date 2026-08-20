@extends('adminlte::page')
@section('title', 'Edit Domain Pricing — '.$pricing->tld)
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Edit: .{{ $pricing->tld }}</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.domain-pricing.index') }}">Domain Pricing</a></li><li class="breadcrumb-item active">Edit</li></ol></div></div>
@stop
@section('content')
    @if ($errors->any()) <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert> @endif
    <x-adminlte.partials.form-card icon="bi bi-globe" title="Edit Domain Pricing" :action="route('admin.domain-pricing.update', $pricing)" submit-label="Update" :cancel-url="route('admin.domain-pricing.show', $pricing)">
        @method('PUT')
        <div class="row">
            <div class="col-md-3"><x-adminlte-input name="tld" label="TLD" value="{{ old('tld', $pricing->tld) }}" required /></div>
            <div class="col-md-3"><x-adminlte-input name="register_price" label="Register Price" type="number" step="0.01" min="0" value="{{ old('register_price', $pricing->register_price) }}" required /></div>
            <div class="col-md-3"><x-adminlte-input name="renew_price" label="Renew Price" type="number" step="0.01" min="0" value="{{ old('renew_price', $pricing->renew_price) }}" required /></div>
            <div class="col-md-3"><x-adminlte-input name="transfer_price" label="Transfer Price" type="number" step="0.01" min="0" value="{{ old('transfer_price', $pricing->transfer_price) }}" required /></div>
        </div>
        <div class="row">
            <div class="col-md-3"><x-adminlte-input name="currency" label="Currency" value="{{ old('currency', $pricing->currency) }}" /></div>
            <div class="col-md-3">
                <div class="mt-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="premium" value="1" @checked(old('premium', $pricing->premium))>
                        <label class="form-check-label">Premium TLD</label>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="mt-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="enabled" value="1" @checked(old('enabled', $pricing->enabled))>
                        <label class="form-check-label">Enabled</label>
                    </div>
                </div>
            </div>
        </div>
        <h6 class="mt-3 mb-2">Term Pricing</h6>
        @php
            $existingTerms = $pricing->terms->keyBy('term_years');
        @endphp
        @for ($i = 0; $i < 10; $i++)
            @php
                $year = $i + 1;
                $term = $existingTerms->get($year);
            @endphp
            <div class="row mb-2">
                <div class="col-md-4">
                    <x-adminlte-select name="terms[{{ $i }}][term_years]" label="{{ $i === 0 ? 'Term (Years)' : '' }}">
                        <option value="">—</option>
                        @foreach (range(1, 10) as $y)
                            <option value="{{ $y }}" @selected(old("terms.$i.term_years", $term?->term_years) == $y)>{{ $y }} year{{ $y > 1 ? 's' : '' }}</option>
                        @endforeach
                    </x-adminlte-select>
                </div>
                <div class="col-md-4"><x-adminlte-input name="terms[{{ $i }}][register_price]" label="{{ $i === 0 ? 'Term Register Price' : '' }}" type="number" step="0.01" min="0" value="{{ old("terms.$i.register_price", $term?->register_price ?? '0') }}" /></div>
                <div class="col-md-4"><x-adminlte-input name="terms[{{ $i }}][renew_price]" label="{{ $i === 0 ? 'Term Renew Price' : '' }}" type="number" step="0.01" min="0" value="{{ old("terms.$i.renew_price", $term?->renew_price ?? '0') }}" /></div>
            </div>
        @endfor
    </x-adminlte.partials.form-card>
@stop
