<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

#[Fillable(['parent_kind', 'parent_id', 'child_kind', 'child_id', 'relationship_type', 'label', 'sort_order', 'notes'])]
class AssetRelationship extends Model
{
    public const DEFAULT_RELATIONSHIP_TYPE = 'hosted_on';

    public const RELATIONSHIP_TYPES = [
        'hosted_on',
        'hosted_in',
        'manages',
        'contains',
    ];

    /**
     * Asset kinds selectable in the admin UI (storage value => label).
     * The model itself accepts any kind string; this list constrains the
     * admin form and index filters.
     */
    public const ASSET_KINDS = [
        'product' => 'Product',
        'server' => 'Server',
        'hosting_account' => 'Hosting Account',
        'datacenter' => 'Datacenter',
        'rack' => 'Rack',
        'ip_subnet' => 'IP Subnet',
        'vlan' => 'VLAN',
        'license' => 'License',
        'resource_pool' => 'Resource Pool',
        'inventory_asset' => 'Inventory Asset',
    ];

    protected $table = 'asset_relationships';

    protected $attributes = [
        'relationship_type' => self::DEFAULT_RELATIONSHIP_TYPE,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $relationship): void {
            self::validate(
                $relationship->getAttributes(),
                $relationship->exists ? (int) $relationship->getKey() : null,
            );
        });
    }

    public static function validate(array $attributes, ?int $ignoreId = null): void
    {
        $relationshipType = array_key_exists('relationship_type', $attributes)
            ? $attributes['relationship_type']
            : self::DEFAULT_RELATIONSHIP_TYPE;

        if (! in_array($relationshipType, self::RELATIONSHIP_TYPES, true)) {
            throw new InvalidArgumentException(
                'Unsupported relationship type '.var_export($relationshipType, true).'. Allowed types: '.implode(', ', self::RELATIONSHIP_TYPES).'.',
            );
        }

        $parentKind = $attributes['parent_kind'] ?? null;
        $parentId = $attributes['parent_id'] ?? null;
        $childKind = $attributes['child_kind'] ?? null;
        $childId = $attributes['child_id'] ?? null;

        if (
            $parentKind !== null
            && $childKind !== null
            && $parentKind === $childKind
            && $parentId !== null
            && $childId !== null
            && (string) $parentId === (string) $childId
        ) {
            throw new InvalidArgumentException('Asset relationships cannot link an asset to itself.');
        }

        if ($parentKind === null || $parentId === null || $childKind === null || $childId === null) {
            return;
        }

        $duplicate = static::query()
            ->where('parent_kind', $parentKind)
            ->where('parent_id', $parentId)
            ->where('child_kind', $childKind)
            ->where('child_id', $childId)
            ->where('relationship_type', $relationshipType)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '<>', $ignoreId))
            ->exists();

        if ($duplicate) {
            throw new InvalidArgumentException(
                'An asset relationship with this parent, child, and type already exists.',
            );
        }
    }
}
