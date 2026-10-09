<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketingConsentLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin consent log — marketing opt-in / opt-out history.
 */
class ConsentLogController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $consentStatus = trim((string) $request->query('consent_status'));
        $contactType = trim((string) $request->query('contact_type'));

        $query = MarketingConsentLog::with('customer');

        if ($consentStatus !== '') {
            $query->where('consent_status', $consentStatus);
        }
        if ($contactType !== '') {
            $query->where('contact_type', $contactType);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('source', 'like', "%{$search}%")
                    ->orWhere('contact_type', 'like', "%{$search}%");
            });
        }

        $logs = $query
            ->gridSort([
                'created_at' => 'created_at',
                'contact_type' => 'contact_type',
                'consent_status' => 'consent_status',
                'source' => 'source',
                'ip_address' => 'ip_address',
            ])
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        $consentStatuses = MarketingConsentLog::selectRaw('DISTINCT consent_status')
            ->orderBy('consent_status')
            ->pluck('consent_status')
            ->filter()
            ->mapWithKeys(fn (string $value) => [$value => ucfirst(str_replace('_', ' ', $value))])
            ->all();

        $contactTypes = MarketingConsentLog::selectRaw('DISTINCT contact_type')
            ->orderBy('contact_type')
            ->pluck('contact_type')
            ->filter()
            ->mapWithKeys(fn (string $value) => [$value => ucfirst($value)])
            ->all();

        return view('admin.consent_log.index', compact('logs', 'consentStatuses', 'contactTypes', 'search', 'consentStatus', 'contactType'));
    }
}
