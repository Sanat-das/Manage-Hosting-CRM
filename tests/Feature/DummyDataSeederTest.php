<?php

namespace Tests\Feature;

use Database\Seeders\Demo\DummyDataConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * End-to-end contract tests for the demo-data seed chain.
 *
 * Every test seeds the FULL `DatabaseSeeder` chain (InitialDataSeeder +
 * AdminLteRbacSeeder + PaymentGatewaySeeder + DummyDataSeeder's 16 modules)
 * on a fresh sqlite:memory database provided by RefreshDatabase.
 *
 * Row minima come from DummyDataConfig::ROWS (the single source of truth).
 */
class DummyDataSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The rowcount contract: table => minimum rows the seed chain must produce.
     *
     * This IS `DummyDataConfig::ROWS` - the demo profile is the single source
     * of truth, so the constant references it rather than duplicating 90 hand
     * written numbers that would rot the moment the profile changes.
     *
     * @var array<string, int>
     */
    public const EXPECTED_ROWS = DummyDataConfig::ROWS;

    /** The login InitialDataSeeder owns; demo seeders must never rewrite it. */
    private const ADMIN_EMAIL = 'admin@localhost.com';

    /** The password InitialDataSeeder hashes for the admin login. */
    private const ADMIN_PASSWORD = 'Admin@123';

    /** The password every demo login is seeded with. */
    private const DEMO_PASSWORD = 'password';

    /**
     * Demo logins the chain must create, all with DEMO_PASSWORD.
     *
     * @var list<string>
     */
    private const DEMO_LOGINS = [
        'support@example.com',
        'sales@example.com',
        'marketing@example.com',
        'client1@example.com',
        'client2@example.com',
        'client3@example.com',
        'client4@example.com',
        'client5@example.com',
        'test1@example.com',
        'test2@example.com',
    ];

    /**
     * Snapshot every business table's row count.
     *
     * @return array<string, int>
     */
    private function snapshotRowCounts(): array
    {
        $counts = [];

        foreach (DummyDataConfig::tables() as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    // ─── Harness ─────────────────────────────────────────────────────

    public function test_harness_bootstraps_against_sqlite_memory(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));

        // The contract must cover every business table the profile declares.
        $this->assertSame(DummyDataConfig::ROWS, self::EXPECTED_ROWS);
        $this->assertCount(DummyDataConfig::TOTAL_BUSINESS_TABLES, self::EXPECTED_ROWS);

        // Every contracted table must actually exist in the migrated schema,
        // otherwise a typo in the profile would silently pass every assertion.
        foreach (array_keys(self::EXPECTED_ROWS) as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "Contracted table [{$table}] does not exist in the migrated schema."
            );
        }
    }

    // ─── Idempotency ─────────────────────────────────────────────────

    public function test_idempotent(): void
    {
        $this->seed();

        $first = $this->snapshotRowCounts();

        // Second run on the SAME database (no RefreshDatabase in between).
        $this->seed();

        $second = $this->snapshotRowCounts();

        // Every module is idempotent (updateOrInsert on natural keys), so a
        // re-run must not add or remove any row in any business table.
        $this->assertSame($first, $second, 'Re-running the seed chain changed row counts.');
    }

    // ─── Row-count minima ────────────────────────────────────────────

    public function test_minimum_rows(): void
    {
        $this->seed();

        $short = [];

        foreach (self::EXPECTED_ROWS as $table => $minimum) {
            // Config tables (settings, settings_properties, sequences,
            // gst_settings, payment_gateways, adminlte_*) are populated by the
            // config migrations / PaymentGatewaySeeder / InitialDataSeeder
            // rather than the Demo modules; their minima are met by those
            // sources, so the >= assertion still holds for them.
            $actual = DB::table($table)->count();

            if ($actual < $minimum) {
                $short[] = "{$table}: {$actual} < {$minimum}";
            }
        }

        // Report every shortfall at once - failing on the first one turns a
        // single run into a whack-a-mole of full re-seeds.
        $this->assertSame(
            [],
            $short,
            "Tables below their DummyDataConfig::ROWS minimum:\n  ".implode("\n  ", $short)
        );
    }

    // ─── Foreign-key integrity ───────────────────────────────────────

    /**
     * [child, fkColumn, parent, parentPk, scope]
     *
     * scope: false (full check) | 'nullable' (non-null FK values only) |
     *        [typeColumn, typeLike] (polymorphic, filtered by type).
     *
     * Column names below were verified against the real migrations. The
     * following pairs from the original plan were DROPPED because the column
     * does not exist in the schema (verified against database/migrations/):
     *   - catalog_products.product_id        (no product_id column)
     *   - provisioning_events.adapter_id     (adapter lives in payload JSON)
     *   - service_instances.hosting_account_id (no such column)
     *   - subscription_changes.subscription_id (no such column)
     *   - licenses.product_id                (licenses use inventory_asset_id)
     *   - license_assignments.hosting_account_id (polymorphic assigned_to_*)
     *   - ssl_certificates.hosting_account_id (uses customer_id/order_id)
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: array|false|string}>
     */
    public static function foreignKeyPairs(): array
    {
        $pairs = [
            // Identity & access
            ['customers', 'user_id', 'users', 'id', false],
            // Customer CRM
            ['customer_contacts', 'customer_id', 'customers', 'id', false],
            ['customer_notes', 'customer_id', 'customers', 'id', false],
            ['customer_wallet', 'customer_id', 'customers', 'id', false],
            ['credits', 'customer_id', 'customers', 'id', false],
            // Product catalog
            ['products', 'product_group_id', 'product_groups', 'id', 'nullable'],
            ['product_pricing', 'product_id', 'products', 'id', false],
            ['product_meta', 'product_id', 'products', 'id', false],
            ['product_addons', 'product_id', 'products', 'id', 'nullable'],
            // Infrastructure
            ['server_group_members', 'server_id', 'servers', 'id', false],
            ['server_group_members', 'server_group_id', 'server_groups', 'id', false],
            ['racks', 'datacenter_id', 'datacenters', 'id', false],
            // Resources & provisioning
            // Real column is pool_id (nullable), not resource_pool_id.
            ['resource_allocations', 'pool_id', 'resource_pools', 'id', 'nullable'],
            ['resource_allocations', 'resource_type_id', 'resource_types', 'id', false],
            // Services
            ['hosting_accounts', 'customer_id', 'customers', 'id', false],
            ['hosting_accounts', 'server_id', 'servers', 'id', 'nullable'],
            // Real column is catalog_product_id (FK to catalog_products).
            ['service_instances', 'catalog_product_id', 'catalog_products', 'id', false],
            // Real column is service_id (FK to service_instances).
            ['subscription_periods', 'service_id', 'service_instances', 'id', false],
            // Real column is service_id (FK to service_instances).
            ['usage_records', 'service_id', 'service_instances', 'id', false],
            // Inventory & licenses
            ['license_assignments', 'license_id', 'licenses', 'id', false],
            // Sales & billing
            ['orders', 'customer_id', 'customers', 'id', false],
            ['orders', 'product_id', 'products', 'id', false],
            ['order_items', 'order_id', 'orders', 'id', false],
            ['order_items', 'product_id', 'products', 'id', 'nullable'],
            ['order_status_history', 'order_id', 'orders', 'id', false],
            ['invoices', 'customer_id', 'customers', 'id', false],
            ['invoices', 'order_id', 'orders', 'id', 'nullable'],
            ['invoice_items', 'invoice_id', 'invoices', 'id', false],
            ['payments', 'invoice_id', 'invoices', 'id', false],
            ['transactions', 'customer_id', 'customers', 'id', false],
            ['transactions', 'invoice_id', 'invoices', 'id', 'nullable'],
            ['quotes', 'customer_id', 'customers', 'id', false],
            ['quote_items', 'quote_id', 'quotes', 'id', false],
            // Support
            ['tickets', 'customer_id', 'customers', 'id', false],
            ['ticket_replies', 'ticket_id', 'tickets', 'id', false],
            ['chat_sessions', 'customer_id', 'customers', 'id', 'nullable'],
            // Real column is session_id (FK to chat_sessions).
            ['chat_messages', 'session_id', 'chat_sessions', 'id', false],
            // Domains, DNS & IPAM
            ['dns_records', 'zone_id', 'dns_zones', 'id', false],
            ['ip_addresses', 'subnet_id', 'ip_subnets', 'id', false],
            ['ip_allocation_history', 'ip_address_id', 'ip_addresses', 'id', false],
            ['domains', 'customer_id', 'customers', 'id', false],
            ['domain_search_logs', 'customer_id', 'customers', 'id', 'nullable'],
            // Communications
            ['emails', 'customer_id', 'customers', 'id', 'nullable'],
            // Polymorphic references
            ['notification_preferences', 'preferrable_id', 'users', 'id', ['preferrable_type', '%User']],
            ['notifications', 'notifiable_id', 'users', 'id', ['notifiable_type', '%User']],
        ];

        $data = [];

        foreach ($pairs as $pair) {
            $data["{$pair[0]}.{$pair[1]} -> {$pair[2]}.{$pair[3]}"] = $pair;
        }

        return $data;
    }

    /**
     * Count rows in `$child` whose `$fk` does not resolve to a `$parent` row.
     *
     * @param  array|string|false  $scope
     */
    private function orphanCount(string $child, string $fk, string $parent, string $parentKey, $scope): int
    {
        $query = DB::table($child);

        if (is_array($scope)) {
            // Polymorphic FK: only rows of the matching type must resolve.
            $query->where($scope[0], 'like', $scope[1]);
        } elseif ($scope === 'nullable') {
            // Nullable FK: NULL values are not orphans; check the rest.
            $query->whereNotNull($fk);
        }

        return $query
            ->leftJoin($parent, "{$parent}.{$parentKey}", '=', "{$child}.{$fk}")
            ->whereNull("{$parent}.{$parentKey}")
            ->count();
    }

    /**
     * Every declared parent/child pair must resolve, on one seeded database.
     *
     * Deliberately NOT a data-provider test. A provider gives each pair its own
     * test case with its own fresh RefreshDatabase transaction, so it would
     * either re-seed the whole 19-seeder chain 45 times, or - as an earlier
     * revision did - seed NOTHING and pass 45 orphan checks trivially against
     * empty tables. One seed, 45 queries, every failure reported together.
     */
    public function test_foreign_keys(): void
    {
        $this->seed();

        $failures = [];

        foreach (self::foreignKeyPairs() as $label => [$child, $fk, $parent, $parentKey, $scope]) {
            // A renamed/removed column must fail loudly - a silent skip would
            // let the pair rot into a permanently unverified relationship.
            $this->assertTrue(
                Schema::hasColumn($child, $fk),
                "Declared FK column [{$child}.{$fk}] does not exist."
            );

            $orphans = $this->orphanCount($child, $fk, $parent, $parentKey, $scope);

            if ($orphans !== 0) {
                $failures[] = "{$label}: {$orphans} orphan(s)";
            }
        }

        $this->assertSame(
            [],
            $failures,
            "Orphan rows found:\n  ".implode("\n  ", $failures)
        );
    }

    // ─── Auth-critical rows ──────────────────────────────────────────

    public function test_admin_preserved(): void
    {
        $this->seed();

        $admin = DB::table('users')->where('email', self::ADMIN_EMAIL)->first();

        // InitialDataSeeder creates exactly one admin; a re-seedable chain must
        // never duplicate it and must keep its credentials intact.
        $this->assertNotNull($admin, self::ADMIN_EMAIL.' must exist after seeding.');
        $this->assertSame(1, DB::table('users')->where('email', self::ADMIN_EMAIL)->count());
        $this->assertSame('admin', $admin->role);
        $this->assertSame('active', $admin->status);
        $this->assertTrue(
            Hash::check(self::ADMIN_PASSWORD, $admin->password_hash),
            'Admin password hash no longer verifies against the seeded password.'
        );

        // The hash must survive a re-run byte-for-byte. Bcrypt re-salts on
        // every Hash::make, so a seeder that rewrote the row would produce a
        // different (still valid) hash - only string equality catches that.
        $this->seed();

        $after = DB::table('users')->where('email', self::ADMIN_EMAIL)->first();

        $this->assertNotNull($after);
        $this->assertSame(1, DB::table('users')->where('email', self::ADMIN_EMAIL)->count());
        $this->assertSame($admin->password_hash, $after->password_hash, 'Admin password hash was rewritten by a re-run.');
        $this->assertSame($admin->role, $after->role);
        $this->assertSame($admin->id, $after->id);
    }

    public function test_seeded_user_can_login(): void
    {
        $this->seed();

        // UserSeeder/CustomerSeeder create the demo logins with
        // Hash::make('password'); verify each hash is usable so a fresh
        // environment can actually log in as any of them.
        foreach (self::DEMO_LOGINS as $email) {
            $user = DB::table('users')->where('email', $email)->first();

            $this->assertNotNull($user, "Demo login [{$email}] must exist after seeding.");
            $this->assertTrue(
                Hash::check(self::DEMO_PASSWORD, $user->password_hash),
                "Demo login [{$email}] cannot authenticate with the documented password."
            );
        }

        // The demo password must never unlock the admin account.
        $admin = DB::table('users')->where('email', self::ADMIN_EMAIL)->first();

        $this->assertFalse(Hash::check(self::DEMO_PASSWORD, $admin->password_hash));
    }
}
