<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceType;
use App\Models\InventoryItem;
use App\Models\Manufacturer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Guards the inventory_items table (NetBox-style component items) and the
 * component-move migration that copies live component rows out of
 * inventory_assets into it.
 *
 * RefreshDatabase migrates the sqlite :memory: database before each test,
 * so the move migration runs once on empty tables (a no-op) and each test
 * then seeds component fixtures and replays the real migration file, which
 * is what an upgrade does.
 *
 * The frozen 2026_11_18_100002 move migration cannot replay against the
 * post-rename schema: it reads the removed `inventory_assets` table and
 * writes the removed `inventory_items.inventory_asset_id` column. So the
 * replay tests first recreate that legacy shape as a copy of `devices`
 * (plus the legacy `inventory_items.inventory_asset_id` column) — decision 6
 * keeps the migration file itself untouched.
 */
final class InventoryItemSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const CREATE_MIGRATION = 'migrations/2026_11_18_100001_create_inventory_items_table.php';

    private const MOVE_MIGRATION = 'migrations/2026_11_18_100002_move_component_assets_to_inventory_items.php';

    private const CREATE_ASSET_RELATIONSHIPS_MIGRATION = 'migrations/2026_08_06_000001_create_asset_relationships_table.php';

    private function requireMigration(string $relativePath): Migration
    {
        $path = database_path($relativePath);

        $this->assertFileExists($path, "Migration [{$relativePath}] is missing.");

        /** @var Migration $migration */
        $migration = require $path;

        return $migration;
    }

    private function makeServerAsset(string $tag): int
    {
        return (int) Device::create([
            'asset_tag' => $tag,
            'device_type_id' => (int) DeviceType::factory()->create(['category' => 'server'])->id,
            'status' => 'installed',
            'model' => 'PowerEdge R650',
        ])->id;
    }

    /** @var array<int, int> component id => parent_asset_id, applied to the legacy copy after the CTAS. */
    private array $legacyParents = [];

    /** @var array<int, string> legacy id => asset_type, replayed into the recreated legacy table after the CTAS. */
    private array $legacyTypes = [];

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeComponentAsset(array $attributes): int
    {
        // parent_asset_id and asset_type are LEGACY columns of the
        // pre-rename schema (devices has neither), so they are held back from
        // the insert and applied to the recreated legacy table by
        // recreateLegacyInventoryAssetsTable().
        $parent = $attributes['parent_asset_id'] ?? null;
        $type = $attributes['asset_type'] ?? 'cpu';
        unset($attributes['parent_asset_id'], $attributes['asset_type']);

        $id = (int) DB::table('devices')->insertGetId(array_merge([
            'asset_tag' => 'CMP-'.uniqid(),
            'serial_number' => null,
            'manufacturer' => null,
            'model' => null,
            'vendor' => null,
            'status' => 'installed',
            'notes' => null,
            'created_at' => '2026-10-01 10:00:00',
            'updated_at' => '2026-10-02 11:00:00',
        ], $attributes));

        $this->legacyTypes[$id] = $type;

        if ($parent !== null) {
            $this->legacyParents[$id] = (int) $parent;
        }

        return $id;
    }

    /**
     * Recreate the pre-rename schema the frozen move migration expects:
     * a legacy `inventory_assets` table (a copy of `devices`, minus the
     * virtual live_asset_tag column) carrying the legacy `parent_asset_id`
     * column, and the legacy `inventory_items.inventory_asset_id` column.
     */
    private function recreateLegacyInventoryAssetsTable(): void
    {
        // devices no longer carries asset_type (dropped by
        // 2026_11_19_000005_replace_asset_type_with_device_type), so the CTAS
        // cannot copy it — the legacy column the frozen move migration reads
        // is re-added afterwards and filled from the captured component map.
        DB::statement('CREATE TABLE inventory_assets AS SELECT id, asset_tag, serial_number, device_type_id, manufacturer, model, vendor, purchase_date, purchase_cost, warranty_expiry, datacenter_id, rack_id, rack_u_position, status, notes, created_at, updated_at, deleted_at FROM devices');

        // CTAS carries no key metadata; the legacy FK from inventory_items
        // needs a unique referenced column.
        DB::statement('CREATE UNIQUE INDEX inventory_assets_id_unique ON inventory_assets(id)');

        Schema::table('inventory_assets', function (Blueprint $table) {
            $table->string('asset_type')->nullable();
            $table->unsignedInteger('parent_asset_id')->nullable()->index();
        });

        foreach (DB::table('inventory_assets')->pluck('id') as $id) {
            DB::table('inventory_assets')->where('id', $id)->update([
                'asset_type' => $this->legacyTypes[(int) $id] ?? 'server',
            ]);
        }

        // The frozen move migration expects the pre-rename inventory_items
        // shape (NOT NULL legacy inventory_asset_id, no device_id). Recreate
        // it through the frozen create migration, exactly as an upgrade did.
        Schema::dropIfExists('inventory_items');
        $this->requireMigration(self::CREATE_MIGRATION)->up();

        foreach ($this->legacyParents as $id => $parent) {
            DB::table('inventory_assets')->where('id', $id)->update(['parent_asset_id' => $parent]);
        }
    }

    /**
     * The move migration also reads and deletes from asset_relationships
     * (both inside the per-row COALESCE and in the trailing edge cleanup).
     * A1's drop migration removes the table from the fresh schema, so the
     * historical SQL has no table to prepare against. Recreate it empty
     * from the historical create migration — fixtures never insert rows,
     * the replay only needs the SQL to prepare and run.
     */
    private function recreateLegacyAssetRelationshipsTable(): void
    {
        $this->requireMigration(self::CREATE_ASSET_RELATIONSHIPS_MIGRATION)->up();
    }

    // ─────────────────────────────────────────────────────────────────
    // Schema contract
    // ─────────────────────────────────────────────────────────────────

    public function test_inventory_items_table_schema_matches_contract(): void
    {
        $this->assertTrue(Schema::hasTable('devices'));
        $this->assertTrue(Schema::hasTable('inventory_items'));
        $this->assertFalse(Schema::hasTable('asset_relationships'), 'asset_relationships must be dropped from the fresh schema.');

        $this->assertTrue(Schema::hasColumns('inventory_items', [
            'id',
            'device_id',
            'parent_item_id',
            'name',
            'label',
            'sku',
            'manufacturer_id',
            'part_number',
            'serial_number',
            'asset_tag',
            'live_item_asset_tag',
            'status',
            'discovered',
            'description',
            'notes',
            'created_at',
            'updated_at',
            'deleted_at',
        ]));

        $foreignKeys = collect(Schema::getForeignKeys('inventory_items'));

        $deviceFk = $foreignKeys->first(fn (array $fk) => in_array('device_id', $fk['columns'], true));
        $this->assertNotNull($deviceFk, 'inventory_items.device_id must carry a foreign key.');
        $this->assertSame('devices', $deviceFk['foreign_table']);
        $this->assertSame('id', $deviceFk['foreign_columns'][0]);
        $this->assertSame('cascade', strtolower((string) $deviceFk['on_delete']));

        $parentFk = $foreignKeys->first(fn (array $fk) => in_array('parent_item_id', $fk['columns'], true));
        $this->assertNotNull($parentFk, 'inventory_items.parent_item_id must carry a foreign key.');
        $this->assertSame('inventory_items', $parentFk['foreign_table']);
        $this->assertSame('cascade', strtolower((string) $parentFk['on_delete']));

        $manufacturerFk = $foreignKeys->first(fn (array $fk) => in_array('manufacturer_id', $fk['columns'], true));
        $this->assertNotNull($manufacturerFk, 'inventory_items.manufacturer_id must carry a foreign key.');
        $this->assertSame('manufacturers', $manufacturerFk['foreign_table']);
        $this->assertSame('set null', strtolower((string) $manufacturerFk['on_delete']));

        $indexes = collect(Schema::getIndexes('inventory_items'));
        $liveTagIndex = $indexes->first(fn (array $index) => $index['name'] === 'inventory_items_live_item_asset_tag_unique');
        $this->assertNotNull($liveTagIndex, 'The unique index inventory_items_live_item_asset_tag_unique is missing.');
        $this->assertTrue((bool) $liveTagIndex['unique'], 'inventory_items_live_item_asset_tag_unique must be unique.');

        $columns = collect(Schema::getColumns('inventory_items'));
        $discovered = $columns->first(fn (array $column) => $column['name'] === 'discovered');
        $this->assertNotNull($discovered, 'inventory_items.discovered is missing.');
        $this->assertContains(strtolower((string) $discovered['type_name']), ['boolean', 'tinyint'], 'discovered must be a boolean column.');
    }

    public function test_live_item_asset_tag_is_generated_and_enforces_uniqueness(): void
    {
        $server = $this->makeServerAsset('DEMO-SRV-GEN');

        InventoryItem::create(['device_id' => $server, 'name' => 'CPU A', 'asset_tag' => 'LIVE-TAG-1']);

        try {
            InventoryItem::create(['device_id' => $server, 'name' => 'CPU B', 'asset_tag' => 'LIVE-TAG-1']);
            $this->fail('A duplicate live asset_tag must be rejected by the generated unique index.');
        } catch (QueryException) {
            // expected: live_item_asset_tag unique index rejected the duplicate
        }

        // Soft-deleting the holder frees the tag for reuse.
        InventoryItem::withTrashed()->where('asset_tag', 'LIVE-TAG-1')->firstOrFail()->delete();

        InventoryItem::create(['device_id' => $server, 'name' => 'CPU C', 'asset_tag' => 'LIVE-TAG-1']);

        $this->assertSame(2, InventoryItem::withTrashed()->where('asset_tag', 'LIVE-TAG-1')->count());
    }

    public function test_status_enum_rejects_unknown_values(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Enum enforcement is asserted against the sqlite check constraint.');
        }

        $server = $this->makeServerAsset('DEMO-SRV-ENUM');

        $threw = false;

        try {
            DB::table('inventory_items')->insert([
                'device_id' => $server,
                'name' => 'Bogus Item',
                'status' => 'not_a_status',
                'discovered' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'The status column must reject values outside the enum.');
    }

    public function test_create_inventory_items_migration_is_reversible(): void
    {
        $migration = $this->requireMigration(self::CREATE_MIGRATION);

        $migration->down();

        $this->assertFalse(Schema::hasTable('inventory_items'), 'down() must drop the inventory_items table.');

        $migration->up();

        $this->assertTrue(Schema::hasTable('inventory_items'), 'up() must recreate the inventory_items table.');
        $this->assertTrue(Schema::hasColumn('inventory_items', 'live_item_asset_tag'));
    }

    public function test_move_migration_down_throws(): void
    {
        $migration = $this->requireMigration(self::MOVE_MIGRATION);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not reversible');

        $migration->down();
    }

    // ─────────────────────────────────────────────────────────────────
    // Component move
    // ─────────────────────────────────────────────────────────────────

    public function test_move_migration_relocates_components_and_preserves_provenance(): void
    {
        $serverOne = $this->makeServerAsset('DEMO-SRV-0001');
        $serverTwo = $this->makeServerAsset('DEMO-SRV-0002');
        $otherHardware = $this->makeComponentAsset(['asset_type' => 'other_hardware', 'asset_tag' => 'OTH-1']);

        Manufacturer::factory()->create(['name' => 'Dell Inc.', 'slug' => 'dell-inc']);

        $cpuId = $this->makeComponentAsset([
            'asset_type' => 'cpu',
            'asset_tag' => 'Intel-6250',
            'model' => 'Intel Xeon Gold 6250',
            'manufacturer' => 'dell inc.', // case-insensitive match against manufacturers.name
            'serial_number' => 'CPU-SN-0001',
            'status' => 'received',
            'notes' => 'keep-me',
            'parent_asset_id' => $serverOne,
        ]);

        $ssdId = $this->makeComponentAsset([
            'asset_type' => 'ssd',
            'asset_tag' => 'DEMO-STO-0001',
            'model' => 'Samsung PM9A3',
            'manufacturer' => 'No Such Manufacturer LLC',
            'status' => 'installed',
            'parent_asset_id' => $serverTwo,
        ]);

        $ramId = $this->makeComponentAsset([
            'asset_type' => 'ram_module',
            'asset_tag' => 'DEMO-RAM-0001',
            'model' => null, // name must fall back to the asset_tag
            'status' => 'installed',
            'parent_asset_id' => $serverTwo,
        ]);

        $this->recreateLegacyInventoryAssetsTable();
        $this->recreateLegacyAssetRelationshipsTable();

        $migration = $this->requireMigration(self::MOVE_MIGRATION);
        $migration->up();

        // All three components now live in inventory_items.
        $items = InventoryItem::query()->orderBy('id')->get();
        $this->assertCount(3, $items);

        $cpuItem = $items->firstWhere('asset_tag', 'Intel-6250');
        $this->assertNotNull($cpuItem);
        // The frozen migration records the owning device in the legacy
        // inventory_asset_id column (pre-rename name).
        $this->assertSame($serverOne, (int) DB::table('inventory_items')->where('asset_tag', 'Intel-6250')->value('inventory_asset_id'));
        $this->assertSame('Intel Xeon Gold 6250', $cpuItem->name);
        $this->assertSame('received', $cpuItem->status);
        $this->assertSame('CPU-SN-0001', $cpuItem->serial_number);
        $this->assertSame('keep-me', $cpuItem->notes);
        $this->assertFalse($cpuItem->discovered);
        $this->assertNull($cpuItem->sku);
        $this->assertNull($cpuItem->part_number);
        $this->assertSame('2026-10-01 10:00:00', $cpuItem->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-02 11:00:00', $cpuItem->updated_at->format('Y-m-d H:i:s'));

        $dell = Manufacturer::where('slug', 'dell-inc')->firstOrFail();
        $this->assertSame($dell->id, $cpuItem->manufacturer_id, 'manufacturer must match manufacturers.name case-insensitively.');

        $ssdItem = $items->firstWhere('asset_tag', 'DEMO-STO-0001');
        $this->assertNotNull($ssdItem);
        $this->assertSame($serverTwo, (int) DB::table('inventory_items')->where('asset_tag', 'DEMO-STO-0001')->value('inventory_asset_id'), 'device must resolve through legacy parent_asset_id.');
        $this->assertNull($ssdItem->manufacturer_id, 'an unmatched manufacturer must resolve to NULL, not a guess.');

        $ramItem = $items->firstWhere('asset_tag', 'DEMO-RAM-0001');
        $this->assertNotNull($ramItem);
        $this->assertSame('DEMO-RAM-0001', $ramItem->name, 'name must fall back to asset_tag when model is null.');
        $this->assertSame($serverTwo, (int) DB::table('inventory_items')->where('asset_tag', 'DEMO-RAM-0001')->value('inventory_asset_id'));

        // Source rows are soft-deleted with the provenance marker.
        $sources = DB::table('inventory_assets')->whereIn('id', [$cpuId, $ssdId, $ramId])->get()->keyBy('id');
        foreach ([$cpuId, $ssdId, $ramId] as $id) {
            $this->assertNotNull($sources[$id]->deleted_at, "source asset {$id} must be soft-deleted.");
            $this->assertStringContainsString('[migrated to inventory_items #', (string) $sources[$id]->notes);
        }
        $this->assertSame('keep-me [migrated to inventory_items #'.$cpuItem->id.']', $sources[$cpuId]->notes);

        // The other-hardware row was not touched.
        $this->assertDatabaseHas('devices', ['id' => $otherHardware, 'deleted_at' => null]);
        $this->assertSame(2, Device::whereIn('asset_tag', ['DEMO-SRV-0001', 'DEMO-SRV-0002'])->count(), 'servers must stay in devices.');

        // Idempotency: a second run moves zero rows.
        $migration->up();

        $this->assertSame(3, InventoryItem::count(), 're-running the migration must not duplicate inventory_items rows.');
        $this->assertSame('keep-me [migrated to inventory_items #'.$cpuItem->id.']', DB::table('inventory_assets')->where('id', $cpuId)->value('notes'));
    }

    public function test_move_migration_throws_when_parent_cannot_be_resolved(): void
    {
        $orphan = $this->makeComponentAsset([
            'asset_type' => 'gpu',
            'asset_tag' => 'ORPHAN-GPU-1',
        ]);

        $this->recreateLegacyInventoryAssetsTable();
        $this->recreateLegacyAssetRelationshipsTable();

        $migration = $this->requireMigration(self::MOVE_MIGRATION);

        try {
            $migration->up();
            $this->fail('The migration must throw when a component row has no resolvable parent.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString((string) $orphan, $exception->getMessage());
        }

        $this->assertSame(0, InventoryItem::withTrashed()->count(), 'a failed run must roll everything back.');
        $this->assertNull(DB::table('inventory_assets')->where('id', $orphan)->value('deleted_at'));
    }
}
