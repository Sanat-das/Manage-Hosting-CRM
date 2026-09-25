<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Notifications\OrderCreatedNotification;
use App\Support\NotificationsNavbar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesPanelUsers;
use Tests\TestCase;

/**
 * The navbar bell dropdown is fed by App\Support\NotificationsNavbar and the
 * published `adminlte::partials.navbar-notifications` view.
 *
 * Frozen contract under test: rows are unread-only, newest first, capped at
 * NotificationsNavbar::LIMIT, and every row is exactly
 * {id, icon, color, title, text, time, url}. The class and the redesigned
 * partial land in parallel tasks, so these tests are intentionally red until
 * then.
 */
class NotificationsNavbarTest extends TestCase
{
    use CreatesPanelUsers;
    use RefreshDatabase;

    /**
     * Frozen type => [icon, color, title] map — one notification per entry.
     *
     * @var array<string, array{icon: string, color: string, title: string}>
     */
    private const TYPE_MAP = [
        'order.created' => ['icon' => 'bi-bag-check', 'color' => 'success', 'title' => 'New order'],
        'order.paid' => ['icon' => 'bi-credit-card', 'color' => 'primary', 'title' => 'Payment received'],
        'invoice.overdue' => ['icon' => 'bi-receipt', 'color' => 'danger', 'title' => 'Invoice overdue'],
        'ticket.new' => ['icon' => 'bi-life-preserver', 'color' => 'info', 'title' => 'New ticket'],
        'ticket.reply' => ['icon' => 'bi-chat-left-text', 'color' => 'primary', 'title' => 'Ticket reply'],
        'ticket.transferred' => ['icon' => 'bi-arrow-left-right', 'color' => 'info', 'title' => 'Ticket transferred'],
        'domain.expiring' => ['icon' => 'bi-globe2', 'color' => 'warning', 'title' => 'Domain expiring'],
        'chat.reply' => ['icon' => 'bi-chat-dots', 'color' => 'info', 'title' => 'Chat reply'],
        'chat.mention' => ['icon' => 'bi-chat-dots', 'color' => 'info', 'title' => 'Chat mention'],
        'chat.assign' => ['icon' => 'bi-person-check', 'color' => 'info', 'title' => 'Chat assigned'],
    ];

    public function test_guests_and_unsaved_users_get_nothing(): void
    {
        $this->assertSame([], NotificationsNavbar::rows(null));
        $this->assertSame(0, NotificationsNavbar::count(null));
        $this->assertNull(NotificationsNavbar::indexUrl(null));
        $this->assertNull(NotificationsNavbar::markAllReadUrl(null));

        $unsaved = new User;

        $this->assertSame([], NotificationsNavbar::rows($unsaved));
        $this->assertSame(0, NotificationsNavbar::count($unsaved));
    }

    public function test_known_types_map_to_exact_icon_color_and_title_triplets(): void
    {
        $user = $this->panelUserWithPermissions('notifications.view');

        $expected = [];

        foreach (self::TYPE_MAP as $type => $triplet) {
            $notification = $this->notify($user, [
                'type' => $type,
                'message' => "Message for {$type}",
            ]);

            $expected[$notification->id] = $triplet;
        }

        $rows = NotificationsNavbar::rows($user, count(self::TYPE_MAP));

        $this->assertCount(count(self::TYPE_MAP), $rows);
        $this->assertSame(array_values($rows), $rows, 'rows() returns a list.');
        $this->assertSame(
            ['id', 'icon', 'color', 'title', 'text', 'time', 'url'],
            array_keys($rows[0]),
            'A row carries exactly the frozen shape.',
        );

        $byId = $this->keyById($rows);

        foreach ($expected as $id => $triplet) {
            $this->assertArrayHasKey($id, $byId, 'Every unread notification gets a row.');

            $row = $byId[$id];

            $this->assertSame($triplet['icon'], $row['icon'], "Wrong icon for {$triplet['title']}.");
            $this->assertSame($triplet['color'], $row['color'], "Wrong colour for {$triplet['title']}.");
            $this->assertSame($triplet['title'], $row['title']);
            $this->assertNotSame('', $row['time'], 'A stored created_at renders a relative time.');
        }
    }

