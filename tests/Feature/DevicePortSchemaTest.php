<?php

namespace Tests\Feature;

use App\Models\InventoryAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Slice A schema contract: the device_ports / port_connections /
 * port_connection_events tables, their named indexes, and the up()/down()
 * reversibility of the three create migrations.
 */
class DevicePortSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<string>
     */
    private function indexNames(string $table): array
    {
        return array_map(
            static fn (array $index): string => (string) $index['name'],
            Schema::getIndexes($table),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeAsset(string $assetTag, array $overrides = []): InventoryAsset
    {
        return InventoryAsset::create(array_merge([
            'asset_tag' => $assetTag,
            'asset_type' => 'switch',
        ], $overrides));
    }

    public function test_device_ports_table_and_indexes_exist(): void
    {
        $this->assertTrue(Schema::hasTable('device_ports'));

        $columns = array_column(Schema::getColumns('device_ports'), 'name');

        foreach ([
            'id', 'inventory_asset_id', 'name', 'port_number', 'port_type', 'media',
            'speed_bps', 'status', 'mac_address', 'source', 'snmp_if_index',
            'snmp_if_descr', 'last_synced_at', 'notes', 'created_at', 'updated_at',
        ] as $column) {
            $this->assertContains($column, $columns, "device_ports is missing column [{$column}].");
        }

        $indexes = $this->indexNames('device_ports');

        $this->assertContains('device_ports_asset_name_unique', $indexes);
        $this->assertContains('device_ports_asset_ifindex_unique', $indexes);
        $this->assertContains('device_ports_mac_address_index', $indexes);
    }

    public function test_port_connections_table_and_indexes_exist(): void
    {
        $this->assertTrue(Schema::hasTable('port_connections'));

        $columns = array_column(Schema::getColumns('port_connections'), 'name');

        foreach ([
            'id', 'port_a_id', 'port_b_id', 'cable_label', 'cable_type',
            'cable_length_m', 'cable_color', 'notes', 'created_at', 'updated_at',
        ] as $column) {
            $this->assertContains($column, $columns, "port_connections is missing column [{$column}].");
        }

        $indexes = $this->indexNames('port_connections');

        $this->assertContains('port_connections_port_a_unique', $indexes);
        $this->assertContains('port_connections_port_b_unique', $indexes);
    }

    public function test_port_connection_events_table_and_indexes_exist(): void
    {
        $this->assertTrue(Schema::hasTable('port_connection_events'));

        $columns = array_column(Schema::getColumns('port_connection_events'), 'name');

        foreach ([
            'id', 'port_connection_id', 'port_a_id', 'port_b_id', 'a_asset_tag',
            'a_port_name', 'b_asset_tag', 'b_port_name', 'action', 'cable_label',
            'cable_type', 'cable_length_m', 'cable_color', 'changed_by_user_id',
            'changed_at', 'notes',
        ] as $column) {
            $this->assertContains($column, $columns, "port_connection_events is missing column [{$column}].");
        }

        $indexes = $this->indexNames('port_connection_events');

        $this->assertContains('port_connection_events_changed_at_index', $indexes);
        $this->assertContains('port_connection_events_port_connection_id_index', $indexes);
        $this->assertContains('port_connection_events_port_a_id_port_b_id_index', $indexes);
    }

    public function test_create_migrations_are_reversible(): void
    {
        $events = require database_path('migrations/2026_10_06_000012_create_port_connection_events_table.php');
        $connections = require database_path('migrations/2026_10_06_000011_create_port_connections_table.php');
        $ports = require database_path('migrations/2026_10_06_000010_create_device_ports_table.php');

        $events->down();
        $connections->down();
        $ports->down();

        $this->assertFalse(Schema::hasTable('port_connection_events'));
        $this->assertFalse(Schema::hasTable('port_connections'));
        $this->assertFalse(Schema::hasTable('device_ports'));

        $ports->up();
        $connections->up();
        $events->up();

        $this->assertTrue(Schema::hasTable('device_ports'));
        $this->assertTrue(Schema::hasTable('port_connections'));
        $this->assertTrue(Schema::hasTable('port_connection_events'));

        $indexes = $this->indexNames('device_ports');

        $this->assertContains('device_ports_asset_name_unique', $indexes);
        $this->assertContains('device_ports_asset_ifindex_unique', $indexes);
    }

    public function test_ports_relation_links_ports_to_an_asset(): void
    {
        $asset = $this->makeAsset('SCHEMA-SW-1');

        $asset->ports()->create([
            'name' => 'GigabitEthernet1/0/1',
            'port_type' => 'ethernet',
        ]);

        $this->assertSame(1, $asset->ports()->count());
        $this->assertSame($asset->id, $asset->ports()->sole()->inventory_asset_id);
    }
}
