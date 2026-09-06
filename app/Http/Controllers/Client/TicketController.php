<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\TicketAttachment;
use App\Models\TicketReply;
use App\Services\TicketService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Client portal — support ticket listing, detail, reply.
 */
class TicketController extends Controller
{
    /**
     * Statuses the portal's status filter accepts. Mirrors the WHMCS-style
     * enum in the tickets table (migration 2026_09_01_000001).
     *
     * @var list<string>
     */
    private const FILTERABLE_STATUSES = [
        'open', 'answered', 'customer_reply', 'on_hold', 'in_progress', 'closed',
    ];

    public function __construct(private readonly TicketService $tickets) {}

    public function index(Request $request): View
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        // Only a real status filters anything — an unknown value used to be
        // passed straight into the where and silently returned an empty list,
        // which reads as "you have no tickets" rather than "bad filter".
        $status = $request->query('status');
        $status = is_string($status) && in_array($status, self::FILTERABLE_STATUSES, true) ? $status : null;

        $search = trim((string) $request->query('search'));

        $tickets = $customer->tickets()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->where(function ($inner) use ($search) {
                $inner->where('ticket_no', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%");
            }))
            ->gridSort([
                'ticket_no' => 'ticket_no',
                'subject' => 'subject',
                'department' => 'department',
                'priority' => 'priority',
                'status' => 'status',
            ])
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('client.tickets.index', compact('tickets', 'status', 'search'));
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
            'department' => ['required', Rule::in(array_keys(TicketService::departments()))],
        ]);

        $ticket = $this->tickets->create([
            'customer_id' => $customer->id,
            'subject' => $validated['subject'],
            'department' => $validated['department'],
            'priority' => $validated['priority'] ?? 'medium',
            'assigned_to' => null,
        ], $validated['message']);

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

        // Staff internal notes are not a column — TicketService::addNote stores
        // them as ordinary reply rows distinguished only by a message prefix.
        // Nothing downstream filters them, so strip them here before the view
        // ever sees them, mirroring Api\TicketController::show().
        $ticket->setRelation('replies', $ticket->replies->reject(
            fn (TicketReply $reply) => str_starts_with((string) $reply->message, TicketService::INTERNAL_NOTE_PREFIX)
        )->values());

        return view('client.tickets.show', compact('ticket'));
    }

    /**
     * Stream a reply's attachment, scoped to a ticket the authenticated
     * customer actually owns — `$customer->tickets()->findOrFail()` is the
     * same ownership check `show()` uses, not just the attachment's own id.
     */
    public function showAttachment(Request $request, int $id, TicketAttachment $attachment): \Symfony\Component\HttpFoundation\Response
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $ticket = $customer->tickets()->findOrFail($id);

        abort_unless($attachment->reply?->ticket_id === $ticket->id, 404);

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404, 'Attachment file is missing.');

        $mime = $attachment->mime_type ?: 'application/octet-stream';
        $forceDownload = $request->boolean('download');

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

    /**
     * SVG and HTML both carry script, and an inline `text/html` response on
     * our own origin runs it in the viewing customer's session - `nosniff`
     * cannot help when the declared type IS html. Mirrors
     * Admin\TicketController::isInlinePreviewable().
     */
    private function isInlinePreviewable(string $mime): bool
    {
        $mime = strtolower(trim($mime));
        if ($mime === 'image/svg+xml' || $mime === 'text/html' || $mime === 'application/xhtml+xml') {
            return false;
        }
        if (str_starts_with($mime, 'image/')) {
            return true;
        }
        return in_array($mime, ['application/pdf', 'text/plain', 'text/csv'], true);
    }

    private function sanitizeFilename(string $filename): string
    {
        return addcslashes($filename, '"\\');
    }

    public function reply(Request $request, int $id): RedirectResponse
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $ticket = $customer->tickets()->findOrFail($id);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:25600'],
        ]);

        try {
            $this->tickets->reply($ticket, $request->user(), $validated['message'], [], $request->file('attachments', []));
        } catch (DomainException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()
            ->route('client.tickets.show', $ticket)
            ->with('success', 'Reply sent.');
    }
}
