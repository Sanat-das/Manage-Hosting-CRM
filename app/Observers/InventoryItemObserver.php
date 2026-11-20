<?php

namespace App\Observers;

use App\Models\InventoryItem;
use App\Services\Inventory\InventoryChangeLogger;
use App\Support\Logging\AppLog;
use DateTimeInterface;

class InventoryItemObserver
{
    /**
     * Persisted attributes that are noise in a change log. `live_item_asset_tag`
     * is a virtual generated column — never actually persisted.
     */
    private const HIDDEN_ATTRIBUTES = ['created_at', 'updated_at', 'deleted_at', 'live_item_asset_tag', 'id'];

    public function __construct(
        private readonly InventoryChangeLogger $logger,
    ) {}

    public function created(InventoryItem $item): void
    {
        $this->safe(function () use ($item): void {
            $changes = [];
            foreach ($this->persistedAttributes($item) as $field => $value) {
                $changes[$field] = ['old' => null, 'new' => $value];
            }

            $this->recordAuditRow($item, 'created', $changes);
        });
    }

    public function updated(InventoryItem $item): void
    {
        $this->safe(function () use ($item): void {
            $reported = $item->getChanges();
            unset($reported['updated_at']);

            $changes = [];
            foreach ($reported as $field => $value) {
                if (in_array($field, self::HIDDEN_ATTRIBUTES, true)) {
                    continue;
                }

                $changes[$field] = [
                    'old' => $this->jsonSafeValue($field, $item->getOriginal($field), $item),
                    'new' => $this->jsonSafeValue($field, $value, $item),
                ];
            }

            if ($changes === []) {
                return;
            }

            $this->recordAuditRow($item, 'updated', $changes);
        });
    }

    public function deleted(InventoryItem $item): void
    {
        $this->safe(function () use ($item): void {
            $this->recordAuditRow($item, 'deleted', [
                'snapshot' => $this->persistedAttributes($item),
            ]);
        });
    }

    /**
     * Every persisted attribute, minus the noisy ones, with date/decimal
     * values normalised to JSON-safe scalars.
     *
     * @return array<string, mixed>
     */
    private function persistedAttributes(InventoryItem $item): array
    {
        $attributes = $item->getAttributes();
        foreach (self::HIDDEN_ATTRIBUTES as $attribute) {
            unset($attributes[$attribute]);
        }

        return $attributes;
    }

    private function recordAuditRow(InventoryItem $item, string $action, array $changes): void
    {
        $written = $this->logger->record($item, $action, $changes);

        if (! $written) {
            AppLog::security()->error('Inventory item audit row was not persisted.', [
                'item_id' => $item->getKey(),
                'action' => $action,
            ]);
        }
    }

    private function jsonSafeValue(string $field, mixed $value, InventoryItem $item): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $cast = strtolower((string) ($item->getCasts()[$field] ?? ''));

        if (str_contains($cast, 'date')) {
            return substr((string) $value, 0, 10);
        }

        if (str_starts_with($cast, 'decimal')) {
            return (string) $value;
        }

        if (is_scalar($value)) {
            return $value;
        }

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
