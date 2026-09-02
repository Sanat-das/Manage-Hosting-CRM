<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Settings\EmailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * "Send Test Email" on the Email settings tab
 * (SettingsController::sendTestEmail).
 */
class SettingsTestEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_blank_smtp_host_falls_back_to_the_default_mailer_and_says_so(): void
    {
        config(['mail.default' => 'array']);

        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.test-email'), ['test_email' => 'admin@example.com'])
            ->assertRedirect(route('admin.settings.index', ['tab' => 'email']))
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'admin@example.com')
                && str_contains($message, 'default "array" mailer'));

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertSame('admin@example.com', $messages[0]->getOriginalMessage()->getTo()[0]->getAddress());

        $this->assertDatabaseHas('emails', [
            'to_email' => 'admin@example.com',
            'template_name' => 'settings.test',
            'status' => 'sent',
        ]);
    }

    public function test_configured_smtp_host_is_used_and_named_in_the_result(): void
    {
        Mail::fake();

        $settings = app(EmailSettings::class);
        $settings->fill(['smtp_host' => 'smtp.example.com', 'smtp_port' => 2525]);
        $settings->save();

        $response = $this->actingAsSettingsAdmin()
            ->postJson(route('admin.settings.test-email'), ['test_email' => 'ops@example.com']);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertStringContainsString('smtp.example.com:2525', $response->json('message'));

        $this->assertDatabaseHas('emails', [
            'to_email' => 'ops@example.com',
            'status' => 'sent',
        ]);
    }

    public function test_an_invalid_address_is_rejected(): void
    {
        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.test-email'), ['test_email' => 'not-an-address'])
            ->assertSessionHasErrors('test_email');

        $this->assertDatabaseCount('emails', 0);
    }

    public function test_a_failed_send_reports_the_error_instead_of_500ing(): void
    {
        // Port 1 on loopback refuses immediately — a real transport failure
        // without depending on an outside host.
        $settings = app(EmailSettings::class);
        $settings->fill(['smtp_host' => '127.0.0.1', 'smtp_port' => 1, 'smtp_encryption' => 'none']);
        $settings->save();

        $response = $this->actingAsSettingsAdmin()
            ->postJson(route('admin.settings.test-email'), ['test_email' => 'ops@example.com']);

        $response->assertOk()->assertJson(['ok' => false]);
        $this->assertStringContainsString('failed', $response->json('message'));

        $this->assertDatabaseHas('emails', [
            'to_email' => 'ops@example.com',
            'status' => 'failed',
        ]);
        $this->assertNotNull(\DB::table('emails')->where('to_email', 'ops@example.com')->value('error'));
    }

    public function test_settings_view_permission_alone_cannot_send(): void
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'viewer'], ['label' => 'Viewer']);
        $permission = Permission::firstOrCreate(['name' => 'settings.view'], ['label' => 'Settings view']);
        $role->permissions()->syncWithoutDetaching($permission->id);
        $user->assignRole('viewer');

        $this->actingAs($user)
            ->post(route('admin.settings.test-email'), ['test_email' => 'ops@example.com'])
            ->assertForbidden();

        $this->assertDatabaseCount('emails', 0);
    }

    public function test_the_email_tab_renders_the_send_test_email_control(): void
    {
        $response = $this->actingAsSettingsAdmin()
            ->get(route('admin.settings.index', ['tab' => 'email']))
            ->assertOk()
            ->assertSee('Send Test Email')
            ->assertSee('id="test-email-form"', false)
            ->assertSee(route('admin.settings.test-email'), false);

        // Blade compiling cleanly is not the same as rendering cleanly — an
        // unterminated directive prints itself into the page.
        foreach (['@error', '@enderror', '@endif', '@csrf'] as $directive) {
            $response->assertDontSee($directive, false);
        }
    }

    public function test_incoming_mail_settings_save_and_the_password_is_masked_on_read(): void
    {
        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'active_tab' => 'email',
                'settings' => [
                    'imap_enabled' => 'yes',
                    'imap_host' => 'imap.example.com',
                    'imap_port' => '993',
                    'imap_username' => 'support@example.com',
                    'imap_password' => 'mailbox-secret',
                ],
            ])
            ->assertSessionHasNoErrors();

        $settings = app(EmailSettings::class);
        $this->assertTrue($settings->imap_enabled);
        $this->assertSame('imap.example.com', $settings->imap_host);
        $this->assertSame('mailbox-secret', $settings->imap_password);

        // Rendered form must never carry the stored mailbox password.
        $this->actingAsSettingsAdmin()
            ->get(route('admin.settings.index', ['tab' => 'email']))
            ->assertOk()
            ->assertSee('Incoming Mail (Ticket Replies)')
            ->assertDontSee('mailbox-secret');
    }

    public function test_a_blank_imap_password_keeps_the_stored_one(): void
    {
        $settings = app(EmailSettings::class);
        $settings->fill(['imap_password' => 'mailbox-secret']);
        $settings->save();

        $this->actingAsSettingsAdmin()
            ->post(route('admin.settings.update'), [
                'active_tab' => 'email',
                'settings' => ['imap_host' => 'imap.example.com', 'imap_password' => ''],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('mailbox-secret', app(EmailSettings::class)->imap_password);
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
