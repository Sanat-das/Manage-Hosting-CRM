@props(['orders' => []])

@php
    $value = fn ($row, $key, $default = null) => data_get($row, $key, $default);
    $orderId = fn ($order) => is_array($order) ? ($order['id'] ?? null) : ($order->id ?? null);
    $customerName = fn ($order) => $value($order, 'customer_name') ?? $value($order, 'customer.full_name') ?? '—';
    $fmtDate = function ($date) {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('M j, Y');
        }
        if (is_string($date) && $date !== '') {
            return substr($date, 0, 10);
        }
        return '—';
    };
@endphp

@if (empty($orders))
    <p class="text-muted mb-0">No recent orders.</p>
@else
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Customer</th>
                    <th>Status</th>
                    <th class="text-end">Total</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($orders as $order)
                    <tr>
                        <td>
                            @if (Route::has('admin.orders.show') && $orderId($order))
                                <a href="{{ route('admin.orders.show', $orderId($order)) }}"><strong>{{ $value($order, 'order_no', '—') }}</strong></a>
                            @else
                                <strong>{{ $value($order, 'order_no', '—') }}</strong>
                            @endif
                        </td>
                        <td>{{ $customerName($order) }}</td>
                        <td><x-adminlte.partials.status-badge :status="$value($order, 'status')" /></td>
                        <td class="text-end">₹{{ number_format((float) $value($order, 'total', 0), 2) }}</td>
                        <td class="text-muted">{{ $fmtDate($value($order, 'created_at', $value($order, 'date'))) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-2 text-end">
        @if (Route::has('admin.orders.index'))
            <a href="{{ route('admin.orders.index') }}" class="small">View all orders <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        @endif
    </div>
@endif
