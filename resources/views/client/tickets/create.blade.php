@extends('adminlte::page')

@section('title', 'New Ticket')

@section('content_header')
    <x-ui.page-header title="Open a Support Ticket" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Tickets', 'url' => route('client.tickets.index')],
        ['label' => 'New', 'active' => true],
    ]" />
@stop

@section('content')
    <x-adminlte.partials.flash-alert />

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