    public function test_unknown_and_missing_types_fall_back_gracefully(): void
    {
        $user = $this->panelUserWithPermissions('notifications.view');

        $unknown = $this->notify($user, [
            'type' => 'billing.weird',
            'message' => 'Something odd happened.',
        ]);

        $missingTypeKey = $this->notify($user, [
            'message' => 'Order 1001 has been activated.',
        ]);

        $byId = $this->rowsById($user);

        $this->assertSame('bi-bell-fill', $byId[$unknown->id]['icon']);
        $this->assertSame('secondary', $byId[$unknown->id]['color']);
        $this->assertSame('Billing Weird', $byId[$unknown->id]['title']);

        // Inbox parity: class basename minus the Notification suffix,
        // headline-cased — never the raw FQCN.
        $this->assertSame('bi-bell-fill', $byId[$missingTypeKey->id]['icon']);
        $this->assertSame('secondary', $byId[$missingTypeKey->id]['color']);
        $this->assertSame('Order Created', $byId[$missingTypeKey->id]['title']);
    }

    public function test_staff_rows_deep_link_to_admin_entities(): void
    {
        $user = $this->panelUserWithPermissions('notifications.view');

        $order = $this->notify($user, [
            'type' => 'order.created',
            'order_id' => 101,
            'message' => 'Order 101 placed.',
        ]);

        $ticket = $this->notify($user, [
            'type' => 'ticket.new',
            'ticket_id' => 202,
            'message' => 'Ticket 202 opened.',
        ]);

        $invoice = $this->notify($user, [
            'type' => 'invoice.overdue',
            'invoice_id' => 303,
            'message' => 'Invoice 303 overdue.',
        ]);

        $domain = $this->notify($user, [
            'type' => 'domain.expiring',
            'domain_id' => 404,
            'message' => 'Domain 404 expiring.',
        ]);

        $byId = $this->rowsById($user, 10);

        $this->assertSame(route('admin.orders.show', 101), $byId[$order->id]['url']);
        $this->assertSame(route('admin.tickets.show', 202), $byId[$ticket->id]['url']);
        $this->assertSame(route('admin.invoices.show', 303), $byId[$invoice->id]['url']);
        $this->assertSame(route('admin.domains.show', 404), $byId[$domain->id]['url']);
    }

    public function test_client_rows_deep_link_to_client_entities(): void
    {
        $client = $this->clientUser();

        $order = $this->notify($client, [
            'type' => 'order.created',
            'order_id' => 111,
            'message' => 'Order 111 placed.',
        ]);

        $ticket = $this->notify($client, [
            'type' => 'ticket.reply',
            'ticket_id' => 222,
            'message' => 'Ticket 222 replied.',
        ]);

        $invoice = $this->notify($client, [
            'type' => 'invoice.overdue',
            'invoice_id' => 333,
            'message' => 'Invoice 333 overdue.',
        ]);

        $domain = $this->notify($client, [
            'type' => 'domain.expiring',
            'domain_id' => 444,
            'message' => 'Domain 444 expiring.',
        ]);

        $byId = $this->rowsById($client, 10);

        // The client route parameter is `id`, not the entity name.
        $this->assertSame(route('client.orders.show', 111), $byId[$order->id]['url']);
        $this->assertSame(route('client.tickets.show', 222), $byId[$ticket->id]['url']);
        $this->assertSame(route('client.invoices.show', 333), $byId[$invoice->id]['url']);
        $this->assertSame(route('client.domains.show', 444), $byId[$domain->id]['url']);
    }

    public function test_payload_url_wins_over_entity_routing(): void
    {
        $user = $this->panelUserWithPermissions('notifications.view');

        $chat = $this->notify($user, [
            'type' => 'chat.reply',
            'url' => '/admin/chat?c=9',
            'message' => 'Bob replied in chat.',
        ]);

        // Even with an entity id present, an explicit url is authoritative.
        $order = $this->notify($user, [
            'type' => 'order.created',
            'order_id' => 55,
            'url' => '/admin/chat?c=9',
            'message' => 'Order 55 placed.',
        ]);

        $byId = $this->rowsById($user);

        $this->assertSame('/admin/chat?c=9', $byId[$chat->id]['url']);
        $this->assertSame('/admin/chat?c=9', $byId[$order->id]['url']);
    }

    public function test_rows_without_url_or_entity_fall_back_to_the_index_url(): void
    {
        $user = $this->panelUserWithPermissions('notifications.view');

        $notification = $this->notify($user, [
            'type' => 'chat.reply',
            'message' => 'A chat mention with nowhere deeper to go.',
        ]);

        $byId = $this->rowsById($user);

        $this->assertSame(route('admin.notifications.index'), $byId[$notification->id]['url']);
    }

