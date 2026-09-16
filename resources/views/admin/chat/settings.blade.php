@extends('adminlte::page')

@section('title', 'Chat Settings')

@section('content_header')
    <x-ui.page-header title="Chat Settings" subtitle="Office hours, out-of-hours messages and transcripts" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Live Chat', 'url' => route('admin.chat.index')],
        ['label' => 'Settings', 'active' => true],
    ]" />
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card icon="bi bi-clock" title="Chat availability"
        :action="route('admin.chat.settings.update')" method="PUT" submit-label="Save settings"
        :cancel-url="route('admin.chat.index')">

        {{-- Everything on this page is off by default on purpose. These controls
             change what a CUSTOMER sees, so an upgrade must not switch any of
             them on by arriving. --}}
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" role="switch" id="enforce_office_hours"
                   name="enforce_office_hours" value="1"
                   @checked(old('enforce_office_hours', $settings->enforce_office_hours))>
            <label class="form-check-label" for="enforce_office_hours">
                Enforce office hours
            </label>
            <div class="form-text">
                While this is off the chat is open 24/7 and nothing else on this card applies.
                A conversation that is already open is never cut off by closing time — only new ones are refused.
            </div>
        </div>

        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" role="switch" id="require_available_operator"
                   name="require_available_operator" value="1"
                   @checked(old('require_available_operator', $settings->require_available_operator))>
            <label class="form-check-label" for="require_available_operator">
                Also require an operator who is online and marked Available
            </label>
            <div class="form-text">
                Closes the chat during its own opening hours when nobody with Manage Live Chat is at their desk
                and accepting. Leave this off if your team does not use the availability control.
            </div>
        </div>

        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="offline_form_enabled"
                   name="offline_form_enabled" value="1"
                   @checked(old('offline_form_enabled', $settings->offline_form_enabled))>
            <label class="form-check-label" for="offline_form_enabled">
                Take a message while closed
            </label>
            <div class="form-text">
                The widget offers a short form instead of a chat, and each message opens a support ticket
                so the reply reaches the customer by email.
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                @php $selectedTimezone = old('timezone', $settings->timezone); @endphp
                <x-adminlte-select name="timezone" label="Office hours are in">
                    <option value="">Application default ({{ $defaultTimezone }})</option>
                    @foreach ($timezonesGrouped as $region => $identifiers)
                        <optgroup label="{{ $region }}">
                            @foreach ($identifiers as $timezone)
                                <option value="{{ $timezone }}" @selected($selectedTimezone === $timezone)>{{ $timezone }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="offline_ticket_department" label="Out-of-hours messages open under">
                    <option value="">First enabled department</option>
                    @foreach ($departments as $slug => $name)
                        <option value="{{ $slug }}" @selected(old('offline_ticket_department', $settings->offline_ticket_department) === $slug)>{{ $name }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>

        <x-adminlte-textarea name="closed_message" label="What visitors are told while closed" rows="2"
            maxlength="{{ \App\Http\Requests\Chat\UpdateChatSettingsRequest::MAX_CLOSED_MESSAGE }}">{{ old('closed_message', $settings->closedMessage()) }}</x-adminlte-textarea>

        <hr>

        <h3 class="h6 mb-1">Opening hours</h3>
        <p class="form-text mt-0 mb-2">
            24-hour times. A closing time at or before the opening time runs past midnight —
            22:00 to 02:00 is a night shift, not an empty window.
        </p>

        {{-- Fixed layout with a percentage colgroup: the grid fits by
             construction rather than by scrolling. --}}
        <div class="table-responsive">
            <table class="table table-sm align-middle m-0" style="table-layout: fixed;">
                <colgroup>
                    <col style="width: 34%;">
                    <col style="width: 22%;">
                    <col style="width: 22%;">
                    <col style="width: 22%;">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">Day</th>
                        <th scope="col">Open</th>
                        <th scope="col">From</th>
                        <th scope="col">To</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($week as $day => $window)
                        <tr>
                            <th scope="row" class="fw-normal">{{ $dayNames[$day] }}</th>
                            <td>
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" value="1"
                                           id="day-{{ $day }}-open" name="days[{{ $day }}][is_open]"
                                           @checked(old("days.$day.is_open", $window->is_open))>
                                    <label class="visually-hidden" for="day-{{ $day }}-open">{{ $dayNames[$day] }} open</label>
                                </div>
                            </td>
                            <td>
                                <input type="time" class="form-control form-control-sm"
                                       name="days[{{ $day }}][opens_at]"
                                       value="{{ old("days.$day.opens_at", $window->opensAtInput()) }}"
                                       aria-label="{{ $dayNames[$day] }} opens at" required>
                            </td>
                            <td>
                                <input type="time" class="form-control form-control-sm"
                                       name="days[{{ $day }}][closes_at]"
                                       value="{{ old("days.$day.closes_at", $window->closesAtInput()) }}"
                                       aria-label="{{ $dayNames[$day] }} closes at" required>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <hr>

        <h3 class="h6 mb-1">Transcripts</h3>
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="send_transcript_on_close"
                   name="send_transcript_on_close" value="1"
                   @checked(old('send_transcript_on_close', $settings->send_transcript_on_close))>
            <label class="form-check-label" for="send_transcript_on_close">
                Email the customer a transcript when a chat is closed
            </label>
            <div class="form-text">
                Uses the <strong>chat_transcript</strong>
                <a href="{{ route('admin.email-templates.index') }}">email template</a>.
                Operator names are replaced with &ldquo;Support&rdquo;, and staff thread replies —
                which the customer never saw — are not included.
            </div>
        </div>
    </x-adminlte.partials.form-card>
@stop
