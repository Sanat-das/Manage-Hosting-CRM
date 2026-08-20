@props(['tickets' => []])

@php
    $value = fn ($row, $key, $default = null) => data_get($row, $key, $default);
    $ticketId = fn ($ticket) => is_array($ticket) ? ($ticket['id'] ?? null) : ($ticket->id ?? null);
    $priorityColors = ['low' => 'secondary', 'medium' => 'warning', 'high' => 'danger', 'urgent' => 'dark'];
@endphp

@if (empty($tickets))
    <p class="text-muted mb-0">No open tickets.</p>
@else
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Ticket</th>
                    <th>Subject</th>
                    <th>Priority</th>
                    <th>Customer</th>
                    <th>Last reply</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tickets as $ticket)
                    @php
                        $priority = $value($ticket, 'priority');
                        $priorityColor = $value($ticket, 'priority_color', $priorityColors[$priority] ?? 'secondary');
                        $lastReply = $value($ticket, 'last_reply_at', $value($ticket, 'last_reply'));
                    @endphp
                    <tr>
                        <td>
                            @if (Route::has('admin.tickets.show') && $ticketId($ticket))
                                <a href="{{ route('admin.tickets.show', $ticketId($ticket)) }}"><strong>{{ $value($ticket, 'ticket_no', '—') }}</strong></a>
                            @else
                                <strong>{{ $value($ticket, 'ticket_no', '—') }}</strong>
                            @endif
                        </td>
                        <td class="text-muted">{{ Str::limit($value($ticket, 'subject', '—'), 40) }}</td>
                        <td><span class="badge bg-{{ $priorityColor }}">{{ ucfirst($priority ?? '—') }}</span></td>
                        <td>{{ $value($ticket, 'customer_name') ?? $value($ticket, 'customer.full_name') ?? '—' }}</td>
                        <td class="text-muted">
                            @if (is_object($lastReply) && method_exists($lastReply, 'diffForHumans'))
                                {{ $lastReply->diffForHumans() }}
                            @elseif ($lastReply)
                                {{ $lastReply }}
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-2 text-end">
        @if (Route::has('admin.tickets.index'))
            <a href="{{ route('admin.tickets.index') }}" class="small">View all tickets <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        @endif
    </div>
@endif
