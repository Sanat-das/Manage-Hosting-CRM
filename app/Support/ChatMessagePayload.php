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
     * What the customer is told instead of the operator's real name.
     *
     * Matches TypingIndicator::OPERATOR_LABEL — the transcript and the typing
     * indicator must agree, otherwise the indicator says "Support is typing…"
     * while the message list above it prints a real full name.
     * Kept here (public) so the clientPayload helper and the tests share one constant.
     */
    public const OPERATOR_LABEL = 'Support';

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
     * The customer-facing view of a message.
     *
     * Strips every identity-bearing field so an unauthenticated visitor learns
     * nothing about which staff member replied. The staff payload (for()) keeps
     * real names; this one does not. The two are deliberately separate methods
     * so a future field cannot silently leak by being added to for() alone.
     *
     * Field-by-field (guest sees):
     * - author_name: "Support" when is_guest is false, otherwise the guest's own name
     * - user: absent (contains id+full_name)
     * - author_email / user_name / email / avatar* / gravatar* : absent — none are emitted by for(), and this method explicitly unsets them if ever added
     * - entity_links: type+label only (admin url+entity_id stripped)
     * - attachments: client route url (admin signed url stripped), no email-derived hash
     * - is_operator: true for staff, false for guest — the only operator signal the widget needs
     * - user_id (bare integer): NOT in message payload by design; typing payload's user_id stays bare integer per TypingIndicator and is not removed
     *
     * @return array<string, mixed>
     */
    public static function forClient(ChatConversationMessage $message): array
    {
        $payload = self::for($message);

        // That a staff member said it is enough; which staff member is not the customer's business.
        // Matches TypingIndicator::OPERATOR_LABEL.
        if (! $payload['is_guest']) {
            $payload['author_name'] = self::OPERATOR_LABEL;
        }

        // Contains {id, name} — the staff full name.
        unset($payload['user']);

        // Defensive: if any identity field is ever added to for(), it must not reach a guest.
        unset($payload['author_email'], $payload['user_name'], $payload['email'], $payload['avatar'], $payload['avatar_url'], $payload['gravatar'], $payload['gravatar_url'], $payload['gravatar_hash'], $payload['author_avatar'], $payload['user_id']);

        // Entity cards are read-only for customers: no url, no entity_id to enumerate.
        $payload['entity_links'] = array_map(
            static fn (array $link): array => ['type' => $link['type'], 'label' => $link['label']],
            $payload['entity_links'],
        );

        // Client attachments use the guest's own download route, not the signed admin one.
        $payload['attachments'] = array_map(
            static fn (array $attachment): array => self::clientAttachmentUrl((int) $message->conversation_id, $attachment),
            $payload['attachments'],
        );

        $payload['is_operator'] = ! $payload['is_guest'];

        return $payload;
    }

    /**
     * Swap the signed admin attachment URL for the customer's own one.
     *
     * @param  array<string, mixed>  $attachment
     * @return array<string, mixed>
     */
    private static function clientAttachmentUrl(int $conversationId, array $attachment): array
    {
        $attachment['url'] = route('chat.attachment', [
            'conversation' => $conversationId,
            'attachment' => $attachment['id'],
        ]);

        return $attachment;
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
