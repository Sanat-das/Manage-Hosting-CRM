@php
    // Map flash keys → theme + icon + token colour for accent border
    $flashMap = [
        'success' => ['theme' => 'success', 'icon' => 'bi bi-check-circle-fill', 'token' => 'var(--color-success)'],
        'error'   => ['theme' => 'danger',  'icon' => 'bi bi-exclamation-triangle-fill', 'token' => 'var(--color-danger)'],
        'warning' => ['theme' => 'warning', 'icon' => 'bi bi-exclamation-circle-fill', 'token' => 'var(--color-warning)'],
        'info'    => ['theme' => 'info',    'icon' => 'bi bi-info-circle-fill', 'token' => 'var(--color-info)'],
    ];
    $activeKey = null;
    $activeMessage = null;
    foreach (['success', 'error', 'warning', 'info'] as $k) {
        if (session($k)) { $activeKey = $k; $activeMessage = session($k); break; }
    }
    $active = $activeKey ? $flashMap[$activeKey] : null;
@endphp

@if ($active)
    <div class="alert alert-{{ $active['theme'] }} alert-dismissible fade show mh-flash-alert d-flex align-items-start gap-2"
         role="alert"
         style="border-radius: var(--radius-md); box-shadow: var(--shadow-sm); border-left: 3px solid {{ $active['token'] }}; animation: mh-flash-in var(--duration-base) var(--ease-out);">
        <i class="{{ $active['icon'] }} flex-shrink-0" style="font-size: 1rem; margin-top: 0.1rem; opacity: 0.95;" aria-hidden="true"></i>
        <div class="flex-fill" style="font-size: var(--text-sm); line-height: var(--leading-normal);">
            {{ $activeMessage }}
        </div>
        <button type="button" class="btn-close flex-shrink-0" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif

@if ($errors->any())
    <div class="alert alert-danger alert-dismissible fade show mh-flash-alert d-flex align-items-start gap-2"
         role="alert"
         style="border-radius: var(--radius-md); box-shadow: var(--shadow-sm); border-left: 3px solid var(--color-danger); animation: mh-flash-in var(--duration-base) var(--ease-out);">
        <i class="bi bi-exclamation-triangle-fill flex-shrink-0" style="font-size: 1rem; margin-top: 0.1rem;" aria-hidden="true"></i>
        <div class="flex-fill">
            <ul class="mb-0 ps-3" style="font-size: var(--text-sm); line-height: var(--leading-normal);">
                @foreach ($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
        <button type="button" class="btn-close flex-shrink-0" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif
