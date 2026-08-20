<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\HostingAccount;
use App\Models\Invoice;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin analytics — summary charts and metrics.
 */
class AnalyticsController extends Controller
{
    public function index(Request $request): View
    {
        // Revenue by month (last 12 months)
        $revenueByMonth = Invoice::where('status', 'paid')
            ->where('paid_at', '>=', now()->subMonths(12))
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') as month, SUM(total) as total")
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('total', 'month')
            ->toArray();

        // Customer count by month (registered)
        $customersByMonth = Customer::selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
            ->where('created_at', '>=', now()->subMonths(12))
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('count', 'month')
            ->toArray();

        // Open tickets by priority
        $ticketsByPriority = Ticket::whereNotIn('status', ['closed', 'resolved'])
            ->selectRaw('priority, COUNT(*) as count')
            ->groupBy('priority')
            ->pluck('count', 'priority')
            ->toArray();

        // Hosting accounts by status
        $hostingByStatus = HostingAccount::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Domain status distribution
        $domainsByStatus = Domain::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Summary cards
        $totalRevenue = Invoice::where('status', 'paid')->sum('total');
        $totalCustomers = Customer::count();
        $totalTickets = Ticket::whereNotIn('status', ['closed', 'resolved'])->count();
        $totalDomains = Domain::where('status', 'active')->count();

        return view('admin.analytics.index', compact(
            'revenueByMonth',
            'customersByMonth',
            'ticketsByPriority',
            'hostingByStatus',
            'domainsByStatus',
            'totalRevenue',
            'totalCustomers',
            'totalTickets',
            'totalDomains',
        ));
    }
}
