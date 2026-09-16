<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendEmail;
use App\Models\ChatConversation;
use App\Models\ChatConversationMessage;
use App\Models\ChatSetting;
use App\Models\EmailTemplate;
use App\Services\Concerns\BuildsEmailVariables;
use App\Support\ChatMessagePayload;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Emails the customer a copy of their chat when it is closed.
 *
 * The sibling of OrderEmailService and InvoiceEmailService, and built the same
 * way: an admin-managed `email_templates` row rendered through
 * BuildsEmailVariables, dispatched on the `emails` queue by SendEmail. Nothing
 * about the delivery path is new — which matters, because the only queue this
 * application's scheduler actually drains is `emails,default`, and a transcript
 * mailer that invented its own queue would pile up silently forever.
 *
 * Three things the transcript deliberately does NOT contain:
 *
 *   operator names       replaced with ChatMessagePayload::OPERATOR_LABEL, the
 *                        same "Support" the widget showed live. A transcript
 *                        that names the agent leaks an identity the chat spent
 *                        a dedicated payload method hiding.
 *   thread replies       the widget only ever renders top-level messages
 *                        (`parent_id` is null), so staff side-threads are
 *                        conversations the customer never saw. Emailing them
 *                        now would be a disclosure, not a record.
 *   deleted messages     a retracted message is retracted. The soft-delete
 *                        scope excludes them and this class does not reach
 *                        past it.
 *
 * send() returns whether the mail was queued, and skips quietly (with a log
 * line) when the feature is off, the conversation has no email to send to, the
 * template is missing or inactive, or there is nothing to send.
 */
class ChatTranscriptEmailService
{
    use BuildsEmailVariables;

    public const TEMPLATE = 'chat_transcript';

    public function send(ChatConversation $conversation, string $templateName = self::TEMPLATE): bool
    {
        if (! $conversation->isCustomerInbox()) {
            // A staff channel has no customer to send to, and every participant
            // can already read it.
            return false;
        }

        if (! ChatSetting::current()->send_transcript_on_close) {
            return false;
        }

        $email = $conversation->customer?->user?->email ?: $conversation->guest_email;

        if (! $email) {
            Log::info('Chat transcript skipped: no recipient address.', ['conversation_id' => $conversation->id]);

            return false;
        }

        $messages = $this->transcriptMessages($conversation);

        if ($messages->isEmpty()) {
            Log::info('Chat transcript skipped: nothing to send.', ['conversation_id' => $conversation->id]);

            return false;
        }

        $template = EmailTemplate::query()
            ->where('name', $templateName)
            ->where('status', 'active')
            ->first();

        if ($template === null) {
            Log::info('Chat transcript skipped: template not found.', [
                'conversation_id' => $conversation->id,
                'template' => $templateName,
            ]);

            return false;
        }

        [$subject, $body] = $this->renderTemplate($template, $this->buildVariables($conversation, $messages));

        $body = $this->stripAvailableVariablesFooter($body);
        $subject = $this->stripAvailableVariablesFooter($subject);

        $htmlBody = null;
        $plainBody = $body;

        if ($this->isHtml($template->body)) {
            $htmlBody = $body;
            $plainBody = $this->toPlainText($body);
        }

        SendEmail::dispatch($email, $subject, $plainBody, null, [], [], [], $htmlBody);

        return true;
    }

    /**
     * The messages the customer actually saw, oldest first.
     *
     * @return Collection<int, ChatConversationMessage>
     */
    private function transcriptMessages(ChatConversation $conversation): Collection
    {
        return $conversation->messages()
            ->whereNull('parent_id')
            ->with('user')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, ChatConversationMessage>  $messages
     * @return array<string, string>
     */
    public function buildVariables(ChatConversation $conversation, Collection $messages): array
    {
        $branding = $this->brandingVariables();

        $customerName = $conversation->customer?->full_name
            ?: ($conversation->guest_name ?: 'there');

        $started = $messages->first()?->created_at ?? $conversation->created_at;
        $closed = $conversation->closed_at ?? now();

        return $branding + [
            'name' => $customerName,
            'customer_name' => $customerName,
            'client_name' => $customerName,

            'chat_id' => (string) $conversation->id,
            'chat_department' => (string) ($conversation->department ?? ''),
            'chat_date' => $started?->format('M j, Y') ?? now()->format('M j, Y'),
            'chat_started_at' => $started?->format('M j, Y H:i') ?? '',
            'chat_closed_at' => $closed->format('M j, Y H:i'),
            'chat_message_count' => (string) $messages->count(),

            'transcript_html' => $this->transcriptHtml($conversation, $messages),
            'transcript_text' => $this->transcriptText($conversation, $messages),
        ];
    }

    /**
     * The transcript as a table, one row per message.
     *
     * Built here rather than from a Blade view because it is substituted into
     * an admin-editable template as a `{{transcript_html}}` placeholder, and
     * every value in it is escaped on the way in — the message body is customer
     * input, and this is the one email in the app that quotes it back.
     *
     * @param  Collection<int, ChatConversationMessage>  $messages
     */
    private function transcriptHtml(ChatConversation $conversation, Collection $messages): string
    {
        $rows = $messages->map(function (ChatConversationMessage $message) use ($conversation): string {
            $author = $this->authorLabel($conversation, $message);
            $time = $message->created_at?->format('H:i') ?? '';

            return '<tr><td style="padding:10px 0;border-bottom:1px solid #f1f5f9;">'
                .'<div style="font-size:12px;color:#64748b;margin-bottom:2px;">'
                .e($author).' &middot; '.e($time)
                .'</div>'
                .'<div style="font-size:14px;color:#0f172a;line-height:1.5;white-space:pre-wrap;">'
                .e($message->visibleBody())
                .'</div></td></tr>';
        })->implode('');

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'.$rows.'</table>';
    }

    /**
     * @param  Collection<int, ChatConversationMessage>  $messages
     */
    private function transcriptText(ChatConversation $conversation, Collection $messages): string
    {
        return $messages
            ->map(fn (ChatConversationMessage $message): string => sprintf(
                '[%s] %s: %s',
                $message->created_at?->format('H:i') ?? '',
                $this->authorLabel($conversation, $message),
                $message->visibleBody(),
            ))
            ->implode("\n");
    }

    /**
     * Who said it, as the customer should read it.
     *
     * Staff become "Support" — ChatMessagePayload::OPERATOR_LABEL, referenced
     * rather than repeated so the transcript and the live widget cannot drift.
     *
     * The customer's own messages keep their own name, and identifying them
     * takes TWO tests. A guest has no `user_id` at all, but a SIGNED-IN
     * customer posts under their own user id, so "has a user id" is not the
     * same as "is staff". Labelling on `user_id !== null` alone would print
     * "Support" over the customer's own words in their own transcript.
     */
    private function authorLabel(ChatConversation $conversation, ChatConversationMessage $message): string
    {
        if ($message->user_id === null) {
            return $message->authorName();
        }

        $customerUserId = $conversation->customer?->user_id;

        if ($customerUserId !== null && (int) $message->user_id === (int) $customerUserId) {
            return $message->authorName();
        }

        return ChatMessagePayload::OPERATOR_LABEL;
    }
}
