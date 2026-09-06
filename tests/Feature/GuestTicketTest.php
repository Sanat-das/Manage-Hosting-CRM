<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\TicketMailParser;
use App\Services\TicketService;
use App\Settings\EmailSettings;
use App\Support\InboundEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GuestTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_unknown_sender_creates_guest_ticket_not_customer(): void
    {
        $settings = app(EmailSettings::class);
        $settings->fill(['imap_auto_create_customers' => false]);
        $settings->save();

        $parser = app(TicketMailParser::class);
        $result = $parser->handle(InboundEmail::fromArray([
            'messageId' => 'guest-1@mail.test',
            'subject' => 'Help with guest account',
            'fromEmail' => 'unknown@example.test',
            'fromName' => 'Unknown Person',
            'body' => 'I need help.',
        ]), false, 'support');

        $this->assertSame(TicketMailParser::STATUS_TICKET_OPENED, $result['status']);
        $this->assertNotNull($result['ticket']);
        $ticket = $result['ticket'];
        $this->assertTrue($ticket->isGuest());
        $this->assertSame('unknown@example.test', $ticket->guest_email);
        $this->assertSame('Unknown Person', $ticket->guest_name);
        $this->assertNull($ticket->customer_id);
        $this->assertDatabaseMissing('users', ['email' => 'unknown@example.test']);
        $this->assertSame('Unknown Person', $ticket->display_name);
        $this->assertSame('unknown@example.test', $ticket->display_email);
    }

    public function test_guest_ticket_is_displayed_with_unknown_badge_on_index(): void
    {
        $guest = $this->makeGuestTicket('sales', 'stranger@test.com', 'Stranger Test');
        $admin = $this->actingAsAdmin();

        $response = $this->actingAs($admin)->get(route('admin.tickets.index'));
        $response->assertOk();
        $response->assertSee('Guest');
        $response->assertSee('stranger@test.com');
        $response->assertSee($guest->ticket_no);
    }

    public function test_show_displays_warning_banner_for_guest(): void
    {
        $guest = $this->makeGuestTicket('support', 'anon@unknown.test', 'Anon User');
        $admin = $this->actingAsAdmin();

        $response = $this->actingAs($admin)->get(route('admin.tickets.show', $guest));
        $response->assertOk();
        $response->assertSee('Guest sender');
        $response->assertSee('anon@unknown.test');
        $response->assertSee('Link to customer');
        $response->assertSee('Add as contact');
        // the two modals should be present
        $response->assertSee('link-guest-modal');
        $response->assertSee('add-contact-modal');
    }

    public function test_show_does_not_display_banner_for_normal_ticket(): void
    {
        [$ticket] = $this->makeNormalTicket();
        $admin = $this->actingAsAdmin();

        $response = $this->actingAs($admin)->get(route('admin.tickets.show', $ticket));
        $response->assertOk();
        $response->assertDontSee('Guest sender');
        $response->assertDontSee('link-guest-modal');
    }

    public function test_link_guest_to_customer_assigns_ownership_and_clears_guest_fields(): void
    {
        $guest = $this->makeGuestTicket('support', 'linkme@test.com', 'Link Me');
        $customer = $this->makeCustomer('existing@test.com');
        $admin = $this->actingAsAdmin();

        $response = $this->actingAs($admin)->post(route('admin.tickets.linkGuest', $guest), [
            'customer_id' => $customer->id,
        ]);

        $response->assertRedirect(route('admin.tickets.show', $guest));
        $guest->refresh();
        $this->assertSame($customer->id, $guest->customer_id);
        $this->assertNull($guest->guest_email);
        $this->assertNull($guest->guest_name);
        $this->assertFalse($guest->isGuest());
    }

    public function test_link_guest_with_create_contact_also_creates_customer_contact(): void
    {
        $guest = $this->makeGuestTicket('billing', 'contactme@test.com', 'Contact Me');
        $customer = $this->makeCustomer('owner@test.com');
        $admin = $this->actingAsAdmin();

        $this->assertDatabaseCount('customer_contacts', 0);

        $response = $this->actingAs($admin)->post(route('admin.tickets.linkGuest', $guest), [
            'customer_id' => $customer->id,
            'create_contact' => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('customer_contacts', [
            'customer_id' => $customer->id,
            'email' => 'contactme@test.com',
            'first_name' => 'Contact',
            'last_name' => 'Me',
        ]);
        $guest->refresh();
        $this->assertSame($customer->id, $guest->customer_id);
    }

    public function test_link_guest_does_not_duplicate_contact_if_already_exists(): void
    {
        $guest = $this->makeGuestTicket('support', 'dup@test.com', 'Dup User');
        $customer = $this->makeCustomer('owner2@test.com');
        CustomerContact::create([
            'customer_id' => $customer->id,
            'first_name' => 'Dup',
            'last_name' => 'User',
            'email' => 'dup@test.com',
            'is_primary' => false,
            'status' => 'active',
        ]);
        $admin = $this->actingAsAdmin();

        $this->actingAs($admin)->post(route('admin.tickets.linkGuest', $guest), [
            'customer_id' => $customer->id,
            'create_contact' => true,
        ]);

        $this->assertDatabaseCount('customer_contacts', 1);
    }

    public function test_add_guest_as_contact_creates_contact_but_leaves_ticket_as_guest(): void
    {
        $guest = $this->makeGuestTicket('technical', 'addcontact@test.com', 'Add Contact');
        $customer = $this->makeCustomer('holder@test.com');
        $admin = $this->actingAsAdmin();

        $response = $this->actingAs($admin)->post(route('admin.tickets.addGuestContact', $guest), [
            'customer_id' => $customer->id,
        ]);

        $response->assertRedirect(route('admin.tickets.show', $guest));
        $this->assertDatabaseHas('customer_contacts', [
            'customer_id' => $customer->id,
            'email' => 'addcontact@test.com',
        ]);
        $guest->refresh();
        $this->assertTrue($guest->isGuest(), 'Ticket should remain guest when only adding contact');
        $this->assertSame('addcontact@test.com', $guest->guest_email);
    }

    public function test_add_guest_as_contact_fails_on_non_guest_ticket(): void
    {
        [$ticket] = $this->makeNormalTicket();
        $customer = $this->makeCustomer('holder2@test.com');
        $admin = $this->actingAsAdmin();

        $response = $this->actingAs($admin)->post(route('admin.tickets.addGuestContact', $ticket), [
            'customer_id' => $customer->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_link_guest_fails_on_already_linked_ticket(): void
    {
        [$ticket] = $this->makeNormalTicket();
        $customer = $this->makeCustomer('other@test.com');
        $admin = $this->actingAsAdmin();

        $response = $this->actingAs($admin)->post(route('admin.tickets.linkGuest', $ticket), [
            'customer_id' => $customer->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_service_link_guest_to_customer_throws_if_already_linked(): void
    {
        [$ticket] = $this->makeNormalTicket();
        $customer = $this->makeCustomer('svc@test.com');
        $svc = app(TicketService::class);

        $this->expectException(\DomainException::class);
        $svc->linkGuestToCustomer($ticket, $customer);
    }

    public function test_service_add_guest_as_contact_throws_on_non_guest(): void
    {
        [$ticket] = $this->makeNormalTicket();
        $customer = $this->makeCustomer('svc2@test.com');
        $svc = app(TicketService::class);

        $this->expectException(\DomainException::class);
        $svc->addGuestAsContact($ticket, $customer);
    }

    public function test_ticket_model_is_guest_helpers(): void
    {
        $guest = $this->makeGuestTicket();
        $this->assertTrue($guest->isGuest());
        $this->assertSame('Guesty McGuest', $guest->display_name);
        $this->assertSame('guesty@test.com', $guest->display_email);

        [$normal] = $this->makeNormalTicket();
        $this->assertFalse($normal->isGuest());
        $this->assertNotNull($normal->display_name);
    }

    // ---- EDGE CASES ----

    public function test_link_guest_validation_fails_with_missing_customer_id(): void
    {
        $guest = $this->makeGuestTicket();
        $admin = $this->actingAsAdmin();

        $response = $this->actingAs($admin)->post(route('admin.tickets.linkGuest', $guest), []);
        $response->assertSessionHasErrors('customer_id');
        $guest->refresh();
        $this->assertTrue($guest->isGuest());
    }

    public function test_link_guest_validation_fails_with_nonexistent_customer(): void
    {
        $guest = $this->makeGuestTicket();
        $admin = $this->actingAsAdmin();

        $response = $this->actingAs($admin)->post(route('admin.tickets.linkGuest', $guest), [
            'customer_id' => 999999,
        ]);
        $response->assertSessionHasErrors('customer_id');
    }

    public function test_add_guest_as_contact_validation_fails_with_missing_customer_id(): void
    {
        $guest = $this->makeGuestTicket();
        $admin = $this->actingAsAdmin();

        $response = $this->actingAs($admin)->post(route('admin.tickets.addGuestContact', $guest), []);
        $response->assertSessionHasErrors('customer_id');
    }

    public function test_add_guest_as_contact_allows_custom_overrides(): void
    {
        $guest = $this->makeGuestTicket('support', 'override@test.com', 'Original Name');
        $customer = $this->makeCustomer('override-owner@test.com');
        $admin = $this->actingAsAdmin();

        $response = $this->actingAs($admin)->post(route('admin.tickets.addGuestContact', $guest), [
            'customer_id' => $customer->id,
            'first_name' => 'Custom',
            'last_name' => 'Override',
            'email' => 'custom@test.com',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('customer_contacts', [
            'customer_id' => $customer->id,
            'first_name' => 'Custom',
            'last_name' => 'Override',
            'email' => 'custom@test.com',
        ]);
        $this->assertDatabaseMissing('customer_contacts', [
            'customer_id' => $customer->id,
            'email' => 'override@test.com',
        ]);
    }

    public function test_link_guest_case_insensitive_duplicate_contact_prevention(): void
    {
        $guest = $this->makeGuestTicket('support', 'Case@Test.com', 'Case Test');
        $customer = $this->makeCustomer('case-owner@test.com');
        CustomerContact::create([
            'customer_id' => $customer->id,
            'first_name' => 'Case',
            'last_name' => 'Test',
            'email' => 'case@test.com',
            'is_primary' => false,
            'status' => 'active',
        ]);
        $admin = $this->actingAsAdmin();

        $this->actingAs($admin)->post(route('admin.tickets.linkGuest', $guest), [
            'customer_id' => $customer->id,
            'create_contact' => true,
        ]);

        $this->assertDatabaseCount('customer_contacts', 1);
    }

    public function test_guest_display_fallbacks_to_email_when_name_empty(): void
    {
        $guest = $this->makeGuestTicket('support', 'onlyemail@test.com', '');
        $this->assertSame('onlyemail@test.com', $guest->display_name);
        $this->assertSame('onlyemail@test.com', $guest->display_email);

        $guest2 = $this->makeGuestTicket('support', 'onlyemail2@test.com', 'Tmp');
        // null guest_name should also fallback to email
        $guest2->update(['guest_name' => null]);
        $guest2->refresh();
        $this->assertSame('onlyemail2@test.com', $guest2->display_name);
    }

    public function test_guest_display_fallbacks_to_unknown_when_both_null(): void
    {
        $ticket = Ticket::create([
            'ticket_no' => 'TKT-'.random_int(10000, 99999),
            'customer_id' => null,
            'guest_email' => null,
            'guest_name' => null,
            'subject' => 'Orphan',
            'priority' => 'medium',
            'status' => 'open',
            'department' => 'support',
            'last_reply_at' => now(),
        ]);
        $this->assertTrue($ticket->isGuest());
        $this->assertSame('Guest', $ticket->display_name);
        $this->assertNull($ticket->display_email);
    }

    public function test_guest_single_word_name_splits_correctly_on_link(): void
    {
        $guest = $this->makeGuestTicket('support', 'madonna@test.com', 'Madonna');
        $customer = $this->makeCustomer('single-owner@test.com');
        $admin = $this->actingAsAdmin();

        $this->actingAs($admin)->post(route('admin.tickets.linkGuest', $guest), [
            'customer_id' => $customer->id,
            'create_contact' => true,
        ]);

        $this->assertDatabaseHas('customer_contacts', [
            'customer_id' => $customer->id,
            'email' => 'madonna@test.com',
            'first_name' => 'Madonna',
            'last_name' => '',
        ]);
    }

    public function test_guest_ticket_still_queues_an_acknowledgement_email(): void
    {
        $settings = app(EmailSettings::class);
        $settings->fill(['imap_auto_create_customers' => false]);
        $settings->save();

        $parser = app(TicketMailParser::class);
        $result = $parser->handle(InboundEmail::fromArray([
            'messageId' => 'guest-no-email-'.random_int(1000, 9999).'@test.com',
            'subject' => 'Guest should still get an email',
            'fromEmail' => 'noemailguest@test.com',
            'fromName' => 'No Email Guest',
            'body' => 'Body',
        ]), false, 'support');

        $this->assertSame(TicketMailParser::STATUS_TICKET_OPENED, $result['status']);
        // SendTicketCreatedNotification falls back to emailing the guest address
        // directly when there is no customer to notify in-app.
        \Illuminate\Support\Facades\Queue::assertPushed(
            \App\Jobs\SendEmail::class,
            fn (\App\Jobs\SendEmail $job) => $job->toEmail === 'noemailguest@test.com'
        );
    }

    public function test_dry_run_guest_reports_guest_ticket_phrase(): void
    {
        $parser = app(TicketMailParser::class);
        $result = $parser->handle(InboundEmail::fromArray([
            'messageId' => 'guest-dry-'.random_int(1000, 9999).'@test.com',
            'subject' => 'Dry run guest',
            'fromEmail' => 'dryguest@test.com',
            'fromName' => 'Dry Guest',
            'body' => 'Body',
        ]), true, 'support');

        // Dry run should indicate guest when auto_create is off (global state from previous test may be off, force off)
        $settings = app(EmailSettings::class);
        $settings->fill(['imap_auto_create_customers' => false]);
        $settings->save();

        $result2 = $parser->handle(InboundEmail::fromArray([
            'messageId' => 'guest-dry2-'.random_int(1000, 9999).'@test.com',
            'subject' => 'Dry run guest 2',
            'fromEmail' => 'dryguest2@test.com',
            'fromName' => 'Dry Guest2',
            'body' => 'Body',
        ]), true, 'support');

        $this->assertSame(TicketMailParser::STATUS_WOULD_OPEN_TICKET, $result2['status']);
        $this->assertStringContainsString('guest ticket', strtolower($result2['reason']));
    }

    public function test_guest_ticket_followup_is_rejected_as_unknown_sender(): void
    {
        $guest = $this->makeGuestTicket('support', 'followup@test.com', 'Followup Guest');
        // Create an outbound reply with message id so inbound can thread
        $staff = User::factory()->create(['role' => 'admin']);
        $reply = TicketReply::create([
            'ticket_id' => $guest->id,
            'user_id' => $staff->id,
            'message' => 'Staff reply',
            'is_staff' => true,
            'email_message_id' => 'staff-msg-'.random_int(1000, 9999).'@test.com',
        ]);

        $parser = app(TicketMailParser::class);
        $result = $parser->handle(InboundEmail::fromArray([
            'messageId' => 'guest-followup-'.random_int(1000, 9999).'@test.com',
            'inReplyTo' => $reply->email_message_id,
            'references' => [$reply->email_message_id],
            'subject' => 'Re: ['.$guest->ticket_no.'] Guest ticket',
            'fromEmail' => 'followup@test.com',
            'body' => 'I am replying as guest',
        ]), false, 'support');

        // Guest follow-up from same guest_email is now allowed (TicketMailParser::authorFor guest branch)
        $this->assertSame(TicketMailParser::STATUS_CREATED, $result['status']);
        $this->assertSame($guest->id, $result['ticket']->id);
        $this->assertSame('followup@test.com', $result['reply']->from_email);
        $this->assertFalse((bool) $result['reply']->is_staff);
    }

    public function test_link_guest_requires_tickets_edit_permission(): void
    {
        $guest = $this->makeGuestTicket();
        $customer = $this->makeCustomer();
        // Staff with only view, no edit
        $user = User::factory()->create(['role' => 'staff']);
        $role = Role::firstOrCreate(['name' => 'staff'], ['label' => 'Staff']);
        $permView = Permission::firstOrCreate(['name' => 'tickets.view'], ['label' => 'tickets.view']);
        $role->permissions()->sync([$permView->id]);
        $user->assignRole('staff');
        // ensure staff is in department for visibility
        \App\Models\TicketDepartment::where('slug', $guest->department)->firstOrFail()->staff()->attach($user->id);

        $response = $this->actingAs($user)->post(route('admin.tickets.linkGuest', $guest), [
            'customer_id' => $customer->id,
        ]);
        // middleware permission:tickets.edit should forbid
        $response->assertStatus(403);
    }

    public function test_banner_disappears_after_link(): void
    {
        $guest = $this->makeGuestTicket('support', 'banner@test.com', 'Banner Test');
        $customer = $this->makeCustomer('banner-owner@test.com');
        $admin = $this->actingAsAdmin();

        $before = $this->actingAs($admin)->get(route('admin.tickets.show', $guest));
        $before->assertSee('Guest sender');

        $this->actingAs($admin)->post(route('admin.tickets.linkGuest', $guest), [
            'customer_id' => $customer->id,
        ]);

        $after = $this->actingAs($admin)->get(route('admin.tickets.show', $guest->fresh()));
        $after->assertOk();
        $after->assertDontSee('Guest sender');
        $after->assertDontSee('link-guest-modal');
        $after->assertSee($customer->user->email);
    }

    public function test_guest_ticket_respects_department_allow_new_tickets_gate(): void
    {
        \App\Models\TicketDepartment::where('slug', 'sales')->update(['allow_new_tickets' => false]);
        $parser = app(TicketMailParser::class);
        $result = $parser->handle(InboundEmail::fromArray([
            'messageId' => 'guest-blocked-'.random_int(1000, 9999).'@test.com',
            'subject' => 'Blocked guest',
            'fromEmail' => 'blocked@test.com',
            'body' => 'Should be blocked',
        ]), false, 'sales');

        $this->assertSame(TicketMailParser::STATUS_UNMATCHED, $result['status']);
        $this->assertStringContainsString('does not accept new tickets', $result['reason']);
    }

    public function test_unauthenticated_cannot_link_guest(): void
    {
        $guest = $this->makeGuestTicket();
        $customer = $this->makeCustomer();
        $response = $this->post(route('admin.tickets.linkGuest', $guest), [
            'customer_id' => $customer->id,
        ]);
        $response->assertRedirect();
        $location = strtolower($response->headers->get('Location') ?? '');
        $this->assertTrue(str_contains($location, 'login') || str_contains($location, 'admin'), "Expected redirect to login or admin, got $location");
    }

    // helpers

    private function makeGuestTicket(string $department = 'support', string $email = 'guesty@test.com', string $name = 'Guesty McGuest'): Ticket
    {
        return Ticket::create([
            'ticket_no' => 'TKT-'.random_int(10000, 99999),
            'customer_id' => null,
            'guest_email' => $email,
            'guest_name' => $name,
            'subject' => 'Guest ticket',
            'priority' => 'medium',
            'status' => 'open',
            'department' => $department,
            'last_reply_at' => now(),
        ]);
    }

    /**
     * @return array{0: Ticket, 1: Customer}
     */
    private function makeNormalTicket(): array
    {
        $customer = $this->makeCustomer();
        $ticket = Ticket::create([
            'ticket_no' => 'TKT-'.random_int(10000, 99999),
            'customer_id' => $customer->id,
            'subject' => 'Normal ticket',
            'priority' => 'medium',
            'status' => 'open',
            'department' => 'support',
            'last_reply_at' => now(),
        ]);
        TicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => $customer->user_id,
            'message' => 'Hello',
            'is_staff' => false,
        ]);
        return [$ticket, $customer];
    }

    private function makeCustomer(?string $email = null): Customer
    {
        $email = $email ?? 'cust'.random_int(1000, 9999).'@test.com';
        $user = User::factory()->create(['email' => $email, 'role' => 'client']);
        return Customer::create(['user_id' => $user->id, 'status' => 'active']);
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin']);
        foreach (['tickets.view', 'tickets.edit', 'tickets.create', 'tickets.assign', 'tickets.transfer'] as $perm) {
            $permission = Permission::firstOrCreate(['name' => $perm], ['label' => $perm]);
            $role->permissions()->syncWithoutDetaching($permission->id);
        }
        $user->assignRole('admin');
        return $user;
    }
}
