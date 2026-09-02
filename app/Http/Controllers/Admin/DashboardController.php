<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\HostingAccount;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Ticket;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

class DashboardController extends Controller
{
    /**
     * Admin panel landing page — enterprise dashboard.
     *
     * All metrics are computed defensively: if a table is missing or a
     * query fails the value falls back to 0 / empty so the dashboard
     * never throws on a fresh install or partial migration.
     */
    public function index(): View
    {
        $metrics = $this->metrics();
        $revenueChart = $this->revenueChart();
        $ticketsByStatus = $this->ticketsByStatus();
        $recentActivity = $this->recentActivity();
        $pendingOrders = $this->pendingOrders();
        $expiringDomains = $this->expiringDomains();

        return view('admin.dashboard', compact(
            'metrics',
            'revenueChart',
            'ticketsByStatus',
            'recentActivity',
            'pendingOrders',
            'expiringDomains',
        ));
    }

    private function metrics(): array
    {
        return [
            'customers' => $this->safeCount(Customer::class),
            'activeServices' => $this->safeCountWhere(HostingAccount::class, ['status' => 'active']),
            'openInvoices' => $this->safeCountWhere(Invoice::class, null, fn ($q) => $q->whereIn('status', ['sent', 'overdue', 'partial'])),
            'overdueInvoices' => $this->safeCountWhere(Invoice::class, ['status' => 'overdue']),
            'openTickets' => $this->safeCountWhere(Ticket::class, null, fn ($q) => $q->whereNotIn('status', ['closed', 'resolved'])),
            'urgentTickets' => $this->safeCountWhere(Ticket::class, null, fn ($q) => $q->whereNotIn('status', ['closed', 'resolved'])->whereIn('priority', ['urgent', 'high'])),
            'revenueMtd' => $this->safeSumWhere(Invoice::class, 'total', fn ($q) => $q->where('status', 'paid')->where('paid_at', '>=', now()->startOfMonth())),
            'revenueMtdPrev' => $this->safeSumWhere(Invoice::class, 'total', fn ($q) => $q->where('status', 'paid')->whereBetween('paid_at', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()])),
        ];
    }

    private function revenueChart(): array
    {
        try {
            if (! Schema::hasTable((new Invoice)->getTable())) {
                return ['labels' => [], 'values' => []];
            }
            $raw = Invoice::where('status', 'paid')
                ->where('paid_at', '>=', now()->subMonths(6)->startOfMonth())
                ->selectRaw("DATE_FORMAT(paid_at, '%b %y') as label, DATE_FORMAT(paid_at, '%Y-%m') as k, SUM(total) as total")
                ->groupBy('k', 'label')
                ->orderBy('k')
                ->pluck('total', 'label')
                ->toArray();

            // Ensure 6 buckets even when sparse — backfill zeros for missing months.
            $labels = [];
            $values = [];
            for ($i = 5; $i >= 0; $i--) {
                $d = now()->subMonths($i);
                $label = $d->format('M y');
                $k = $d->format('M y');
                // Match against the DATE_FORMAT '%b %y' labels (e.g. "Jan 26")
                $labels[] = $d->format('M');
                $values[] = (float) ($raw[$k] ?? $raw[$d->format('M y')] ?? 0);
            }

            // If raw used '%b %y' with full month name mismatch, fallback to ordered values
            if (array_sum($values) === 0.0 && count($raw) > 0) {
                $labels = array_keys($raw);
                $values = array_values(array_map(fn ($v) => (float) $v, $raw));
            }

            return ['labels' => $labels, 'values' => $values];
        } catch (Throwable) {
            return ['labels' => [], 'values' => []];
        }
    }

    private function ticketsByStatus(): array
    {
        try {
            if (! Schema::hasTable((new Ticket)->getTable())) {
                return [];
            }
            return Ticket::whereNotIn('status', ['closed', 'resolved'])
                ->selectRaw('status, COUNT(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status')
                ->toArray();
        } catch (Throwable) {
            return [];
        }
    }

    private function recentActivity(): \Illuminate\Support\Collection
    {
        try {
            if (! Schema::hasTable((new ActivityLog)->getTable())) {
                return collect();
            }
            return ActivityLog::with(['user', 'customer'])
                ->orderByDesc('created_at')
                ->limit(8)
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    private function pendingOrders(): \Illuminate\Support\Collection
    {
        try {
            if (! Schema::hasTable((new Order)->getTable())) {
                return collect();
            }
            return Order::with('customer')
                ->whereIn('status', ['pending', 'provisioning', 'paid'])
                ->latest()
                ->limit(5)
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    private function expiringDomains(): \Illuminate\Support\Collection
    {
        try {
            if (! Schema::hasTable((new Domain)->getTable())) {
                return collect();
            }
            return Domain::with('customer')
                ->where('status', 'active')
                ->whereNotNull('expiry_date')
                ->where('expiry_date', '>=', now())
                ->where('expiry_date', '<=', now()->addDays(30))
                ->orderBy('expiry_date')
                ->limit(5)
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    private function safeCount(string $model): int
    {
        try {
            if (! Schema::hasTable((new $model)->getTable())) {
                return 0;
            }
            return $model::count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function safeCountWhere(string $model, ?array $whereEquals, ?callable $scope = null): int
    {
        try {
            if (! Schema::hasTable((new $model)->getTable())) {
                return 0;
            }
            $q = $model::query();
            if ($whereEquals) {
                $q->where($whereEquals);
            }
            if ($scope) {
                $scope($q);
            }
            return (int) $q->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function safeSumWhere(string $model, string $column, callable $scope): float
    {
        try {
            if (! Schema::hasTable((new $model)->getTable())) {
                return 0.0;
            }
            $q = $model::query();
            $scope($q);
            return (float) $q->sum($column);
        } catch (Throwable) {
            return 0.0;
        }
    }
}
