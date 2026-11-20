<?php

namespace App\Observers;

use App\Models\Device;
use App\Services\Inventory\InventoryChangeLogger;
use App\Support\Logging\AppLog;
use DateTimeInterface;

class DeviceObserver
{
    /**
     * Persisted attributes that are noise in a change log. `live_asset_tag`
     * is a virtual generated column — never actually persisted.
     */
    private const HIDDEN_ATTRIBUTES = ['created_at', 'updated_at', 'deleted_at', 'live_asset_tag'];

    public function __construct(
        private readonly InventoryChangeLogger $logger,
    ) {}

    public function created(Device $asset): void
    {
        $this->safe(function () use ($asset): void {
            $changes = [];
            foreach ($this->auditAttributeMap($asset) as $field => $value) {
                $changes[$field] = ['old' => null, 'new' => $value];
            }

            $this->recordAuditRow($asset, 'created', $changes);
        });
    }

    public function updated(Device $asset): void
    {
        $this->safe(function () use ($asset): void {
            $reported = $asset->getChanges();
            unset($reported['updated_at']);

            $changes = [];
            foreach ($reported as $field => $value) {
                if (in_array($field, self::HIDDEN_ATTRIBUTES, true)) {
                    continue;
                }

                $changes[$field] = [
                    'old' => $this->jsonSafeValue($field, $asset->getOriginal($field), $asset),
                    'new' => $this->jsonSafeValue($field, $value, $asset),
                ];
            }

            if ($changes === []) {
                return;
            }

            $this->recordAuditRow($asset, 'updated', $changes);
        });
    }

    public function deleted(Device $asset): void
    {
        $this->safe(function () use ($asset): void {
            $this->recordAuditRow($asset, 'deleted', [
                'snapshot' => $this->auditAttributeMap($asset),
            ]);
        });
    }

    /**
     * The model-layer audit write is best-effort by app convention (a
     * failed audit write is reported to the security channel, not thrown),
     * but a genuinely lost row is correlated to the asset and action here so
     * operators can tell "nothing changed" apart from "the trail is incomplete".
     *
     * @param  array<string, mixed>  $changes
     */
    private function recordAuditRow(Device $asset, string $action, array $changes): void
    {
        $written = $this->logger->record($asset, $action, $changes);

        if (! $written) {
            AppLog::security()->error('Device audit row was not persisted.', [
                'asset_id' => $asset->getKey(),
                'action' => $action,
            ]);
        }
    }

    /**
     * Every non-null persisted attribute, minus the noisy ones, with
     * date/decimal values normalised to JSON-safe scalars.
     *
     * @return array<string, mixed>
     */
    private function auditAttributeMap(Device $asset): array
    {
        $map = [];
        foreach ($asset->getAttributes() as $field => $value) {
            if ($value === null || in_array($field, self::HIDDEN_ATTRIBUTES, true)) {
                continue;
            }

            $map[$field] = $this->jsonSafeValue($field, $value, $asset);
        }

        return $map;
    }

    /**
     * Normalise a persisted/cast attribute value to something json_encode
     * handles without surprises: dates become Y-m-d, decimals become strings.
     */
    private function jsonSafeValue(string $field, mixed $value, Device $asset): mixed
    {
        if ($value === null) {
            return null;
        }

        $cast = strtolower((string) ($asset->getCasts()[$field] ?? ''));

        if ($value instanceof DateTimeInterface || str_contains($cast, 'date')) {
            return $value instanceof DateTimeInterface
                ? $value->format('Y-m-d')
                : substr((string) $value, 0, 10);
        }

        if (str_starts_with($cast, 'decimal')) {
            return (string) $value;
        }

        if (is_scalar($value)) {
            return $value;
        }

        // Persisted attributes are scalars; anything else (e.g. an object from
        // a custom cast) is reduced to its JSON round-trip so the row still writes.
        return json_decode((string) json_encode($value), true);
    }

    private function safe(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
