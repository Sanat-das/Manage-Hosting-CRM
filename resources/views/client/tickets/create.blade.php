@extends('adminlte::page')

@section('title', 'New Ticket')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Open a Support Ticket</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.tickets.index') }}">Tickets</a></li>
                <li class="breadcrumb-item active">New</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-adminlte-alert>
    @endif

    <x-adminlte-card icon="bi bi-life-preserver" title="Submit a New Ticket">
        <form method="POST" action="{{ route('client.tickets.store') }}">
            @csrf
            <div class="row">
                <div class="col-md-8">
                    <x-adminlte-input name="subject" label="Subject" placeholder="Brief description of your issue" value="{{ old('subject') }}" required />
                </div>
                <div class="col-md-2">
                    <x-adminlte-select name="priority" label="Priority">
                        <option value="low" @selected(old('priority') === 'low')>Low</option>
                        <option value="medium" @selected(old('priority', 'medium') === 'medium')>Medium</option>
                        <option value="high" @selected(old('priority') === 'high')>High</option>
                        <option value="urgent" @selected(old('priority') === 'urgent')>Urgent</option>
                    </x-adminlte-select>
                </div>
                <div class="col-md-2">
                    <x-adminlte-select name="department" label="Department">
                        <option value="" @selected(old('department') === null)>Select department</option>
                        @foreach (\App\Services\TicketService::departments() as $slug => $label)
                            <option value="{{ $slug }}" @selected(old('department') === $slug)>{{ $label }}</option>
                        @endforeach
                    </x-adminlte-select>
                </div>
            </div>
            <x-adminlte-textarea name="message" label="Message" rows="6" placeholder="Describe your issue in detail..." required>{{ old('message') }}</x-adminlte-textarea>
            <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i> Submit Ticket</button>
        </form>
    </x-adminlte-card>
@stop
