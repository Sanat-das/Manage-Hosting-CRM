<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Registry of installable modules (WP-style plugins table).
 *
 * Status flows installed -> active -> disabled / crashed. `config` holds
 * per-module global configuration as JSON (some values are encrypted by the
 * module manager later — the model only stores the JSON).
 */
#[Fillable(['slug', 'name', 'version', 'status', 'provider', 'manifest', 'config', 'crashed_at'])]
class Module extends Model
{
    public const STATUS_INSTALLED = 'installed';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_CRASHED = 'crashed';

    protected $table = 'modules';

    protected $casts = [
        'manifest' => 'array',
        'config' => 'array',
        'crashed_at' => 'datetime',
    ];

    public function productModules(): HasMany
    {
        return $this->hasMany(ProductModule::class, 'module_id');
    }
}
