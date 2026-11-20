<?php

declare(strict_types=1);

namespace App\Services\Search\Providers;

use App\Models\Device;
use App\Services\Search\AbstractSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Devices, gated by `devices.view` — the same permission the devices
 * index/show routes use.
 *
 * The asset tag is the identifier a maintainer searches by, so it is both the
 * result label and the default ranking column. `searchableRelations()` mirrors
 * the device picker's matching of a datacenter or rack name; this provider
 * deliberately extends that by also
 * matching a tag name and one of the device's assigned IP addresses, so an IP or
 * tag search finds the device. The subtitle reproduces the picker's meta line — manufacturer + model,
 * serial, status and location — dropping empty parts so a sparse device never
 * renders stray bullets.
 */
class DeviceSearchProvider extends AbstractSearchProvider
{
    public function key(): string
    {
        return 'devices';
    }

    public function label(): string
    {
        return 'Devices';
    }

    public function icon(): string
    {
        return 'bi bi-boxes';
    }

    public function permission(): string
    {
        return 'devices.view';
    }

    public function showRoute(): string
    {
        return 'admin.devices.show';
    }

    public function listRoute(): ?string
    {
        return 'admin.devices.index';
    }

    protected function baseQuery(): Builder
    {
        return Device::query()->with(['datacenter:id,name', 'rack:id,name']);
    }

    protected function searchableColumns(): array
    {
        return ['asset_tag', 'serial_number', 'model', 'manufacturer', 'vendor', 'notes', 'status'];
    }

    protected function searchableRelations(): array
    {
        return [
            'datacenter' => ['name'],
            'rack' => ['name'],
            'tags' => ['name'],
            'ipAddresses' => ['ip_address'],
        ];
    }

    public function toResult(Model $model): array
    {
        /** @var Device $model */
        return $this->resultRow($model, (string) $model->asset_tag, $this->meta($model));
    }

    /**
     * The compact one-line summary shown under an asset's label, byte-for-byte
     * the picker's meta: empty parts are dropped so a sparse asset does not
     * render stray bullets.
     */
    private function meta(Device $asset): ?string
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
