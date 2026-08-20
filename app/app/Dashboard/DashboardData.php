<?php

namespace App\Dashboard;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Server;
use App\Models\Ticket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Data providers for the dashboard widgets.
 *
 * Each public method maps 1:1 to a WidgetDefinition provider name and returns
 * the exact payload the widget's partial view expects (the partial receives
 * the returned array keys as its own variables via @include($view, $data)).
 *
 * Query style mirrors AnalyticsController; money is formatted as INR rupee
 * exactly like the admin orders/invoices views ('₹' . number_format(..., 2)).
 */
class DashboardData
{
    /**
     * KPI summary cards: title (the value), text (the label), icon, theme and
     * a link to the matching admin index/report page. Keyed by the partial's
     * variable name so @include($view, $data) binds it as `$metrics`.
     *
     * @return array{metrics:list<array{title:string,text:string,icon:string,theme:string,url:string}>}
     */
    public function kpiMetrics(): array
    {
        return [
            'metrics' => [
                [
                    'title' => (string) Customer::count(),
                    'text' => 'Customers',
                    'icon' => 'bi bi-people',
                    'theme' => 'primary',
                    'url' => route('admin.customers.index'),
                ],
                [
                    'title' => (string) Order::count(),
                    'text' => 'Orders',
                    'icon' => 'bi bi-cart3',
                    'theme' => 'warning',
                    'url' => route('admin.orders.index'),
                ],
                [
                    'title' => (string) Ticket::whereNotIn('status', ['closed', 'resolved'])->count(),
                    'text' => 'Open Tickets',
                    'icon' => 'bi bi-life-preserver',
                    'theme' => 'success',
                    'url' => route('admin.tickets.index'),
                ],
                [
                    'title' => $this->money(Invoice::where('status', 'paid')->sum('total')),
                    'text' => 'Revenue',
                    'icon' => 'bi bi-currency-rupee',
                    'theme' => 'info',
                    'url' => route('admin.reports.revenue'),
                ],
            ],
        ];
    }

    /**
     * ApexCharts config for the 12-month paid-revenue area chart. Keyed by the
     * partial's variable name so @include($view, $data) binds it as `$chartConfig`.
     *
     * @return array{chartConfig:array<string, mixed>}
     */
    public function revenueTrend(): array
    {
        $months = collect(range(11, 0))
            ->map(fn (int $offset): Carbon => now()->subMonths($offset)->startOfMonth());

        $revenue = Invoice::where('status', 'paid')
            ->where('paid_at', '>=', $months->first())
            ->selectRaw($this->monthExpression('paid_at').' as month, SUM(total) as total')
            ->groupBy('month')
            ->pluck('total', 'month');

        $labels = $months->map(fn (Carbon $month): string => $month->format('M Y'))->values();
        $values = $months
            ->map(fn (Carbon $month): float => (float) ($revenue[$month->format('Y-m')] ?? 0))
            ->values();

        return [
            'chartConfig' => [
                'chart' => [
                    'type' => 'area',
                    'height' => 300,
                    'toolbar' => ['show' => false],
                ],
                'series' => [
                    ['name' => 'Revenue', 'data' => $values->all()],
                ],
                'xaxis' => [
                    'categories' => $labels->all(),
                ],
                'colors' => ['#2DD4BF'],
                'dataLabels' => ['enabled' => false],
                'stroke' => ['curve' => 'smooth'],
                'fill' => ['type' => 'gradient'],
            ],
        ];
    }

    /**
     * Latest five orders with customer, status and total. Keyed by the
     * partial's variable name so @include($view, $data) binds it as `$orders`.
     *
     * @return array{orders:list<array{id:int,order_no:string,customer_name:string,status:string,total:string,created_at:Carbon,show_url:string}>}
     */
    public function recentOrders(): array
    {
        return [
            'orders' => Order::with(['customer'])
                ->latest('id')
                ->limit(5)
                ->get()
                ->map(fn (Order $order): array => [
                    'id' => $order->id,
                    'order_no' => $order->order_no,
                    'customer_name' => $order->customer?->full_name ?? '—',
                    'status' => $order->status,
                    'total' => $this->money($order->total),
                    'created_at' => $order->created_at,
                    'show_url' => route('admin.orders.show', $order),
                ])
                ->all(),
        ];
    }

