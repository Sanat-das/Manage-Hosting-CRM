<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot linking a product to a module. Each product carries its own
 * per-module `enabled` flag, `provisioning_mode` (auto|manual) and
 * `config` JSON.
 */
#[Fillable(['product_id', 'module_slug', 'enabled', 'provisioning_mode', 'config'])]
class ProductModule extends Model
{
    public const PROVISIONING_MODE_AUTO = 'auto';

    public const PROVISIONING_MODE_MANUAL = 'manual';

    public const PROVISIONING_MODES = [self::PROVISIONING_MODE_AUTO, self::PROVISIONING_MODE_MANUAL];

    protected $table = 'product_module';

    protected $casts = [
        'enabled' => 'boolean',
        'config' => 'array',
    ];

    public function isManual(): bool
    {
        return ($this->provisioning_mode ?? self::PROVISIONING_MODE_AUTO) === self::PROVISIONING_MODE_MANUAL;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'module_slug', 'slug');
    }

    public function name(): string
    {
        return app(\App\Services\Integrations\IntegrationRegistry::class)->nameFor((string) $this->module_slug);
    }
}
