<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin activity log — view system activity.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        $query = ActivityLog::with('user', 'customer');

        if ($request->filled('action')) {
            $query->where('action', $request->query('action'));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }
        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%");
            });
        }

        $logs = $query->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        $actions = ActivityLog::selectRaw('DISTINCT action')
            ->orderBy('action')
            ->pluck('action');

        return view('admin.activity_log.index', compact('logs', 'actions'));
    }
}
