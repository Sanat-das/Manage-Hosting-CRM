<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmail;
use App\Models\EmailLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin email log — view sent/queued/failed emails.
 */
class EmailLogController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = trim((string) $request->query('status'));

        $query = EmailLog::query();

        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('to_email', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%");
            });
        }

        $logs = $query->with(['customer'])
            ->gridSort([
                'created_at' => 'created_at',
                'to_email' => 'to_email',
                'subject' => 'subject',
                'status' => 'status',
            ])
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        $statuses = EmailLog::selectRaw('DISTINCT status')
            ->pluck('status')
            ->filter()
            ->mapWithKeys(fn (string $value) => [$value => ucfirst($value)])
            ->all();

        return view('admin.email_logs.index', compact('logs', 'statuses', 'search', 'status'));
    }

    public function show(EmailLog $emailLog): View
    {
        return view('admin.email_logs.show', ['log' => $emailLog]);
    }

    /**
     * Re-queue a stored email from its recorded payload.
     *
     * Rows written before payloads existed have nothing to resend, so they are
     * reported rather than silently doing nothing.
     */
    public function resend(EmailLog $emailLog): RedirectResponse
    {
        $payload = $emailLog->payload;

        if ($payload === null) {
            return back()->with('error', 'This row predates resend support — no payload stored.');
        }

        $data = is_array($payload) ? $payload : (array) json_decode((string) $payload, true);

        SendEmail::dispatch(
            $data['to'] ?? $emailLog->to_email,
            $data['subject'] ?? (string) $emailLog->subject,
            $data['body'] ?? (string) $emailLog->body,
            $data['from_email'] ?? null,
            $data['headers'] ?? [],
            $data['cc'] ?? [],
            $data['bcc'] ?? [],
            null,
            $data['attachments'] ?? [],
            null,
            $data['template_name'] ?? null,
            $data['customer_id'] ?? null,
        );

        return back()->with('success', 'Resend queued.');
    }
}
