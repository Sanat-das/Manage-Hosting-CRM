<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Tests\TestCase;

class NotificationTableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Minimal database-channel notification (no dedicated class file).
     */
    private function dbNotification(string $message = 'Hello'): Notification
    {
        return new class($message) extends Notification
        {
            public function __construct(private readonly string $message) {}

            public function via(object $notifiable): array
            {
                return ['database'];
            }

            public function toArray(object $notifiable): array
            {
                return ['message' => $this->message];
            }
        };
    }

    public function test_user_can_receive_database_notification_and_list_unread(): void
    {
        $user = User::factory()->create();

        $user->notify($this->dbNotification('Hello user'));

        $this->assertCount(1, $user->unreadNotifications);
        $this->assertSame('Hello user', $user->unreadNotifications->first()->data['message']);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_mark_as_read_clears_unread_notification(): void
    {
        $user = User::factory()->create();

        $user->notify($this->dbNotification());

        $notification = $user->unreadNotifications->first();
        $this->assertNotNull($notification);

        $notification->markAsRead();

        $this->assertCount(0, $user->fresh()->unreadNotifications);
        $this->assertNotNull($notification->refresh()->read_at);
    }

    public function test_notification_preferences_unique_constraint_rejects_duplicates(): void
    {
        $user = User::factory()->create();

        NotificationPreference::create([
            'preferrable_type' => User::class,
            'preferrable_id' => $user->id,
            'type' => 'invoice',
            'channel' => 'database',
        ]);

        $this->expectException(QueryException::class);

        NotificationPreference::create([
            'preferrable_type' => User::class,
            'preferrable_id' => $user->id,
            'type' => 'invoice',
            'channel' => 'database',
        ]);
    }

    public function test_customer_can_receive_database_notification(): void
    {
        $customer = Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);

        $customer->notify($this->dbNotification('Hello customer'));

        $this->assertCount(1, $customer->unreadNotifications);
        $this->assertSame('Hello customer', $customer->unreadNotifications->first()->data['message']);
        $this->assertDatabaseCount('notifications', 1);
    }
}
