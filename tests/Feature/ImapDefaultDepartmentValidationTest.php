<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Services\TicketMailParser;
use App\Settings\EmailSettings;
use App\Support\InboundEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `imap_default_department` validation on the Email settings save path
 * (EmailSettings::rules()), and confirmation that TicketMailParser::departmentFor
 * still falls back safely when the stored slug does not resolve.
 */
class ImapDefaultDepartmentValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_default_passes(): void
    {
        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'active_tab' => 'email',
                'settings' => [
                    'imap_enabled' => 'yes',
                    'imap_default_department' => 'sales',
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('sales', app(EmailSettings::class)->imap_default_department);
    }

    public function test_disabled_slug_rejected(): void
    {
        TicketDepartment::where('slug', 'sales')->update(['enabled' => false]);

        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'active_tab' => 'email',
                'settings' => [
                    'imap_enabled' => 'yes',
                    'imap_default_department' => 'sales',
                ],
            ])
            ->assertSessionHasErrors('settings.imap_default_department');
    }

    public function test_blank_default_passes(): void
    {
        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'active_tab' => 'email',
                'settings' => [
                    'imap_enabled' => 'yes',
                    'imap_default_department' => '',
                ],
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_department_for_falls_back_to_is_default_when_the_stored_slug_is_invalid(): void
    {
        // Bypass validation to simulate a slug that was valid when saved and
        // later disabled — departmentFor() must still resolve a department.
        $settings = app(EmailSettings::class);
        $settings->fill(['imap_default_department' => 'not-a-real-slug']);
        $settings->save();

        TicketDepartment::query()->update(['is_default' => false]);
        TicketDepartment::where('slug', 'support')->update(['is_default' => true, 'enabled' => true]);

        $result = app(TicketMailParser::class)->handle(InboundEmail::fromArray([
            'messageId' => 'inbound-y@mail.example',
            'subject' => 'Help please',
            'fromEmail' => 'newcustomer@example.test',
            'body' => 'Need help.',
        ]));

        $this->assertSame(TicketMailParser::STATUS_TICKET_OPENED, $result['status']);
        $this->assertSame('support', $result['ticket']->department);
    }

    private function actingAsSettingsAdmin(): self
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['settings.view', 'settings.manage'] as $permName) {
            $perm = Permission::firstOrCreate(['name' => $permName], ['label' => ucfirst($permName)]);
            $adminRole->permissions()->syncWithoutDetaching($perm->id);
        }

        $user->assignRole('admin');

        return $this->actingAs($user);
    }
}
