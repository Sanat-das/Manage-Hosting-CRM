<?php

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use Illuminate\Support\Facades\DB;

/**
 * Single-item deletion, shared by the admin panel and the API.
 *
 * NetBox treats InventoryItem.parent as FK(self, on_delete=CASCADE): deleting
 * an item deletes the whole subtree. This schema soft-deletes items so the
 * FK cascade never fires, so the subtree is soft-deleted here instead —
 * the target plus every live descendant found by walking parent_item_id.
 * Each deleted row gets its own inventory_item audit row (a snapshot, like
 * the asset teardown path), and InventoryItemObserver is intentionally
 * bypassed so the fail-closed audit write is the only audit source. A
 * failed audit write rolls the whole deletion back.
 */
final class InventoryItemDeletionService
{
    public function delete(InventoryItem $item): void
    {
        DB::transaction(function () use ($item): void {
            $target = InventoryItem::query()
                ->whereKey($item->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $subtree = $this->collectSubtree($target);

            $changeLog = app(InventoryChangeLogger::class);

            foreach ($subtree as $subtreeItem) {
                $snapshot = $this->itemSnapshot($subtreeItem);
                InventoryItem::withoutEvents(fn () => $subtreeItem->delete());

                $written = $changeLog->record($subtreeItem, 'deleted', ['snapshot' => $snapshot]);

                if (! $written) {
                    throw new \RuntimeException("Audit log write failed for inventory item {$subtreeItem->getKey()}; the item was not deleted.");
                }
            }
        });
    }

    /**
     * The target plus every live descendant, depth-first, each locked for
     * update. Bounded by a seen-set so a pre-existing cycle in legacy data
     * cannot loop forever.
     *
     * @return array<int, InventoryItem>
     */
    private function collectSubtree(InventoryItem $target): array
    {
        $subtree = [];
        $seen = [];
        $stack = [$target];

        while ($stack !== []) {
            /** @var InventoryItem $current */
            $current = array_pop($stack);
            $id = (int) $current->getKey();

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $subtree[] = $current;

            $children = InventoryItem::query()
                ->where('parent_item_id', $id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($children->reverse() as $child) {
                $stack[] = $child;
            }
        }

        return $subtree;
    }

    /**
     * Every non-null persisted attribute, minus the noisy ones and the
     * virtual generated column.
     *
     * @return array<string, mixed>
     */
    private function itemSnapshot(InventoryItem $item): array
    {
        $hidden = ['created_at', 'updated_at', 'deleted_at', 'live_item_asset_tag'];
        $snapshot = [];

        foreach ($item->getAttributes() as $field => $value) {
            if ($value === null || in_array($field, $hidden, true)) {
                continue;
            }

            $snapshot[$field] = $value;
        }

        return $snapshot;
    }
}
