<?php

namespace App\Services\Inventory;

use App\Models\Device;
use App\Models\InventoryItem;
use App\Models\Tag;
use App\Support\Audit\AuditRecorder;
use Throwable;

class InventoryChangeLogger
{
    private const ENTITY_TYPE = 'device';

    /**
     * Audit entity_type used for inventory items (components installed
     * inside a device).
     */
    private const ITEM_ENTITY_TYPE = 'inventory_item';

    /**
     * Fields that hold free text an operator pastes credentials into. The
     * audit trail's job is to prove that they changed, not to reproduce the
     * secret, so the value is masked and a marker records that it changed.
     */
    private const REDACTED_FIELDS = ['notes'];

    public function __construct(
        private readonly AuditRecorder $recorder,
    ) {}

    /** @param  array<string, mixed>  $changes */
    public function record(Device|InventoryItem $subject, string $action, array $changes = []): bool
    {
        $entityType = $subject instanceof InventoryItem ? self::ITEM_ENTITY_TYPE : self::ENTITY_TYPE;

        return $this->recorder->entityRef($action, $entityType, (int) $subject->getKey(), $this->sanitize($changes));
    }

    /**
     * @param  array<int, int>  $beforeIds
     * @param  array<int, int>  $afterIds
     * @param  array<int, string>  $namesById  id => name, required when the ids may already be gone from the database (e.g. deleting a tag records the row after the cascade)
     */
    public function recordTagChange(Device $asset, array $beforeIds, array $afterIds, array $namesById = []): bool
    {
        sort($beforeIds);
        sort($afterIds);

        if ($beforeIds === $afterIds) {
            return true;
        }

        try {
            $names = $this->names(array_unique(array_merge($beforeIds, $afterIds)), $namesById);

            return $this->record($asset, 'tags_updated', [
                'tags' => [
                    'old' => $this->label($beforeIds, $names),
                    'new' => $this->label($afterIds, $names),
                ],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function sanitize(array $changes): array
    {
        foreach ($changes as $field => $change) {
            if (is_string($field) && in_array(strtolower($field), self::REDACTED_FIELDS, true)) {
                $changes[$field] = ['redacted' => true];

                continue;
            }

            if (is_array($change)) {
                $changes[$field] = $this->sanitize($change);
            }
        }

        return $changes;
    }

    /**
     * @param  array<int, int>  $ids
     * @param  array<int, string>  $namesById
     * @return array<int, string>
     */
    private function names(array $ids, array $namesById = []): array
    {
        $names = [];
        foreach ($namesById as $id => $name) {
            $names[(int) $id] = (string) $name;
        }

        $missing = array_values(array_diff($ids, array_keys($names)));
        if ($missing !== []) {
            $names += Tag::whereIn('id', $missing)->pluck('name', 'id')->all();
        }

        return $names;
    }

    /**
     * @param  array<int, int>  $ids
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    private function label(array $ids, array $names): array
    {
        return array_values(array_map(
            static fn (int $id): string => $names[$id] ?? "unknown-{$id}",
            $ids,
        ));
    }
}
