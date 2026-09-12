<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ChatConversationMessage;

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
                ? $message->attachments->map(static fn ($a) => [
                    'id' => $a->id,
                    'filename' => $a->filename,
                    'mime_type' => $a->mime_type,
                    'size' => $a->humanSize(),
                    'is_image' => $a->isImage(),
                ])->all()
                : [],
            'entity_links' => $message->relationLoaded('entityLinks')
                ? $message->entityLinks->map(static fn ($link) => [
                    'id' => $link->id,
                    'type' => $link->typeKey(),
                    'entity_id' => $link->linkable_id,
                ])->all()
                : [],
        ];
    }
}
