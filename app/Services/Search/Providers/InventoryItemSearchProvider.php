<?php

declare(strict_types=1);

namespace App\Services\Search\Providers;

use App\Models\InventoryItem;
use App\Services\Search\AbstractSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Inventory items, gated by `devices.view` — the same permission the
 * inventory index/show routes use.
 *
 * An item is the NetBox-style component hanging off a device
 * (device), so the asset tag a maintainer holds often identifies the device,
 * not the item. The item name is the strongest identifier the person
 * searching has in hand, so it is both the result label and the default
 * ranking column. `searchableRelations()` matches the manufacturer's name
 * and the owning device's asset_tag / serial_number, so a search for the
 * device finds the items installed in it. The subtitle reproduces the panel
 * meta line — type marker, serial, asset tag and device — dropping empty
 * parts so a sparse item never renders stray bullets.
 */
class InventoryItemSearchProvider extends AbstractSearchProvider
{
    public function key(): string
    {
        return 'inventory-items';
    }

    public function label(): string
    {
        return 'Inventory Items';
    }

    public function icon(): string
    {
        return 'bi bi-box';
    }

    public function permission(): string
    {
        return 'devices.view';
    }

    public function showRoute(): string
    {
        return 'admin.inventory-items.show';
    }

    public function listRoute(): ?string
    {
        return 'admin.inventory-items.index';
    }

    protected function baseQuery(): Builder
    {
        return InventoryItem::query()->with(['device:id,asset_tag,serial_number', 'manufacturer:id,name']);
    }

    protected function searchableColumns(): array
    {
        return ['name', 'serial_number', 'asset_tag', 'sku', 'part_number', 'label'];
    }

    protected function searchableRelations(): array
    {
        return [
            'manufacturer' => ['name'],
            'device' => ['asset_tag', 'serial_number'],
        ];
    }

    public function toResult(Model $model): array
    {
        /** @var InventoryItem $model */
        return $this->resultRow($model, (string) $model->name, $this->meta($model));
    }

    /**
     * The compact one-line summary shown under an item's label: an `Item`
     * marker, then serial, asset tag and owning device — each dropped when
     * empty so a sparse item does not render stray bullets.
     */
    private function meta(InventoryItem $item): ?string
    {
        $parts = array_filter([
            'Item',
            $item->serial_number !== null ? 'SN '.$item->serial_number : '',
            (string) $item->asset_tag,
            $item->device !== null ? 'Device '.$item->device->asset_tag : '',
        ], static fn (string $part): bool => trim($part) !== '');

        $meta = implode(' · ', $parts);

        return $meta !== '' ? $meta : null;
    }
}
