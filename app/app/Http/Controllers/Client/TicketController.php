<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Client portal — support ticket listing, detail, reply.
 */
class TicketController extends Controller
{
    public function index(Request $request): View
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $status = $request->query('status');

        $tickets = $customer->tickets()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('client.tickets.index', compact('tickets', 'status'));
    }

    public function create(): View
    {
        return view('client.tickets.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'priority' => ['sometimes', 'string', 'in:low,medium,high,urgent'],
            'department' => ['nullable', 'string', 'max:100'],
        ]);

        $ticket = Ticket::create([
            'ticket_no' => 'T-'.str_pad((string) (Ticket::max('id') + 1), 6, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'subject' => $validated['subject'],
            'priority' => $validated['priority'] ?? 'medium',
            'status' => 'open',
            'department' => $validated['department'] ?? null,
        ]);

        if (! empty($validated['message'])) {
            $ticket->replies()->create([
                'user_id' => $request->user()->id,
                'message' => $validated['message'],
                'is_staff' => false,
            ]);
        }

        return redirect()
            ->route('client.tickets.show', $ticket)
            ->with('success', 'Ticket created.');
    }

    public function show(Request $request, int $id): View
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $ticket = $customer->tickets()
            ->with(['replies.user', 'assignedTo'])
            ->findOrFail($id);

        return view('client.tickets.show', compact('ticket'));
    }

    public function reply(Request $request, int $id): RedirectResponse
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $ticket = $customer->tickets()->findOrFail($id);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $ticket->replies()->create([
            'user_id' => $request->user()->id,
            'message' => $validated['message'],
            'is_staff' => false,
        ]);

        $ticket->update(['last_reply_at' => now()]);

        return redirect()
            ->route('client.tickets.show', $ticket)
            ->with('success', 'Reply sent.');
    }
}
