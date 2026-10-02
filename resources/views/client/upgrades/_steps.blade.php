@php
    $upgradeSteps = [
        1 => 'Plan',
        2 => 'Configure',
        3 => 'Review & Confirm',
    ];
    // The completed step-1 item links back to the plan step; callers with
    // their own plan route (the admin wizard) pass $backRoute to override.
    $backRoute = $backRoute ?? route('client.hosting.upgrade', $order);
@endphp

<nav aria-label="Upgrade steps" class="mb-3">
    <ol class="list-unstyled d-flex flex-wrap align-items-center gap-3 mb-0">
        @foreach ($upgradeSteps as $stepNo => $stepLabel)
            @php
                $state = $stepNo < $step ? 'completed' : ($stepNo === $step ? 'current' : 'upcoming');
            @endphp
            <li class="d-flex align-items-center gap-2">
                @if ($state === 'completed' && $stepNo === 1)
                    <a href="{{ $backRoute }}" class="d-flex align-items-center gap-2 text-decoration-none" aria-label="Back to Plan">
                        <span class="badge bg-success rounded-circle"><i class="bi bi-check-lg"></i></span>
                        <span class="text-success fw-semibold">{{ $stepLabel }}</span>
                    </a>
                @else
                    <span class="badge rounded-circle @if ($state === 'current') bg-primary @elseif ($state === 'completed') bg-success @else bg-body-tertiary text-secondary @endif">
                        @if ($state === 'completed')
                            <i class="bi bi-check-lg"></i>
                        @else
                            {{ $stepNo }}
                        @endif
                    </span>
                    <span @if ($state === 'current') class="fw-bold" aria-current="step" @endif>{{ $stepLabel }}</span>
                @endif
                @if (! $loop->last)
                    <span class="bi bi-chevron-right text-secondary" aria-hidden="true"></span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>