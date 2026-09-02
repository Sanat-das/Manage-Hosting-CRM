<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T5 — orphan department slugs general/abuse + backfill audit.
 *
 * Verifies:
 *  (a) after audit, every tickets.department has a matching ticket_departments.slug
 *  (b) TicketService::departments() includes general/abuse if they had tickets
 *  (c) re-running the migration is idempotent (no duplicates, counts stable)
 */
class OrphanDepartmentAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TicketService::forgetDepartmentCache();
    }

    public function test_orphans_created(): void
    {
        // Seed state has only the original four — general/abuse must not exist yet.
        $this->assertDatabaseMissing('ticket_departments', ['slug' => 'general']);
        $this->assertDatabaseMissing('ticket_departments', ['slug' => 'abuse']);

        $customer = $this->makeCustomer();

        $generalTicket = Ticket::create([
            'ticket_no' => 'TKT-80001',
            'customer_id' => $customer->id,
            'subject' => 'General question',
            'priority' => 'low',
            'status' => 'open',
            'department' => 'general',
            'last_reply_at' => now(),
        ]);

        $abuseTicket = Ticket::create([
            'ticket_no' => 'TKT-80002',
            'customer_id' => $customer->id,
            'subject' => 'Abuse report',
            'priority' => 'high',
            'status' => 'open',
            'department' => 'abuse',
            'last_reply_at' => now(),
        ]);

        $maxBefore = (int) DB::table('ticket_departments')->max('sort_order');

        $this->runOrphanAudit();

        // Departments must now exist with correct attributes, additive only.
        foreach (['general', 'abuse'] as $slug) {
            $dept = TicketDepartment::where('slug', $slug)->first();
            $this->assertNotNull($dept, "department {$slug} should have been created");
            $this->assertSame(ucfirst($slug), $dept->name);
            $this->assertTrue((bool) $dept->enabled, "{$slug} should be enabled");
            $this->assertTrue((bool) $dept->allow_new_tickets, "{$slug} should allow_new_tickets");
            $this->assertFalse((bool) $dept->is_default, "{$slug} must not be default");
        }

        // sort_order appended as max+10 per orphan, in sorted order (abuse, general).
        $abuse = TicketDepartment::where('slug', 'abuse')->firstOrFail();
        $general = TicketDepartment::where('slug', 'general')->firstOrFail();
        $this->assertGreaterThan($maxBefore, $abuse->sort_order);
        $this->assertGreaterThan($abuse->sort_order, $general->sort_order, 'second orphan should be max+10 after first');
        $this->assertSame($maxBefore + 10, (int) $abuse->sort_order);
        $this->assertSame($maxBefore + 20, (int) $general->sort_order);

        // Tickets must NOT have been remapped.
        $this->assertSame('general', $generalTicket->fresh()->department);
        $this->assertSame('abuse', $abuseTicket->fresh()->department);

        TicketService::forgetDepartmentCache();
        $deps = TicketService::departments();
        $this->assertArrayHasKey('general', $deps, 'departments() should include general after backfill');
        $this->assertArrayHasKey('abuse', $deps, 'departments() should include abuse after backfill');
        $this->assertSame('General', $deps['general']);
        $this->assertSame('Abuse', $deps['abuse']);
    }

    public function test_every_ticket_department_has_matching_slug(): void
    {
        $customer = $this->makeCustomer();

        // Create tickets with orphan slugs.
        Ticket::create([
            'ticket_no' => 'TKT-80101',
            'customer_id' => $customer->id,
            'subject' => 'General orphan',
            'priority' => 'low',
            'status' => 'open',
            'department' => 'general',
            'last_reply_at' => now(),
        ]);
        Ticket::create([
            'ticket_no' => 'TKT-80102',
            'customer_id' => $customer->id,
            'subject' => 'Abuse orphan',
            'priority' => 'low',
            'status' => 'open',
            'department' => 'abuse',
            'last_reply_at' => now(),
        ]);
        // Also a non-orphan control.
        Ticket::create([
            'ticket_no' => 'TKT-80103',
            'customer_id' => $customer->id,
            'subject' => 'Support control',
            'priority' => 'low',
            'status' => 'open',
            'department' => 'support',
            'last_reply_at' => now(),
        ]);

        $this->runOrphanAudit();

        $distinct = DB::table('tickets')->select('department')->distinct()->pluck('department')->all();
        $slugs = DB::table('ticket_departments')->pluck('slug')->all();

        foreach ($distinct as $dept) {
            $this->assertContains($dept, $slugs, "tickets.department '{$dept}' should have a matching ticket_departments.slug after audit");
        }

        // Raw SQL form from spec must also yield zero orphans.
        $orphans = DB::table('tickets')
            ->whereNotIn('department', DB::table('ticket_departments')->select('slug'))
            ->distinct()
            ->pluck('department')
            ->all();

        $this->assertSame([], $orphans, 'SELECT DISTINCT orphan query should return zero rows after audit');
    }

    public function test_idempotent_on_rerun(): void
    {
        $customer = $this->makeCustomer();

        Ticket::create([
            'ticket_no' => 'TKT-80201',
            'customer_id' => $customer->id,
            'subject' => 'General one',
            'priority' => 'low',
            'status' => 'open',
            'department' => 'general',
            'last_reply_at' => now(),
        ]);

        $this->runOrphanAudit();
        $countAfterFirst = TicketDepartment::whereIn('slug', ['general', 'abuse'])->count();
        $generalSortFirst = TicketDepartment::where('slug', 'general')->value('sort_order');
        $allCountFirst = TicketDepartment::count();

        // Second run — should not duplicate or change sort_order.
        $this->runOrphanAudit();
        $countAfterSecond = TicketDepartment::whereIn('slug', ['general', 'abuse'])->count();
        $generalSortSecond = TicketDepartment::where('slug', 'general')->value('sort_order');
        $allCountSecond = TicketDepartment::count();

        $this->assertSame($countAfterFirst, $countAfterSecond, 'rerun should not create duplicate orphan rows');
        $this->assertSame($generalSortFirst, $generalSortSecond, 'sort_order should be stable on rerun');
        $this->assertSame($allCountFirst, $allCountSecond, 'total department count should be stable');

        // Third run via direct migration instance — also stable.
        $this->runOrphanAudit();
        $this->assertSame($allCountFirst, TicketDepartment::count());
        $this->assertSame(1, TicketDepartment::where('slug', 'general')->count(), 'general slug unique constraint holds');
    }

    /**
     * Re-run the orphan audit migration's up() logic.
     * Uses the migration file directly so tests validate the real file, not a copy.
     */
    private function runOrphanAudit(): void
    {
        $path = database_path('migrations/2026_08_28_000009_audit_orphan_ticket_departments.php');

        // Anonymous class migrations return the instance on require.
        $migration = require $path;
        $migration->up();

        TicketService::forgetDepartmentCache();
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create(['role' => 'client']);

        return Customer::create(['user_id' => $user->id, 'status' => 'active']);
    }
}