    public function test_only_unread_rows_appear_and_the_list_is_capped_at_five(): void
    {
        $user = $this->panelUserWithPermissions('notifications.view');

        $unreadIds = [];

        for ($i = 0; $i < 7; $i++) {
            $unreadIds[] = $this->notify($user, [
                'type' => 'ticket.reply',
                'message' => "Unread reply {$i}",
            ], ageMinutes: $i)->id;
        }

        $readIds = [
            $this->notify($user, [
                'type' => 'ticket.reply',
                'message' => 'Old read one',
            ], read: true, ageMinutes: 30)->id,
            $this->notify($user, [
                'type' => 'ticket.reply',
                'message' => 'Old read two',
            ], read: true, ageMinutes: 31)->id,
        ];

        $rows = NotificationsNavbar::rows($user);
        $ids = array_column($rows, 'id');

        $this->assertCount(NotificationsNavbar::LIMIT, $rows, 'The dropdown shows at most five rows.');
        $this->assertSame(7, NotificationsNavbar::count($user), 'The badge counts every unread notification.');

        // Newest first: the five freshest unread rows, in order.
        $this->assertSame(array_slice($unreadIds, 0, 5), $ids);

        foreach ($readIds as $readId) {
            $this->assertNotContains($readId, $ids, 'Read notifications never appear.');
        }
    }

    public function test_index_and_mark_all_read_urls_follow_role_and_permission(): void
    {
        // Order matters: the trait shares one scoped role per test, so the
        // view-only assertions run before manage is granted to that role.
        $viewer = $this->panelUserWithPermissions('notifications.view');

        $this->assertSame(route('admin.notifications.index'), NotificationsNavbar::indexUrl($viewer));
        $this->assertNull(
            NotificationsNavbar::markAllReadUrl($viewer),
            'Viewing the inbox is not managing it.',
        );

        $manager = $this->panelUserWithPermissions('notifications.manage');

        $this->assertSame(route('admin.notifications.markAllRead'), NotificationsNavbar::markAllReadUrl($manager));

        // An admin holds every permission, including both notification ones.
        $admin = $this->adminUser();

        $this->assertSame(route('admin.notifications.index'), NotificationsNavbar::indexUrl($admin));
        $this->assertSame(route('admin.notifications.markAllRead'), NotificationsNavbar::markAllReadUrl($admin));

        $denied = $this->panelUserWithoutPermission('notifications.view', 'notifications.manage');

        $this->assertNull(NotificationsNavbar::indexUrl($denied));
        $this->assertNull(NotificationsNavbar::markAllReadUrl($denied));

        // Clients are gated by the portal, not by staff permissions.
        $client = $this->clientUser();

        $this->assertSame(route('client.notifications.index'), NotificationsNavbar::indexUrl($client));
        $this->assertSame(route('client.notifications.markAllRead'), NotificationsNavbar::markAllReadUrl($client));
    }

    public function test_chat_messages_are_decoded_once_and_non_chat_messages_are_not(): void
    {
        $user = $this->panelUserWithPermissions('notifications.view');

        $chat = $this->notify($user, [
            'type' => 'chat.reply',
            'url' => '/admin/chat?c=3',
            'message' => 'Bob &amp; Alice',
        ]);

        $order = $this->notify($user, [
            'type' => 'order.created',
            'message' => 'Bob &amp; Alice',
        ]);

        $byId = $this->rowsById($user);

        $this->assertSame(
            'Bob & Alice',
            $byId[$chat->id]['text'],
            'Chat messages are stored pre-escaped, so they are decoded once.',
        );
        $this->assertSame(
            'Bob &amp; Alice',
            $byId[$order->id]['text'],
            'Non-chat payloads must not be decoded.',
        );
    }

    public function test_the_dropdown_escapes_notification_messages(): void
    {
        $user = $this->panelUserWithPermissions('notifications.view');

        $this->notify($user, [
            'type' => 'order.created',
            'message' => '<script>alert(1)</script>',
        ]);

        $row = NotificationsNavbar::rows($user)[0];

        // The class hands the raw payload to the view; escaping is Blade's job.
        $this->assertSame('<script>alert(1)</script>', $row['text']);

        $this->actingAs($user);

        $this->view('adminlte::partials.navbar-notifications')
            ->assertSee('data-notif-nav', false)
            ->assertDontSee('<script>alert(1)', false)
            ->assertSee('&lt;script&gt;', false);
    }

    public function test_the_dropdown_renders_for_staff_and_client_with_the_right_footer(): void
    {
        $staff = $this->panelUserWithPermissions('notifications.view');
        $client = $this->clientUser();

        $this->actingAs($staff);
        $staffHtml = view('adminlte::partials.navbar-notifications')->render();

        $this->assertStringContainsString('data-notif-nav', $staffHtml);
        $this->assertStringContainsString('aria-label', $staffHtml);
        $this->assertStringContainsString('href="'.route('admin.notifications.index').'"', $staffHtml);
        $this->assertStringNotContainsString('adminlte.notifications.index', $staffHtml);

        $this->actingAs($client);
        $clientHtml = view('adminlte::partials.navbar-notifications')->render();

        $this->assertStringContainsString('href="'.route('client.notifications.index').'"', $clientHtml);
        $this->assertStringNotContainsString('adminlte.notifications.index', $clientHtml);
    }

