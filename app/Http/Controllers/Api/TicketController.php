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

    private const STATUSES = ['open', 'answered', 'customer_reply', 'on_hold', 'in_progress', 'closed'];

    public function __construct(private readonly TicketService $tickets) {}

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
            ->when(in_array($department, array_keys(TicketService::departments()), true), function ($query) use ($department) {
                $query->where('department', $department);
            })
            ->when(in_array($priority, array_keys(TicketService::PRIORITIES), true), function ($query) use ($priority) {
                $query->where('priority', $priority);
            })
            ->when($user?->role === 'client', function ($query) use ($user) {
                $query->whereHas('customer', fn ($c) => $c->where('user_id', $user->id));
            })
            ->when($user?->role !== 'client', function ($query) use ($user) {
                TicketService::applyVisibility($query, $user);
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
            'department' => ['required', Rule::in(array_keys(TicketService::departments()))],
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
            'replies.attachments',
        ]);

        return response()->json(['data' => $this->present($ticket, true)]);
    }

    /**
     * `to`/`cc`/`bcc`/`html_body`/`attachments` are all optional and additive
     * — a caller sending just `message` (every existing API consumer) keeps
     * working exactly as before. Unlike the admin web form's comma-separated
     * text fields, these are JSON-native: `to`/`cc`/`bcc` are arrays of
     * addresses, individually validated.
     */
    public function reply(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorizeAccess($request, $ticket);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:10000'],
            'html_body' => ['nullable', 'string', 'max:20000'],
            'to' => ['nullable', 'array'],
            'to.*' => ['email'],
            'cc' => ['nullable', 'array'],
            'cc.*' => ['email'],
            'bcc' => ['nullable', 'array'],
            'bcc.*' => ['email'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:25600'],
        ]);

        $attributes = array_filter([
            'to' => $validated['to'] ?? null,
            'cc' => $validated['cc'] ?? null,
            'bcc' => $validated['bcc'] ?? null,
            'html_body' => $validated['html_body'] ?? null,
        ]);

        try {
            $reply = $this->tickets->reply(
                $ticket,
                $request->user(),
                $validated['message'],
                $attributes,
                $request->file('attachments', [])
            );
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->presentReply($reply->fresh('attachments'))], 201);
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

    /**
     * Transfer a ticket to another department (T18 service, mirrored for the
     * API). Staff tokens only, gated by `tickets.transfer` on top of the
     * same visibility scope as `show`; client tokens are always forbidden.
     */
    public function transfer(Request $request, Ticket $ticket): JsonResponse
    {
        $user = $request->user();

        abort_if($user->role === 'client', 403, 'Forbidden.');

        abort_unless(
            TicketService::applyVisibility(Ticket::query(), $user)->whereKey($ticket->id)->exists(),
            403,
            'Forbidden.'
        );

        abort_unless($user->hasPermission('tickets.transfer'), 403, 'Forbidden.');

        $validated = $request->validate([
            'target_department' => ['required', Rule::in(array_keys(TicketService::departments()))],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $ticket = $this->tickets->transferDepartment(
                $ticket,
                $validated['target_department'],
                $user,
                $validated['assigned_to'] ?? null,
                $validated['note'] ?? null
            );
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->present($ticket->load('customer.user:id,email,first_name,last_name')),
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Ticket::query();

        if ($user->role === 'client') {
            $query->whereHas('customer', fn ($c) => $c->where('user_id', $user->id));
        } else {
            TicketService::applyVisibility($query, $user);
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
     * Staff/admin tokens are limited to tickets visible per their
     * department membership (admins bypass, see Ticket::scopeVisibleTo).
     */
    private function authorizeAccess(Request $request, Ticket $ticket): void
    {
        $user = $request->user();

        if ($user->role === 'client') {
            abort_unless($ticket->customer?->user_id === $user->id, 403, 'Forbidden.');

            return;
        }

        abort_unless(
            TicketService::applyVisibility(Ticket::query(), $user)->whereKey($ticket->id)->exists(),
            403,
            'Forbidden.'
        );
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
                ->map(fn (TicketReply $reply) => $this->presentReply($reply));
        }

        return $data;
    }

    /**
     * `to`/`cc`/`bcc` are `null` (not `[]`) when unset, matching how the
     * column itself stores them. `attachments` never includes a download
     * URL — the web attachment routes are session-authenticated
     * (`admin.tickets.attachments.show` / `client.tickets.attachments.show`),
     * a different guard than this Sanctum-token API, so a URL here would
     * either be wrong or need a whole separate token-authenticated download
     * endpoint; out of scope for this pass. Filename/size/mime/is_inline are
     * enough for an API consumer to know a file exists and describe it.
     *
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
            'has_html_body' => $reply->html_body !== null && $reply->html_body !== '',
            'to' => $reply->to,
            'cc' => $reply->cc,
            'bcc' => $reply->bcc,
            'attachments' => $reply->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'filename' => $attachment->filename,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes,
                'is_inline' => $attachment->is_inline,
            ])->all(),
            'created_at' => $reply->created_at?->toIso8601String(),
        ];
    }
}
