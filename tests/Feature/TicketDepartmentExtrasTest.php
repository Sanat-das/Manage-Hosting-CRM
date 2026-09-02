<?php

namespace Tests\Feature;

use App\Models\TicketDepartment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * T1 — additive columns on ticket_departments (description/signature/is_default).
 */
class TicketDepartmentExtrasTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_adds_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('ticket_departments', 'description'));
        $this->assertTrue(Schema::hasColumn('ticket_departments', 'signature'));
        $this->assertTrue(Schema::hasColumn('ticket_departments', 'is_default'));
    }

    public function test_backfill_sets_nulls_and_exactly_one_default(): void
    {
        // Seeded by 2026_08_28_000004_create_ticket_departments_table.php + backfill.
        $rows = DB::table('ticket_departments')->get();

        foreach ($rows as $row) {
            $this->assertNull($row->description, "description should be NULL for {$row->slug}");
            $this->assertNull($row->signature, "signature should be NULL for {$row->slug}");
        }

        $defaultCount = DB::table('ticket_departments')->where('is_default', true)->count();
        $this->assertSame(1, $defaultCount, 'exactly one department should be default after migrate');

        $default = DB::table('ticket_departments')->where('is_default', true)->first();
        $this->assertSame('support', $default->slug, 'support should be the default when none was set');
    }

    public function test_is_default_exactly_one_after_migrate(): void
    {
        $count = TicketDepartment::where('is_default', true)->count();
        $this->assertSame(1, $count, 'is_default must have exactly one true row');

        // No row should have is_default = NULL (boolean default false covers new inserts).
        $nulls = DB::table('ticket_departments')->whereNull('is_default')->count();
        $this->assertSame(0, $nulls, 'is_default should never be NULL');
    }

    public function test_is_default_index_exists(): void
    {
        // Verify the index declared via $table->index('is_default') exists.
        // SQLite stores indexes in sqlite_master; MySQL via information_schema.
        $connection = DB::connection()->getDriverName();

        if ($connection === 'sqlite') {
            $indexes = DB::select("SELECT name, tbl_name, sql FROM sqlite_master WHERE type='index' AND tbl_name='ticket_departments'");
            $found = false;
            foreach ($indexes as $idx) {
                if (str_contains(strtolower((string) $idx->sql), 'is_default') || str_contains(strtolower((string) $idx->name), 'is_default')) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'index on is_default should exist (sqlite_master)');
        } else {
            $hasIndex = collect(Schema::getIndexes('ticket_departments'))
                ->pluck('name')
                ->filter(fn ($n) => str_contains(strtolower($n), 'is_default'))
                ->isNotEmpty();

            // Fallback: Doctrine DBAL may expose columns.
            if (! $hasIndex) {
                $indexes = Schema::getIndexes('ticket_departments');
                $hasIndex = collect($indexes)->contains(fn ($idx) => in_array('is_default', $idx['columns'] ?? [], true));
            }

            $this->assertTrue($hasIndex, 'index on is_default should exist');
        }
    }

    public function test_fillable_and_casts_include_new_fields(): void
    {
        $dept = TicketDepartment::where('slug', 'support')->firstOrFail();

        // Fillable: mass-assign should persist.
        $dept->fill([
            'description' => 'Help desk for general support',
            'signature' => "--\nSupport Team",
            'is_default' => true,
        ]);
        $dept->save();
        $dept->refresh();

        $this->assertSame('Help desk for general support', $dept->description);
        $this->assertSame("--\nSupport Team", $dept->signature);
        $this->assertTrue($dept->is_default);
        $this->assertIsBool($dept->is_default, 'is_default should cast to boolean');

        // Non-default rows stay false.
        $billing = TicketDepartment::where('slug', 'billing')->firstOrFail();
        $this->assertFalse((bool) $billing->is_default);
    }

    public function test_columns_are_nullable_and_default_false(): void
    {
        $dept = TicketDepartment::create([
            'name' => 'Abuse Extra',
            'slug' => 'abuse_extra',
            'enabled' => true,
            'sort_order' => 999,
        ]);

        $dept->refresh();
        $this->assertNull($dept->description);
        $this->assertNull($dept->signature);
        $this->assertFalse($dept->is_default, 'is_default should default to false');
    }
}
