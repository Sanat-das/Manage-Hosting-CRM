<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Models\ChatOfficeHour;
use App\Services\TicketService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The chat settings form: the toggles plus the seven weekday windows.
 *
 * A plain FormRequest, not ChatFormRequest — this is an ordinary admin page
 * that posts an ordinary form and needs redirect-back-with-errors, not a JSON
 * 422.
 *
 * The times are validated as `H:i` strings and nothing more. In particular
 * `closes_at` is deliberately NOT required to be after `opens_at`: a window
 * that ends at or before it starts is how an overnight shift is expressed
 * (22:00-02:00), and ChatOfficeHours reads it that way. An `after:` rule here
 * would make a night-shift schedule unenterable.
 *
 * The checkboxes are validated `sometimes` and read with `boolean()` in the
 * controller, because an unchecked checkbox is not posted at all — validating
 * them as `required` would make "office hours off" a validation error.
 */
class UpdateChatSettingsRequest extends FormRequest
{
    /** Long enough to explain, short enough to fit in the widget. */
    public const MAX_CLOSED_MESSAGE = 500;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enforce_office_hours' => ['sometimes', 'boolean'],
            'require_available_operator' => ['sometimes', 'boolean'],
            'offline_form_enabled' => ['sometimes', 'boolean'],
            'send_transcript_on_close' => ['sometimes', 'boolean'],

            // Blank is allowed and means "the application's timezone"; anything
            // else has to be a real identifier or every comparison against it
            // throws at read time, in the widget, on a page that has nothing to
            // do with chat settings.
            'timezone' => ['nullable', 'string', Rule::in(timezone_identifiers_list())],

            'closed_message' => ['nullable', 'string', 'max:'.self::MAX_CLOSED_MESSAGE],
            'offline_ticket_department' => ['nullable', 'string', Rule::in(array_keys(TicketService::departments()))],

            'days' => ['required', 'array', 'size:7'],
            'days.*.is_open' => ['sometimes', 'boolean'],
            'days.*.opens_at' => ['required', 'date_format:H:i'],
            'days.*.closes_at' => ['required', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'timezone.in' => 'That is not a recognised timezone.',
            'days.size' => 'All seven days have to be submitted together.',
            'days.*.opens_at.date_format' => 'Opening times use 24-hour HH:MM.',
            'days.*.closes_at.date_format' => 'Closing times use 24-hour HH:MM.',
            'offline_ticket_department.in' => 'That is not one of the current departments.',
        ];
    }

    /**
     * The seven windows, keyed by day, in the shape the model expects.
     *
     * Only the days the form actually offered are returned, so a hand-crafted
     * post cannot invent day 9 — the keys are intersected with the real week.
     *
     * @return array<int, array{is_open: bool, opens_at: string, closes_at: string}>
     */
    public function week(): array
    {
        $submitted = (array) $this->input('days', []);
        $week = [];

        foreach (array_keys(ChatOfficeHour::DAY_NAMES) as $day) {
            if (! isset($submitted[$day]) || ! is_array($submitted[$day])) {
                continue;
            }

            $week[$day] = [
                'is_open' => $this->boolean("days.{$day}.is_open"),
                'opens_at' => (string) ($submitted[$day]['opens_at'] ?? '09:00').':00',
                'closes_at' => (string) ($submitted[$day]['closes_at'] ?? '18:00').':00',
            ];
        }

        return $week;
    }
}
