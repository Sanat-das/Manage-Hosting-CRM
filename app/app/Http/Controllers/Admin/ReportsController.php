<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\HostingAccount;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin reports — various report types.
 */
class ReportsController extends Controller
{
    public function revenue(Request $request): View
    {
        $from = $request->query('from', now()->startOfYear()->toDateString());
        $to = $request->query('to', now()->toDateString());

        $invoices = Invoice::where('status', 'paid')
            ->whereBetween('paid_at', [$from, $to])
            ->with('customer')
            ->orderByDesc('paid_at')
            ->paginate(20)
            ->withQueryString();

        $total = Invoice::where('status', 'paid')
            ->whereBetween('paid_at', [$from, $to])
            ->sum('total');

        return view('admin.reports.revenue', compact('invoices', 'total', 'from', 'to'));
    }

    public function customers(Request $request): View
    {
        $customers = Customer::withCount('invoices', 'tickets', 'domains', 'hostingAccounts')
            ->orderByDesc('id')
            ->paginate(20);

        return view('admin.reports.customers', compact('customers'));
    }

    public function tickets(Request $request): View
    {
        $byPriority = Ticket::selectRaw('priority, COUNT(*) as count')
            ->groupBy('priority')
            ->pluck('count', 'priority');

        $byStatus = Ticket::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $byDepartment = Ticket::selectRaw('department, COUNT(*) as count')
            ->groupBy('department')
            ->pluck('count', 'department');

        return view('admin.reports.tickets', compact('byPriority', 'byStatus', 'byDepartment'));
    }

    public function domains(Request $request): View
    {
        $expiring = Domain::where('expiry_date', '<=', now()->addDays(30))
            ->where('expiry_date', '>=', now())
            ->where('status', 'active')
            ->with('customer')
            ->orderBy('expiry_date')
            ->get();

        $byStatus = Domain::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return view('admin.reports.domains', compact('expiring', 'byStatus'));
    }

    public function hosting(Request $request): View
    {
        $accounts = HostingAccount::with(['customer', 'product', 'server'])
            ->orderByDesc('id')
            ->paginate(20);

        $byStatus = HostingAccount::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $byServer = HostingAccount::selectRaw('server_id, COUNT(*) as count')
            ->groupBy('server_id')
            ->pluck('count', 'server_id');

        return view('admin.reports.hosting', compact('accounts', 'byStatus', 'byServer'));
    }

    /**
     * Sales report — orders with totals by period.
     */
    public function sales(Request $request): View
    {
        $from = $request->query('from', now()->startOfYear()->toDateString());
        $to = $request->query('to', now()->toDateString());

        $orders = Order::whereBetween('created_at', [$from, $to])
            ->with('customer')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $totalRevenue = Order::whereBetween('created_at', [$from, $to])->sum('total');
        $orderCount = Order::whereBetween('created_at', [$from, $to])->count();

        return view('admin.reports.sales', compact('orders', 'totalRevenue', 'orderCount', 'from', 'to'));
    }

    /**
     * Export report data as CSV download.
     */
    public function export(Request $request): StreamedResponse
    {
        $type = $request->query('type', 'invoices');
        $from = $request->query('from', now()->startOfYear()->toDateString());
        $to = $request->query('to', now()->toDateString());

        $filename = "{$type}_report_{$from}_{$to}.csv";

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($type, $from, $to) {
            $handle = fopen('php://output', 'w');

            match ($type) {
                'invoices' => $this->exportInvoices($handle, $from, $to),
                'customers' => $this->exportCustomers($handle),
                'orders' => $this->exportOrders($handle, $from, $to),
                default => $this->exportInvoices($handle, $from, $to),
            };

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function exportInvoices($handle, string $from, string $to): void
    {
        fputcsv($handle, ['Invoice #', 'Customer', 'Amount', 'Tax', 'Total', 'Status', 'Paid At']);

        Invoice::whereBetween('created_at', [$from, $to])
            ->with('customer')
            ->orderByDesc('created_at')
            ->chunk(200, function ($invoices) use ($handle) {
                foreach ($invoices as $inv) {
                    fputcsv($handle, [
                        $inv->invoice_no,
                        $inv->customer?->full_name ?? '',
                        $inv->amount,
                        $inv->tax,
                        $inv->total,
                        $inv->status,
                        $inv->paid_at?->format('Y-m-d H:i') ?? '',
                    ]);
                }
            });
    }

    private function exportCustomers($handle): void
    {
        fputcsv($handle, ['ID', 'Name', 'Email', 'Company', 'Balance', 'Credit', 'Status']);

        Customer::with('user')->orderByDesc('id')->chunk(200, function ($customers) use ($handle) {
            foreach ($customers as $c) {
                fputcsv($handle, [
                    $c->id,
                    $c->full_name,
                    $c->user?->email ?? '',
                    $c->company ?? '',
                    $c->balance,
                    $c->credit,
                    $c->status,
                ]);
            }
        });
    }

    private function exportOrders($handle, string $from, string $to): void
    {
        fputcsv($handle, ['Order #', 'Customer', 'Total', 'Status', 'Created At']);

        Order::whereBetween('created_at', [$from, $to])
            ->with('customer')
            ->orderByDesc('created_at')
            ->chunk(200, function ($orders) use ($handle) {
                foreach ($orders as $o) {
                    fputcsv($handle, [
                        $o->order_no ?? $o->id,
                        $o->customer?->full_name ?? '',
                        $o->total,
                        $o->status,
                        $o->created_at?->format('Y-m-d H:i') ?? '',
                    ]);
                }
            });
    }
}
