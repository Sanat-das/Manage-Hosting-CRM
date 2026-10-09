<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin audit trail — privileged actions recorded against entities.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $action = trim((string) $request->query('action'));
        $entityType = trim((string) $request->query('entity_type'));

        $query = AuditLog::with('user');

        if ($action !== '') {
            $query->where('action', $action);
        }
        if ($entityType !== '') {
            $query->where('entity_type', $entityType);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                    ->orWhere('entity_type', 'like', "%{$search}%");
            });
        }

        $logs = $query
            ->gridSort([
                'created_at' => 'created_at',
                'user' => 'user.first_name',
                'action' => 'action',
                'entity_type' => 'entity_type',
                'ip_address' => 'ip_address',
            ])
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        $actions = AuditLog::selectRaw('DISTINCT action')
            ->orderBy('action')
            ->pluck('action')
            ->filter()
            ->mapWithKeys(fn (string $value) => [$value => $value])
            ->all();

        $entityTypes = AuditLog::selectRaw('DISTINCT entity_type')
            ->orderBy('entity_type')
            ->pluck('entity_type')
            ->filter()
            ->mapWithKeys(fn (string $value) => [$value => $value])
            ->all();

        return view('admin.audit_log.index', compact('logs', 'actions', 'entityTypes', 'search', 'action', 'entityType'));
    }
}
