<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        // Normalize split phone fields from new flag dropdown
        if (isset($input['phone_code']) || isset($input['phone_number'])) {
            $code = trim((string) ($input['phone_code'] ?? ''));
            $number = trim((string) ($input['phone_number'] ?? ''));
            if ($code === '' && $number !== '') $code = '+91';
            $input['phone'] = $number !== '' ? trim($code.' '.$number) : ($code !== '' ? $code : ($input['phone'] ?? null));
        }
        Validator::make($input, [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
            'phone' => ['nullable', 'string', 'max:50'],
            'phone_code' => ['nullable', 'string', 'max:10'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'company' => ['nullable', 'string', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
        ])->validate();

        $legacy = collect([$input['address_line1'] ?? null, $input['address_line2'] ?? null, $input['city'] ?? null, $input['state'] ?? null, $input['postcode'] ?? null, $input['country'] ?? null])->filter()->implode(', ');
        if ($legacy === '') {
            $legacy = $input['address'] ?? null;
        }

        return User::create([
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'],
            'email' => $input['email'],
            'password_hash' => Hash::make($input['password']),
            'role' => 'client',
            'status' => 'active',
            'phone' => $input['phone'] ?? null,
            'company' => $input['company'] ?? null,
            'address' => $legacy,
            'address_line1' => $input['address_line1'] ?? null,
            'address_line2' => $input['address_line2'] ?? null,
            'city' => $input['city'] ?? null,
            'state' => $input['state'] ?? null,
            'postcode' => $input['postcode'] ?? null,
            'country' => $input['country'] ?? null,
        ]);
    }
}
