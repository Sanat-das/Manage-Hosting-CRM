<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\MessageEntityLink;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * The composer's entity picker: searching, linking, and how the links render.
 */
class EntityLinkTest extends TestCase
{
    use CreatesChatUsers;
    use RefreshDatabase;

    private ChatService $chat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chat = app(ChatService::class);
    }

    private function makeProduct(string $name): Product
    {
        $group = ProductGroup::firstOrCreate(
            ['slug' => 'chat-test-group'],
            ['name' => 'Chat Test Group', 'status' => 'active'],
        );

        return Product::create([
            'name' => $name,
            'product_group_id' => $group->id,
            'price' => 10,
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ]);
    }

    private function makeCustomer(string $first, string $last, string $company): Customer
    {
        $user = User::factory()->create([
            'first_name' => $first,
            'last_name' => $last,
            'role' => 'client',
        ]);

        return Customer::create(['user_id' => $user->id, 'company' => $company, 'status' => 'active']);
    }

    private function makeTicket(string $no, string $subject): Ticket
    {
        return Ticket::create([
            'ticket_no' => $no,
            'subject' => $subject,
            'priority' => 'medium',
            'status' => 'open',
            'department' => 'support',
        ]);
    }

    // --- search -----------------------------------------------------------

    public function test_searching_finds_products_customers_contacts_and_tickets(): void
    {
        $this->makeProduct('Nimbus Hosting');
        $customer = $this->makeCustomer('Nimbus', 'Client', 'Nimbus Ltd');
        CustomerContact::create([
            'customer_id' => $customer->id,
            'first_name' => 'Nimbus',
            'last_name' => 'Contact',
            'email' => 'nimbus@example.com',
        ]);
        $this->makeTicket('TKT-NIMBUS', 'Nimbus is down');

        $user = $this->chatUser('chat.view', 'products.view', 'customers.view', 'tickets.view');

        $response = $this->actingAs($user)
            ->getJson(route('admin.chat.search-entities', ['q' => 'Nimbus']))
            ->assertOk();

        $types = collect($response->json('results'))->pluck('type')->unique()->sort()->values()->all();

        $this->assertSame(['contact', 'customer', 'product', 'ticket'], $types);
    }

    public function test_a_type_can_be_searched_on_its_own(): void
    {
        $this->makeProduct('Nimbus Hosting');
        $this->makeTicket('TKT-NIMBUS', 'Nimbus is down');

        $user = $this->chatUser('chat.view', 'products.view', 'tickets.view');

        $this->actingAs($user)
            ->getJson(route('admin.chat.search-entities', ['q' => 'Nimbus', 'type' => 'product']))
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.type', 'product')
            ->assertJsonPath('results.0.label', 'Nimbus Hosting');
    }

    public function test_search_only_returns_types_the_user_may_read(): void
    {
        $this->makeProduct('Nimbus Hosting');
        $customer = $this->makeCustomer('Nimbus', 'Client', 'Nimbus Ltd');
        $this->makeTicket('TKT-NIMBUS', 'Nimbus is down');

        // Products only: customers and tickets must not leak through the picker.
        $user = $this->chatUser('chat.view', 'products.view');

        $response = $this->actingAs($user)
            ->getJson(route('admin.chat.search-entities', ['q' => 'Nimbus']))
            ->assertOk();

        $this->assertSame(['product'], collect($response->json('results'))->pluck('type')->unique()->all());
        $this->assertSame(['product'], $response->json('types'));
    }

    public function test_asking_for_a_forbidden_type_is_refused_rather_than_answered_empty(): void
    {
        $this->makeCustomer('Nimbus', 'Client', 'Nimbus Ltd');

        $this->actingAs($this->chatUser('chat.view', 'products.view'))
            ->getJson(route('admin.chat.search-entities', ['q' => 'Nimbus', 'type' => 'customer']))
            ->assertForbidden();
    }

    public function test_search_validates_its_input(): void
    {
        $user = $this->chatUser('chat.view', 'products.view');

        $this->actingAs($user)
            ->getJson(route('admin.chat.search-entities', ['q' => 'a']))
            ->assertStatus(422);

        $this->actingAs($user)
            ->getJson(route('admin.chat.search-entities', ['q' => 'Nimbus', 'type' => 'server']))
            ->assertStatus(422);
    }

    public function test_a_sql_injection_attempt_is_escaped_and_matches_nothing(): void
    {
        $this->makeProduct('Nimbus Hosting');

        $this->actingAs($this->chatUser('chat.view', 'products.view'))
            ->getJson(route('admin.chat.search-entities', ['q' => "' OR 1=1 --"]))
            ->assertOk()
            ->assertJsonCount(0, 'results');

        $this->assertDatabaseCount('products', 1);
    }

    // --- linking ----------------------------------------------------------

    public function test_a_ticket_can_be_attached_to_a_message_and_renders_as_a_card(): void
    {
        $ticket = $this->makeTicket('TKT-CARD', 'Something broke');
        $user = $this->chatUser('chat.view', 'chat.create_channel', 'tickets.view');
        $channel = $this->chat->createChannel('Ops', $user);
        $message = $this->chat->sendMessage($channel, $user, 'See this one');

        $this->actingAs($user)
            ->postJson(route('admin.chat.entity-links.store', $message), [
                'type' => 'ticket',
                'id' => $ticket->id,
            ])
            ->assertCreated()
            ->assertJsonPath('message.entity_links.0.type', 'ticket')
            ->assertJsonPath('message.entity_links.0.label', 'TKT-CARD')
            ->assertJsonPath('message.entity_links.0.url', route('admin.tickets.show', $ticket->id));

        $this->assertDatabaseHas('message_entity_links', [
            'message_id' => $message->id,
            'linkable_type' => Ticket::class,
            'linkable_id' => $ticket->id,
        ]);
    }

    public function test_linking_the_same_entity_twice_does_not_duplicate(): void
    {
        $ticket = $this->makeTicket('TKT-ONCE', 'Once only');
        $user = $this->chatUser('chat.view', 'chat.create_channel', 'tickets.view');
        $channel = $this->chat->createChannel('Ops', $user);
        $message = $this->chat->sendMessage($channel, $user, 'See this one');

        foreach ([1, 2] as $_) {
            $this->actingAs($user)->postJson(route('admin.chat.entity-links.store', $message), [
                'type' => 'ticket',
                'id' => $ticket->id,
            ])->assertCreated();
        }

        $this->assertSame(1, MessageEntityLink::count());
    }

    public function test_a_non_existent_entity_is_a_404_and_writes_nothing(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel', 'tickets.view');
        $channel = $this->chat->createChannel('Ops', $user);
        $message = $this->chat->sendMessage($channel, $user, 'See this one');

        $this->actingAs($user)
            ->postJson(route('admin.chat.entity-links.store', $message), ['type' => 'ticket', 'id' => 999999])
            ->assertNotFound();

        $this->assertSame(0, MessageEntityLink::count());
    }

    public function test_an_unknown_type_is_refused(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel', 'tickets.view');
        $channel = $this->chat->createChannel('Ops', $user);
        $message = $this->chat->sendMessage($channel, $user, 'See this one');

        $this->actingAs($user)
            ->postJson(route('admin.chat.entity-links.store', $message), ['type' => 'server', 'id' => 1])
            ->assertStatus(422);

        $this->assertSame(0, MessageEntityLink::count());
    }

    public function test_linking_requires_permission_on_the_target_type(): void
    {
        $ticket = $this->makeTicket('TKT-GUESS', 'Guessable');
        // chat.view but no tickets.view: guessing an id must not bypass the
        // picker's gate.
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);
        $message = $this->chat->sendMessage($channel, $user, 'See this one');

        $this->actingAs($user)
            ->postJson(route('admin.chat.entity-links.store', $message), ['type' => 'ticket', 'id' => $ticket->id])
            ->assertForbidden();

        $this->assertSame(0, MessageEntityLink::count());
    }

    public function test_you_cannot_attach_a_link_to_someone_elses_message(): void
    {
        $ticket = $this->makeTicket('TKT-THEIRS', 'Not yours');
        $author = $this->chatUser('chat.view', 'chat.create_channel', 'tickets.view');
        $channel = $this->chat->createChannel('Ops', $author);
        $message = $this->chat->sendMessage($channel, $author, 'Mine');

        $other = $this->chatUser('chat.view', 'tickets.view');
        $this->chat->addMember($channel, $other);

        $this->actingAs($other)
            ->postJson(route('admin.chat.entity-links.store', $message), ['type' => 'ticket', 'id' => $ticket->id])
            ->assertForbidden();
    }

    public function test_a_link_can_be_removed_and_only_from_its_own_message(): void
    {
        $ticket = $this->makeTicket('TKT-DROP', 'Droppable');
        $user = $this->chatUser('chat.view', 'chat.create_channel', 'tickets.view');
        $channel = $this->chat->createChannel('Ops', $user);
        $message = $this->chat->sendMessage($channel, $user, 'See this one');
        $other = $this->chat->sendMessage($channel, $user, 'Another message');

        $link = $this->chat->linkEntity($message, 'ticket', $ticket->id);

        $this->actingAs($user)
            ->deleteJson(route('admin.chat.entity-links.destroy', [$other, $link]))
            ->assertNotFound();

        $this->actingAs($user)
            ->deleteJson(route('admin.chat.entity-links.destroy', [$message, $link]))
            ->assertOk();

        $this->assertSame(0, MessageEntityLink::count());
    }

    public function test_a_link_whose_target_is_gone_renders_as_a_tombstone(): void
    {
        $ticket = $this->makeTicket('TKT-GONE', 'Will vanish');
        $user = $this->chatUser('chat.view', 'chat.create_channel', 'tickets.view');
        $channel = $this->chat->createChannel('Ops', $user);
        $message = $this->chat->sendMessage($channel, $user, 'See this one');

        $this->chat->linkEntity($message, 'ticket', $ticket->id);
        $ticket->delete();

        $this->actingAs($user)
            ->getJson(route('admin.chat.messages.index', $channel))
            ->assertOk()
            ->assertJsonPath('messages.0.entity_links.0.label', '(deleted)')
            ->assertJsonPath('messages.0.entity_links.0.url', null);
    }

    public function test_rendering_many_links_does_not_n_plus_one(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel', 'tickets.view', 'products.view');
        $channel = $this->chat->createChannel('Ops', $user);

        foreach (range(1, 4) as $n) {
            $message = $this->chat->sendMessage($channel, $user, "message {$n}");
            $this->chat->linkEntity($message, 'ticket', $this->makeTicket("TKT-N{$n}", "Subject {$n}")->id);
            $this->chat->linkEntity($message, 'product', $this->makeProduct("Product {$n}")->id);
        }

        DB::enableQueryLog();

        $this->actingAs($user)->getJson(route('admin.chat.messages.index', $channel))->assertOk();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // A morphTo eager load costs one query per distinct target class, not
        // one per row: without it this would be 8 extra queries and rising.
        $this->assertLessThan(
            20,
            $queries,
            "Rendering 4 messages with 8 entity links took {$queries} queries - the morph eager load is not working.",
        );
    }
}
