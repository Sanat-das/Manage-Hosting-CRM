<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Models\ChatCannedReply;
use App\Services\ChatService;
use App\Services\TicketService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating or editing a saved reply.
 *
 * A plain FormRequest, NOT ChatFormRequest: the canned-reply screens are
 * ordinary admin pages that post ordinary forms, so a validation failure has to
 * redirect back with the error bag. ChatFormRequest exists to force JSON on the
 * XHR endpoints and would leave this form with a 422 the browser renders as a
 * blank page.
 *
 * Authorisation stays in the controller. Whether you may write a SHARED reply
 * depends on chat.manage, and whether you may edit an existing one depends on
 * whose it is — neither is a fact about the request body.
 *
 * `shortcut` is where the real validation is. It is what an operator types
 * (`/refund`), so it has to be unique within the scope it will be looked up in,
 * and the table cannot enforce that: MySQL treats NULLs as distinct, so a
 * unique index on (user_id, shortcut) enforces nothing for the shared replies
 * whose user_id is NULL. The closure below is therefore the only thing standing
 * between two `/refund`s and a picker that silently resolves to whichever the
 * database happened to return first.
 */
class StoreCannedReplyRequest extends FormRequest
{
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
            'title' => ['required', 'string', 'max:120'],

            // Letters, numbers, dash and underscore. No spaces and no slash:
            // the operator types "/" to open the picker and the shortcut is
            // what follows it, so a shortcut containing either could never be
            // typed back.
            'shortcut' => [
                'nullable',
                'string',
                'max:64',
                'regex:/^[A-Za-z0-9_-]+$/',
                $this->uniqueWithinScope(),
            ],

            'body' => ['required', 'string', 'max:'.ChatService::MAX_BODY_LENGTH],

            // Same vocabulary as a ticket department, validated against the
            // ones that exist rather than stored blind.
            'department' => ['nullable', 'string', Rule::in(array_keys(TicketService::departments()))],

            'scope' => ['required', 'string', 'in:shared,personal'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shortcut.regex' => 'A shortcut can contain letters, numbers, dashes and underscores only.',
            'department.in' => 'That is not one of the current departments.',
            'scope.in' => 'A reply is either shared with everyone or personal to you.',
        ];
    }

    /**
     * No two replies in the same scope may answer to the same shortcut.
     *
     * "Same scope" is the shared library, or one person's own set — a personal
     * `/refund` alongside the shared `/refund` is allowed and resolves to the
     * personal one, which is the point of having personal replies at all.
     */
    private function uniqueWithinScope(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            $query = ChatCannedReply::query()->where('shortcut', trim($value));

            if ($this->input('scope') === 'personal') {
                $query->where('user_id', $this->user()?->id);
            } else {
                $query->whereNull('user_id');
            }

            // On an edit, the row being edited is not a clash with itself.
            $current = $this->route('cannedReply');

            if ($current instanceof ChatCannedReply) {
                $query->whereKeyNot($current->getKey());
            }

            if ($query->exists()) {
                $fail("The shortcut /{$value} is already in use.");
            }
        };
    }
}
