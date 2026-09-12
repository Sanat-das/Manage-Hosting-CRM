<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

/**
 * Message search over the conversations the caller may read.
 *
 * The two-character minimum is the same rule the entity typeahead uses, and for
 * the same reason: the query behind this is a leading-wildcard LIKE, so a
 * single character matches most of the table and costs a full scan. `max:100`
 * bounds the other end — a 10k-character pattern is not a search, it is a way
 * to make the database do arbitrary work per request.
 *
 * `channel` is deliberately NOT an `exists:` rule. A 422 for an id that does
 * not exist, against an empty 200 for one that exists but is not the caller's,
 * is enough to enumerate every conversation in the install. Both answers must
 * be the same empty result set, so the filter is applied inside the already
 * authorised scope instead of being validated against the whole table.
 */
class SearchChatMessagesRequest extends ChatFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'channel' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            // `page` is intentionally unvalidated: Laravel's paginator already
            // treats anything that is not an integer >= 1 as page 1, and a 422
            // on `?page=-3` would be a worse answer than the first page.
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.min' => 'Search for at least two characters.',
            'to.after_or_equal' => 'The end of the date range cannot be before its start.',
        ];
    }
}
