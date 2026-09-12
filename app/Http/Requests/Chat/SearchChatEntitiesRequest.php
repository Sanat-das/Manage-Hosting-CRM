<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Services\ChatEntitySearch;
use Illuminate\Validation\Rule;

/**
 * Typeahead query for the composer's attach picker.
 *
 * The two-character minimum is not cosmetic: a single character matches most of
 * the table through a leading-wildcard LIKE, which is a full scan per type on
 * every keystroke.
 */
class SearchChatEntitiesRequest extends ChatFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'type' => ['nullable', 'string', Rule::in(array_keys(ChatEntitySearch::PERMISSIONS))],
        ];
    }
}
