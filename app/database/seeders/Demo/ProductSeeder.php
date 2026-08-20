<?php

namespace Database\Seeders\Demo;

use Database\Seeders\Demo\Traits\WithIdempotentSeed;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Demo product catalog.
 *
 * Seeds, idempotently:
 *   product_groups          reuses the six groups InitialDataSeeder owns
 *                           (matched on `slug`), creating them only if absent
 *   products                DummyDataConfig::PRODUCTS demo products spread
 *                           across all six product groups (is_bundle=false)
 *   product_pricing         2-3 billing cycles per product
 *   product_option_groups   shared option groups (matched on `name`), linked
 *                           to products through the product_option_group_product
 *                           pivot: one dropdown + one radio + one slider-or-number
 *                           group per product (table has `created_at` only)
 *   product_option_values   2 values per option group (no timestamps)
 *   product_option_pricing  1 price modifier per value (no timestamps)
 *   product_option_link_values      per-pivot snapshot: the group's values
 *                                   copied onto every product_option_group_product
 *                                   pivot row (label, is_default, sort_order)
 *   product_option_link_value_pricing  per-link-value copy of the source
 *                                   value's product_option_pricing rows
 *   customer-editable demo link     Demo Starter Shared Hosting additionally
 *                                   owns a "Managed Support" group whose pivot
 *                                   has customer_editable = true and whose two
 *                                   values carry distinct per-cycle price
 *                                   modifiers (monthly +149 / annual +1499 /
 *                                   biennial +2799) so the store controls and
 *                                   modifier math are demonstrable
 *   product_meta            2 metadata rows per product
 *
 * ENUM VALUES ARE TAKEN FROM THE MIGRATION, NOT GUESSED
 * -----------------------------------------------------
 * 2026_07_30_120010_create_product_tables.php declares:
 *   products.billing_cycle   monthly|quarterly|semi_annual|annual|biennial|
 *                            one_time            (narrower than pricing!)
 *   products.provisioning_module manual|cpanel|plesk|directadmin|virtualizor|custom
 *   products.status / product_groups.status      active|inactive
 *   products.gst_type        standard|exempt|reverse_charge
 *   product_pricing.billing_cycle and product_option_pricing.billing_cycle
 *                            free|one_time|monthly|quarterly|semi_annual|
 *                            annual|biennial|triennial
 *   product_option_groups.type  dropdown|radio|quantity|text|number|slider|checkbox
 * SQLite compiles these into CHECK constraints, so any other value aborts the
 * seed. Every literal below is copied from that list.
 */
class ProductSeeder extends Seeder
{
    use WithIdempotentSeed;

    /**
     * Product groups owned by InitialDataSeeder. Matched on `slug` (the UNIQUE
     * column) with `seedRowOnce`, so an existing group is left exactly as the
     * operator left it and only a missing one is created.
     *
     * @var list<array{slug: string, name: string, description: string, sort_order: int, is_hosting: bool}>
     */
    private const GROUPS = [
        ['slug' => 'shared-hosting', 'name' => 'Shared Hosting', 'description' => 'Entry level cPanel hosting plans.', 'sort_order' => 1, 'is_hosting' => true],
        ['slug' => 'reseller-hosting', 'name' => 'Reseller Hosting', 'description' => 'White label reseller packages.', 'sort_order' => 2, 'is_hosting' => true],
        ['slug' => 'vps-hosting', 'name' => 'VPS Hosting', 'description' => 'Virtual private servers with dedicated resources.', 'sort_order' => 3, 'is_hosting' => true],
        ['slug' => 'dedicated-servers', 'name' => 'Dedicated Servers', 'description' => 'Single tenant bare metal servers.', 'sort_order' => 4, 'is_hosting' => true],
        ['slug' => 'domain-registration', 'name' => 'Domain Registration', 'description' => 'TLD registration, transfer and renewal.', 'sort_order' => 5, 'is_hosting' => false],
        ['slug' => 'addons-extras', 'name' => 'Addons & Extras', 'description' => 'SSL, backups, licences and other add-ons.', 'sort_order' => 6, 'is_hosting' => false],
    ];

