<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Support departments as data: the seeded four, the CRUD screen, and the
 * mailbox rules borrowed from WHMCS.
 */
class TicketDepartmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // departments() memoises per request; the static outlives a test.
        TicketService::forgetDepartmentCache();
    }

    public function test_the_original_four_departments_are_seeded_unchanged(): void
    {
        $this->assertSame(
            array_keys(TicketService::DEPARTMENTS),
            TicketDepartment::ordered()->pluck('slug')->all()
        );

        // Everything reading departments() sees exactly what the constant gave.
        $this->assertSame(TicketService::DEPARTMENTS, TicketService::departments());

        $this->assertTrue(TicketDepartment::where('slug', 'support')->value('enabled'));
    }

    public function test_the_index_lists_departments_for_a_settings_viewer(): void
    {
        $this->actingAsSettingsAdmin()
            ->get(route('admin.ticket-departments.index'))
            ->assertOk()
            ->assertSee('Support Departments')
            ->assertSee('Technical')
            ->assertSee('Uses global settings');
    }

    public function test_the_create_and_edit_forms_render(): void
    {
        $this->actingAsSettingsAdmin()
            ->get(route('admin.ticket-departments.create'))
            ->assertOk()
            ->assertSee('New Department')
            ->assertSee('Incoming Mailbox')
            ->assertDontSee('@endif', false);

        $support = TicketDepartment::where('slug', 'support')->firstOrFail();
        $support->update(['imap_password' => 'stored-secret']);

        $this->actingAsSettingsAdmin()
            ->get(route('admin.ticket-departments.edit', $support))
            ->assertOk()
            ->assertSee('Edit Support')
            ->assertSee('Leave blank to keep current')
            // The key is frozen once tickets reference it.
            ->assertSee('existing tickets are stored against it')
            // A stored mailbox password is never echoed back into the form.
            ->assertDontSee('stored-secret');
    }

    public function test_ticket_permissions_alone_do_not_expose_mailbox_settings(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        $role = Role::firstOrCreate(['name' => 'agent'], ['label' => 'Agent']);
        foreach (['tickets.view', 'tickets.edit'] as $name) {
            $role->permissions()->syncWithoutDetaching(
                Permission::firstOrCreate(['name' => $name], ['label' => $name])->id
            );
        }
        $user->assignRole('agent');

        $this->actingAs($user)
            ->get(route('admin.ticket-departments.index'))
            ->assertForbidden();
    }

    public function test_a_new_department_can_be_created_and_used_on_a_ticket(): void
    {
        $this->actingAsSettingsAdmin()
            ->post(route('admin.ticket-departments.store'), [
                'name' => 'Abuse Reports',
                'enabled' => 'active',
                'email_address' => 'abuse@example.test',
            ])
            ->assertRedirect(route('admin.ticket-departments.index'))
            ->assertSessionHasNoErrors();

        $department = TicketDepartment::where('slug', 'abuse_reports')->first();
        $this->assertNotNull($department);
        $this->assertSame('Abuse Reports', $department->name);

        TicketService::forgetDepartmentCache();
        $this->assertArrayHasKey('abuse_reports', TicketService::departments());

        // The point of widening tickets.department off the ENUM: a department
        // created in the UI has to be storable on a ticket.
        $ticket = Ticket::create([
            'ticket_no' => 'TKT-90001',
            'customer_id' => $this->customer()->id,
            'subject' => 'Spam from your network',
            'priority' => 'high',
            'status' => 'open',
            'department' => 'abuse_reports',
            'last_reply_at' => now(),
        ]);

        $this->assertSame('abuse_reports', $ticket->fresh()->department);
        $this->assertSame('Abuse Reports', TicketService::departmentLabel('abuse_reports'));
    }

    public function test_the_key_is_derived_from_the_name_and_frozen_afterwards(): void
    {
        $this->actingAsSettingsAdmin()
            ->post(route('admin.ticket-departments.store'), ['name' => 'Pre Sales', 'enabled' => 'active'])
            ->assertSessionHasNoErrors();

        $department = TicketDepartment::where('name', 'Pre Sales')->firstOrFail();
        $this->assertSame('pre_sales', $department->slug);

        // A rename must not move the key out from under existing tickets.
        $this->actingAsSettingsAdmin()
            ->put(route('admin.ticket-departments.update', $department), [
                'name' => 'Pre-Sales Questions',
                'slug' => 'something_else',
                'enabled' => 'active',
            ])
            ->assertSessionHasNoErrors();

        $department->refresh();
        $this->assertSame('pre_sales', $department->slug);
        $this->assertSame('Pre-Sales Questions', $department->name);
    }

    public function test_two_departments_cannot_share_one_mailbox(): void
    {
        $support = TicketDepartment::where('slug', 'support')->firstOrFail();
        $support->update([
            'imap_enabled' => true,
            'imap_host' => 'mail.example.test',
            'imap_port' => 993,
            'imap_username' => 'support@example.test',
            'imap_folder' => 'INBOX',
        ]);

        $sales = TicketDepartment::where('slug', 'sales')->firstOrFail();

        $this->actingAsSettingsAdmin()
            ->put(route('admin.ticket-departments.update', $sales), [
                'name' => 'Sales',
                'enabled' => 'active',
                'imap_enabled' => 'yes',
                'imap_host' => 'MAIL.example.test',
                'imap_port' => '993',
                'imap_username' => 'Support@example.test',
                'imap_password' => 'secret',
                'imap_folder' => 'INBOX',
            ])
            ->assertSessionHasErrors('imap_username');

        $this->assertFalse($sales->fresh()->imap_enabled);
    }

    public function test_a_unique_mailbox_is_accepted(): void
    {
        $sales = TicketDepartment::where('slug', 'sales')->firstOrFail();

        $this->actingAsSettingsAdmin()
            ->put(route('admin.ticket-departments.update', $sales), [
                'name' => 'Sales',
                'enabled' => 'active',
                'imap_enabled' => 'yes',
                'imap_host' => 'mail.example.test',
                'imap_port' => '993',
                'imap_username' => 'sales@example.test',
                'imap_password' => 'secret',
                'imap_folder' => 'INBOX',
            ])
            ->assertSessionHasNoErrors();

        $sales->refresh();
        $this->assertTrue($sales->hasMailbox());
        $this->assertSame('secret', $sales->imap_password);
    }

    public function test_a_blank_password_keeps_the_stored_one(): void
    {
        $sales = TicketDepartment::where('slug', 'sales')->firstOrFail();
        $sales->update([
            'imap_enabled' => true,
            'imap_host' => 'mail.example.test',
            'imap_username' => 'sales@example.test',
            'imap_password' => 'stored-secret',
        ]);

        $this->actingAsSettingsAdmin()
            ->put(route('admin.ticket-departments.update', $sales), [
                'name' => 'Sales',
                'enabled' => 'active',
                'imap_enabled' => 'yes',
                'imap_host' => 'mail.example.test',
                'imap_username' => 'sales@example.test',
                'imap_password' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('stored-secret', $sales->fresh()->imap_password);
    }

    public function test_the_email_address_must_be_unique_across_departments(): void
    {
        TicketDepartment::where('slug', 'support')->update(['email_address' => 'support@example.test']);

        $this->actingAsSettingsAdmin()
            ->post(route('admin.ticket-departments.store'), [
                'name' => 'Second Line',
                'enabled' => 'active',
                'email_address' => 'support@example.test',
            ])
            ->assertSessionHasErrors('email_address');
    }

    public function test_a_department_with_tickets_cannot_be_deleted(): void
    {
        $support = TicketDepartment::where('slug', 'support')->firstOrFail();

        Ticket::create([
            'ticket_no' => 'TKT-90002',
            'customer_id' => $this->customer()->id,
            'subject' => 'Anything',
            'priority' => 'low',
            'status' => 'open',
            'department' => 'support',
            'last_reply_at' => now(),
        ]);

        $this->actingAsSettingsAdmin()
            ->delete(route('admin.ticket-departments.destroy', $support))
            ->assertRedirect(route('admin.ticket-departments.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('ticket_departments', ['id' => $support->id]);
    }

    public function test_an_empty_department_can_be_deleted(): void
    {
        $billing = TicketDepartment::where('slug', 'billing')->firstOrFail();

        $this->actingAsSettingsAdmin()
            ->delete(route('admin.ticket-departments.destroy', $billing))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('ticket_departments', ['id' => $billing->id]);
    }

    public function test_a_disabled_department_leaves_the_picker_but_still_labels_old_tickets(): void
    {
        $billing = TicketDepartment::where('slug', 'billing')->firstOrFail();

        $this->actingAsSettingsAdmin()
            ->put(route('admin.ticket-departments.update', $billing), [
                'name' => 'Billing',
                'enabled' => 'inactive',
            ])
            ->assertSessionHasNoErrors();

        TicketService::forgetDepartmentCache();

        $this->assertArrayNotHasKey('billing', TicketService::departments());
        $this->assertSame('Billing', TicketService::departmentLabel('billing'));
    }

    public function test_the_ticket_create_form_still_offers_the_seeded_departments(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['tickets.view', 'tickets.create'] as $name) {
            $role->permissions()->syncWithoutDetaching(
                Permission::firstOrCreate(['name' => $name], ['label' => $name])->id
            );
        }
        $user->assignRole('admin');

        $this->actingAs($user)
            ->get(route('admin.tickets.create'))
            ->assertOk()
            ->assertSee('Technical')
            ->assertSee('Billing');
    }

    private function customer(): Customer
    {
        $user = User::factory()->create(['role' => 'client']);

        return Customer::create(['user_id' => $user->id, 'status' => 'active']);
    }

    private function actingAsSettingsAdmin(): self
    {
        $user = User::factory()->create(['role' => 'admin']);

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['settings.view', 'settings.manage'] as $permName) {
            $perm = Permission::firstOrCreate(['name' => $permName], ['label' => $permName]);
            $adminRole->permissions()->syncWithoutDetaching($perm->id);
        }

        $user->assignRole('admin');

        return $this->actingAs($user);
    }
}
