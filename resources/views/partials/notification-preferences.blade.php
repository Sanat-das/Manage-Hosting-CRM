{{-- Shared per-user notification preferences form.
     Expects: $notificationPreferences (rows from PerUserNotificationMap::rows()),
              $action (form action URL),
              $customerPending (optional bool; client accounts with no linked Customer) --}}
@php($customerPending = $customerPending ?? false)

@if ($customerPending)
    <div class="alert alert-warning small mb-3">
        Your account setup is pending, so order and invoice email and in-app notifications aren't linked yet.
        Those toggles are disabled for now and will become available once your customer account is set up.
    </div>
@endif

<form method="POST" action="{{ $action }}">
    @csrf

    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" aria-label="Your notification preferences">
            <thead>
                <tr>
                    <th class="text-start" scope="col">Event</th>
                    <th class="text-center" style="width: 90px;" scope="col">In-app</th>
                    <th class="text-center" style="width: 90px;" scope="col">Email</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($notificationPreferences as $row)
                    <tr>
                        <td class="text-start">
                            <div class="fw-semibold">{{ $row['label'] }}</div>
                            <div class="small text-body-secondary">{{ $row['description'] }}</div>
                        </td>
                        @foreach (['database', 'mail'] as $channel)
                            @php($cell = $row['channels'][$channel])
                            @if (! $cell['applicable'])
                                <td class="text-center text-muted"><span aria-hidden="true">&mdash;</span><span class="visually-hidden">Not applicable</span></td>
                            @elseif ($row['locked'])
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input" disabled
                                           aria-label="{{ $row['label'] }} — {{ $channel === 'database' ? 'In-app' : 'Email' }} (account pending)">
                                    <span class="visually-hidden">Unavailable — account pending</span>
                                </td>
                            @else
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input"
                                           name="notification_preferences[{{ $row['key'] }}][{{ $channel }}]"
                                           value="1"
                                           id="np-{{ $row['key'] }}-{{ $channel }}"
                                           aria-label="{{ $row['label'] }} — {{ $channel === 'database' ? 'In-app' : 'Email' }}"
                                           @checked($cell['enabled'])>
                                </td>
                            @endif
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @isset($channelHelp)
        <p class="form-text text-muted mb-0">{{ $channelHelp }}</p>
    @endisset

    <button type="submit" class="{{ $buttonClass ?? 'btn btn-primary mt-3' }}">
        <i class="bi bi-check-lg me-1" aria-hidden="true"></i> Save Preferences
    </button>
</form>
