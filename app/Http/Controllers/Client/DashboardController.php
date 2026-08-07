<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Client portal landing page.
 *
 * Mirrors the reference portal summary: counts of active hosting accounts,
 * open invoices (sent/overdue), open tickets, active domains, plus the
 * customer's balance and credit, and recent invoices/tickets/activity.
 */
class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $customer = $request->user()->customer;

        // No linked Customer record yet (e.g. a freshly self-registered
        // account) — show a friendly "pending setup" screen instead of a
        // bare 404 on the client's own landing page.
        if ($customer === null) {
            return view('client.pending', ['user' => $request->user()]);
        }

        $customer->load([
            'hostingAccounts' => fn ($q) => $q->where('status', 'active'),
            'domains' => fn ($q) => $q->where('status', 'active'),
            'invoices' => fn ($q) => $q->whereIn('status', ['sent', 'overdue'])->latest()->limit(5),
            'tickets' => fn ($q) => $q->whereNotIn('status', ['closed', 'resolved'])->latest()->limit(5),
        ]);

        $summary = [
            'hosting_accounts' => $customer->hostingAccounts->count(),
            'active_domains' => $customer->domains->count(),
            'open_invoices' => $customer->invoices->count(),
            'open_tickets' => $customer->tickets->count(),
        ];

        $recentActivity = ActivityLog::query()
            ->where('customer_id', $customer->id)
            ->with('user')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $featuredProducts = Product::query()
            ->where('status', 'active')
            ->where('show_in_order', true)
            ->where('only_admin', false)
            ->orderBy('sort_order')
            ->limit(3)
            ->get();

        return view('client.dashboard', compact('customer', 'summary', 'recentActivity', 'featuredProducts'));
    }
}
