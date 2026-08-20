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
        $query = EmailLog::query();

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('to_email', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%");
            });
        }

        $logs = $query->with(['customer'])
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        $statuses = EmailLog::selectRaw('DISTINCT status')->pluck('status');

        return view('admin.email_logs.index', compact('logs', 'statuses'));
    }

    public function show(EmailLog $emailLog): View
    {
        return view('admin.email_logs.show', ['log' => $emailLog]);
    }
}
