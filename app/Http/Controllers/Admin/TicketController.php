<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketReply;
use App\Models\User;
use App\Models\UserGridFilter;
use App\Services\TicketMailService;
use App\Services\TicketService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /** @var array<string, string> status value => label (WHMCS-style enum, migration 2026_09_01_000001) */
    public const STATUSES = [
        'open' => 'Open',
        'answered' => 'Answered',
        'customer_reply' => 'Customer-Reply',
        'on_hold' => 'On Hold',
        'in_progress' => 'In Progress',
        'closed' => 'Closed',
    ];

    public function __construct(
        private readonly TicketService $tickets,
        private readonly TicketMailService $mail
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $gridKey = 'admin.tickets.index';
        $user = $request->user();
        $saved = UserGridFilter::where('user_id', $user->id)->where('grid_key', $gridKey)->first();
        $savedFilters = is_array($saved?->filters) ? $saved->filters : [];

        // Explicit reset — clear saved and show unfiltered grid.
        if ($request->boolean('reset')) {
            $saved?->delete();

            return redirect()->route('admin.tickets.index');
        }

        $hasFiltersInQuery = $request->query('search') !== null
            || $request->query('department') !== null
            || $request->query('status') !== null
            || $request->query('priority') !== null
            || $request->query('sort') !== null
            || $request->query('direction') !== null;

        if (! $hasFiltersInQuery && $savedFilters !== []) {
            $search = trim((string) ($savedFilters['search'] ?? ''));
            $department = $savedFilters['department'] ?? null;
            $status = array_values(array_intersect((array) ($savedFilters['status'] ?? []), array_keys(self::STATUSES)));
            $priority = $savedFilters['priority'] ?? null;

            if (! empty($savedFilters['sort'])) {
                $request->query->set('sort', $savedFilters['sort']);
                $request->query->set('direction', $savedFilters['direction'] ?? 'asc');
            }
            // Inject restored filters into the query bag so gridSort + withQueryString + form values see them.
            if ($search !== '') {
                $request->query->set('search', $search);
            }
            if ($department !== null && $department !== '') {
                $request->query->set('department', $department);
            }
            if ($status !== []) {
                $request->query->set('status', $status);
            }
            if ($priority !== null && $priority !== '') {
                $request->query->set('priority', $priority);
            }
        } else {
            $search = trim((string) $request->query('search'));
            $department = $request->query('department');
            $status = array_values(array_intersect((array) $request->query('status', []), array_keys(self::STATUSES)));
            $priority = $request->query('priority');
        }

        // Persist the current filter set for this user (auto-restore next visit).
        if ($hasFiltersInQuery) {
            $toPersist = array_filter([
                'search' => $search !== '' ? $search : null,
                'department' => is_string($department) && in_array($department, array_keys(TicketService::departments()), true) ? $department : null,
                'status' => $status !== [] ? $status : null,
                'priority' => is_string($priority) && in_array($priority, array_keys(TicketService::PRIORITIES), true) ? $priority : null,
                'sort' => is_string($request->query('sort')) && trim((string) $request->query('sort')) !== '' ? trim((string) $request->query('sort')) : null,
                'direction' => is_string($request->query('direction')) && in_array(strtolower(trim((string) $request->query('direction'))), ['asc', 'desc'], true) ? strtolower(trim((string) $request->query('direction'))) : null,
            ], fn ($v) => $v !== null);

            if ($toPersist === []) {
                $saved?->delete();
            } else {
                UserGridFilter::updateOrCreate(
                    ['user_id' => $user->id, 'grid_key' => $gridKey],
                    ['filters' => $toPersist]
                );
            }
        }

        $query = Ticket::query();
        TicketService::applyVisibility($query, $request->user());

        $tickets = $query
            ->with(['customer.user', 'assignedTo'])
            ->withCount('replies')
            ->when(in_array($department, array_keys(TicketService::departments()), true), function ($query) use ($department) {
                $query->where('department', $department);
            })
            ->when($status !== [], function ($query) use ($status) {
                $query->whereIn('status', $status);
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
            ->gridSort([
                'ticket_no' => 'ticket_no',
                'subject' => 'subject',
                'customer' => fn (Builder $q, string $dir) => $q->orderBy(Customer::select('company')->whereColumn('customers.id', 'tickets.customer_id'), $dir),
                'department' => 'department',
                'priority' => 'priority',
                'status' => 'status',
                'last_reply_at' => 'last_reply_at',
                'created_at' => 'created_at',
                'replies_count' => 'replies_count',
            ])
            ->orderByDesc('last_reply_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Status counts for the metric mini-cards row, scoped the same way.
        $statsQuery = Ticket::query();
        TicketService::applyVisibility($statsQuery, $request->user());

        $stats = $statsQuery
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $statuses = self::STATUSES;
        $departments = TicketService::departments();
        $priorities = TicketService::PRIORITIES;

        return view('admin.tickets.index', compact('tickets', 'search', 'department', 'status', 'priority', 'stats', 'statuses', 'departments', 'priorities'));
    }

    public function create(Request $request): View
    {
        // Preselect the customer when arriving from the customer profile
        // ("New Ticket" quick action): flash the query value so old() picks it up.
        if ($customerId = $request->query('customer_id')) {
            $request->flashOnly('customer_id');
        }

        $customers = Customer::with('user')->orderBy('id')->get();
        $staff = $this->staffUsers();
        $departments = TicketService::departments();
        $priorities = TicketService::PRIORITIES;

        return view('admin.tickets.create', compact('customers', 'staff', 'departments', 'priorities'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'subject' => ['required', 'string', 'max:255'],
            'department' => ['required', Rule::in(array_keys(TicketService::departments()))],
            'priority' => ['required', Rule::in(array_keys(TicketService::PRIORITIES))],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'message' => ['required', 'string', 'max:10000'],
            'html_body' => ['nullable', 'string', 'max:20000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:25600'],
        ]);

        if ($validated['assigned_to'] ?? null) {
            $assignee = User::find($validated['assigned_to']);
            if ($assignee === null || ! $this->tickets->isStaff($assignee) || ! $this->tickets->isInDepartment($assignee, $validated['department'])) {
                return back()->withInput()->withErrors(['assigned_to' => 'Selected assignee is not a member of this department.']);
            }
        }

        $replyAttributes = array_filter([
            'html_body' => $validated['html_body'] ?? null,
            'is_staff' => true,
            'user_id' => $request->user()?->id,
        ]);

        try {
            $ticket = $this->tickets->create($validated, $validated['message'], $replyAttributes, $request->file('attachments', []));
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not create ticket: '.$e->getMessage()]);
        }

        return redirect()
            ->route('admin.tickets.show', $ticket)
            ->with('success', "Ticket {$ticket->ticket_no} created.");
    }

    public function show(Request $request, Ticket $ticket): View
    {
        abort_unless(
            TicketService::applyVisibility(Ticket::query(), $request->user())->whereKey($ticket->id)->exists(),
            403,
            'Forbidden.'
        );

        $ticket->load(['customer.user', 'assignedTo', 'replies.user', 'transfers.actor']);

        $replies = $ticket->replies;
        $staff = $this->staffUsers();
        $internalPrefix = TicketService::INTERNAL_NOTE_PREFIX;
        $statuses = self::STATUSES;
        $departments = TicketService::departments();
        $priorities = TicketService::PRIORITIES;
        $customers = Customer::with('user')->orderBy('id')->get();
        $defaultReplyTo = $this->mail->recipientFor($ticket);
        $defaultCc = implode(', ', $this->fallbackCcFor($ticket));
        $defaultBcc = implode(', ', $this->fallbackBccFor($ticket));

        // Customer products/services for the right-sidebar "Products" card.
        // Primary source is ServiceInstance (modern catalog-driven services);
        // if empty, fall back to legacy Orders, then HostingAccounts.
        $customerServices = collect();
        $customerOrders = collect();
        $customerHostingAccounts = collect();

        if ($ticket->customer_id !== null && $ticket->customer) {
            if (class_exists(\App\Models\ServiceInstance::class)) {
                $customerServices = \App\Models\ServiceInstance::with(['catalogProduct.category', 'server'])
                    ->where('customer_id', $ticket->customer_id)
                    ->orderByDesc('id')
                    ->limit(10)
                    ->get();
            }

            if ($customerServices->isEmpty()) {
                $customerOrders = $ticket->customer->orders()
                    ->with(['product', 'items.product'])
                    ->latest()
                    ->limit(5)
                    ->get();
            }

            if ($customerServices->isEmpty() && $customerOrders->isEmpty()) {
                $customerHostingAccounts = $ticket->customer->hostingAccounts()
                    ->with(['product', 'server'])
                    ->limit(5)
                    ->get();
            }
        }

        return view('admin.tickets.show', compact('ticket', 'replies', 'staff', 'internalPrefix', 'statuses', 'departments', 'priorities', 'customers', 'defaultReplyTo', 'defaultCc', 'defaultBcc', 'customerServices', 'customerOrders', 'customerHostingAccounts'));
    }

    /**
     * Search contacts for autocomplete in the ticket reply form.
     *
     * Scoped strictly to the ticket's customer_id — a staff member must not be
     * able to enumerate all customers' contacts. Mirrors the visibility guard
     * used in show() / reply().
     */
    public function searchContacts(Request $request, Ticket $ticket): JsonResponse
    {
        abort_unless(
            TicketService::applyVisibility(Ticket::query(), $request->user())->whereKey($ticket->id)->exists(),
            403,
            'Forbidden.'
        );

        if ($ticket->customer_id === null) {
            return response()->json([]);
        }

        $ticket->loadMissing(['customer.user', 'customer.contacts']);

        $customer = $ticket->customer;
        $q = trim((string) $request->query('q', ''));
        $qLower = strtolower($q);

        $results = [];
        $seen = [];

        $addEntry = function (?string $email, ?string $name, ?string $role = null) use (&$results, &$seen): void {
            if ($email === null || trim($email) === '') {
                return;
            }
            $email = trim($email);
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            $lower = strtolower($email);
            if (isset($seen[$lower])) {
                return;
            }
            $seen[$lower] = true;
            $name = trim((string) $name);
            $role = trim((string) $role);
            $label = $name !== '' ? $name.' <'.$email.'>' : $email;
            if ($role !== '') {
                $label .= ' — '.$role;
            }
            $results[] = [
                'email' => $email,
                'name' => $name,
                'label' => $label,
                'role' => $role !== '' ? $role : null,
            ];
        };

        // Primary customer user email — considered first so it appears at top when relevant.
        $primaryEmail = $customer->user?->email;
        $primaryName = null;
        if ($customer->user) {
            $primaryName = trim((string) ($customer->user->full_name ?? trim(($customer->user->first_name ?? '').' '.($customer->user->last_name ?? ''))));
        }
        if ($primaryName === '') {
            $primaryName = trim((string) $customer->full_name);
        }

        // Primary user has no contact role — keep role null so "Client"/"Admin" system role is not shown as contact role.
        $primaryRole = null;

        $shouldIncludePrimary = false;
        if ($primaryEmail) {
            if ($q === '') {
                $shouldIncludePrimary = true;
            } else {
                $nameLower = strtolower((string) $primaryName);
                $emailLower = strtolower((string) $primaryEmail);
                if (str_contains($emailLower, $qLower) || ($nameLower !== '' && str_contains($nameLower, $qLower))) {
                    $shouldIncludePrimary = true;
                }
            }
        }

        if ($shouldIncludePrimary) {
            $addEntry($primaryEmail, $primaryName, $primaryRole);
        }

        // Build contacts query.
        $contactsQuery = $customer->contacts()
            ->orderByDesc('is_primary')
            ->orderBy('first_name')
            ->orderBy('last_name');

        if ($q !== '') {
            $contactsQuery->where(function ($qb) use ($qLower) {
                $qb->whereRaw('LOWER(email) LIKE ?', ["%{$qLower}%"])
                    ->orWhereRaw('LOWER(first_name) LIKE ?', ["%{$qLower}%"])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', ["%{$qLower}%"])
                    ->orWhereRaw("LOWER(CONCAT(first_name, ' ', last_name)) LIKE ?", ["%{$qLower}%"])
                    ->orWhereRaw("LOWER(CONCAT(first_name, last_name)) LIKE ?", ["%{$qLower}%"])
                    ->orWhereRaw('LOWER(role) LIKE ?', ["%{$qLower}%"]);
            });
            $contactsQuery->limit(20);
        } else {
            $contactsQuery->limit(10);
        }

        $contacts = $contactsQuery->get(['email', 'first_name', 'last_name', 'is_primary', 'role']);

        foreach ($contacts as $contact) {
            if (count($results) >= 20) {
                break;
            }
            $contactName = trim((string) (($contact->first_name ?? '').' '.($contact->last_name ?? '')));
            $contactName = trim($contactName);
            // Exclude empty emails and let addEntry handle validation/dedup.
            $addEntry($contact->email, $contactName, $contact->role ?? null);
        }

        // Enforce final cap of 20.
        $results = array_slice($results, 0, 20);

        return response()->json($results);
    }

    /**
     * Stream a reply's attachment. Scoped through `$ticket` in the route (not
     * just the attachment's own id) and re-checked here against the same
     * visibility rule `show()` uses — a staff member scoped to one department
     * must not be able to fetch a file by guessing/incrementing another
     * department's ticket id in the URL.
     */
    public function showAttachment(Request $request, Ticket $ticket, TicketAttachment $attachment): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless(
            TicketService::applyVisibility(Ticket::query(), $request->user())->whereKey($ticket->id)->exists(),
            403,
            'Forbidden.'
        );

        abort_unless($attachment->reply?->ticket_id === $ticket->id, 404);

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404, 'Attachment file is missing.');

        $mime = $attachment->mime_type ?: 'application/octet-stream';
        $forceDownload = $request->boolean('download');

        // Modern email client behavior: preview inline when the browser can
        // render it (images, PDFs, text), otherwise fall back to a download.
        // `?download=1` always forces the Content-Disposition to attachment.
        if (! $forceDownload && $this->isInlinePreviewable($mime)) {
            $content = $disk->get($attachment->path);
            $size = $disk->size($attachment->path);

            return response($content, 200, [
                'Content-Type' => $mime,
                'Content-Disposition' => 'inline; filename="'.$this->sanitizeFilename($attachment->filename).'"',
                'Content-Length' => (string) $size,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=300',
            ]);
        }

        return $disk->download($attachment->path, $attachment->filename, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function isInlinePreviewable(string $mime): bool
    {
        $mime = strtolower(trim($mime));

        // SVG and HTML both carry script — never render either inline. Serving
        // attacker-supplied markup from our own origin with `Content-Type:
        // text/html` runs it in the viewing staff member's session, and
        // `nosniff` cannot help when the declared type IS html.
        if ($mime === 'image/svg+xml' || $mime === 'text/html' || $mime === 'application/xhtml+xml') {
            return false;
        }

        if (str_starts_with($mime, 'image/')) {
            return true;
        }

        return in_array($mime, [
            'application/pdf',
            'text/plain',
            'text/csv',
        ], true);
    }

    private function sanitizeFilename(string $filename): string
    {
        return addcslashes($filename, '"\\');
    }

    /**
     * "Show original" — admin-only (see TicketMailService::originalSourceFor()
     * doc block for why a staff reply is reconstructed rather than verbatim,
     * and why a portal-submitted customer reply has nothing to show at all).
     */
    public function showOriginal(Request $request, Ticket $ticket, TicketReply $reply): View
    {
        abort_unless(
            TicketService::applyVisibility(Ticket::query(), $request->user())->whereKey($ticket->id)->exists(),
            403,
            'Forbidden.'
        );

        abort_unless($reply->ticket_id === $ticket->id, 404);

        $original = $this->mail->originalSourceFor($ticket, $reply);

        abort_if($original === null, 404, 'No original source is available for this message.');

        return view('admin.tickets.original', [
            'ticket' => $ticket,
            'source' => $original['source'],
            'isRaw' => $original['isRaw'],
        ]);
    }

    public function linkGuest(Request $request, Ticket $ticket): RedirectResponse
    {
        abort_unless($ticket->isGuest(), 422, 'Ticket is not a guest ticket.');
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'create_contact' => ['sometimes', 'boolean'],
        ]);
        $customer = Customer::findOrFail($validated['customer_id']);
        try {
            $this->tickets->linkGuestToCustomer($ticket, $customer, $request->boolean('create_contact'));
        } catch (DomainException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
        return redirect()->route('admin.tickets.show', $ticket)->with('success', "Guest ticket linked to {$customer->full_name}." . ($request->boolean('create_contact') ? ' Contact created.' : ''));
    }

    public function addGuestAsContact(Request $request, Ticket $ticket): RedirectResponse
    {
        abort_unless($ticket->isGuest(), 422, 'Ticket is not a guest ticket.');
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255'],
        ]);
        $customer = Customer::findOrFail($validated['customer_id']);
        try {
            $contact = $this->tickets->addGuestAsContact($ticket, $customer, $validated);
        } catch (DomainException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
        return redirect()->route('admin.tickets.show', $ticket)->with('success', "Guest added as contact ({$contact->email}) to {$customer->full_name}.");
    }

    /**
     * Add a public reply (moves the ticket to 'pending').
     *
     * To/Cc/Bcc/HTML body/attachments are all optional — a plain-text reply
     * with none of them behaves exactly as before (`TicketMailService::
     * recipientFor()` still supplies `to` when the form leaves it blank).
     * Comma-separated address lists match how a mail client's To/Cc/Bcc
     * fields are typically typed; each address is validated individually so
     * one typo does not sink the whole list silently.
     */
    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:10000'],
            'html_body' => ['nullable', 'string', 'max:20000'],
            'to' => ['nullable', 'string', 'max:1000'],
            'cc' => ['nullable', 'string', 'max:1000'],
            'bcc' => ['nullable', 'string', 'max:1000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:25600'],
        ]);

        $attributes = array_filter([
            'to' => $this->addressList($validated['to'] ?? null, single: false),
            'cc' => $this->addressList($validated['cc'] ?? null),
            'bcc' => $this->addressList($validated['bcc'] ?? null),
            'html_body' => $validated['html_body'] ?? null,
        ]);

        try {
            $this->tickets->reply(
                $ticket,
                $request->user(),
                $validated['message'],
                $attributes,
                $request->file('attachments', [])
            );
        } catch (DomainException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.tickets.show', $ticket)
            ->with('success', 'Reply added.');
    }

    /**
     * Comma/semicolon-separated addresses from a form field into a clean
     * list, dropping blanks and anything that isn't a plausible email — a
     * stray comma or trailing separator must not become an empty recipient.
     *
     * @return list<string>|null
     */
    private function addressList(?string $raw, bool $single = false): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $addresses = array_values(array_filter(array_map(
            fn ($a) => filter_var(trim($a), FILTER_VALIDATE_EMAIL) ? strtolower(trim($a)) : null,
            preg_split('/[,;]+/', $raw) ?: []
        )));

        if ($addresses === []) {
            return null;
        }

        return $single ? [$addresses[0]] : $addresses;
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
     * Manually set On Hold / In Progress — the two staff-only statuses
     * TicketService never assigns automatically.
     */
    public function updateStatus(Request $request, Ticket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(TicketService::MANUAL_STATUSES)],
        ]);

        try {
            $this->tickets->setStatus($ticket, $validated['status']);
        } catch (DomainException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.tickets.show', $ticket)
            ->with('success', 'Ticket status updated to '.self::STATUSES[$validated['status']].'.');
    }

    /**
     * Reassign a ticket to another staff member (or clear the assignment).
     */
    public function reassign(Request $request, Ticket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        try {
            $this->tickets->assign($ticket, $validated['assigned_to'] ?? null);
        } catch (DomainException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.tickets.show', $ticket)
            ->with('success', 'Ticket assignment updated.');
    }

    /**
     * Transfer a ticket to another department (T7 service). Gated by
     * `tickets.transfer` and the same visibility scope as `show`.
     */
    public function transfer(Request $request, Ticket $ticket): RedirectResponse
    {
        abort_unless(
            TicketService::applyVisibility(Ticket::query(), $request->user())->whereKey($ticket->id)->exists(),
            403,
            'Forbidden.'
        );

        $validated = $request->validate([
            'target_department' => ['required', Rule::in(array_keys(TicketService::departments()))],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->tickets->transferDepartment(
                $ticket,
                $validated['target_department'],
                $request->user(),
                $validated['assigned_to'] ?? null,
                $validated['note'] ?? null
            );
        } catch (DomainException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.tickets.show', $ticket)
            ->with('success', 'Ticket transferred.');
    }

    /**
     * Distinct CC emails from all replies for this ticket, deduplicated
     * and excluding the primary To — mirrors TicketMailService::fallbackCcFor
     * so the compose form prefills the same recipients the send path would
     * fallback to.
     *
     * @return list<string>
     */
    private function fallbackCcFor(Ticket $ticket): array
    {
        return $this->fallbackAddressesFor($ticket, 'cc');
    }

    /**
     * Distinct BCC emails from all replies for this ticket, deduplicated
     * and excluding the primary To — mirrors TicketMailService::fallbackBccFor.
     *
     * @return list<string>
     */
    private function fallbackBccFor(Ticket $ticket): array
    {
        return $this->fallbackAddressesFor($ticket, 'bcc');
    }

    /**
     * Generic helper for fallback CC/BCC collection.
     *
     * @return list<string>
     */
    private function fallbackAddressesFor(Ticket $ticket, string $field): array
    {
        $toLower = strtolower((string) $this->mail->recipientFor($ticket));

        $rows = $ticket->replies()->whereNotNull($field)->get([$field]);

        $emails = [];
        foreach ($rows as $row) {
            $value = $row->{$field};
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : [];
            }
            if (! is_array($value)) {
                continue;
            }
            foreach ($value as $address) {
                if (! is_string($address)) {
                    continue;
                }
                $address = trim($address);
                if ($address === '' || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $lower = strtolower($address);
                if ($lower === $toLower) {
                    continue;
                }
                $emails[$lower] = $address;
            }
        }

        return array_values($emails);
    }

    /**
     * Staff (non-client) users available for assignment.
     *
     * @return Collection<int, User>
     */
    private function staffUsers(): Collection
    {
        return User::query()
            ->where('role', '!=', 'client')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'email']);
    }
}
