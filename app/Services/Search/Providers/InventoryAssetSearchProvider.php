<?php

declare(strict_types=1);

namespace App\Services\Search\Providers;

use App\Models\InventoryAsset;
use App\Services\Search\AbstractSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Inventory assets, gated by `inventory.view` — the same permission the
 * inventory index/show routes use.
 *
 * The asset tag is the identifier a maintainer searches by, so it is both the
 * result label and the default ranking column. `searchableRelations()` mirrors
 * the standalone picker (InventoryAssetController::search()), which matches a
 * datacenter or rack name; this provider deliberately extends that by also
 * matching one of the asset's assigned IP addresses, so an IP search finds the
 * asset. The subtitle reproduces the picker's meta line — manufacturer + model,
 * serial, status and location — dropping empty parts so a sparse asset never
 * renders stray bullets.
 */
class InventoryAssetSearchProvider extends AbstractSearchProvider
{
    public function key(): string
    {
        return 'inventory-assets';
    }

    public function label(): string
    {
        return 'Inventory';
    }

    public function icon(): string
    {
        return 'bi bi-boxes';
    }

    public function permission(): string
    {
        return 'inventory.view';
    }

    public function showRoute(): string
    {
        return 'admin.inventory-assets.show';
    }

    public function listRoute(): ?string
    {
        return 'admin.inventory-assets.index';
    }

    protected function baseQuery(): Builder
    {
        return InventoryAsset::query()->with(['datacenter:id,name', 'rack:id,name']);
    }

    protected function searchableColumns(): array
    {
        return ['asset_tag', 'serial_number', 'model', 'manufacturer', 'vendor', 'notes', 'status', 'asset_type'];
    }

    protected function searchableRelations(): array
    {
        return [
            'datacenter' => ['name'],
            'rack' => ['name'],
            'ipAddresses' => ['ip_address'],
        ];
    }

    public function toResult(Model $model): array
    {
        /** @var InventoryAsset $model */
        return $this->resultRow($model, (string) $model->asset_tag, $this->meta($model));
    }

    /**
     * The compact one-line summary shown under an asset's label, byte-for-byte
     * the picker's meta: empty parts are dropped so a sparse asset does not
     * render stray bullets.
     */
    private function meta(InventoryAsset $asset): ?string
    {
        $hardware = trim(implode(' ', array_filter([
            (string) $asset->manufacturer,
            (string) $asset->model,
        ], static fn (string $part): bool => trim($part) !== '')));

        $parts = array_filter([
            $hardware,
            $asset->serial_number !== null ? 'SN '.$asset->serial_number : '',
            (string) $asset->status,
            (string) $asset->datacenter?->name,
            (string) $asset->rack?->name,
        ], static fn (string $part): bool => trim($part) !== '');

        $meta = implode(' · ', $parts);

        return $meta !== '' ? $meta : null;
    }
}
