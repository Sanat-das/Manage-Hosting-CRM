<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Base for the chat's request objects.
 *
 * Every chat endpoint is called by XHR and answers JSON, so a validation
 * failure has to answer JSON too. It would not by default: bootstrap/app.php
 * narrows `shouldRenderJsonWhen()` to `api/*` paths, which means a failed
 * validation on an /admin route redirects back with a flashed error bag even
 * when the caller asked for JSON — leaving the composer with a 302 it cannot
 * interpret and no message to show.
 *
 * Overriding it here rather than widening the global rule keeps the blast
 * radius to the chat: every other admin form still gets the redirect-with-errors
 * behaviour it was written against.
 *
 * Authorisation is deliberately not done in these requests. The route's
 * `permission:` middleware decides whether you may use the chat at all, and
 * ChatConversationPolicy — which needs the resolved conversation — decides the
 * rest in the controller.
 */
abstract class ChatFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The given data was invalid.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
