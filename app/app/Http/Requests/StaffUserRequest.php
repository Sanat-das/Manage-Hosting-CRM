<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared validation for staff accounts (admin web + Sanctum API).
 *
 * Create rules: email unique, password min 8 (confirmed, complexity policy
 * matching the customer pilot). Update rules make every field optional so the
 * same request powers both the web edit form and partial API updates; the
 * password is never changed through update (use resetPassword instead).
 */
class StaffUserRequest extends FormRequest
{
    /**
     * Authorization is enforced by route middleware (`permission:users.*`),
     * so the request itself only shapes the validation rules.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('post');

        $rules = [
            'first_name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:100'],
            'last_name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:100'],
            'email' => [
                $isCreate ? 'required' : 'sometimes',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->route('user')?->id),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            // `client` is deliberately excluded: client accounts are managed
            // through the dedicated customer module (admin.customers.*).
            'role' => [$isCreate ? 'required' : 'sometimes', Rule::in(['admin', 'staff', 'support', 'sales', 'marketing'])],
            'status' => [$isCreate ? 'required' : 'sometimes', Rule::in(['active', 'inactive', 'suspended'])],
        ];

        $rules['password'] = $isCreate
            ? ['required', 'string', 'min:8', 'confirmed', 'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/']
            : ['sometimes', 'nullable', 'string', 'min:8', 'confirmed', 'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/'];

        return $rules;
    }
}
