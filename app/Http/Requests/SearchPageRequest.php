<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the full grouped results page.
 *
 * Mirrors the typeahead contract (`SearchTypeaheadRequest`): a short or
 * missing query is NOT a validation error — the controller keeps the
 * historical 200 "short query" page — so `q` stays `nullable` here and the
 * only rejection is the upper bound. The `string` rule also rejects an array
 * `q` (e.g. `?q[]=a`) before it can reach the controller's string cast, so a
 * malformed query can never 500: a browser-style request is redirected back
 * with the error flashed, a JSON request gets 422.
 */
class SearchPageRequest extends FormRequest
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
