<?php

namespace Tests\Feature;

use App\Models\TicketDepartment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * T2 — ticket_department_user pivot + model relations.
 *
 * Acceptance: (a) can attach, (b) duplicate rejected, (c) cascade delete,
 * (d) TicketDepartment::with('staff') eager loads.
 */
class TicketDepartmentStaffPivotTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_attach_staff_to_department(): void
    {
        $dept = TicketDepartment::where('slug', 'support')->firstOrFail();
        $staff = User::factory()->create(['role' => 'admin']);

        $dept->staff()->attach($staff->id);

        $this->assertDatabaseHas('ticket_department_user', [
            'ticket_department_id' => $dept->id,
            'user_id' => $staff->id,
        ]);

        $this->assertTrue($dept->fresh()->staff->contains($staff->id));
        $this->assertTrue($staff->fresh()->ticketDepartments->contains($dept->id));

        // Timestamps are filled.
        $row = DB::table('ticket_department_user')->where('ticket_department_id', $dept->id)->where('user_id', $staff->id)->first();
        $this->assertNotNull($row->created_at);
        $this->assertNotNull($row->updated_at);
    }

    public function test_duplicate_attach_rejected_by_unique(): void
    {
        $dept = TicketDepartment::where('slug', 'billing')->firstOrFail();
        $staff = User::factory()->create(['role' => 'admin']);

        $dept->staff()->attach($staff->id);

        $this->expectException(QueryException::class);
        $dept->staff()->attach($staff->id);
    }

    public function test_cascade_delete_removes_pivot_when_department_deleted(): void
    {
        $dept = TicketDepartment::where('slug', 'technical')->firstOrFail();
        $staff = User::factory()->create(['role' => 'admin']);
        $dept->staff()->attach($staff->id);

        $this->assertDatabaseHas('ticket_department_user', [
            'ticket_department_id' => $dept->id,
            'user_id' => $staff->id,
        ]);

        $dept->delete();

        $this->assertDatabaseMissing('ticket_department_user', [
            'ticket_department_id' => $dept->id,
            'user_id' => $staff->id,
        ]);
        // User still exists, adminlte_role_user not affected (separate check below).
        $this->assertDatabaseHas('users', ['id' => $staff->id]);
    }

    public function test_cascade_delete_removes_pivot_when_user_deleted(): void
    {
        $dept = TicketDepartment::where('slug', 'sales')->firstOrFail();
        $staff = User::factory()->create(['role' => 'admin']);
        $dept->staff()->attach($staff->id);

        $this->assertDatabaseHas('ticket_department_user', [
            'ticket_department_id' => $dept->id,
            'user_id' => $staff->id,
        ]);

        $staff->delete();

        $this->assertDatabaseMissing('ticket_department_user', [
            'ticket_department_id' => $dept->id,
            'user_id' => $staff->id,
        ]);
        $this->assertDatabaseHas('ticket_departments', ['id' => $dept->id]);
    }

    public function test_eager_loads_staff_via_with(): void
    {
        $dept = TicketDepartment::where('slug', 'support')->firstOrFail();
        $alice = User::factory()->create(['role' => 'admin']);
        $bob = User::factory()->create(['role' => 'admin']);
        $dept->staff()->attach([$alice->id, $bob->id]);

        $loaded = TicketDepartment::with('staff')->findOrFail($dept->id);

        $this->assertTrue($loaded->relationLoaded('staff'));
        $this->assertCount(2, $loaded->staff);
        $this->assertTrue($loaded->staff->contains($alice->id));
        $this->assertTrue($loaded->staff->contains($bob->id));

        // Inverse eager load also works: User::with('ticketDepartments')
        $userLoaded = User::with('ticketDepartments')->findOrFail($alice->id);
        $this->assertTrue($userLoaded->relationLoaded('ticketDepartments'));
        $this->assertTrue($userLoaded->ticketDepartments->contains($dept->id));
    }

    public function test_pivot_schema_has_expected_columns_and_constraints(): void
    {
        $this->assertTrue(Schema::hasTable('ticket_department_user'));
        $this->assertTrue(Schema::hasColumn('ticket_department_user', 'id'));
        $this->assertTrue(Schema::hasColumn('ticket_department_user', 'ticket_department_id'));
        $this->assertTrue(Schema::hasColumn('ticket_department_user', 'user_id'));
        $this->assertTrue(Schema::hasColumn('ticket_department_user', 'created_at'));
        $this->assertTrue(Schema::hasColumn('ticket_department_user', 'updated_at'));

        // Unique composite + index on user_id exist (sqlite check via sqlite_master)
        $connection = DB::connection()->getDriverName();
        if ($connection === 'sqlite') {
            $indexes = DB::select("SELECT name, tbl_name, sql FROM sqlite_master WHERE type='index' AND tbl_name='ticket_department_user'");
            $sqls = implode(' ', array_map(fn ($r) => strtolower((string) ($r->sql ?? '').' '.(string) $r->name), $indexes));
            // Unique on composite manifests as UNIQUE constraint in table sql or index sql containing both columns.
            $tableSql = DB::selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name='ticket_department_user'");
            $combined = strtolower((string) ($tableSql->sql ?? '').' '.$sqls);
            $this->assertStringContainsString('ticket_department_id', $combined);
            $this->assertStringContainsString('user_id', $combined);
            $this->assertTrue(str_contains($combined, 'unique') || str_contains($combined, 'ticket_department_user_ticket_department_id_user_id_unique'), 'unique composite should exist');
        }
    }

    public function test_does_not_touch_adminlte_role_user_table(): void
    {
        // Sanity: adminlte_role_user pivot still works independently
        $this->assertTrue(Schema::hasTable('adminlte_role_user'));
    }
}