    public function test_mark_all_read_button_visibility_follows_permission_and_unread_count(): void
    {
        // Order matters: the trait shares one scoped role per test, so the
        // view-only render runs before manage is granted to that role.
        $viewer = $this->panelUserWithPermissions('notifications.view');
        $this->notify($viewer, ['type' => 'order.created', 'message' => 'Order placed.']);

        $this->actingAs($viewer);
        $viewerHtml = view('adminlte::partials.navbar-notifications')->render();

        $this->assertStringNotContainsString(
            route('admin.notifications.markAllRead'),
            $viewerHtml,
            'View-only staff must not get the mark-all-read action.',
        );

        $manager = $this->panelUserWithPermissions('notifications.manage');
        $this->notify($manager, ['type' => 'order.created', 'message' => 'Order placed.']);

        $this->actingAs($manager);
        $managerHtml = view('adminlte::partials.navbar-notifications')->render();

        $this->assertStringContainsString(
            route('admin.notifications.markAllRead'),
            $managerHtml,
            'Staff holding notifications.manage with unread rows get the action.',
        );

        $client = $this->clientUser();
        $this->notify($client, ['type' => 'order.created', 'message' => 'Order placed.']);

        $this->actingAs($client);
        $clientHtml = view('adminlte::partials.navbar-notifications')->render();

        $this->assertStringContainsString(
            route('client.notifications.markAllRead'),
            $clientHtml,
            'Clients with unread rows get the action.',
        );

        $idle = $this->panelUserWithPermissions('notifications.manage');

        $this->actingAs($idle);
        $idleHtml = view('adminlte::partials.navbar-notifications')->render();

        $this->assertStringNotContainsString(
            route('admin.notifications.markAllRead'),
            $idleHtml,
            'No unread rows, no action.',
        );
    }

    public function test_long_messages_are_truncated_with_str_limit(): void
    {
        $user = $this->panelUserWithPermissions('notifications.view');

        $message = str_repeat('a', 300);

        $notification = $this->notify($user, [
            'type' => 'ticket.reply',
            'message' => $message,
        ]);

        $byId = $this->rowsById($user);
        $text = $byId[$notification->id]['text'];

        $this->assertSame(Str::limit($message, 80), $text);
        $this->assertLessThanOrEqual(83, mb_strlen($text), 'Str::limit keeps the ellipsis inside 83 characters.');
    }

    public function test_a_missing_notifications_table_degrades_to_empty_rows_and_zero_count(): void
    {
        $user = $this->panelUserWithPermissions('notifications.view');

        $this->notify($user, ['type' => 'order.created', 'message' => 'Order placed.']);

        Schema::drop('notifications');

        $this->assertSame([], NotificationsNavbar::rows($user));
        $this->assertSame(0, NotificationsNavbar::count($user));
    }

    /**
     * A real client-portal user: `users.role` = client so hasRole('client')
     * passes, plus the linked Customer record the portal expects.
     */
    private function clientUser(): User
    {
        $user = User::factory()->create(['role' => 'client']);

        Customer::create([
            'user_id' => $user->id,
            'company' => 'Notifications Corp',
            'status' => 'active',
        ]);

        return $user;
    }

    /**
     * Insert a database notification directly — the navbar reads the table,
     * never dispatched notification classes.
     *
     * @param  array<string, mixed>  $data
     */
    private function notify(
        User $user,
        array $data,
        bool $read = false,
        int $ageMinutes = 0,
        string $type = OrderCreatedNotification::class,
    ): DatabaseNotification {
        $createdAt = now()->subMinutes($ageMinutes);

        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => $type,
            'data' => $data,
            'read_at' => $read ? $createdAt : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function keyById(array $rows): array
    {
        $keyed = [];

        foreach ($rows as $row) {
            $keyed[$row['id']] = $row;
        }

        return $keyed;
    }

    /**
     * NotificationsNavbar::rows() keyed by notification id.
     *
     * @return array<string, array<string, mixed>>
     */
    private function rowsById(User $user, int $limit = NotificationsNavbar::LIMIT): array
    {
        return $this->keyById(NotificationsNavbar::rows($user, $limit));
    }
}
