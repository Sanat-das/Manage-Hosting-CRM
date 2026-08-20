<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill the option-link data and the order-item config snapshots.
 *
 * For every `product_option_group_product` pivot row this copies the group's
 * `product_option_values` into `product_option_link_values` and mirrors each
 * source value's `product_option_pricing` into
 * `product_option_link_value_pricing` for the new link value. For every
 * `order_items` row whose product still exists it writes the
 * `config_options` JSON snapshot. Both passes are guarded so a re-run is a
 * no-op. `down()` is intentionally empty: a data backfill is not reversed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $this->backfillOptionLinks();
            $this->backfillOrderSnapshots();
        });
    }

    /**
     * Data backfill is not reversed.
     */
    public function down(): void
    {
        // data backfill is not reversed
    }

    /**
     * Copy each pivot's group values into `product_option_link_values` and
     * mirror the source value pricing into `product_option_link_value_pricing`.
     */
    private function backfillOptionLinks(): void
    {
        DB::table('product_option_group_product')
            ->chunkById(500, function ($pivots) {
                foreach ($pivots as $pivot) {
                    // Idempotency guard: skip pivots that already have link values.
                    $alreadyBackfilled = DB::table('product_option_link_values')
                        ->where('product_option_group_product_id', $pivot->id)
                        ->exists();

                    if ($alreadyBackfilled) {
                        continue;
                    }

                    $values = DB::table('product_option_values')
                        ->where('option_group_id', $pivot->option_group_id)
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->get(['id', 'label', 'sort_order']);

                    $first = true;

                    foreach ($values as $value) {
                        $linkValueId = DB::table('product_option_link_values')->insertGetId([
                            'product_option_group_product_id' => $pivot->id,
                            'label' => $value->label,
                            'is_default' => $first,
                            'sort_order' => $value->sort_order,
                        ]);

                        $first = false;

                        $pricing = DB::table('product_option_pricing')
                            ->where('option_value_id', $value->id)
                            ->get(['billing_cycle', 'price_modifier']);

                        foreach ($pricing as $price) {
                            DB::table('product_option_link_value_pricing')->insert([
                                'product_option_link_value_id' => $linkValueId,
                                'billing_cycle' => $price->billing_cycle,
                                'price_modifier' => $price->price_modifier,
                            ]);
                        }
                    }
                }
            });
    }

    /**
     * Write `order_items.config_options` for every order item whose product
     * still exists, leaving any existing snapshot untouched.
     */
    private function backfillOrderSnapshots(): void
    {
        DB::table('order_items')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereNull('order_items.config_options')
            ->select('order_items.id', 'order_items.product_id')
            ->chunkById(500, function ($items) {
                foreach ($items as $item) {
                    $product = DB::table('products')->where('id', $item->product_id)->first();

                    $productGroupName = $product->product_group_id !== null
                        ? DB::table('product_groups')->where('id', $product->product_group_id)->value('name')
                        : null;

                    $options = [];

                    $groups = DB::table('product_option_group_product')
                        ->join('product_option_groups', 'product_option_groups.id', '=', 'product_option_group_product.option_group_id')
                        ->where('product_option_group_product.product_id', $item->product_id)
                        ->orderBy('product_option_groups.sort_order')
                        ->orderBy('product_option_groups.id')
                        ->get([
                            'product_option_group_product.id as pivot_id',
                            'product_option_groups.name as group_name',
                            'product_option_groups.type as group_type',
                        ]);

                    foreach ($groups as $group) {
                        $values = DB::table('product_option_link_values')
                            ->where('product_option_group_product_id', $group->pivot_id)
                            ->orderBy('sort_order')
                            ->orderBy('id')
                            ->pluck('label')
                            ->all();

                        $options[] = [
                            'group' => $group->group_name,
                            'type' => $group->group_type,
                            'values' => $values,
                        ];
                    }

                    DB::table('order_items')
                        ->where('id', $item->id)
                        ->update([
                            'config_options' => json_encode([
                                'product_group_name' => $productGroupName,
                                'provisioning_module' => $product->provisioning_module,
                                'options' => $options,
                            ]),
                        ]);
                }
            }, 'order_items.id', 'id');
    }
};
