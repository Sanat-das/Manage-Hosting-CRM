<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Services\TicketService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sanctum-protected ticket REST API.
 *
 * Mirrors the reference /api/tickets endpoints: index (status / priority /
 * department / search filters), store (customer + subject + department +
 * priority + message), show (with the public reply thread — internal notes
 * are always stripped), reply, close, reopen and stats. Client-role tokens
 * are scoped to their own customer's tickets.
 */
class TicketController extends Controller
{
    private const PER_PAGE = 20;

    private const STATUSES = ['open', 'pending', 'resolved', 'closed'];

    public function __construct(private readonly TicketService $tickets)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');
        $department = $request->query('department');
        $priority = $request->query('priority');

        $tickets = Ticket::query()
            ->with(['customer.user:id,email,first_name,last_name', 'assignedTo:id,first_name,last_name,email'])
            ->withCount('replies')
            ->when(in_array($status, self::STATUSES, true), function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->when(in_array($department, array_keys(TicketService::DEPARTMENTS), true), function ($query) use ($department) {
                $query->where('department', $department);
            })
            ->when(in_array($priority, array_keys(TicketService::PRIORITIES), true), function ($query) use ($priority) {
                $query->where('priority', $priority);
            })
            ->when($user?->role === 'client', function ($query) use ($user) {
                $query->whereHas('customer', fn ($c) => $c->where('user_id', $user->id));
            })
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('ticket_no', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('last_reply_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => $tickets->map(fn (Ticket $ticket) => $this->present($ticket)),
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'per_page' => $tickets->perPage(),
                'total' => $tickets->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $rules = [
            'subject' => ['required', 'string', 'max:255'],
            'department' => ['required', Rule::in(array_keys(TicketService::DEPARTMENTS))],
            'priority' => ['required', Rule::in(array_keys(TicketService::PRIORITIES))],
            'message' => ['required', 'string', 'max:10000'],
        ];

        if ($user->role === 'client') {
            $customer = Customer::where('user_id', $user->id)->first();

            if ($customer === null) {
                return response()->json(['error' => 'No customer profile is linked to this account.'], 422);
            }

            $data = ['customer_id' => $customer->id] + $request->validate($rules);
        } else {
            $rules['customer_id'] = ['required', 'integer', 'exists:customers,id'];
            $data = $request->validate($rules);
        }

        $ticket = $this->tickets->create($data, $data['message']);

        return response()->json([
            'data' => $this->present($ticket->load('customer.user:id,email,first_name,last_name')),
        ], 201);
    }

    public function show(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorizeAccess($request, $ticket);

        $ticket->load([
            'customer.user:id,email,first_name,last_name',
            'assignedTo:id,first_name,last_name,email',
            'replies.user:id,first_name,last_name,role',
        ]);

        return response()->json(['data' => $this->present($ticket, true)]);
    }

    public function reply(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorizeAccess($request, $ticket);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:10000'],
        ]);

        try {
            $reply = $this->tickets->reply($ticket, $request->user(), $validated['message']);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->presentReply($reply)], 201);
    }

    public function close(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorizeAccess($request, $ticket);

        try {
            $this->tickets->close($ticket);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Ticket closed.']);
    }

    public function reopen(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorizeAccess($request, $ticket);

        try {
            $this->tickets->reopen($ticket);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Ticket reopened.']);
    }

    public function stats(Request $request): JsonResponse
    {
        $query = Ticket::query();

        if ($request->user()->role === 'client') {
            $query->whereHas('customer', fn ($c) => $c->where('user_id', $request->user()->id));
        }

        return response()->json([
            'data' => $query
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status'),
        ]);
    }

    /**
     * Client-role tokens may only touch their own customer's tickets.
     */
    private function authorizeAccess(Request $request, Ticket $ticket): void
    {
        $user = $request->user();

        if ($user->role === 'client' && $ticket->customer?->user_id !== $user->id) {
            abort(403, 'Forbidden.');
        }
    }

    /**
     * API resource shape.
     *
     * @return array<string, mixed>
     */
    private function present(Ticket $ticket, bool $detailed = false): array
    {
        $data = [
            'id' => $ticket->id,
            'ticket_no' => $ticket->ticket_no,
            'customer' => $ticket->customer !== null ? [
                'id' => $ticket->customer->id,
                'display_id' => $ticket->customer->display_id,
                'name' => $ticket->customer->full_name,
                'email' => $ticket->customer->user?->email,
            ] : null,
            'subject' => $ticket->subject,
            'department' => $ticket->department,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'assigned_to' => $ticket->assignedTo !== null ? [
                'id' => $ticket->assignedTo->id,
                'name' => $ticket->assignedTo->full_name,
            ] : null,
            'message_count' => $ticket->replies_count ?? $ticket->replies->count(),
            'last_reply_at' => $ticket->last_reply_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['replies'] = $ticket->replies
                ->reject(fn (TicketReply $reply) => str_starts_with($reply->message, TicketService::INTERNAL_NOTE_PREFIX))
                ->values()
                ->map(fn (TicketReply $reply) => [
                    'id' => $reply->id,
                    'user_id' => $reply->user_id,
                    'user_name' => $reply->user?->full_name ?? 'Unknown',
                    'role' => $reply->user?->role,
                    'is_staff' => $reply->is_staff,
                    'message' => $reply->message,
                    'created_at' => $reply->created_at?->toIso8601String(),
                ]);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentReply(TicketReply $reply): array
    {
        return [
            'id' => $reply->id,
            'ticket_id' => $reply->ticket_id,
            'user_id' => $reply->user_id,
            'is_staff' => $reply->is_staff,
            'message' => $reply->message,
            'created_at' => $reply->created_at?->toIso8601String(),
        ];
    }
}
