<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Tests\TestCase;

class ClientNotificationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Inline database-channel notification (T3.3 produces real ones; the
     * inbox UI only consumes the framework relation, so a stub is enough).
     */
    private function notifyOnce(User $user, string $suffix = 'Action'): void
    {
        $user->notify(new class extends Notification
        {
            private string $suffix;

            public function __construct(string $suffix = 'Action')
            {
                $this->suffix = $suffix;
            }

            public function via($notifiable): array
            {
                return ['database'];
            }

            public function toArray($notifiable): array
            {
                return ['message' => "Test notification {$this->suffix}."];
            }
        });
    }

    private function makeCustomerUser(): Customer
    {
        $user = User::factory()->create();

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Notify Corp',
            'status' => 'active',
        ]);
    }

    public function test_client_sees_notification_list_and_unread_badge(): void
    {
        $customer = $this->makeCustomerUser();
        $user = $customer->user;

        $this->notifyOnce($user, 'Alpha');

        $response = $this->actingAs($user)
            ->get(route('client.notifications.index'))
            ->assertOk();

        // List renders the notification title (derived from the type).
        $response->assertSee('Test');

        // Sidebar unread badge is present (only rendered when count > 0).
        $response->assertSee('nav-badge');
        $this->assertSame(1, $user->unreadNotifications()->count());
    }

    public function test_client_marks_single_notification_read(): void
    {
        $customer = $this->makeCustomerUser();
        $user = $customer->user;

        $this->notifyOnce($user, 'Beta');

        $notification = $user->unreadNotifications()->first();
        $this->assertNotNull($notification);

        $this->actingAs($user)
            ->from(route('client.notifications.index'))
            ->post(route('client.notifications.markRead', $notification->id))
            ->assertRedirect(route('client.notifications.index'))
            ->assertSessionHas('success');

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
    }

    public function test_client_marks_all_notifications_read(): void
    {
        $customer = $this->makeCustomerUser();
        $user = $customer->user;

        $this->notifyOnce($user, 'One');
        $this->notifyOnce($user, 'Two');
        $this->notifyOnce($user, 'Three');

        $this->assertSame(3, $user->unreadNotifications()->count());

        $this->actingAs($user)
            ->from(route('client.notifications.index'))
            ->post(route('client.notifications.markAllRead'))
            ->assertRedirect(route('client.notifications.index'))
            ->assertSessionHas('success');

        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
        $this->assertSame(0, $user->fresh()->notifications()->whereNull('read_at')->count());
    }
}
