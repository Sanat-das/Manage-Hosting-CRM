<?php

namespace App\Dashboard;

/**
 * Catalogue of dashboard widgets available to admin users.
 *
 * Each definition carries the display metadata (title/icon/description), the
 * Bootstrap column classes for the grid (`size`), the partial view that
 * renders the widget body, and the DashboardData provider method that feeds
 * that partial.
 */
class WidgetRegistry
{
    /** @var array<string, WidgetDefinition> keyed by widget key */
    private array $definitions;

    public function __construct()
    {
        $this->definitions = [
            'kpi_metrics' => new WidgetDefinition(
                key: 'kpi_metrics',
                title: 'Key Metrics',
                icon: 'bi bi-speedometer2',
                description: 'Customers, orders, tickets and revenue at a glance',
                size: 'col-12',
                view: 'admin.dashboard.widgets.metric-cards',
                provider: 'kpiMetrics',
            ),
            'revenue_trend' => new WidgetDefinition(
                key: 'revenue_trend',
                title: 'Revenue Trend',
                icon: 'bi bi-graph-up-arrow',
                description: 'Paid revenue over the last 12 months',
                size: 'col-xl-8',
                view: 'admin.dashboard.widgets.revenue-trend',
                provider: 'revenueTrend',
            ),
            'recent_orders' => new WidgetDefinition(
                key: 'recent_orders',
                title: 'Recent Orders',
                icon: 'bi bi-cart-check',
                description: 'Latest five orders with status',
                size: 'col-xl-4',
                view: 'admin.dashboard.widgets.recent-orders',
                provider: 'recentOrders',
            ),
            'pending_invoices' => new WidgetDefinition(
                key: 'pending_invoices',
                title: 'Pending Invoices',
                icon: 'bi bi-receipt',
                description: 'Unpaid and overdue invoices needing attention',
                size: 'col-xl-4',
                view: 'admin.dashboard.widgets.pending-invoices',
                provider: 'pendingInvoices',
            ),
            'open_tickets' => new WidgetDefinition(
                key: 'open_tickets',
                title: 'Open Tickets',
                icon: 'bi bi-life-preserver',
                description: 'Latest open and pending support tickets',
                size: 'col-xl-4',
                view: 'admin.dashboard.widgets.open-tickets',
                provider: 'openTickets',
            ),
            'server_status' => new WidgetDefinition(
                key: 'server_status',
                title: 'Server Status',
                icon: 'bi bi-hdd-rack',
                description: 'Server inventory with hosting account counts',
                size: 'col-xl-8',
                view: 'admin.dashboard.widgets.server-status',
                provider: 'serverStatus',
            ),
        ];
    }

    /**
     * All widget definitions in catalogue order.
     *
     * @return list<WidgetDefinition>
     */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function get(string $key): ?WidgetDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->definitions);
    }
}