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

            if ($slug !== '') {
                $model->provisioning_mode = self::defaultModeFor($slug);
            }
        });
    }

    /**
     * The mode a link gets when the operator has not chosen one.
     *
     * Hyper-V links historically defaulted to manual (a Windows VM needs guest
     * credentials before it is useful); every other module defaults to auto.
     */
    public static function defaultModeFor(string $slug): string
    {
        return trim($slug) === 'hyperv'
            ? self::PROVISIONING_MODE_MANUAL
            : self::PROVISIONING_MODE_AUTO;
    }

    /**
     * Split a Details-tab selection into its module slug and mode.
     *
     * The form submits `proxmox|manual`; a legacy plain slug (`cpanel`) keeps a
     * null mode so callers preserve whatever the link already stores instead of
     * resetting it to the default. The mode is returned as-is (lowercased) so
     * the request layer can reject an invalid one instead of silently falling
     * back to the default.
     *
     * @return array{slug: string, mode: string|null}
     */
    public static function parseSelection(string $selection): array
    {
        $parts = explode('|', trim($selection), 2);
        $slug = trim($parts[0]);
        $mode = isset($parts[1]) ? strtolower(trim($parts[1])) : '';

        return [
            'slug' => $slug,
            'mode' => $mode !== '' ? $mode : null,
        ];
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
