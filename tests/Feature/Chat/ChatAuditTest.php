<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\AuditLog;
use App\Models\ChatConversation;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Services\ChatService;
use App\Services\TicketService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * Two halves of Todo 17.
 *
 * (a) The audit trail: seven chat actions each write exactly one row of
 *     METADATA to `audit_log`. The verbatim message body must never reach it.
 * (b) The entity timeline: a product / customer / ticket page lists the recent
 *     chat messages that reference it, gated by the chat policy and costing a
 *     constant number of queries however many links exist.
 */
class ChatAuditTest extends TestCase
{
    use CreatesChatUsers;
    use RefreshDatabase;

    /** Marks the timeline card in the rendered page. */
    private const TIMELINE_MARKER = 'data-chat-entity-timeline';

    private ChatService $chat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chat = app(ChatService::class);

        TicketDepartment::firstOrCreate(
            ['slug' => 'support'],
            ['name' => 'Support', 'enabled' => true],
        );
        TicketService::forgetDepartmentCache();
    }

    // --- fixtures ---------------------------------------------------------

    private function makeProduct(string $name = 'Nimbus Hosting'): Product
    {
        $group = ProductGroup::firstOrCreate(
            ['slug' => 'chat-audit-group'],
            ['name' => 'Chat Audit Group', 'status' => 'active'],
        );

        return Product::create([
            'name' => $name,
            'product_group_id' => $group->id,
            'price' => 10,
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ]);
    }

    private function makeCustomer(string $company = 'Nimbus Ltd'): Customer
    {
        $user = User::factory()->create([
            'first_name' => 'Nimbus',
            'last_name' => 'Client',
            'role' => 'client',
        ]);

        return Customer::create(['user_id' => $user->id, 'company' => $company, 'status' => 'active']);
    }

    private function makeTicket(string $no = 'TKT-AUDIT-1'): Ticket
    {
        return Ticket::create([
            'ticket_no' => $no,
            'subject' => 'A ticket someone talked about',
            'priority' => 'medium',
            'status' => 'open',
            'department' => 'support',
        ]);
    }

    /**
     * A user who can both use chat and open the three entity pages. Ticket
     * visibility is department-scoped, so the pivot has to be attached too.
     */
    private function viewer(string ...$extra): User
    {
        $user = $this->chatUser(
            'chat.view',
            'chat.create_channel',
            'chat.manage',
            'products.view',
            'customers.view',
            'tickets.view',
            ...$extra,
        );

        $user->ticketDepartments()->attach(TicketDepartment::where('slug', 'support')->value('id'));

        return $user->fresh();
    }

    private function customerInbox(string $body = 'my server is down'): ChatConversation
    {
        return $this->chat->startCustomerChat(
            ['name' => 'Guest Person', 'email' => 'guest@example.test'],
            'support',
            $body,
        );
    }

    /** @return array<string, mixed> */
    private function detailsOf(AuditLog $row): array
    {
        $decoded = json_decode((string) $row->details, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return Collection<int, AuditLog> */
    private function auditRows(string $action): Collection
    {
        return AuditLog::where('action', $action)->get();
    }

    // --- (a) audit logging ------------------------------------------------

    public function test_creating_a_channel_writes_exactly_one_audit_row(): void
    {
        $user = $this->viewer();

        $channel = $this->chat->createChannel('Ops Room', $user, false, 'support', 'Topic here');

        $rows = $this->auditRows('chat.channel_created');
        $this->assertCount(1, $rows, 'Creating a channel did not write exactly one audit row.');

        $row = $rows->first();
        $this->assertSame('chat_conversation', $row->entity_type);
        $this->assertSame($channel->id, (int) $row->entity_id);
        $this->assertSame($user->id, (int) $row->user_id);

        $details = $this->detailsOf($row);
        $this->assertSame('Ops Room', $details['name'] ?? null);
        $this->assertFalse($details['is_private'] ?? null);
        $this->assertSame('support', $details['department'] ?? null);
    }

    public function test_archiving_a_channel_writes_exactly_one_audit_row(): void
    {
        $user = $this->viewer();
        $channel = $this->chat->createChannel('Ops Room', $user);

        $this->actingAs($user)
            ->postJson(route('admin.chat.channels.archive', $channel))
            ->assertSuccessful();

        $rows = $this->auditRows('chat.channel_archived');
        $this->assertCount(1, $rows, 'Archiving a channel did not write exactly one audit row.');

        $row = $rows->first();
        $this->assertSame('chat_conversation', $row->entity_type);
        $this->assertSame($channel->id, (int) $row->entity_id);
        $this->assertSame($user->id, (int) $row->user_id);
        $this->assertSame('Ops Room', $this->detailsOf($row)['name'] ?? null);
    }

    public function test_adding_a_member_writes_exactly_one_audit_row(): void
    {
        $owner = $this->viewer();
        $channel = $this->chat->createChannel('Ops Room', $owner);
        $newcomer = $this->chatUser('chat.view');

        // The creator's own bootstrap membership must not be counted here.
        $this->assertCount(0, $this->auditRows('chat.member_added'));

        $this->actingAs($owner)
            ->postJson(route('admin.chat.channels.members.store', $channel), ['user_id' => $newcomer->id])
            ->assertSuccessful();

        $rows = $this->auditRows('chat.member_added');
        $this->assertCount(1, $rows, 'Adding a member did not write exactly one audit row.');

        $details = $this->detailsOf($rows->first());
        $this->assertSame($newcomer->id, $details['member_user_id'] ?? null);
        $this->assertSame($channel->id, (int) $rows->first()->entity_id);
    }

    public function test_removing_a_member_writes_exactly_one_audit_row(): void
    {
        $owner = $this->viewer();
        $channel = $this->chat->createChannel('Ops Room', $owner);
        $member = $this->chatUser('chat.view');
        $this->chat->addMember($channel, $member);

        $this->actingAs($owner)
            ->deleteJson(route('admin.chat.channels.members.destroy', [$channel, $member]))
            ->assertSuccessful();

        $rows = $this->auditRows('chat.member_removed');
        $this->assertCount(1, $rows, 'Removing a member did not write exactly one audit row.');
        $this->assertSame($member->id, $this->detailsOf($rows->first())['member_user_id'] ?? null);
    }

    public function test_deleting_a_message_writes_exactly_one_audit_row(): void
    {
        $user = $this->viewer();
        $channel = $this->chat->createChannel('Ops Room', $user);
        $body = 'a retracted remark';
        $message = $this->chat->sendMessage($channel, $user, $body);

        $this->actingAs($user)
            ->deleteJson(route('admin.chat.messages.destroy', $message))
            ->assertSuccessful();

        $rows = $this->auditRows('chat.message_deleted');
        $this->assertCount(1, $rows, 'Deleting a message did not write exactly one audit row.');

        $row = $rows->first();
        $this->assertSame('chat_message', $row->entity_type);
        $this->assertSame($message->id, (int) $row->entity_id);

        $details = $this->detailsOf($row);
        $this->assertSame($channel->id, $details['conversation_id'] ?? null);
        $this->assertSame(
            mb_strlen($body),
            $details['body_length'] ?? null,
            'The audit should record the length, not the text.',
        );
    }

    public function test_closing_a_customer_chat_writes_exactly_one_audit_row(): void
    {
        $operator = $this->viewer();
        $inbox = $this->customerInbox();

        $this->actingAs($operator)
            ->postJson(route('admin.chat.inbox.close', $inbox))
            ->assertSuccessful();

        $rows = $this->auditRows('chat.conversation_closed');
        $this->assertCount(1, $rows, 'Closing a customer chat did not write exactly one audit row.');
        $this->assertSame($inbox->id, (int) $rows->first()->entity_id);
        $this->assertSame('customer_inbox', $this->detailsOf($rows->first())['conversation_type'] ?? null);
    }

    public function test_converting_a_customer_chat_to_a_ticket_writes_exactly_one_audit_row(): void
    {
        $operator = $this->viewer();
        $inbox = $this->customerInbox();

        $this->actingAs($operator)
            ->postJson(route('admin.chat.inbox.convert', $inbox))
            ->assertSuccessful();

        $rows = $this->auditRows('chat.converted_to_ticket');
        $this->assertCount(1, $rows, 'Converting a chat did not write exactly one audit row.');

        $details = $this->detailsOf($rows->first());
        $this->assertSame($inbox->id, (int) $rows->first()->entity_id);
        $this->assertNotNull($details['ticket_id'] ?? null);
        $this->assertNotNull($details['ticket_no'] ?? null);
    }

    /**
     * The Must-NOT of this todo: metadata only, never the words.
     */
    public function test_no_audit_row_ever_contains_a_message_body(): void
    {
        $needle = 'SECRET-PASSPHRASE-swordfish-42';

        $owner = $this->viewer();
        $channel = $this->chat->createChannel('Ops Room', $owner);
        $member = $this->chatUser('chat.view');
        $this->chat->addMember($channel, $member);
        $this->chat->removeMember($channel, $member);

        $message = $this->chat->sendMessage($channel, $owner, $needle);
        $this->chat->deleteMessage($message);
        $this->chat->archive($channel);

        $inbox = $this->customerInbox($needle);
        $this->chat->convertToTicket($inbox, app(TicketService::class));
        $this->chat->closeConversation($inbox);

        $this->assertGreaterThanOrEqual(7, AuditLog::count(), 'Not every audited action produced a row.');

        foreach (AuditLog::all() as $row) {
            $blob = (string) $row->details.'|'.(string) $row->action.'|'.(string) $row->entity_type;

            $this->assertStringNotContainsString(
                $needle,
                $blob,
                "Audit row #{$row->id} ({$row->action}) stored the verbatim message body.",
            );
        }
    }

    public function test_a_failed_permission_attempt_writes_no_audit_row(): void
    {
        $owner = $this->viewer();
        $channel = $this->chat->createChannel('Ops Room', $owner);

        // A plain member: may read the channel, may not archive it.
        $outsider = $this->chatUser('chat.view');
        $this->chat->addMember($channel, $outsider);

        AuditLog::query()->delete();

        $this->actingAs($outsider)
            ->postJson(route('admin.chat.channels.archive', $channel))
            ->assertForbidden();

        $this->assertSame(
            0,
            AuditLog::where('action', 'chat.channel_archived')->count(),
            'A refused archive wrote a success audit row.',
        );
        $this->assertNull($channel->fresh()->archived_at, 'The refused archive actually happened.');
    }

    // --- (b) entity timeline ----------------------------------------------

    /**
     * @return array{0: User, 1: ChatConversation}
     */
    private function channelWithViewer(string $name = 'Ops Room'): array
    {
        $user = $this->viewer();

        return [$user, $this->chat->createChannel($name, $user)];
    }

    public function test_a_product_page_renders_its_linked_chat_messages(): void
    {
        [$user, $channel] = $this->channelWithViewer();
        $product = $this->makeProduct();
        $message = $this->chat->sendMessage($channel, $user, 'we should reprice this plan');
        $this->chat->linkEntity($message, 'product', $product->id);

        $this->actingAs($user)
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertSee(self::TIMELINE_MARKER, false)
            ->assertSee('we should reprice this plan')
            ->assertSee(route('admin.chat.index', ['c' => $channel->id]), false);
    }

    public function test_a_customer_page_renders_its_linked_chat_messages(): void
    {
        [$user, $channel] = $this->channelWithViewer();
        $customer = $this->makeCustomer();
        $message = $this->chat->sendMessage($channel, $user, 'this account needs a call back');
        $this->chat->linkEntity($message, 'customer', $customer->id);

        $this->actingAs($user)
            ->get(route('admin.customers.show', $customer))
            ->assertOk()
            ->assertSee(self::TIMELINE_MARKER, false)
            ->assertSee('this account needs a call back');
    }

    public function test_a_ticket_page_renders_its_linked_chat_messages(): void
    {
        [$user, $channel] = $this->channelWithViewer();
        $ticket = $this->makeTicket();
        $message = $this->chat->sendMessage($channel, $user, 'escalating this one to ops');
        $this->chat->linkEntity($message, 'ticket', $ticket->id);

        $this->actingAs($user)
            ->get(route('admin.tickets.show', $ticket))
            ->assertOk()
            ->assertSee(self::TIMELINE_MARKER, false)
            ->assertSee('escalating this one to ops');
    }

    /**
     * A contact has no page of its own, so a link to one surfaces on the
     * customer it belongs to.
     */
    public function test_a_contact_link_surfaces_on_its_customers_page(): void
    {
        [$user, $channel] = $this->channelWithViewer();
        $customer = $this->makeCustomer();
        $contact = CustomerContact::create([
            'customer_id' => $customer->id,
            'first_name' => 'Ada',
            'last_name' => 'Byron',
            'email' => 'ada@example.test',
        ]);

        $message = $this->chat->sendMessage($channel, $user, 'spoke to the technical contact');
        $this->chat->linkEntity($message, 'contact', $contact->id);

        $this->actingAs($user)
            ->get(route('admin.customers.show', $customer))
            ->assertOk()
            ->assertSee('spoke to the technical contact');
    }

    /**
     * THE leak vector: the entity page is a side door into chat. A private
     * channel's message must not come through it.
     */
    public function test_a_private_channel_message_is_not_leaked_to_a_non_participant(): void
    {
        $insider = $this->viewer();
        $private = $this->chat->createChannel('War Room', $insider, true);
        $product = $this->makeProduct();
        $message = $this->chat->sendMessage($private, $insider, 'CONFIDENTIAL margin discussion');
        $this->chat->linkEntity($message, 'product', $product->id);

        // Sees it: a participant.
        $this->actingAs($insider)
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertSee('CONFIDENTIAL margin discussion');

        // Does not: everyone else, however much chat permission they hold.
        $outsider = $this->viewer();

        $this->actingAs($outsider)
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertDontSee('CONFIDENTIAL margin discussion');
    }

    public function test_the_timeline_shows_at_most_five_messages(): void
    {
        [$user, $channel] = $this->channelWithViewer();
        $product = $this->makeProduct();

        foreach (range(1, 8) as $n) {
            $message = $this->chat->sendMessage($channel, $user, "linked remark number {$n}");
            $this->chat->linkEntity($message, 'product', $product->id);
        }

        $html = $this->actingAs($user)
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->getContent();

        $shown = 0;
        foreach (range(1, 8) as $n) {
            if (str_contains($html, "linked remark number {$n}")) {
                $shown++;
            }
        }

        $this->assertSame(5, $shown, "The timeline rendered {$shown} messages; the limit is 5.");
        $this->assertStringContainsString('linked remark number 8', $html, 'The five shown are not the newest.');
    }

    /**
     * Query count must not grow with the number of links.
     */
    public function test_the_entity_timeline_does_not_n_plus_one(): void
    {
        [$user, $channel] = $this->channelWithViewer();

        $small = $this->makeProduct('Small Product');
        $large = $this->makeProduct('Large Product');

        foreach (range(1, 5) as $n) {
            $m = $this->chat->sendMessage($channel, $user, "small {$n}");
            $this->chat->linkEntity($m, 'product', $small->id);
        }

        foreach (range(1, 15) as $n) {
            $m = $this->chat->sendMessage($channel, $user, "large {$n}");
            $this->chat->linkEntity($m, 'product', $large->id);
        }

        // Warm anything cached per-process (module list, permissions) so the
        // first measured request is not paying for it.
        $this->actingAs($user)->get(route('admin.products.show', $small))->assertOk();

        $withFive = $this->countQueries(fn () => $this->actingAs($user)
            ->get(route('admin.products.show', $small))->assertOk());

        $withFifteen = $this->countQueries(fn () => $this->actingAs($user)
            ->get(route('admin.products.show', $large))->assertOk());

        $this->assertSame(
            $withFive,
            $withFifteen,
            "5 links cost {$withFive} queries and 15 cost {$withFifteen} - the timeline is N+1 on link count.",
        );
    }

    private function countQueries(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $work();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $count;
    }

    /**
     * malformed_input: the linked row is gone. Todo 12 renders "(deleted)" in
     * the message card; the timeline must not 500 either.
     */
    public function test_a_link_whose_target_is_gone_does_not_break_the_page(): void
    {
        [$user, $channel] = $this->channelWithViewer();
        $customer = $this->makeCustomer();
        $contact = CustomerContact::create([
            'customer_id' => $customer->id,
            'first_name' => 'Ada',
            'last_name' => 'Byron',
            'email' => 'ada@example.test',
        ]);

        $message = $this->chat->sendMessage($channel, $user, 'about the departed contact');
        $this->chat->linkEntity($message, 'contact', $contact->id);
        $contact->delete();

        $this->actingAs($user)
            ->get(route('admin.customers.show', $customer))
            ->assertOk();
    }

    public function test_an_entity_with_no_links_renders_no_timeline_card(): void
    {
        $user = $this->viewer();
        $product = $this->makeProduct();

        $this->actingAs($user)
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertDontSee(self::TIMELINE_MARKER, false);
    }

    /**
     * A Blade file can compile cleanly and still print literal directive text.
     * Assert on the rendered HTML, never on the source.
     */
    public function test_the_timeline_emits_no_stray_blade_directives(): void
    {
        [$user, $channel] = $this->channelWithViewer();
        $product = $this->makeProduct();
        $message = $this->chat->sendMessage($channel, $user, 'rendered check');
        $this->chat->linkEntity($message, 'product', $product->id);

        $html = $this->actingAs($user)
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->getContent();

        foreach (['@if', '@endif', '@foreach', '@endforeach', '@include', '@can', '@endcan', '@stop', '@php'] as $directive) {
            $this->assertStringNotContainsString(
                $directive,
                $html,
                "The rendered page printed the literal Blade directive {$directive}.",
            );
        }
    }
}