    /**
     * Unpaid / overdue / partial invoices, soonest due date first. Keyed by
     * the partial's variable name so @include($view, $data) binds it as `$invoices`.
     *
     * @return array{invoices:list<array{id:int,invoice_no:string,customer_name:string,total:string,due_amount:string,due_date:Carbon,status:string,status_label:string,show_url:string}>}
     */
    public function pendingInvoices(): array
    {
        return [
            'invoices' => Invoice::with(['customer'])
                ->whereIn('status', ['sent', 'overdue', 'partial'])
                ->orderBy('due_date')
                ->limit(8)
                ->get()
                ->map(fn (Invoice $invoice): array => [
                    'id' => $invoice->id,
                    'invoice_no' => $invoice->invoice_no,
                    'customer_name' => $invoice->customer?->full_name ?? '—',
                    'total' => $this->money($invoice->total),
                    'due_amount' => $this->money($invoice->dueAmount()),
                    'due_date' => $invoice->due_date,
                    'status' => $invoice->status,
                    'status_label' => $invoice->status_label,
                    'show_url' => route('admin.invoices.show', $invoice),
                ])
                ->all(),
        ];
    }

    /**
     * Latest open / pending tickets, most recently replied first. Keyed by the
     * partial's variable name so @include($view, $data) binds it as `$tickets`.
     *
     * @return array{tickets:list<array{id:int,ticket_no:string,subject:string,priority:string,priority_color:string,customer_name:string,last_reply_at:?Carbon,show_url:string}>}
     */
    public function openTickets(): array
    {
        $priorityColors = [
            'low' => 'secondary',
            'medium' => 'warning',
            'high' => 'danger',
            'urgent' => 'dark',
        ];

        return [
            'tickets' => Ticket::with(['customer'])
                ->whereNotIn('status', ['closed', 'resolved'])
                ->orderByDesc('last_reply_at')
                ->orderByDesc('id')
                ->limit(8)
                ->get()
                ->map(fn (Ticket $ticket): array => [
                    'id' => $ticket->id,
                    'ticket_no' => $ticket->ticket_no,
                    'subject' => $ticket->subject,
                    'priority' => $ticket->priority,
                    'priority_color' => $priorityColors[$ticket->priority] ?? 'secondary',
                    'customer_name' => $ticket->customer?->full_name ?? '—',
                    'last_reply_at' => $ticket->last_reply_at,
                    'show_url' => route('admin.tickets.show', $ticket),
                ])
                ->all(),
        ];
    }

    /**
     * Server inventory with hosting account counts. Keyed by the partial's
     * variable name so @include($view, $data) binds it as `$servers`.
     *
     * @return array{servers:list<array{id:int,name:string,ip_address:string,panel_type:string,status:string,status_color:string,hosting_count:int,show_url:string}>}
     */
    public function serverStatus(): array
    {
        $statusColors = [
            'active' => 'success',
            'inactive' => 'secondary',
        ];

        return [
            'servers' => Server::withCount('hostingAccounts')
                ->orderBy('name')
                ->get()
                ->map(fn (Server $server): array => [
                    'id' => $server->id,
                    'name' => $server->name,
                    'ip_address' => $server->ip_address,
                    'panel_type' => $server->panel_type,
                    'status' => $server->status,
                    'status_color' => $statusColors[$server->status] ?? 'secondary',
                    'hosting_count' => (int) $server->hosting_accounts_count,
                    'show_url' => route('admin.servers.show', $server),
                ])
                ->all(),
        ];
    }

    /**
     * Format an amount as INR rupee, mirroring the admin orders/invoices views.
     */
    private function money(float|int|string|null $amount): string
    {
        return '₹'.number_format((float) $amount, 2);
    }

    /**
     * Month-truncation expression for the revenue query. MySQL uses
     * DATE_FORMAT (as in AnalyticsController); SQLite (the test database)
     * has no DATE_FORMAT, so strftime is used there.
     */
    private function monthExpression(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', {$column})"
            : "DATE_FORMAT({$column}, '%Y-%m')";
    }
}