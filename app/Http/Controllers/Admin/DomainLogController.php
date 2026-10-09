<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DomainSearchLog;
use App\Models\DomainSyncLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin domain logs — registrar sync operations and availability searches,
 * each paginated independently under its own tab.
 */
class DomainLogController extends Controller
{
    public function index(Request $request): View
    {
        $provider = trim((string) $request->query('provider'));
        $status = trim((string) $request->query('status'));

        $syncQuery = DomainSyncLog::query();

        if ($provider !== '') {
            $syncQuery->where('provider', $provider);
        }
        if ($status !== '') {
            $syncQuery->where('status', $status);
        }

        $syncLogs = $syncQuery
            ->orderByDesc('created_at')
            ->paginate(30, ['*'], 'sync_page')
            ->withQueryString();

        $searchLogs = DomainSearchLog::query()
            ->orderByDesc('created_at')
            ->paginate(30, ['*'], 'search_page')
            ->withQueryString();

        $providers = DomainSyncLog::selectRaw('DISTINCT provider')
            ->orderBy('provider')
            ->pluck('provider')
            ->filter()
            ->mapWithKeys(fn (string $value) => [$value => $value])
            ->all();

        $statuses = DomainSyncLog::selectRaw('DISTINCT status')
            ->orderBy('status')
            ->pluck('status')
            ->filter()
            ->mapWithKeys(fn (string $value) => [$value => ucfirst($value)])
            ->all();

        return view('admin.domain_logs.index', compact('syncLogs', 'searchLogs', 'providers', 'statuses', 'provider', 'status'));
    }
}
