<?php

namespace Tests\Feature;

use App\Events\TicketTransferred;
use App\Jobs\SendEmail;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Notifications\TicketTransferredNotification;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * T19 — TicketTransferred event/listener/notification.
 *
 * Acceptance: (a) transfer dispatches the event, (b) target department staff
 * get a DB notification, (c) source department staff (not in target) do not,
 * (d) the customer gets no mail/notification — transfers are internal only.
 */
class TicketTransferredNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TicketService::forgetDepartmentCache();
    }

    public function test_transfer_dispatches_the_event(): void
    {
        Event::fake([TicketTransferred::class]);

        $actor = User::factory()->create(['role' => 'admin']);
        $ticket = $this->makeTicket('support');

        app(TicketService::class)->transferDepartment($ticket, 'billing', $actor);

        Event::assertDispatched(TicketTransferred::class, function (TicketTransferred $event) use ($ticket, $actor) {
            return $event->ticket->id === $ticket->id
                && $event->fromDepartment === 'support'
                && $event->toDepartment === 'billing'
                && $event->actor->id === $actor->id;
        });
    }

    public function test_target_staff_notified(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $billing = TicketDepartment::where('slug', 'billing')->firstOrFail();
        $billingStaff = User::factory()->create(['role' => 'support']);
        $billing->staff()->attach($billingStaff->id);

        $ticket = $this->makeTicket('support');

        app(TicketService::class)->transferDepartment($ticket, 'billing', $actor);

        $this->assertSame(1, $billingStaff->fresh()->notifications()
            ->where('type', TicketTransferredNotification::class)
            ->count());
    }

    public function test_source_staff_not_in_target_not_notified(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $support = TicketDepartment::where('slug', 'support')->firstOrFail();
        $supportOnlyStaff = User::factory()->create(['role' => 'support']);
        $support->staff()->attach($supportOnlyStaff->id);

        $ticket = $this->makeTicket('support');

        app(TicketService::class)->transferDepartment($ticket, 'billing', $actor);

        $this->assertSame(0, $supportOnlyStaff->fresh()->notifications()
            ->where('type', TicketTransferredNotification::class)
            ->count());
    }

    public function test_customer_not_notified(): void
    {
        Bus::fake();

        $actor = User::factory()->create(['role' => 'admin']);
        $ticket = $this->makeTicket('support');
        $customer = $ticket->customer;

        app(TicketService::class)->transferDepartment($ticket, 'billing', $actor);

        $this->assertSame(0, $customer->fresh()->notifications()->count());
        Bus::assertNotDispatched(SendEmail::class);
    }

    private function makeTicket(string $department, string $status = 'open'): Ticket
    {
        $user = User::factory()->create(['role' => 'client']);
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);

        return Ticket::create([
            'ticket_no' => 'TKT-'.random_int(10000, 99999),
            'customer_id' => $customer->id,
            'subject' => 'Test ticket',
            'priority' => 'medium',
            'status' => $status,
            'department' => $department,
            'last_reply_at' => now(),
        ]);
    }
}
