<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin support-ticket management.
 *
 * Thin wrapper over {@see TicketService} — all state changes (reply, close,
 * reopen, assign, internal notes) delegate to the service, which owns the
 * status transition rules. Route contract: routes/admin/support.php
 * (index/create/store/show/reply/note/close/reopen/assign) gated by the
 * tickets.view / tickets.create / tickets.edit / tickets.assign permissions.
 */
class TicketController extends Controller
{
    private const PER_PAGE = 20;

    /** @var array<string, string> status value => label (migration 120050 enum) */
    public const STATUSES = [
        'open' => 'Open',
        'pending' => 'Pending',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ];

    public function __construct(private readonly TicketService $tickets)
    {
    }

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $department = $request->query('department');
        $status = $request->query('status');
        $priority = $request->query('priority');

        $tickets = Ticket::query()
            ->with(['customer.user', 'assignedTo'])
            ->withCount('replies')
            ->when(in_array($department, array_keys(TicketService::DEPARTMENTS), true), function ($query) use ($department) {
                $query->where('department', $department);
            })
            ->when(in_array($status, array_keys(self::STATUSES), true), function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->when(in_array($priority, array_keys(TicketService::PRIORITIES), true), function ($query) use ($priority) {
                $query->where('priority', $priority);
            })
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('ticket_no', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%")
                        ->orWhereHas('customer.user', function ($u) use ($search) {
                            $u->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByDesc('last_reply_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Status counts for the metric mini-cards row.
        $stats = Ticket::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $statuses = self::STATUSES;
        $departments = TicketService::DEPARTMENTS;
        $priorities = TicketService::PRIORITIES;

        return view('admin.tickets.index', compact('tickets', 'search', 'department', 'status', 'priority', 'stats', 'statuses', 'departments', 'priorities'));
    }

    public function create(): View
    {
        $customers = Customer::with('user')->orderBy('id')->get();
        $staff = $this->staffUsers();
        $departments = TicketService::DEPARTMENTS;
        $priorities = TicketService::PRIORITIES;

        return view('admin.tickets.create', compact('customers', 'staff', 'departments', 'priorities'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'subject' => ['required', 'string', 'max:255'],
            'department' => ['required', Rule::in(array_keys(TicketService::DEPARTMENTS))],
            'priority' => ['required', Rule::in(array_keys(TicketService::PRIORITIES))],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'message' => ['required', 'string', 'max:10000'],
        ]);

        try {
            $ticket = $this->tickets->create($validated, $validated['message']);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not create ticket: '.$e->getMessage()]);
        }

        return redirect()
            ->route('admin.tickets.show', $ticket)
            ->with('success', "Ticket {$ticket->ticket_no} created.");
    }

    public function show(Ticket $ticket): View
    {
        $ticket->load(['customer.user', 'assignedTo', 'replies.user']);

        $replies = $ticket->replies;
        $staff = $this->staffUsers();
        $internalPrefix = TicketService::INTERNAL_NOTE_PREFIX;
        $statuses = self::STATUSES;
        $departments = TicketService::DEPARTMENTS;
        $priorities = TicketService::PRIORITIES;

        return view('admin.tickets.show', compact('ticket', 'replies', 'staff', 'internalPrefix', 'statuses', 'departments', 'priorities'));
    }

    /**
     * Add a public reply (moves the ticket to 'pending').
     */
    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:10000'],
        ]);

        try {
            $this->tickets->reply($ticket, $request->user(), $validated['message']);
        } catch (DomainException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.tickets.show', $ticket)
            ->with('success', 'Reply added.');
    }

    /**
     * Add an internal (staff-only) note. Never touches status or the
     * customer-facing timeline.
     */
    public function storeNote(Request $request, Ticket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:5000'],
        ]);

        $this->tickets->addNote($ticket, $request->user(), $validated['note']);

        return redirect()
            ->route('admin.tickets.show', $ticket)
            ->with('success', 'Internal note added.');
    }

    public function close(Ticket $ticket): RedirectResponse
    {
        try {
            $this->tickets->close($ticket);
        } catch (DomainException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.tickets.show', $ticket)
            ->with('success', "Ticket {$ticket->ticket_no} closed.");
    }

    public function reopen(Ticket $ticket): RedirectResponse
    {
        try {
            $this->tickets->reopen($ticket);
        } catch (DomainException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.tickets.show', $ticket)
            ->with('success', "Ticket {$ticket->ticket_no} reopened.");
    }

    /**
     * Reassign a ticket to another staff member (or clear the assignment).
     */
    public function reassign(Request $request, Ticket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $this->tickets->assign($ticket, $validated['assigned_to'] ?? null);

        return redirect()
            ->route('admin.tickets.show', $ticket)
            ->with('success', 'Ticket assignment updated.');
    }

    /**
     * Staff (non-client) users available for assignment.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function staffUsers(): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()
            ->where('role', '!=', 'client')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'email']);
    }
}
