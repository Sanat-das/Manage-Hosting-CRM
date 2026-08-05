@extends('adminlte::page')
@section('title', 'Edit DNS Record')
@section('content_header')
    <div class="row"><div class="col-sm-6"><h1 class="m-0">Edit Record: {{ $dnsRecord->name }} ({{ $dnsRecord->type }})</h1></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li><li class="breadcrumb-item"><a href="{{ route('admin.dns-zones.records.index', $dnsZone) }}">Records</a></li><li class="breadcrumb-item active">Edit</li></ol></div></div>
@stop
@section('content')
    @if ($errors->any()) <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert> @endif
    <x-adminlte.partials.form-card icon="bi bi-pencil" title="Edit DNS Record" :action="route('admin.dns-zones.records.update', [$dnsZone, $dnsRecord])" submit-label="Update Record" :cancel-url="route('admin.dns-zones.records.index', $dnsZone)">
        @method('PUT')
        <div class="row">
            <div class="col-md-6"><x-adminlte-input name="name" label="Name" value="{{ old('name', $dnsRecord->name) }}" required /></div>
            <div class="col-md-6">
                <x-adminlte-select name="type" label="Type">
                    @foreach (['A','AAAA','CNAME','MX','NS','TXT','SRV','PTR','SOA','CAA'] as $t)
                        <option value="{{ $t }}" @selected(old('type', $dnsRecord->type) === $t)>{{ $t }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4"><x-adminlte-input name="ttl" label="TTL" type="number" min="0" max="86400" value="{{ old('ttl', $dnsRecord->ttl) }}" /></div>
            <div class="col-md-4"><x-adminlte-input name="priority" label="Priority" type="number" min="0" value="{{ old('priority', $dnsRecord->priority) }}" /></div>
            <div class="col-md-4"><x-adminlte-input name="content" label="Content" value="{{ old('content', $dnsRecord->content) }}" required /></div>
        </div>
    </x-adminlte.partials.form-card>
@stop
