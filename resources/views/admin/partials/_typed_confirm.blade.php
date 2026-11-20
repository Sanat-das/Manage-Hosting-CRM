@php
    /**
     * Blast-radius body for a destructive confirmation modal.
     *
     * @var string $name            Object being deleted (typed confirmation target)
     * @var array<string, int> $counts Label => affected row count
     * @var int $threshold       Sum of counts at/above which typing the name is required (default 6)
     */
    $counts = $counts ?? [];
    $threshold = (int) ($threshold ?? 6);
    $total = array_sum(array_map('intval', $counts));
    $requiresTyped = $total >= $threshold;
@endphp

@if ($total === 0)
    <div class="alert alert-success py-2 mb-0" role="alert">
        <i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i> No dependent rows — safe to delete.
    </div>
@else
    <div class="alert alert-warning py-2 mb-2" role="alert">
        <div class="fw-semibold mb-1">
            <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>This will affect {{ $total }} {{ $total === 1 ? 'row' : 'rows' }}:
        </div>
        <ul class="mb-0 ps-4">
            @foreach ($counts as $label => $count)
                <li>{{ $label }}: <strong>{{ (int) $count }}</strong></li>
            @endforeach
        </ul>
    </div>
    @if ($requiresTyped)
        <div class="typed-confirm">
            <label class="form-label small fw-semibold mb-1">Type <code>{{ $name }}</code> to confirm</label>
            <input type="text" class="form-control form-control-sm typed-confirm-input" data-expected="{{ $name }}"
                   autocomplete="off" spellcheck="false" placeholder="{{ $name }}" aria-label="Type {{ $name }} to confirm">
        </div>
    @endif
@endif

@once
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.typed-confirm-input').forEach(function (input) {
        var content = input.closest('.modal-content');
        var submit = content ? content.querySelector('.modal-footer button[type="submit"]') : null;
        if (!submit) { return; }
        var expected = (input.getAttribute('data-expected') || '').trim();
        var sync = function () { submit.disabled = input.value.trim() !== expected; };
        input.addEventListener('input', sync);
        sync();
        var modal = input.closest('.modal');
        if (modal) {
            modal.addEventListener('hidden.bs.modal', function () { input.value = ''; sync(); });
        }
    });
});
</script>
@endonce
