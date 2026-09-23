<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the JSON typeahead endpoint.
 *
 * A short query is NOT a validation error: the controller answers queries
 * below the 2-character minimum with a 200 empty envelope, so `q` stays
 * `nullable` here and the only rejection is the upper bound (a query longer
 * than 100 characters returns 422).
 */
class SearchTypeaheadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('search') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }
}
