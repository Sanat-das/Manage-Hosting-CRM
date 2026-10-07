<?php

declare(strict_types=1);

namespace App\Services\Search\Providers;

use App\Models\IpAddress;
use App\Services\Search\AbstractSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * IP Manager addresses, gated by `ip-addresses.view` — the same permission the
 * IP address index/show routes use.
 *
 * The address is the identifier a maintainer searches by, so it is both the
 * result label and the default ranking column. `searchableRelations()` mirrors
 * the standalone picker (IpAddressController::search()): a term may match the
 * owning subnet's name or CIDR. The subtitle reproduces the picker's meta line
 * — type, a differing computed status, the asset link or assigned flag, and the
 * last-seen timestamp — dropping empty parts so a sparse address never renders
 * stray bullets.
 */
class IpAddressSearchProvider extends AbstractSearchProvider
{
    public function key(): string
    {
        return 'ip-addresses';
    }

    public function label(): string
    {
        return 'IP Addresses';
    }

    public function icon(): string
    {
        return 'bi bi-circle';
    }

    public function permission(): string
    {
        return 'ip-addresses.view';
    }

    public function showRoute(): string
    {
        return 'admin.ip-addresses.show';
    }

    public function listRoute(): ?string
    {
        return 'admin.ip-addresses.index';
    }

    protected function baseQuery(): Builder
    {
        return IpAddress::query()->with(['inventoryAsset:id,asset_tag']);
    }

    protected function searchableColumns(): array
    {
        return ['ip_address', 'ptr_record', 'notes', 'type'];
    }

    protected function searchableRelations(): array
    {
        return [
            'subnet' => ['name', 'subnet_cidr'],
        ];
    }

    public function toResult(Model $model): array
    {
        /** @var IpAddress $model */
        $meta = $this->meta($model);

        return $this->resultRow($model, (string) $model->ip_address, $meta !== '' ? $meta : null);
    }

    /**
     * The compact one-line summary shown under an address's label, byte-for-byte
     * the picker's meta: empty parts are dropped so a sparse address does not
     * render stray bullets.
     */
    private function meta(IpAddress $ip): string
    {
        $assignment = $ip->inventoryAsset?->asset_tag !== null
            ? 'Asset '.$ip->inventoryAsset->asset_tag
            : ($ip->is_assigned ? 'assigned' : '');

        $parts = array_filter([
            (string) $ip->type,
            $ip->status !== $ip->type ? (string) $ip->status : '',
            $assignment,
            $ip->last_seen_at !== null ? 'last seen '.$ip->last_seen_at->format('Y-m-d H:i') : '',
        ], static fn (string $part): bool => trim($part) !== '');

        return implode(' · ', $parts);
    }
}