    public function run(): void
    {
        $groups = $this->seedGroups();
        $catalog = $this->catalog();

        if (count($catalog) < DummyDataConfig::PRODUCTS) {
            throw new RuntimeException(sprintf(
                'ProductSeeder defines %d products but DummyDataConfig::PRODUCTS requires %d.',
                count($catalog),
                DummyDataConfig::PRODUCTS
            ));
        }

        foreach ($catalog as $index => $definition) {
            $productId = $this->seedProduct($definition, $groups);

            $this->seedPricing($productId, $definition['pricing']);
            $this->seedOptions($productId, $index, $definition['default_cycle']);
            $this->seedMeta($productId, $definition['meta']);
        }

        $this->seedCustomerEditableDemoLink();
        $this->backfillLinkSnapshots();
    }

    /**
     * @return array<string, int> slug => product_groups.id
     */
    private function seedGroups(): array
    {
        $ids = [];

        foreach (self::GROUPS as $group) {
            $ids[$group['slug']] = (int) $this->seedRowOnce('product_groups', $group + [
                'parent_id' => null,
                'status' => 'active',
            ]);
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, int>  $groups
     */
    private function seedProduct(array $definition, array $groups): int
    {
        return (int) $this->seedRowOnce('products', [
            'name' => $definition['name'],
            'product_group_id' => $groups[$definition['group']] ?? null,
            'description' => $definition['description'],
            'price' => $definition['price'],
            'billing_cycle' => $definition['default_cycle'],
            'setup_fee' => $definition['setup_fee'],
            'provisioning_module' => $definition['provisioning_module'],
            'server_group_id' => null,
            'welcome_email_template_id' => null,
            'require_domain' => $definition['require_domain'],
            'show_in_order' => true,
            'show_in_affiliate' => true,
            'only_admin' => false,
            'sort_order' => $definition['sort_order'],
            'is_bundle' => false,
            'status' => 'active',
            'gst_enabled' => true,
            'gst_rate' => 18.00,
            'gst_type' => 'standard',
            'cgst_rate' => 9.00,
            'sgst_rate' => 9.00,
            'igst_rate' => 18.00,
        ]);
    }

    /**
     * @param  array<string, float>  $cycles  billing_cycle => recurring price
     */
    private function seedPricing(int $productId, array $cycles): void
    {
        foreach ($cycles as $cycle => $price) {
            $this->seedRow('product_pricing', [
                'product_id' => $productId,
                'billing_cycle' => $cycle,
                'setup_fee' => 0,
                'price' => $price,
                'promo_price' => $cycle === 'annual' ? round($price * 0.9, 2) : null,
                'promo_start' => null,
                'promo_end' => null,
            ]);
        }
    }

    /**
     * Every product is linked to one dropdown, one radio and one
     * slider-or-number option group. Groups are shared across products
     * (matched on `name`); the product_option_group_product pivot row is what
     * attaches a group to a product. Each pivot row additionally gets the
     * product-options-snapshot rows (product_option_link_values +
     * product_option_link_value_pricing) copied from its group.
     */
    private function seedOptions(int $productId, int $index, string $defaultCycle): void
    {
        $pricingCycle = $defaultCycle === 'one_time' ? 'one_time' : $defaultCycle;

        foreach ($this->optionBlueprint($index) as $sort => $group) {
            $groupId = (int) $this->seedRow('product_option_groups', array_filter([
                'name' => $group['name'],
                'sort_order' => $sort + 1,
                'type' => $group['type'],
                'input_min' => $group['input_min'] ?? null,
                'input_max' => $group['input_max'] ?? null,
                'input_step' => $group['input_step'] ?? null,
            ], fn ($value) => $value !== null));

            foreach ($group['values'] as $valueSort => $value) {
                $valueId = (int) $this->seedRow('product_option_values', [
                    'option_group_id' => $groupId,
                    'label' => $value['label'],
                    'sort_order' => $valueSort + 1,
                ]);

                $this->seedRow('product_option_pricing', [
                    'option_value_id' => $valueId,
                    'billing_cycle' => $pricingCycle,
                    'price_modifier' => $value['modifier'],
                ]);
            }

            $pivotId = (int) $this->seedRow('product_option_group_product', [
                'product_id' => $productId,
                'option_group_id' => $groupId,
            ]);

            $this->seedLinkValues($pivotId, $groupId);
        }
    }

    /**
     * Copy the group's catalog values and their per-cycle pricing into the
     * product-scoped snapshot tables for one pivot row. The first value
     * becomes the default; every other value is non-default. A group with no
     * values produces no link rows (guard against a zero-value group).
     *
     * Mirrors the backfill migration and ProductOptionLinkController's
     * copyGroupValues, so a re-run converges instead of duplicating: rows are
     * matched on the natural keys declared in DummyDataConfig.
     */
    private function seedLinkValues(int $pivotId, int $groupId): void
    {
        $values = DB::table('product_option_values')
            ->where('option_group_id', $groupId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'label', 'sort_order']);

        $first = true;

        foreach ($values as $value) {
            $linkValueId = (int) $this->seedRow('product_option_link_values', [
                'product_option_group_product_id' => $pivotId,
                'label' => $value->label,
                'is_default' => $first,
                'sort_order' => $value->sort_order,
            ]);

            $first = false;

            $pricing = DB::table('product_option_pricing')
                ->where('option_value_id', $value->id)
                ->get(['billing_cycle', 'price_modifier']);

            foreach ($pricing as $price) {
                $this->seedRow('product_option_link_value_pricing', [
                    'product_option_link_value_id' => $linkValueId,
                    'billing_cycle' => $price->billing_cycle,
                    'price_modifier' => $price->price_modifier,
                ]);
            }
        }
    }

    /**
     * Give the flagship shared-hosting demo product one customer-editable
     * option group with two values whose per-cycle price modifiers are
     * distinct (monthly +149 / annual +1499 / biennial +2799), so the store
     * controls and the modifier math are demonstrable.
     *
     * The group is attached through its own pivot row with customer_editable
     * = true and the same snapshot rows as every other pivot. The group is
     * created here (it is not part of optionBlueprint) and matched on `name`,
     * so re-running the seeder never duplicates it.
     */
    private function seedCustomerEditableDemoLink(): void
    {
        $productId = (int) DB::table('products')->where('name', 'Demo Starter Shared Hosting')->value('id');

        if ($productId === 0) {
            return; // catalog not seeded yet — nothing to attach to
        }

        $groupId = (int) $this->seedRow('product_option_groups', [
            'name' => 'Managed Support',
            'sort_order' => 4,
            'type' => 'radio',
        ]);

        $managedValues = [
            ['label' => 'Standard Support', 'modifiers' => ['monthly' => 0.00, 'annual' => 0.00, 'biennial' => 0.00]],
            ['label' => 'Priority Support', 'modifiers' => ['monthly' => 149.00, 'annual' => 1499.00, 'biennial' => 2799.00]],
        ];

        foreach ($managedValues as $valueSort => $value) {
            $valueId = (int) $this->seedRow('product_option_values', [
                'option_group_id' => $groupId,
                'label' => $value['label'],
                'sort_order' => $valueSort + 1,
            ]);

            foreach ($value['modifiers'] as $cycle => $modifier) {
                $this->seedRow('product_option_pricing', [
                    'option_value_id' => $valueId,
                    'billing_cycle' => $cycle,
                    'price_modifier' => $modifier,
                ]);
            }
        }

        $pivotId = (int) $this->seedRow('product_option_group_product', [
            'product_id' => $productId,
            'option_group_id' => $groupId,
            'customer_editable' => true,
        ]);

        $this->seedLinkValues($pivotId, $groupId);
    }

    /**
     * Converge every pivot's snapshot rows to the group's final value/pricing
     * state. Groups are shared across products and their source pricing grows
     * as later products attach with a different default billing cycle, so the
     * per-pivot copy made during seedOptions may be incomplete until the
     * catalog loop has finished. This final pass re-runs seedLinkValues for
     * every pivot; rows already present are updated (natural keys), missing
     * ones inserted, so the result is identical whether this is the first seed
     * or a re-seed.
     */
    private function backfillLinkSnapshots(): void
    {
        $pivots = DB::table('product_option_group_product')
            ->get(['id', 'option_group_id']);

        foreach ($pivots as $pivot) {
            $this->seedLinkValues((int) $pivot->id, (int) $pivot->option_group_id);
        }
    }

    /**
     * @param  array<string, string>  $meta
     */
    private function seedMeta(int $productId, array $meta): void
    {
        foreach ($meta as $key => $value) {
            $this->seedRow('product_meta', [
                'product_id' => $productId,
                'meta_key' => $key,
                'meta_value' => $value,
            ]);
        }
    }

    /**
     * Option groups are keyed off the product index so labels differ between
     * products while the option-group `type` enum is exercised across the
     * new input kinds: a dropdown, a radio set, and a slider or number input
     * (with input_min / input_max / input_step).
     *
     * @return list<array{name: string, type: string, values: list<array{label: string, modifier: float}>, input_min?: int, input_max?: int, input_step?: int}>
     */
    private function optionBlueprint(int $index): array
    {
        $controlPanels = [
            ['label' => 'cPanel', 'modifier' => 0.00],
            ['label' => 'Plesk Web Admin', 'modifier' => 250.00],
        ];

        $backups = [
            ['label' => 'Daily backups', 'modifier' => 149.00],
            ['label' => 'Weekly backups', 'modifier' => 0.00],
        ];

        $diskBlocks = [
            ['label' => '10 GB block', 'modifier' => 99.00],
            ['label' => '50 GB block', 'modifier' => 399.00],
        ];

        $ipBlocks = [
            ['label' => '1 IPv4 address', 'modifier' => 199.00],
            ['label' => '4 IPv4 addresses', 'modifier' => 699.00],
        ];

        return [
            [
                'name' => 'Control Panel',
                'type' => 'dropdown',
                'values' => $controlPanels,
            ],
            [
                'name' => 'Backup Frequency',
                'type' => 'radio',
                'values' => $backups,
            ],
            $index % 2 === 0
                ? [
                    'name' => 'Extra Disk (GB)',
                    'type' => 'slider',
                    'input_min' => 10,
                    'input_max' => 500,
                    'input_step' => 10,
                    'values' => $diskBlocks,
                ]
                : [
                    'name' => 'Additional IPs',
                    'type' => 'number',
                    'input_min' => 1,
                    'input_max' => 8,
                    'input_step' => 1,
                    'values' => $ipBlocks,
                ],
        ];
    }

    /**
     * The demo products. Names are the natural key, so
     * they are deliberately prefixed to never collide with catalog rows an
     * operator created by hand.
     *
     * @return list<array<string, mixed>>
     */
    private function catalog(): array
    {
        $catalog = [
            [
                'name' => 'Demo Starter Shared Hosting',
                'group' => 'shared-hosting',
                'description' => 'Single site cPanel plan for personal projects and portfolios.',
                'price' => 199.00,
                'default_cycle' => 'monthly',
                'setup_fee' => 0.00,
                'provisioning_module' => 'cpanel',
                'require_domain' => true,
                'sort_order' => 10,
                'pricing' => ['monthly' => 199.00, 'annual' => 1999.00, 'biennial' => 3799.00],
                'meta' => ['datacenter' => 'Mumbai (BOM1)', 'support_tier' => 'standard'],
            ],
            [
                'name' => 'Demo Business Shared Hosting',
                'group' => 'shared-hosting',
                'description' => 'Unlimited sites, LiteSpeed cache and free SSL for growing businesses.',
                'price' => 499.00,
                'default_cycle' => 'monthly',
                'setup_fee' => 0.00,
                'provisioning_module' => 'cpanel',
                'require_domain' => true,
                'sort_order' => 20,
                'pricing' => ['monthly' => 499.00, 'quarterly' => 1399.00, 'annual' => 4999.00],
                'meta' => ['datacenter' => 'Mumbai (BOM1)', 'support_tier' => 'priority'],
            ],
            [
                'name' => 'Demo Reseller Bronze',
                'group' => 'reseller-hosting',
                'description' => 'WHM reseller account with 25 cPanel slots and white label nameservers.',
                'price' => 1299.00,
                'default_cycle' => 'monthly',
                'setup_fee' => 500.00,
                'provisioning_module' => 'cpanel',
                'require_domain' => true,
                'sort_order' => 30,
                'pricing' => ['monthly' => 1299.00, 'annual' => 12999.00],
                'meta' => ['whm_accounts' => '25', 'support_tier' => 'priority'],
            ],
            [
                'name' => 'Demo Cloud VPS 2GB',
                'group' => 'vps-hosting',
                'description' => 'KVM VPS with 2 vCPU, 2 GB RAM and NVMe storage.',
                'price' => 899.00,
                'default_cycle' => 'monthly',
                'setup_fee' => 0.00,
                'provisioning_module' => 'virtualizor',
                'require_domain' => false,
                'sort_order' => 40,
                'pricing' => ['monthly' => 899.00, 'semi_annual' => 4999.00, 'annual' => 8999.00],
                'meta' => ['hypervisor' => 'kvm', 'os_templates' => 'ubuntu-22.04,almalinux-9,debian-12'],
            ],
            [
                'name' => 'Demo Cloud VPS 8GB',
                'group' => 'vps-hosting',
                'description' => 'KVM VPS with 4 vCPU, 8 GB RAM, ideal for staging and CI runners.',
                'price' => 2499.00,
                'default_cycle' => 'monthly',
                'setup_fee' => 0.00,
                'provisioning_module' => 'virtualizor',
                'require_domain' => false,
                'sort_order' => 50,
                'pricing' => ['monthly' => 2499.00, 'annual' => 24999.00, 'triennial' => 69999.00],
                'meta' => ['hypervisor' => 'kvm', 'os_templates' => 'ubuntu-22.04,rocky-9,windows-2022'],
            ],
            [
                'name' => 'Demo Dedicated E3 Server',
                'group' => 'dedicated-servers',
                'description' => 'Bare metal Xeon E3 server with IPMI access and 5 usable IPv4 addresses.',
                'price' => 8999.00,
                'default_cycle' => 'monthly',
                'setup_fee' => 2500.00,
                'provisioning_module' => 'custom',
                'require_domain' => false,
                'sort_order' => 60,
                'pricing' => ['monthly' => 8999.00, 'annual' => 89999.00],
                'meta' => ['rack_location' => 'BOM1 / R12', 'ipmi' => 'included'],
            ],
            [
                'name' => 'Demo .com Domain Registration',
                'group' => 'domain-registration',
                'description' => 'One year .com registration including free WHOIS privacy.',
                'price' => 899.00,
                'default_cycle' => 'annual',
                'setup_fee' => 0.00,
                'provisioning_module' => 'manual',
                'require_domain' => true,
                'sort_order' => 70,
                'pricing' => ['annual' => 899.00, 'biennial' => 1699.00],
                'meta' => ['tld' => '.com', 'whois_privacy' => 'free'],
            ],
            [
                'name' => 'Demo SSL & Backup Addon',
                'group' => 'addons-extras',
                'description' => 'Positive SSL certificate bundled with offsite daily backups.',
                'price' => 1499.00,
                'default_cycle' => 'annual',
                'setup_fee' => 0.00,
                'provisioning_module' => 'manual',
                'require_domain' => true,
                'sort_order' => 80,
                'pricing' => ['annual' => 1499.00, 'one_time' => 1999.00],
                'meta' => ['certificate_authority' => 'Sectigo', 'backup_retention_days' => '30'],
            ],
            [
                'name' => 'Demo Legacy Hosting Pack',
                'group' => 'shared-hosting',
                'description' => 'Grandfathered hosting bundle retained for migrated legacy accounts.',
                'price' => 349.00,
                'default_cycle' => 'quarterly',
                'setup_fee' => 0.00,
                'provisioning_module' => 'directadmin',
                'require_domain' => true,
                'sort_order' => 90,
                'pricing' => ['quarterly' => 349.00, 'annual' => 1299.00, 'free' => 0.00],
                'meta' => ['legacy_plan_code' => 'LEG-2019-A', 'support_tier' => 'standard'],
            ],
        ];

        return $catalog;
    }
}
