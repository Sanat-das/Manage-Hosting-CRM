<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketTransfer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * T3 — ticket_transfers audit table + model.
 *
 * Acceptance: (a) can create transfer row, (b) cascade on ticket delete,
 * (c) Ticket::transfers() HasMany returns chronologically.
 */
class TicketTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_transfer_row(): void
    {
        $ticket = $this->makeTicket('support');
        $actor = User::factory()->create(['role' => 'admin']);
        $assignee = User::factory()->create(['role' => 'admin']);

        $transfer = TicketTransfer::create([
            'ticket_id' => $ticket->id,
            'from_department' => 'support',
            'to_department' => 'billing',
            'assigned_to' => $assignee->id,
            'assigned_from' => null,
            'actor_id' => $actor->id,
            'note' => 'Escalating to billing',
        ]);

        $this->assertDatabaseHas('ticket_transfers', [
            'id' => $transfer->id,
            'ticket_id' => $ticket->id,
            'from_department' => 'support',
            'to_department' => 'billing',
            'assigned_to' => $assignee->id,
            'actor_id' => $actor->id,
        ]);

        $this->assertNotNull($transfer->created_at);
        $this->assertTrue($transfer->relationLoaded('ticket') || $transfer->ticket !== null);
        $this->assertSame($ticket->id, $transfer->ticket->id);
        $this->assertSame($actor->id, $transfer->actor->id);
    }

    public function test_ticket_transfers_relation_returns_chronologically(): void
    {
        $ticket = $this->makeTicket('support');
        $actor = User::factory()->create(['role' => 'admin']);

        $t1 = TicketTransfer::create([
            'ticket_id' => $ticket->id,
            'from_department' => 'support',
            'to_department' => 'billing',
            'actor_id' => $actor->id,
            'created_at' => now()->subMinutes(5),
        ]);

        $t2 = TicketTransfer::create([
            'ticket_id' => $ticket->id,
            'from_department' => 'billing',
            'to_department' => 'technical',
            'actor_id' => $actor->id,
            'created_at' => now()->subMinutes(2),
        ]);

        $t3 = TicketTransfer::create([
            'ticket_id' => $ticket->id,
            'from_department' => 'technical',
            'to_department' => 'sales',
            'actor_id' => $actor->id,
            'created_at' => now(),
        ]);

        $ordered = $ticket->fresh()->transfers()->pluck('id')->all();
        $this->assertSame([$t1->id, $t2->id, $t3->id], $ordered, 'transfers should be chronological (oldest first)');

        // Also via eager load
        $loaded = Ticket::with('transfers')->findOrFail($ticket->id);
        $this->assertTrue($loaded->relationLoaded('transfers'));
        $this->assertCount(3, $loaded->transfers);
        $this->assertSame($t1->id, $loaded->transfers->first()->id);
        $this->assertSame($t3->id, $loaded->transfers->last()->id);
    }

    public function test_cascade_on_ticket_delete(): void
    {
        $ticket = $this->makeTicket('support');
        $actor = User::factory()->create(['role' => 'admin']);

        TicketTransfer::create([
            'ticket_id' => $ticket->id,
            'from_department' => 'support',
            'to_department' => 'billing',
            'actor_id' => $actor->id,
        ]);

        $this->assertDatabaseHas('ticket_transfers', ['ticket_id' => $ticket->id]);

        $ticket->delete();

        $this->assertDatabaseMissing('ticket_transfers', ['ticket_id' => $ticket->id]);
    }

    public function test_requires_valid_ticket_fk(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);

        $this->expectException(QueryException::class);
        TicketTransfer::create([
            'ticket_id' => 999999,
            'from_department' => 'support',
            'to_department' => 'billing',
            'actor_id' => $actor->id,
        ]);
    }

    public function test_requires_valid_actor_fk(): void
    {
        $ticket = $this->makeTicket('support');

        $this->expectException(QueryException::class);
        TicketTransfer::create([
            'ticket_id' => $ticket->id,
            'from_department' => 'support',
            'to_department' => 'billing',
            'actor_id' => 999999,
        ]);
    }

    public function test_schema_has_expected_columns_and_indexes(): void
    {
        $this->assertTrue(Schema::hasTable('ticket_transfers'));
        $this->assertTrue(Schema::hasColumn('ticket_transfers', 'id'));
        $this->assertTrue(Schema::hasColumn('ticket_transfers', 'ticket_id'));
        $this->assertTrue(Schema::hasColumn('ticket_transfers', 'from_department'));
        $this->assertTrue(Schema::hasColumn('ticket_transfers', 'to_department'));
        $this->assertTrue(Schema::hasColumn('ticket_transfers', 'assigned_to'));
        $this->assertTrue(Schema::hasColumn('ticket_transfers', 'assigned_from'));
        $this->assertTrue(Schema::hasColumn('ticket_transfers', 'actor_id'));
        $this->assertTrue(Schema::hasColumn('ticket_transfers', 'note'));
        $this->assertTrue(Schema::hasColumn('ticket_transfers', 'created_at'));
        $this->assertFalse(Schema::hasColumn('ticket_transfers', 'updated_at'), 'audit is append-only — no updated_at');
        $this->assertFalse(Schema::hasColumn('ticket_transfers', 'deleted_at'), 'no soft deletes');

        // Indexes on ticket_id and to_department
        $connection = DB::connection()->getDriverName();
        if ($connection === 'sqlite') {
            $indexes = DB::select("SELECT name, tbl_name, sql FROM sqlite_master WHERE type='index' AND tbl_name='ticket_transfers'");
            $sqls = implode(' ', array_map(fn ($r) => strtolower((string) ($r->sql ?? '').' '.(string) $r->name), $indexes));
            $tableSql = DB::selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name='ticket_transfers'");
            $combined = strtolower((string) ($tableSql->sql ?? '').' '.$sqls);
            $this->assertStringContainsString('ticket_id', $combined);
            $this->assertStringContainsString('to_department', $combined);
        } else {
            $indexes = Schema::getIndexes('ticket_transfers');
            $cols = collect($indexes)->flatMap(fn ($idx) => $idx['columns'] ?? [])->map(fn ($c) => strtolower($c))->all();
            $this->assertContains('ticket_id', $cols);
            $this->assertContains('to_department', $cols);
        }
    }

    public function test_model_fillable_and_casts_and_relations(): void
    {
        $ticket = $this->makeTicket('billing');
        $actor = User::factory()->create(['role' => 'admin']);
        $assignee = User::factory()->create(['role' => 'admin']);

        $transfer = TicketTransfer::create([
            'ticket_id' => $ticket->id,
            'from_department' => 'billing',
            'to_department' => 'support',
            'assigned_to' => $assignee->id,
            'assigned_from' => null,
            'actor_id' => $actor->id,
            'note' => 'Reassigning',
        ]);

        // Fillable persists note
        $this->assertSame('Reassigning', $transfer->note);
        // Casts
        $this->assertIsInt($transfer->ticket_id);
        $this->assertInstanceOf(Carbon::class, $transfer->created_at);
        // Relations
        $this->assertInstanceOf(Ticket::class, $transfer->ticket);
        $this->assertInstanceOf(User::class, $transfer->actor);
        // Ticket side
        $this->assertTrue($ticket->fresh()->transfers->contains($transfer->id));
    }

    private function makeTicket(string $department = 'support'): Ticket
    {
        $user = User::factory()->create(['role' => 'client']);
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);

        return Ticket::create([
            'ticket_no' => 'TKT-'.random_int(10000, 99999),
            'customer_id' => $customer->id,
            'subject' => 'Test ticket',
            'priority' => 'medium',
            'status' => 'open',
            'department' => $department,
            'last_reply_at' => now(),
        ]);
    }
}
