<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMessageAttachment;
use App\Models\User;
use App\Services\ChatService;
use App\Support\ChatBodyHtml;
use App\Support\ChatMessagePayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * The chat's security envelope: what a hostile body renders as, how fast a
 * staff member may post, how long an attachment link lives, and which names a
 * channel may be given.
 *
 * Several of these pin behaviour that ALREADY existed before this test file —
 * they are characterisation, not new guarantees, and they are marked as such so
 * a future reader does not mistake a passing assertion for a feature this file
 * introduced.
 *
 * Determinism note for the rate-limit cases: time is frozen with freezeTime()
 * so the limiter's one-minute decay window cannot roll over half-way through a
 * 61-request loop, and the cache (which is where the limiter's counters live)
 * is flushed in setUp. Nothing here depends on wall-clock speed.
 */
class ChatSecurityTest extends TestCase
{
    use CreatesChatUsers;
    use RefreshDatabase;

    private ChatService $chat;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Cache::flush();

        $this->chat = app(ChatService::class);
    }

    // --- XSS / sanitisation ------------------------------------------------

    /**
     * CHARACTERISATION — passes before this task's changes.
     *
     * ChatBodyHtml escapes the body before it introduces any tag, so the
     * headline acceptance case was already safe. Kept so that a future
     * "optimisation" of the marker handling cannot quietly reopen it.
     */
    public function test_a_script_body_is_neutralised_end_to_end(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);

        $response = $this->actingAs($user)->postJson(
            route('admin.chat.messages.store', $channel),
            ['body' => '<script>alert(1)</script>'],
        )->assertCreated();

        $html = $response->json('message.body_html');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);

        // Stored as typed: the body column is the author's text, the HTML is
        // derived. Escaping at storage time would double-escape on render.
        $this->assertSame('<script>alert(1)</script>', ChatConversation::find($channel->id)
            ->messages()->firstOrFail()->body);

        $this->actingAs($user)
            ->get(route('admin.chat.index'))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    /**
     * The misleading-success guard: a sanitiser that stripped everything would
     * satisfy "no script present" while destroying every legitimate message.
     */
    public function test_legitimate_formatting_survives_sanitisation(): void
    {
        $html = ChatBodyHtml::render("**bold** and *italic* and `code`\nline two https://example.com/x");

        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<em>italic</em>', $html);
        $this->assertStringContainsString('<code>code</code>', $html);
        // `<br` rather than `<br>`: the sanitiser re-serialises it as `<br />`.
        $this->assertStringContainsString('<br', $html);
        $this->assertStringContainsString('<a href="https://example.com/x"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);

        $this->assertStringContainsString('<pre><code>', ChatBodyHtml::render("```\nsudo rm\n```"));
    }

    /**
     * The deliberate allow-list, asserted at the sanitiser stage itself.
     *
     * The renderer escapes first, so nothing hostile reaches this stage today —
     * that is exactly why it is asserted directly. This is the second layer: if
     * a future edit to the marker handling emits a tag, the allow-list is what
     * decides whether it survives.
     */
    #[DataProvider('hostileHtmlProvider')]
    public function test_the_sanitiser_allow_list_drops_everything_outside_the_chat_vocabulary(
        string $hostile,
        string $mustNotContain,
    ): void {
        $clean = ChatBodyHtml::sanitize($hostile);

        $this->assertStringNotContainsString($mustNotContain, strtolower($clean));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function hostileHtmlProvider(): array
    {
        return [
            'script tag' => ['<script>alert(1)</script>', '<script'],
            'img onerror' => ['<img src=x onerror="alert(1)">', 'onerror'],
            'img tag itself' => ['<img src="https://e.test/a.png">', '<img'],
            'svg onload' => ['<svg onload="alert(1)"></svg>', '<svg'],
            'iframe' => ['<iframe src="https://evil.test"></iframe>', '<iframe'],
            'onclick attribute' => ['<strong onclick="alert(1)">hi</strong>', 'onclick'],
            'javascript url' => ['<a href="javascript:alert(1)">x</a>', 'javascript:'],
            'data url link' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>', 'data:text/html'],
            'style injection' => ['<div style="background:url(javascript:alert(1))">x</div>', 'javascript:'],
            'broken nested tag' => ['<scr<script>ipt>alert(1)</script>', '<script'],
            'form action' => ['<form action="https://evil.test"><input name="a"></form>', '<form'],
            'object embed' => ['<object data="https://evil.test"></object>', '<object'],
        ];
    }

    public function test_the_sanitiser_keeps_the_tags_the_renderer_actually_emits(): void
    {
        $clean = ChatBodyHtml::sanitize(
            '<strong>b</strong><em>i</em><code>c</code><pre><code>block</code></pre>'
            .'<br><a href="https://example.com" target="_blank" rel="noopener noreferrer">link</a>'
        );

        foreach (['<strong>b</strong>', '<em>i</em>', '<code>c</code>', '<pre>', '<br', 'https://example.com'] as $keep) {
            $this->assertStringContainsString($keep, $clean, "The allow-list dropped {$keep}.");
        }
    }

    // --- rate limiting -----------------------------------------------------

    /**
     * The acceptance case: 60 messages a minute are allowed, the 61st is not.
     *
     * Deterministic by construction — time is frozen, so all 61 requests land
     * inside one decay window regardless of how slow the machine is.
     */
    public function test_the_sixty_first_message_in_a_minute_is_rate_limited(): void
    {
        $this->freezeTime();

        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);
        $url = route('admin.chat.messages.store', $channel);

        for ($i = 1; $i <= 60; $i++) {
            $this->actingAs($user)
                ->postJson($url, ['body' => "message {$i}"])
                ->assertStatus(201, "Request {$i} of 60 was refused; the limiter is tighter than 60/min.");
        }

        $this->actingAs($user)
            ->postJson($url, ['body' => 'message 61'])
            ->assertStatus(429);
    }

    /**
     * The limiter is per user, not global: one noisy staff member must not
     * silence the rest of the company.
     */
    public function test_the_limit_is_counted_per_user(): void
    {
        $this->freezeTime();

        $noisy = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $noisy);
        $quiet = $this->chatUser('chat.view');
        $this->chat->addMember($channel, $quiet);

        $url = route('admin.chat.messages.store', $channel);

        for ($i = 1; $i <= 61; $i++) {
            $this->actingAs($noisy)->postJson($url, ['body' => "flood {$i}"]);
        }

        $this->actingAs($noisy)->postJson($url, ['body' => 'still blocked'])->assertStatus(429);
        $this->actingAs($quiet)->postJson($url, ['body' => 'unaffected'])->assertStatus(201);
    }

    /**
     * Reading is not spending the write budget: the polling fallback from
     * Todo 19 fetches every 5s and must never exhaust a user's message quota.
     */
    public function test_fetching_messages_does_not_consume_the_message_budget(): void
    {
        $this->freezeTime();

        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);

        for ($i = 0; $i < 70; $i++) {
            $this->actingAs($user)->getJson(route('admin.chat.messages.index', $channel))->assertOk();
        }

        $this->actingAs($user)
            ->postJson(route('admin.chat.messages.store', $channel), ['body' => 'still allowed'])
            ->assertStatus(201);
    }

    // --- lengths and names -------------------------------------------------

    /**
     * CHARACTERISATION — the 4000 ceiling already existed on both the request
     * and the service. Pinned at the exact boundary, which nothing did before.
     */
    public function test_a_body_of_exactly_four_thousand_characters_is_accepted_and_four_thousand_and_one_is_not(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);
        $url = route('admin.chat.messages.store', $channel);

        $this->actingAs($user)
            ->postJson($url, ['body' => str_repeat('a', ChatService::MAX_BODY_LENGTH)])
            ->assertStatus(201);

        $this->actingAs($user)
            ->postJson($url, ['body' => str_repeat('a', ChatService::MAX_BODY_LENGTH + 1)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    #[DataProvider('invalidChannelNameProvider')]
    public function test_an_unusable_channel_name_is_refused(string $name): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');

        $this->actingAs($user)
            ->postJson(route('admin.chat.channels.store'), ['name' => $name])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidChannelNameProvider(): array
    {
        return [
            'leading hyphen' => ['-deploys'],
            'leading underscore' => ['_deploys'],
            'blank' => ['   '],
            'fifty one characters' => [str_repeat('a', 51)],
            'markup' => ['<script>x</script>'],
            'punctuation' => ['deploys!'],
            'slash' => ['deploys/prod'],
            // Observed, and left as it is: the name charset is ASCII
            // alphanumerics, spaces, hyphen and underscore. Non-ASCII and
            // emoji are refused rather than silently transliterated into a
            // slug that no longer resembles what was typed.
            'accented latin' => ['Café Crème'],
            'emoji' => ['deploys 🚀'],
        ];
    }

    /**
     * The slug is the identifier, and it is what must be well-formed: whatever
     * a human types as the display name, what lands in the URL is
     * lowercase-alphanumeric-and-hyphens.
     */
    public function test_the_derived_slug_is_always_url_safe(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');

        foreach (['Deploy Notices', 'Ops_2026', 'UPPER CASE', 'a', str_repeat('n', 50)] as $name) {
            $slug = $this->actingAs($user)
                ->postJson(route('admin.chat.channels.store'), ['name' => $name])
                ->assertStatus(201)
                ->json('channel.slug');

            $this->assertMatchesRegularExpression(
                '/^[a-z0-9][a-z0-9_\-]*$/',
                $slug,
                "Channel name {$name} produced an unusable slug {$slug}.",
            );
            $this->assertLessThanOrEqual(50, strlen($slug));
        }
    }

    public function test_a_duplicate_channel_slug_is_refused(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');

        $this->actingAs($user)
            ->postJson(route('admin.chat.channels.store'), ['name' => 'Deploys'])
            ->assertStatus(201);

        $this->actingAs($user)
            ->postJson(route('admin.chat.channels.store'), ['name' => 'Deploys'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame(1, ChatConversation::where('slug', 'deploys')->count());
    }

    /**
     * Two names that differ only by case, or only by the separator, collapse to
     * the same slug — and a slug is what the URL and the @-mention resolve on.
     */
    public function test_a_name_that_collides_only_after_slugging_is_refused(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');

        $this->actingAs($user)
            ->postJson(route('admin.chat.channels.store'), ['name' => 'Deploy Notices'])
            ->assertStatus(201);

        foreach (['deploy notices', 'DEPLOY NOTICES', 'Deploy-Notices'] as $collision) {
            $this->actingAs($user)
                ->postJson(route('admin.chat.channels.store'), ['name' => $collision])
                ->assertStatus(422)
                ->assertJsonValidationErrors('name');
        }

        $this->assertSame(1, ChatConversation::where('slug', 'deploy-notices')->count());
    }

    /**
     * Surrounding whitespace is not a validation failure — Laravel's global
     * TrimStrings middleware has already removed it by the time the rule runs,
     * so `  Deploys  ` is the name `Deploys`. Pinned because it looks like a
     * hole in the leading-character regex and is not one.
     */
    public function test_a_padded_channel_name_is_trimmed_rather_than_refused(): void
    {
        $this->actingAs($this->chatUser('chat.view', 'chat.create_channel'))
            ->postJson(route('admin.chat.channels.store'), ['name' => '   Deploys   '])
            ->assertStatus(201)
            ->assertJsonPath('channel.name', 'Deploys')
            ->assertJsonPath('channel.slug', 'deploys');
    }

    // --- participants ------------------------------------------------------

    /**
     * CHARACTERISATION of ChatService::MAX_GROUP_DM_PARTICIPANTS, which already
     * existed — but was never reached over HTTP, so the 422 it produces at the
     * controller boundary was unproven.
     */
    public function test_a_group_message_refuses_a_fifty_first_participant(): void
    {
        $creator = $this->chatUser('chat.view', 'chat.create_channel');
        $members = User::factory()->count(ChatService::MAX_GROUP_DM_PARTICIPANTS - 1)->create();

        $group = $this->chat->createGroupDirectMessage($members, $creator);

        $this->assertSame(
            ChatService::MAX_GROUP_DM_PARTICIPANTS,
            $group->participants()->count(),
            'The fixture did not actually fill the group.',
        );

        $extra = User::factory()->create();

        $this->actingAs($creator)
            ->postJson(route('admin.chat.channels.members.store', $group), ['user_id' => $extra->id])
            ->assertStatus(422);

        $this->assertSame(ChatService::MAX_GROUP_DM_PARTICIPANTS, $group->participants()->count());
    }

    public function test_a_group_message_cannot_be_created_over_the_cap(): void
    {
        $creator = $this->chatUser('chat.view');
        $members = User::factory()->count(ChatService::MAX_GROUP_DM_PARTICIPANTS)->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->chat->createGroupDirectMessage($members, $creator);
    }

    // --- signed attachment URLs -------------------------------------------

    /**
     * The URL the application actually hands out — not one the test built —
     * works inside the window and is refused outside it.
     *
     * The existing ChatAttachmentTest builds its own 60-minute URL, so it would
     * stay green even if ChatMessagePayload started issuing week-long links.
     */
    public function test_the_issued_attachment_url_expires_after_sixty_minutes(): void
    {
        $this->assertSame(60, ChatMessagePayload::ATTACHMENT_URL_MINUTES);

        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);
        $message = $this->chat->sendMessage($channel, $user, 'See attached');

        $this->actingAs($user)->postJson(route('admin.chat.attachments.store', $message), [
            'file' => UploadedFile::fake()->image('diagram.png'),
        ])->assertCreated();

        $issued = $this->actingAs($user)
            ->getJson(route('admin.chat.messages.index', $channel))
            ->assertOk()
            ->json('messages.0.attachments.0.url');

        $this->assertIsString($issued);

        $this->travel(59)->minutes();
        $this->actingAs($user)->get($issued)->assertOk();

        $this->travel(2)->minutes();
        $this->actingAs($user)->get($issued)->assertForbidden();
    }

    #[DataProvider('tamperedSignatureProvider')]
    public function test_a_tampered_signature_is_refused(string $mutation): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);
        $message = $this->chat->sendMessage($channel, $user, 'See attached');

        $this->actingAs($user)->postJson(route('admin.chat.attachments.store', $message), [
            'file' => UploadedFile::fake()->image('diagram.png'),
        ])->assertCreated();

        $attachment = ChatMessageAttachment::firstOrFail();
        $url = URL::signedRoute(
            'admin.chat.attachments.show',
            ['attachment' => $attachment->id],
            now()->addMinutes(60),
        );

        $tampered = match ($mutation) {
            'appended' => $url.'0',
            'truncated' => substr($url, 0, -1),
            'stripped' => strtok($url, '?'),
            'extra_param' => $url.'&download=1',
        };

        $this->actingAs($user)->get($tampered)->assertForbidden();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function tamperedSignatureProvider(): array
    {
        return [
            'a digit appended to the signature' => ['appended'],
            'a digit removed from the signature' => ['truncated'],
            'the query string removed entirely' => ['stripped'],
            'an unsigned parameter smuggled in' => ['extra_param'],
        ];
    }

    // --- transport headers -------------------------------------------------

    /**
     * CHARACTERISATION — the app already ships a blocking CSP on every web
     * response (App\Http\Middleware\SecurityHeaders). The chat page is asserted
     * to be covered by it, and to carry the directives that matter for an
     * injection: no plugins, no base-tag rewrite, no cross-origin form post.
     */
    public function test_the_chat_page_is_covered_by_the_content_security_policy(): void
    {
        $response = $this->actingAs($this->chatUser('chat.view'))
            ->get(route('admin.chat.index'))
            ->assertOk();

        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertIsString($csp, 'The chat page shipped without a CSP.');

        foreach (["default-src 'self'", "object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'self'"] as $directive) {
            $this->assertStringContainsString($directive, $csp);
        }

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }
}
