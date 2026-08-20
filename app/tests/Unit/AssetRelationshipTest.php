<?php

namespace Tests\Unit;

use App\Models\AssetRelationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AssetRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_creates_asset_relationships_schema(): void
    {
        $this->assertTrue(Schema::hasTable('asset_relationships'));
        $this->assertEqualsCanonicalizing(
            [
                'id',
                'parent_kind',
                'parent_id',
                'child_kind',
                'child_id',
                'relationship_type',
                'label',
                'sort_order',
                'notes',
                'created_at',
                'updated_at',
            ],
            Schema::getColumnListing('asset_relationships'),
        );

        $indexes = array_map(
            static fn (array $index): array => [
                'columns' => $index['columns'],
                'unique' => $index['unique'],
            ],
            Schema::getIndexes('asset_relationships'),
        );

        $this->assertContains(['columns' => ['parent_kind', 'parent_id'], 'unique' => false], $indexes);
        $this->assertContains(['columns' => ['child_kind', 'child_id'], 'unique' => false], $indexes);
        $this->assertContains(
            [
                'columns' => ['parent_kind', 'parent_id', 'child_kind', 'child_id', 'relationship_type'],
                'unique' => true,
            ],
            $indexes,
        );
    }

    public function test_persists_valid(): void
    {
        $relationship = AssetRelationship::create([
            'parent_kind' => 'server',
            'parent_id' => 1,
            'child_kind' => 'product',
            'child_id' => 2,
            'label' => 'Runs product workloads',
            'notes' => 'Reporting link only',
        ]);

        $this->assertDatabaseHas('asset_relationships', [
            'id' => $relationship->id,
            'parent_kind' => 'server',
            'parent_id' => 1,
            'child_kind' => 'product',
            'child_id' => 2,
            'relationship_type' => 'hosted_on',
            'sort_order' => 0,
        ]);
        $this->assertSame(0, $relationship->sort_order);
    }

    public function test_rejects_self_relationship(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot link an asset to itself');

        AssetRelationship::create([
            'parent_kind' => 'server',
            'parent_id' => 1,
            'child_kind' => 'server',
            'child_id' => 1,
        ]);
    }

    public function test_rejects_unknown_relationship_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported relationship type 'depends_on'");

        AssetRelationship::create([
            'parent_kind' => 'server',
            'parent_id' => 1,
            'child_kind' => 'product',
            'child_id' => 2,
            'relationship_type' => 'depends_on',
        ]);
    }

    public function test_rejects_duplicate_before_database_insert(): void
    {
        AssetRelationship::create([
            'parent_kind' => 'server',
            'parent_id' => 1,
            'child_kind' => 'product',
            'child_id' => 2,
            'relationship_type' => 'manages',
        ]);

        try {
            AssetRelationship::create([
                'parent_kind' => 'server',
                'parent_id' => 1,
                'child_kind' => 'product',
                'child_id' => 2,
                'relationship_type' => 'manages',
            ]);
            $this->fail('Expected a duplicate relationship exception.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('already exists', $exception->getMessage());
        }

        $this->assertDatabaseCount('asset_relationships', 1);
    }
}
