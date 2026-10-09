<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModuleLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin module log — module events and failures.
 */
class ModuleLogController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = trim((string) $request->query('status'));

        $query = ModuleLog::with('module');

        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($search !== '') {
            $query->where('event', 'like', "%{$search}%");
        }

        $logs = $query
            ->gridSort([
                'created_at' => 'created_at',
                'event' => 'event',
                'status' => 'status',
            ])
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        $statuses = ModuleLog::selectRaw('DISTINCT status')
            ->orderBy('status')
            ->pluck('status')
            ->filter()
            ->mapWithKeys(fn (string $value) => [$value => ucfirst($value)])
            ->all();

        return view('admin.module_logs.index', compact('logs', 'statuses', 'search', 'status'));
    }
}
