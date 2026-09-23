<?php

declare(strict_types=1);

namespace App\Services\Search\Providers;

use App\Models\CatalogProduct;
use App\Services\Search\AbstractSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Catalog products (the sellable definitions), matched on name and SKU.
 *
 * Gated by `catalog-products.view`, the same permission
 * `routes/admin/enterprise.php` puts on the catalog index/show screens. The
 * model's SoftDeletes scope excludes trashed rows from search, which is the
 * intended behaviour. The SKU is the identifier a staff member actually
 * recognises, so it is the subtitle.
 */
class CatalogProductSearchProvider extends AbstractSearchProvider
{
    public function key(): string
    {
        return 'catalog-products';
    }

    public function label(): string
    {
        return 'Catalog Products';
    }

    public function icon(): string
    {
        return 'bi bi-boxes';
    }

    public function permission(): string
    {
        return 'catalog-products.view';
    }

    public function showRoute(): string
    {
        return 'admin.catalog-products.show';
    }

    public function listRoute(): ?string
    {
        return 'admin.catalog-products.index';
    }

    protected function baseQuery(): Builder
    {
        return CatalogProduct::query();
    }

    protected function searchableColumns(): array
    {
        return ['name', 'sku'];
    }

    public function toResult(Model $model): array
    {
        /** @var CatalogProduct $model */
        return $this->resultRow(
            $model,
            (string) $model->name,
            $model->sku ?: $model->status,
        );
    }
}
