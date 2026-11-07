<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-user notification preference screens (client portal + staff panel).
 */
class NotificationPreferencesUiTest extends TestCase
{
    use RefreshDatabase;

    private function makeClient(bool $withCustomer = true): User
    {
        $user = User::factory()->create(['role' => 'client']);

        if ($withCustomer) {
            Customer::create([
                'user_id' => $user->id,
                'status' => 'active',
            ]);
        }

        return $user->refresh();
    }

    private function makeStaff(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function assertPreference(Model $notifiable, string $type, string $channel, bool $expected): void
    {
        $row = NotificationPreference::query()
            ->where('preferrable_type', $notifiable->getMorphClass())
            ->where('preferrable_id', $notifiable->getKey())
            ->where('type', $type)
            ->where('channel', $channel)
            ->first();

        $this->assertNotNull($row, "Missing preference row for [{$type}] via [{$channel}].");
        $this->assertSame($expected, $row->enabled, "Unexpected enabled flag for [{$type}] via [{$channel}].");
    }

    private function assertNoPreference(Model $notifiable, string $type, string $channel): void
    {
        $exists = NotificationPreference::query()
            ->where('preferrable_type', $notifiable->getMorphClass())
            ->where('preferrable_id', $notifiable->getKey())
            ->where('type', $type)
            ->where('channel', $channel)
            ->exists();

        $this->assertFalse($exists, "Unexpected preference row for [{$type}] via [{$channel}].");
    }

    public function test_client_with_linked_customer_posts_mixed_toggles(): void
    {
        $user = $this->makeClient();
        $customer = $user->customer;

        $response = $this->actingAs($user)->post(route('client.notification-preferences.update'), [
            'notification_preferences' => [
                'order.created' => ['database' => '1'],
                'renewal.upcoming' => ['mail' => '1'],
                'chat.transcript' => ['mail' => '1'],
            ],
        ]);

        $response->assertRedirect(route('client.profile'));
        $response->assertSessionHas('success', 'Notification preferences saved.');

        // Customer-keyed rows (unchecked boxes are written as enabled=false).
        $this->assertPreference($customer, 'order.created', 'database', true);
        $this->assertPreference($customer, 'order.created', 'mail', false);
        $this->assertPreference($customer, 'order.paid', 'database', false);
        $this->assertPreference($customer, 'invoice.overdue', 'database', false);
        $this->assertPreference($customer, 'invoice.overdue', 'mail', false);
        $this->assertPreference($customer, 'invoice.paid', 'mail', false);
        $this->assertPreference($customer, 'ticket.new', 'database', false);
        $this->assertPreference($customer, 'ticket.reply', 'mail', false);
        $this->assertPreference($customer, 'domain.expiring', 'database', false);
        $this->assertPreference($customer, 'service.activated', 'mail', false);
        $this->assertPreference($customer, 'upgrade.requested', 'mail', false);
        $this->assertPreference($customer, 'upgrade.applied', 'mail', false);
        $this->assertPreference($customer, 'upgrade.cancelled', 'mail', false);

        // User-keyed rows (notifiable = the portal login User).
        $this->assertPreference($user, 'renewal.upcoming', 'database', false);
        $this->assertPreference($user, 'renewal.upcoming', 'mail', true);
        $this->assertPreference($user, 'chat.mention', 'database', false);
        $this->assertPreference($user, 'chat.reply', 'database', false);
        $this->assertPreference($user, 'chat.transcript', 'mail', true);

        $this->assertDatabaseCount('notification_preferences', 20);
    }

    public function test_client_without_linked_customer_posts_without_error(): void
    {
        $user = $this->makeClient(withCustomer: false);

        $response = $this->actingAs($user)->post(route('client.notification-preferences.update'), [
            'notification_preferences' => [
                'order.created' => ['database' => '1', 'mail' => '1'],
                'renewal.upcoming' => ['mail' => '1'],
            ],
        ]);

        $response->assertRedirect(route('client.profile'));
        $response->assertSessionHas('success', 'Notification preferences saved.');

        // User-keyed rows are written; customer-keyed cells are skipped silently.
        $this->assertPreference($user, 'renewal.upcoming', 'database', false);
        $this->assertPreference($user, 'renewal.upcoming', 'mail', true);
        $this->assertPreference($user, 'chat.mention', 'database', false);
        $this->assertPreference($user, 'chat.transcript', 'mail', false);

        $this->assertSame(
            0,
            NotificationPreference::query()->where('preferrable_type', Customer::class)->count(),
            'Customer-keyed rows must not be written for a pending account.'
        );
    }

    public function test_staff_user_posts_and_scheduled_report_is_not_written_for_sales(): void
    {
        $user = $this->makeStaff('sales');

        $response = $this->actingAs($user)->post(route('admin.profile.notification-preferences.update'), [
            'notification_preferences' => [
                'order.created' => ['database' => '1'],
                'order.paid' => ['database' => '1'],
                'ticket.new' => ['database' => '1'],
                'ticket.reply' => ['database' => '1'],
                'ticket.transfer' => ['database' => '1'],
                'renewal.upcoming' => ['mail' => '1'],
                'service.overdue' => ['database' => '1', 'mail' => '1'],
                'chat.mention' => ['database' => '1'],
                'chat.reply' => ['database' => '1'],
                'chat.assign' => ['database' => '1'],
                'scheduled.report' => ['mail' => '1'],
            ],
        ]);

        $response->assertRedirect(route('admin.profile'));
        $response->assertSessionHas('success', 'Notification preferences saved.');

        $this->assertPreference($user, 'order.created', 'database', true);
        $this->assertPreference($user, 'service.overdue', 'mail', true);
        $this->assertPreference($user, 'chat.assign', 'database', true);

        // scheduled.report is restricted to admin|staff|support; sales must not get a row.
        $this->assertNoPreference($user, 'scheduled.report', 'mail');

        $this->assertDatabaseCount('notification_preferences', 11);
    }

    public function test_profile_pages_render_the_preferences_card(): void
    {
        $client = $this->makeClient();
        $this->actingAs($client)
            ->get(route('client.profile'))
            ->assertOk()
            ->assertSee('Notification Preferences')
            ->assertSee('name="notification_preferences[order.created][database]"', false);

        $admin = $this->makeStaff('admin');
        $this->actingAs($admin)
            ->get(route('admin.profile'))
            ->assertOk()
            ->assertSee('My Notification Preferences')
            ->assertSee('name="notification_preferences[scheduled.report][mail]"', false);

        $sales = $this->makeStaff('sales');
        $this->actingAs($sales)
            ->get(route('admin.profile'))
            ->assertOk()
            ->assertDontSee('name="notification_preferences[scheduled.report][mail]"', false);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->post(route('client.notification-preferences.update'), [
            'notification_preferences' => [],
        ]);

        $response->assertRedirect(route('client.login'));
    }
}
