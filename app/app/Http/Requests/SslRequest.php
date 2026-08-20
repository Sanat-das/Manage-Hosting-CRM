<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for SSL certificate create/update (admin panel).
 *
 * Manage actions are gated by the `settings.edit` permission (see
 * routes/admin/ssl.php) — a dedicated `ssl.*` permission set does not exist
 * yet, so `authorize()` mirrors the route-level gate.
 */
class SslRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('settings.edit') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'domain_name' => ['required', 'string', 'max:255'],
            'certificate_type' => ['required', Rule::in(['single', 'wildcard', 'multidomain'])],
            'provider' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'pending', 'expired', 'revoked', 'failed'])],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
