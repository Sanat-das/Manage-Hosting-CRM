<?php

declare(strict_types=1);

namespace App\Services\Search\Providers;

use App\Models\Product;
use App\Services\Search\AbstractSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Products, matched on the name alone.
 *
 * Gated by `products.view`, the same permission `routes/admin/products.php`
 * puts on the product index/show screens. The subtitle is the status so a
 * staff member can tell an inactive product apart from an active one without
 * opening it.
 */
class ProductSearchProvider extends AbstractSearchProvider
{
    public function key(): string
    {
        return 'products';
    }

    public function label(): string
    {
        return 'Products';
    }

    public function icon(): string
    {
        return 'bi bi-box';
    }

    public function permission(): string
    {
        return 'products.view';
    }

    public function showRoute(): string
    {
        return 'admin.products.show';
    }

    public function listRoute(): ?string
    {
        return 'admin.products.index';
    }

    protected function baseQuery(): Builder
    {
        return Product::query();
    }

    protected function searchableColumns(): array
    {
        return ['name'];
    }

    public function toResult(Model $model): array
    {
        /** @var Product $model */
        return $this->resultRow($model, (string) $model->name, $model->status);
    }
}
