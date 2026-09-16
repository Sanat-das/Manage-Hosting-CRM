@extends('adminlte::page')

@section('title', 'Chat Satisfaction')

@section('content_header')
    <x-ui.page-header title="Chat Satisfaction" subtitle="What customers scored their chats" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Live Chat', 'url' => route('admin.chat.index')],
        ['label' => 'Satisfaction', 'active' => true],
    ]" />
@stop

@section('content')
    {{-- The range, echoed back from the service so what is shown is what was
         applied — including the 30-day default and the swap when the dates are
         entered the wrong way round. --}}
    <x-adminlte-card icon="bi bi-funnel" title="Range" collapsible="false" class="mb-3">
        <form method="GET" action="{{ route('admin.chat.satisfaction') }}" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label mb-1" for="from">From</label>
                <input type="date" class="form-control form-control-sm" id="from" name="from" value="{{ $from }}">
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1" for="to">To</label>
                <input type="date" class="form-control form-control-sm" id="to" name="to" value="{{ $to }}">
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1" for="department">Department</label>
                <select class="form-select form-select-sm" id="department" name="department">
                    <option value="">All departments</option>
                    @foreach ($departments as $slug => $name)
                        <option value="{{ $slug }}" @selected($department === $slug)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-sm btn-primary">Apply</button>
                <a href="{{ route('admin.chat.satisfaction') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </x-adminlte-card>

    {{-- The response rate sits next to the average deliberately. An average of
         5.0 from three ratings across four hundred closed chats is not a 5.0
         satisfaction score, and showing the average without its denominator
         invites exactly that reading. --}}
    <x-adminlte.partials.metric-cards :items="[
        [
            'title' => $report['average'] === null ? '—' : number_format($report['average'], 2).' / 5',
            'text' => 'Average rating',
            'icon' => 'bi bi-star-fill',
            'theme' => 'success',
        ],
        [
            'title' => (string) $report['rated'],
            'text' => 'Chats rated',
            'icon' => 'bi bi-chat-square-heart',
            'theme' => 'info',
        ],
        [
            'title' => (string) $report['closed'],
            'text' => 'Chats closed',
            'icon' => 'bi bi-archive',
            'theme' => 'primary',
        ],
        [
            'title' => number_format($report['response_rate'], 1).'%',
            'text' => 'Left a rating',
            'icon' => 'bi bi-percent',
            'theme' => 'warning',
        ],
    ]" />

    <div class="row">
        <div class="col-lg-5">
            <x-adminlte-card icon="bi bi-bar-chart" title="Distribution">
                @php $peak = max(1, max($report['distribution'])); @endphp
                @foreach (array_reverse($report['distribution'], true) as $star => $count)
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="text-nowrap small" style="width: 3.5rem;">
                            {{ $star }} <i class="bi bi-star-fill text-warning" aria-hidden="true"></i>
                        </span>
                        <div class="progress flex-grow-1" style="height: 0.75rem;"
                             role="progressbar" aria-label="{{ $star }} star ratings"
                             aria-valuenow="{{ $count }}" aria-valuemin="0" aria-valuemax="{{ $peak }}">
                            <div class="progress-bar bg-warning" style="width: {{ round($count / $peak * 100) }}%;"></div>
                        </div>
                        <span class="small text-muted text-end" style="width: 2.5rem;">{{ $count }}</span>
                    </div>
                @endforeach

                @if ($report['rated'] === 0)
                    <p class="text-muted small mb-0">No ratings in this range.</p>
                @endif
            </x-adminlte-card>
        </div>

        <div class="col-lg-7">
            <x-adminlte-card icon="bi bi-people" title="By operator" bodyClass="p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle m-0" style="table-layout: fixed;">
                        <colgroup>
                            <col style="width: 55%;">
                            <col style="width: 20%;">
                            <col style="width: 25%;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col">Operator</th>
                                <th scope="col" class="text-end">Rated</th>
                                <th scope="col" class="text-end">Average</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($report['by_operator'] as $row)
                                <tr>
                                    <td>{{ $row['name'] }}</td>
                                    <td class="text-end">{{ $row['rated'] }}</td>
                                    <td class="text-end">{{ number_format($row['average'], 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-center text-muted py-3">Nothing rated yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-adminlte-card>

            <x-adminlte-card icon="bi bi-diagram-3" title="By department" bodyClass="p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle m-0" style="table-layout: fixed;">
                        <colgroup>
                            <col style="width: 55%;">
                            <col style="width: 20%;">
                            <col style="width: 25%;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col">Department</th>
                                <th scope="col" class="text-end">Rated</th>
                                <th scope="col" class="text-end">Average</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($report['by_department'] as $row)
                                <tr>
                                    <td>{{ $row['department'] }}</td>
                                    <td class="text-end">{{ $row['rated'] }}</td>
                                    <td class="text-end">{{ number_format($row['average'], 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-center text-muted py-3">Nothing rated yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-adminlte-card>
        </div>
    </div>

    <x-adminlte-card icon="bi bi-list-stars" title="Most recent ratings" bodyClass="p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle m-0" style="table-layout: fixed;">
                <colgroup>
                    <col style="width: 30%;">
                    <col style="width: 20%;">
                    <col style="width: 20%;">
                    <col style="width: 15%;">
                    <col style="width: 15%;">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">Customer</th>
                        <th scope="col">Operator</th>
                        <th scope="col">Department</th>
                        <th scope="col">Closed</th>
                        <th scope="col" class="text-end">Rating</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['recent'] as $conversation)
                        <tr>
                            <td>
                                <a href="{{ route('admin.chat.index', ['c' => $conversation->id]) }}">
                                    {{ $conversation->displayName() }}
                                </a>
                            </td>
                            <td>{{ $conversation->assignedOperator?->full_name ?? '—' }}</td>
                            <td>{{ $conversation->department ?? '—' }}</td>
                            <td>{{ ($conversation->closed_at ?? $conversation->created_at)?->format('M j, Y') }}</td>
                            <td class="text-end text-nowrap">
                                <span class="visually-hidden">{{ $conversation->rating }} out of 5</span>
                                @for ($star = 1; $star <= 5; $star++)
                                    <i class="bi bi-star{{ $star <= (int) $conversation->rating ? '-fill text-warning' : ' text-muted' }}"
                                       aria-hidden="true"></i>
                                @endfor
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">No rated conversations in this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-adminlte-card>
@stop
