<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        if (isset($input['phone_code']) || isset($input['phone_number'])) {
            $code = trim((string) ($input['phone_code'] ?? ''));
            $number = trim((string) ($input['phone_number'] ?? ''));
            if ($code === '' && $number !== '') $code = '+91';
            $input['phone'] = $number !== '' ? trim($code.' '.$number) : ($code !== '' ? $code : ($input['phone'] ?? null));
        }
        Validator::make($input, [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'],
            'phone_code' => ['nullable', 'string', 'max:10'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'company' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],

            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
        ])->validateWithBag('updateProfileInformation');

        $legacy = collect([$input['address_line1'] ?? null, $input['address_line2'] ?? null, $input['city'] ?? null, $input['state'] ?? null, $input['postcode'] ?? null, $input['country'] ?? null])->filter()->implode(', ');
        $user->forceFill([
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'],
            'phone' => $input['phone'] ?? null,
            'company' => $input['company'] ?? null,
            'address' => $legacy !== '' ? $legacy : ($input['address'] ?? null),
            'address_line1' => $input['address_line1'] ?? null,
            'address_line2' => $input['address_line2'] ?? null,
            'city' => $input['city'] ?? null,
            'state' => $input['state'] ?? null,
            'postcode' => $input['postcode'] ?? null,
            'country' => $input['country'] ?? null,
            'email' => $input['email'],
        ])->save();
    }
}
