@props(['available' => []])

<x-adminlte-modal id="dashboardWidgetPicker" title="Add Widget" size="lg">
    @if (empty($available))
        <p class="text-muted mb-0">All available widgets are already on your dashboard.</p>
    @else
        <ul class="list-group">
            @foreach ($available as $widget)
                <li class="list-group-item d-flex align-items-center gap-3">
                    <i class="bi {{ $widget['icon'] ?? 'bi-grid' }} fs-5 text-primary" aria-hidden="true"></i>
                    <div class="flex-grow-1">
                        <strong>{{ $widget['title'] ?? '—' }}</strong>
                        @if (!empty($widget['description']))
                            <div class="text-muted small">{{ $widget['description'] }}</div>
                        @endif
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-dashboard-add
                            data-key="{{ $widget['key'] ?? '' }}">Add</button>
                </li>
            @endforeach
        </ul>
    @endif
</x-adminlte-modal>
