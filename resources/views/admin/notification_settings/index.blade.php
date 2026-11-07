@extends('adminlte::page')

@section('title', 'Notification Settings')

@section('content_header')
    <x-ui.page-header title="Notification Settings" subtitle="Event-by-audience matrix, per channel." :breadcrumbs="[['label' => __('adminlte.home'), 'url' => url('/')], ['label' => 'Notification Settings', 'active' => true]]" />
@stop

@section('content')
    <x-adminlte-alert theme="info" class="mb-3">
        Manual sends (Send Invoice button, test emails, email resend) and the password-reset email are always sent — they are not listed here.
    </x-adminlte-alert>

    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible id="notification-settings-success-alert">{{ session('success') }}</x-adminlte-alert>
    @endif
    @if (session('error'))
        <x-adminlte-alert theme="danger" dismissible id="notification-settings-error-alert">{{ session('error') }}</x-adminlte-alert>
    @endif

    @php
        $channels = [
            'database' => 'In-app',
            'mail' => 'Email',
        ];
        $categoryIcons = [
            'Orders & Billing' => 'bi bi-receipt',
            'Tickets' => 'bi bi-ticket-detailed',
            'Chat' => 'bi bi-chat-dots',
            'Renewals & Hosting' => 'bi bi-arrow-repeat',
            'System' => 'bi bi-gear',
        ];
    @endphp

    <form method="POST" action="{{ route('admin.notification-settings.update') }}">
        @csrf
        @method('PUT')

        @foreach ($groups as $category => $events)
            <x-adminlte-card :icon="$categoryIcons[$category] ?? 'bi bi-bell'" :title="$category">
                <div class="table-responsive">
                    <table class="table table-grid table-sm align-middle mb-0" style="min-width: max-content;" aria-label="Notification event matrix">
                        <thead>
                            <tr>
                                <th rowspan="2" class="text-start align-middle" scope="col">Event</th>
                                @foreach ($audiences as $audience => $audienceLabel)
                                    <th colspan="2" class="text-center text-nowrap" scope="colgroup">{{ $audienceLabel }}</th>
                                @endforeach
                            </tr>
                            <tr>
                                @foreach ($audiences as $audienceLabel)
                                    @foreach ($channels as $channelLabel)
                                        <th class="text-center small text-body-secondary text-nowrap" scope="col">{{ $channelLabel }}</th>
                                    @endforeach
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($events as $event)
                                <tr>
                                    <td class="text-start" style="min-width: 260px; max-width: 380px;">
                                        <div class="fw-semibold">{{ $event['label'] }}</div>
                                        <div class="small text-body-secondary">{{ $event['description'] }}</div>
                                    </td>
                                    @foreach ($audiences as $audience => $audienceLabel)
                                        @foreach ($channels as $channel => $channelLabel)
                                            @php
                                                $applicable = array_key_exists($audience, $state[$event['key']] ?? []) && array_key_exists($channel, $state[$event['key']][$audience]);
                                                $checked = $state[$event['key']][$audience][$channel] ?? false;
                                            @endphp
                                            @if ($applicable)
                                                <td class="text-center align-middle">
                                                    <input type="checkbox" class="form-check-input"
                                                           name="settings[{{ $event['key'] }}][{{ $audience }}][{{ $channel }}]"
                                                           value="1"
                                                           id="setting-{{ $event['key'] }}-{{ $audience }}-{{ $channel }}"
                                                           aria-label="{{ $event['label'] }} — {{ $audienceLabel }} — {{ $channelLabel }}"
                                                           @checked($checked)>
                                                </td>
                                            @else
                                                <td class="text-center align-middle bg-body-tertiary">
                                                    <span aria-hidden="true">&mdash;</span><span class="visually-hidden">Not applicable</span>
                                                </td>
                                            @endif
                                        @endforeach
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-adminlte-card>
        @endforeach

        <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1" aria-hidden="true"></i> Save Changes
            </button>
        </div>
    </form>
@stop
