<?php

declare(strict_types=1);

namespace App\Services\Search\Providers;

use App\Models\Order;
use App\Services\Search\AbstractSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Orders in the global search registry.
 *
 * Matched on the generated `ORD-{YEAR}-{seq}` identifier and the ordered
 * domain name; gated by `orders.view`, the same permission
 * `routes/admin/orders.php` gates the index/show screens with. The typeahead
 * row carries the identifier and a non-monetary descriptor only — never the
 * order total.
 */
class OrderSearchProvider extends AbstractSearchProvider
{
    public function key(): string
    {
        return 'orders';
    }

    public function label(): string
    {
        return 'Orders';
    }

    public function icon(): string
    {
        return 'bi bi-cart';
    }

    public function permission(): string
    {
        return 'orders.view';
    }

    public function showRoute(): string
    {
        return 'admin.orders.show';
    }

    public function listRoute(): ?string
    {
        return 'admin.orders.index';
    }

    protected function baseQuery(): Builder
    {
        return Order::query();
    }

    protected function searchableColumns(): array
    {
        return ['order_number', 'domain_name'];
    }

    /**
     * Closeness is decided by the order number, never by the domain: an exact
     * `ORD-…` hit must rank before a domain that merely contains the term.
     */
    protected function rankColumn(): string
    {
        return 'order_number';
    }

    public function toResult(Model $model): array
    {
        /** @var Order $model */
        return $this->resultRow(
            $model,
            $model->order_no,
            filled($model->domain_name) ? (string) $model->domain_name : ucfirst((string) $model->status),
        );
    }
}
