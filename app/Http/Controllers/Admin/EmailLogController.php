<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
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
}
