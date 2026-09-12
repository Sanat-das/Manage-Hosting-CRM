<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Models\ChatConversation;
use Closure;
use Illuminate\Support\Str;

/**
 * Creating a channel.
 *
 * `type` is deliberately absent: the conversation type is decided by the
 * endpoint, never by the request. Accepting it would let a caller post a
 * customer_inbox into the staff channel list.
 */
class StoreChatChannelRequest extends ChatFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9][A-Za-z0-9 _\-]*$/',
                $this->slugIsFree(...),
            ],
            'is_private' => ['sometimes', 'boolean'],
            'department' => ['nullable', 'string', 'max:100'],
            'topic' => ['nullable', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Refuse a name whose slug is already taken.
     *
     * The slug — not the display name — is the channel's identifier, so
     * "Deploy Notices", "deploy notices" and "Deploy-Notices" are the same
     * channel as far as a URL is concerned. ChatService::uniqueSlug() would
     * otherwise quietly hand the second one `deploy-notices-2`, leaving two
     * rooms that read identically in the sidebar and cannot be told apart.
     *
     * The service keeps its auto-suffixing on purpose: a seeder or console
     * command re-running must not explode on a name it created last time. This
     * rule is the interactive path, where a person can be told instead.
     */
    private function slugIsFree(string $attribute, mixed $value, Closure $fail): void
    {
        $slug = Str::slug(trim((string) $value));

        if ($slug === '') {
            return;
        }

        if (ChatConversation::where('slug', $slug)->exists()) {
            $fail('A channel with that name already exists.');
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'A channel name starts with a letter or number and may contain spaces, hyphens and underscores.',
        ];
    }
}
