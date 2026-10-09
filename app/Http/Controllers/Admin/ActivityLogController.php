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
        $search = trim((string) $request->query('search'));
        $action = trim((string) $request->query('action'));

        $query = ActivityLog::with('user', 'customer');

        if ($action !== '') {
            // Rows come from two writers: app code fills `action`, the AdminLTE
            // vendor logger fills only `event` (auth.login/logout/failed) —
            // match either so the filter reaches both.
            $query->where(function ($q) use ($action) {
                $q->where('action', $action)->orWhere('event', $action);
            });
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%")
                    ->orWhere('event', 'like', "%{$search}%");
            });
        }

        $logs = $query
            ->gridSort([
                'created_at' => 'created_at',
                'action' => 'action',
                'user' => 'user.first_name',
                'description' => 'description',
                'ip_address' => 'ip_address',
            ])
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        $actions = ActivityLog::query()
            ->select('action')
            ->whereNotNull('action')
            ->distinct()
            ->pluck('action')
            ->merge(
                ActivityLog::query()
                    ->select('event')
                    ->whereNotNull('event')
                    ->distinct()
                    ->pluck('event')
            )
            ->unique()
            ->sort()
            ->mapWithKeys(fn (string $value) => [$value => $value])
            ->all();

        return view('admin.activity_log.index', compact('logs', 'actions', 'search', 'action'));
    }
}
