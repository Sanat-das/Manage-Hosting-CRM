<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Http\Requests\Chat\StoreChatAttachmentRequest;
use App\Models\ChatMessageAttachment;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * Chat file attachments: what may be uploaded, where it lands, and who can
 * fetch it back.
 */
class ChatAttachmentTest extends TestCase
{
    use CreatesChatUsers;
    use RefreshDatabase;

    private ChatService $chat;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->chat = app(ChatService::class);
    }

    public function test_an_image_is_stored_off_the_web_root_and_recorded(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);
        $message = $this->chat->sendMessage($channel, $user, 'See attached');

        $response = $this->actingAs($user)
            ->postJson(route('admin.chat.attachments.store', $message), [
                'file' => UploadedFile::fake()->image('diagram.png', 40, 40),
            ])
            ->assertCreated();

        $response->assertJsonPath('attachment.filename', 'diagram.png');
        $response->assertJsonPath('attachment.is_image', true);
        $response->assertJsonStructure(['attachment' => ['id', 'filename', 'mime_type', 'size', 'url']]);

        $attachment = ChatMessageAttachment::firstOrFail();

        $this->assertSame('local', $attachment->disk);
        $this->assertStringStartsWith('chat-attachments/'.$channel->id.'/', $attachment->path);
        $this->assertGreaterThan(0, $attachment->size_bytes);
        Storage::disk('local')->assertExists($attachment->path);

        // The stored name is generated; the user's name is data, never a path.
        $this->assertStringNotContainsString('diagram.png', $attachment->path);
        $this->assertStringNotContainsString(base_path(), $attachment->path);
    }

    public function test_an_oversized_file_is_refused(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);
        $message = $this->chat->sendMessage($channel, $user, 'Big one');

        $this->actingAs($user)
            ->postJson(route('admin.chat.attachments.store', $message), [
                'file' => UploadedFile::fake()->create('huge.pdf', StoreChatAttachmentRequest::MAX_KILOBYTES + 1, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, ChatMessageAttachment::count());
    }

    public function test_an_executable_is_refused(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);
        $message = $this->chat->sendMessage($channel, $user, 'Run this');

        foreach ([
            ['payload.exe', 'application/x-msdownload'],
            ['script.ps1', 'text/plain'],
            ['macro.bat', 'text/plain'],
        ] as [$name, $mime]) {
            $this->actingAs($user)
                ->postJson(route('admin.chat.attachments.store', $message), [
                    'file' => UploadedFile::fake()->create($name, 10, $mime),
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('file');
        }

        $this->assertSame(0, ChatMessageAttachment::count());
    }

    public function test_you_cannot_hang_a_file_off_someone_elses_message(): void
    {
        $author = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $author);
        $message = $this->chat->sendMessage($channel, $author, 'Mine');

        $other = $this->chatUser('chat.view');
        $this->chat->addMember($channel, $other);

        $this->actingAs($other)
            ->postJson(route('admin.chat.attachments.store', $message), [
                'file' => UploadedFile::fake()->image('theirs.png'),
            ])
            ->assertForbidden();

        $this->assertSame(0, ChatMessageAttachment::count());
    }

    public function test_an_attachment_downloads_for_someone_who_can_read_the_message(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);
        $message = $this->chat->sendMessage($channel, $user, 'See attached');

        $this->actingAs($user)->postJson(route('admin.chat.attachments.store', $message), [
            'file' => UploadedFile::fake()->image('diagram.png', 20, 20),
        ])->assertCreated();

        $attachment = ChatMessageAttachment::firstOrFail();
        $url = $this->signedUrlFor($attachment);

        $response = $this->actingAs($user)->get($url)->assertOk();

        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        // ?download=1 has to be inside the signature: the signed URL covers the
        // whole query string, so appending a parameter invalidates it.
        $this->actingAs($user)->get($this->signedUrlFor($attachment, ['download' => 1]))
            ->assertOk()
            ->assertDownload('diagram.png');
    }

    public function test_an_svg_is_never_served_inline(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);
        $message = $this->chat->sendMessage($channel, $user, 'Diagram');

        $svg = UploadedFile::fake()->createWithContent(
            'diagram.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->actingAs($user)
            ->postJson(route('admin.chat.attachments.store', $message), ['file' => $svg])
            ->assertCreated()
            // SVG is script-capable markup: it must not be advertised as a
            // previewable image either.
            ->assertJsonPath('attachment.is_image', false);

        $attachment = ChatMessageAttachment::firstOrFail();

        $this->actingAs($user)->get($this->signedUrlFor($attachment))
            ->assertOk()
            ->assertDownload('diagram.svg');
    }

    public function test_a_signed_url_is_not_enough_on_its_own(): void
    {
        $author = $this->chatUser('chat.view', 'chat.create_channel');
        $private = $this->chat->createChannel('Secret', $author, true);
        $message = $this->chat->sendMessage($private, $author, 'Confidential');

        $this->actingAs($author)->postJson(route('admin.chat.attachments.store', $message), [
            'file' => UploadedFile::fake()->image('secret.png'),
        ])->assertCreated();

        $attachment = ChatMessageAttachment::firstOrFail();
        $url = $this->signedUrlFor($attachment);

        // A correctly signed link that leaked to someone outside the room is
        // still refused: the signature bounds lifetime, the policy authorises.
        $this->actingAs($this->chatUser('chat.view'))
            ->get($url)
            ->assertForbidden();
    }

    public function test_an_unsigned_or_tampered_url_is_refused(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);
        $message = $this->chat->sendMessage($channel, $user, 'See attached');

        $this->actingAs($user)->postJson(route('admin.chat.attachments.store', $message), [
            'file' => UploadedFile::fake()->image('diagram.png'),
        ])->assertCreated();

        $attachment = ChatMessageAttachment::firstOrFail();

        $this->actingAs($user)
            ->get(route('admin.chat.attachments.show', $attachment))
            ->assertForbidden();

        $this->actingAs($user)
            ->get($this->signedUrlFor($attachment).'x')
            ->assertForbidden();
    }

    public function test_an_expired_url_is_refused(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);
        $message = $this->chat->sendMessage($channel, $user, 'See attached');

        $this->actingAs($user)->postJson(route('admin.chat.attachments.store', $message), [
            'file' => UploadedFile::fake()->image('diagram.png'),
        ])->assertCreated();

        $attachment = ChatMessageAttachment::firstOrFail();
        $url = $this->signedUrlFor($attachment);

        $this->travel(61)->minutes();

        $this->actingAs($user)->get($url)->assertForbidden();
    }

    public function test_a_message_payload_carries_its_attachments(): void
    {
        $user = $this->chatUser('chat.view', 'chat.create_channel');
        $channel = $this->chat->createChannel('Ops', $user);
        $message = $this->chat->sendMessage($channel, $user, 'See attached');

        $this->actingAs($user)->postJson(route('admin.chat.attachments.store', $message), [
            'file' => UploadedFile::fake()->image('diagram.png'),
        ])->assertCreated();

        $this->actingAs($user)
            ->getJson(route('admin.chat.messages.index', $channel))
            ->assertOk()
            ->assertJsonPath('messages.0.attachments.0.filename', 'diagram.png')
            ->assertJsonCount(1, 'messages.0.attachments');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function signedUrlFor(ChatMessageAttachment $attachment, array $extra = []): string
    {
        return URL::signedRoute(
            'admin.chat.attachments.show',
            ['attachment' => $attachment->id] + $extra,
            now()->addMinutes(60),
        );
    }
}
