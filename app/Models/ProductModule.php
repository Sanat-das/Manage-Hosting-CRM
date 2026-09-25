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

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $attrs = $model->getAttributes();

            // Respect an explicit provisioning_mode value — only default when
            // the caller did not provide one (null/blank/missing). Existing
            // rows are untouched; this only affects INSERT.
            $hasExplicitMode = array_key_exists('provisioning_mode', $attrs)
                && $attrs['provisioning_mode'] !== null
                && trim((string) $attrs['provisioning_mode']) !== '';

            if ($hasExplicitMode) {
                return;
            }

            $slug = trim((string) ($model->module_slug ?? ''));

            if ($slug === 'hyperv') {
                $model->provisioning_mode = self::PROVISIONING_MODE_MANUAL;
            } elseif ($slug !== '' && ($attrs['provisioning_mode'] ?? null) === null) {
                // Non-hyperv links keep the historical default; fill explicitly
                // so the in-memory model matches the DB default after save.
                $model->provisioning_mode = self::PROVISIONING_MODE_AUTO;
            }
        });
    }

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
