<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ChatConversationMessage;
use App\Models\ChatMessageAttachment;
use App\Models\MessageEntityLink;
use Illuminate\Support\Facades\URL;

/**
 * The one shape a message takes on the wire.
 *
 * Both the broadcast events and the HTTP endpoints return this, so a message
 * that arrives over the websocket is indistinguishable from one fetched by
 * polling — which is what lets the client render both with the same code and
 * lets the polling fallback be a genuine fallback.
 *
 * Kept deliberately small: chat events broadcast synchronously inside the web
 * request, so the payload is ids plus rendered text, never an eager-loaded
 * object graph.
 */
final class ChatMessagePayload
{
    /** How long a signed attachment URL stays usable. */
    public const ATTACHMENT_URL_MINUTES = 60;

    /**
     * @return array<string, mixed>
     */
    public static function for(ChatConversationMessage $message): array
    {
        $deleted = $message->isDeleted();

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'parent_id' => $message->parent_id,
            'user' => $message->user === null ? null : [
                'id' => $message->user->id,
                'name' => $message->user->full_name,
            ],
            'author_name' => $message->authorName(),
            'is_guest' => $message->user_id === null,
            // A deleted message keeps its place in the thread but never its
            // text — not over the socket either.
            'body' => $deleted ? ChatConversationMessage::DELETED_PLACEHOLDER : (string) $message->body,
            'body_html' => $deleted
                ? '<em>'.ChatConversationMessage::DELETED_PLACEHOLDER.'</em>'
                : ChatBodyHtml::render($message->body),
            'is_deleted' => $deleted,
            'is_edited' => $message->isEdited(),
            'created_at' => $message->created_at?->toIso8601String(),
            'edited_at' => $message->edited_at?->toIso8601String(),
            'attachments' => $message->relationLoaded('attachments')
                ? $message->attachments->map(static fn ($a) => self::attachment($a))->all()
                : [],
            'entity_links' => $message->relationLoaded('entityLinks')
                ? $message->entityLinks->map(static fn (MessageEntityLink $link) => [
                    'id' => $link->id,
                    'type' => $link->typeKey(),
                    'entity_id' => $link->linkable_id,
                    'label' => $link->label(),
                    'url' => $link->url(),
                ])->all()
                : [],
        ];
    }

    /**
     * One attachment, with a time-limited signed URL.
     *
     * The signature bounds how long a URL keeps working; it is NOT the
     * authorisation. The route re-checks the policy on every request, so a
     * link that leaks out of the conversation is still useless to anyone who
     * could not have read the message anyway.
     *
     * @return array<string, mixed>
     */
    public static function attachment(ChatMessageAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'filename' => $attachment->filename,
            'mime_type' => $attachment->mime_type,
            'size' => $attachment->humanSize(),
            'size_bytes' => (int) $attachment->size_bytes,
            'is_image' => $attachment->isImage() && $attachment->mime_type !== 'image/svg+xml',
            'url' => URL::signedRoute(
                'admin.chat.attachments.show',
                ['attachment' => $attachment->id],
                now()->addMinutes(self::ATTACHMENT_URL_MINUTES),
            ),
        ];
    }
}
