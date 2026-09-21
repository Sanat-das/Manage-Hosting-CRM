<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Essential catalog option groups for a fresh install.
 *
 * Catalog-only: groups are created with explicit canonical keys and
 * sensible input constraints but are NOT attached to any product.
 * Attaching is left to the operator or to Demo\ProductSeeder.
 *
 * Idempotent: re-running the seeder updates the five canonical rows
 * matched on `product_option_groups.key` (unique) and their
 * `product_option_values` matched on `(option_group_id, label)`.
 * Exactly one catalog value per group is flagged `is_default`.
 *
 * Wired into InitialDataSeeder before AdminLteRbacSeeder so
 * `migrate:fresh --seed` on a clean install gets defaults without
 * running any Demo seeder.
 */
class EssentialOptionsSeeder extends Seeder
{
    /**
     * Canonical catalog groups.
     *
     * Keys are explicit and stable (no auto-generated slugs).
     * Units: Cores / MB / GB / GB / null (plan is free-form text).
     *
     * @var list<array{
     *     key: string,
     *     name: string,
     *     type: string,
     *     unit: string|null,
     *     sort_order: int,
     *     input_min: float|int|null,
     *     input_max: float|int|null,
     *     input_step: float|int|null,
     *     input_placeholder: string|null,
     *     values: list<array{label: string, is_default: bool, sort_order: int}>
     * }>
     */
    private const GROUPS = [
        [
            'key' => 'cpu',
            'name' => 'CPU',
            'type' => 'number',
            'unit' => 'Cores',
            'sort_order' => 1,
            'input_min' => 1,
            'input_max' => 32,
            'input_step' => 1,
            'input_placeholder' => 'e.g. 4',
            'values' => [
                ['label' => '1', 'is_default' => false, 'sort_order' => 1],
                ['label' => '2', 'is_default' => true, 'sort_order' => 2],
                ['label' => '4', 'is_default' => false, 'sort_order' => 3],
                ['label' => '8', 'is_default' => false, 'sort_order' => 4],
            ],
        ],
        [
            'key' => 'ram',
            'name' => 'RAM',
            'type' => 'number',
            'unit' => 'MB',
            'sort_order' => 2,
            'input_min' => 512,
            'input_max' => 65536,
            'input_step' => 512,
            'input_placeholder' => 'e.g. 4096',
            'values' => [
                ['label' => '1024', 'is_default' => false, 'sort_order' => 1],
                ['label' => '2048', 'is_default' => false, 'sort_order' => 2],
                ['label' => '4096', 'is_default' => true, 'sort_order' => 3],
                ['label' => '8192', 'is_default' => false, 'sort_order' => 4],
            ],
        ],
        [
            'key' => 'disk',
            'name' => 'Disk Space',
            'type' => 'number',
            'unit' => 'GB',
            'sort_order' => 3,
            'input_min' => 10,
            'input_max' => 2000,
            'input_step' => 10,
            'input_placeholder' => 'e.g. 100',
            'values' => [
                ['label' => '20', 'is_default' => false, 'sort_order' => 1],
                ['label' => '50', 'is_default' => true, 'sort_order' => 2],
                ['label' => '100', 'is_default' => false, 'sort_order' => 3],
                ['label' => '250', 'is_default' => false, 'sort_order' => 4],
            ],
        ],
        [
            'key' => 'bandwidth',
            'name' => 'Bandwidth',
            'type' => 'number',
            'unit' => 'GB',
            'sort_order' => 4,
            'input_min' => 100,
            'input_max' => 10000,
            'input_step' => 100,
            'input_placeholder' => 'e.g. 1000',
            'values' => [
                ['label' => '100', 'is_default' => false, 'sort_order' => 1],
                ['label' => '500', 'is_default' => false, 'sort_order' => 2],
                ['label' => '1000', 'is_default' => true, 'sort_order' => 3],
                ['label' => '2000', 'is_default' => false, 'sort_order' => 4],
            ],
        ],
        [
            'key' => 'plan',
            'name' => 'Plan',
            'type' => 'text',
            'unit' => null,
            'sort_order' => 5,
            'input_min' => null,
            'input_max' => null,
            'input_step' => null,
            'input_placeholder' => 'e.g. Standard',
            'values' => [
                ['label' => 'Basic', 'is_default' => false, 'sort_order' => 1],
                ['label' => 'Standard', 'is_default' => true, 'sort_order' => 2],
                ['label' => 'Premium', 'is_default' => false, 'sort_order' => 3],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::GROUPS as $group) {
            $key = $group['key'];

            // Idempotent upsert keyed by canonical `key` (unique, explicit).
            // No hard-coded IDs; ProductOptionGroup::booted would auto-slug
            // from name, but an explicit key overrides that.
            DB::table('product_option_groups')->updateOrInsert(
                ['key' => $key],
                [
                    'name' => $group['name'],
                    'unit' => $group['unit'],
                    'type' => $group['type'],
                    'sort_order' => $group['sort_order'],
                    'input_min' => $group['input_min'],
                    'input_max' => $group['input_max'],
                    'input_step' => $group['input_step'],
                    'input_placeholder' => $group['input_placeholder'],
                ]
            );

            $groupId = (int) DB::table('product_option_groups')->where('key', $key)->value('id');

            if ($groupId === 0) {
                continue;
            }

            // Catalog values: idempotent on (option_group_id, label) — the
            // natural key in DummyDataConfig. Exactly one value per group is
            // flagged is_default; the rest are explicitly false so a re-run
            // that flips the default converges rather than leaving two trues.
            foreach ($group['values'] as $value) {
                DB::table('product_option_values')->updateOrInsert(
                    [
                        'option_group_id' => $groupId,
                        'label' => $value['label'],
                    ],
                    [
                        'sort_order' => $value['sort_order'],
                        'is_default' => $value['is_default'],
                    ]
                );
            }

            // Enforce exactly-one-default invariant even if an operator
            // manually flipped flags or an older seed left a different default.
            // The declared default wins; all others are cleared.
            $defaultLabel = null;
            foreach ($group['values'] as $value) {
                if ($value['is_default']) {
                    $defaultLabel = $value['label'];
                    break;
                }
            }

            if ($defaultLabel !== null) {
                DB::table('product_option_values')
                    ->where('option_group_id', $groupId)
                    ->where('label', '!=', $defaultLabel)
                    ->update(['is_default' => false]);

                DB::table('product_option_values')
                    ->where('option_group_id', $groupId)
                    ->where('label', $defaultLabel)
                    ->update(['is_default' => true]);
            }

            // No product attachment: catalog only per decision. No
            // product_option_group_product or product_option_link_* rows.
        }
    }
}
