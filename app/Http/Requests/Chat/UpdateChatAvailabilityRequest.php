<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Models\ChatOperatorAvailability;
use Illuminate\Validation\Rule;

/**
 * An operator setting their own state.
 *
 * `state` is checked against the model's own list rather than a literal
 * `in:available,away,busy`, so adding a state is one edit and not a hunt for
 * the string repeated in a validator.
 *
 * The note is capped short and deliberately never shown to a customer — it is
 * "on a call until 3", read by the other people in the roster.
 */
class UpdateChatAvailabilityRequest extends ChatFormRequest
{
    /** Long enough for "back after the 2pm deploy", short enough not to be a status page. */
    public const MAX_NOTE_LENGTH = 120;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'state' => ['required', 'string', Rule::in(ChatOperatorAvailability::STATES)],
            'note' => ['nullable', 'string', 'max:'.self::MAX_NOTE_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'state.in' => 'Choose Available, Away or Busy.',
        ];
    }
}
