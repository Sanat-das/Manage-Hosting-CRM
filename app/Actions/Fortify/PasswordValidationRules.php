<?php

namespace App\Actions\Fortify;

use App\Support\AppSettings;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, Rule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        // Minimum length comes from the `password_min_length` setting (default 8).
        try {
            $minLength = max(1, (int) (AppSettings::get('password_min_length', '8') ?? '8'));
        } catch (\Throwable) {
            $minLength = 8;
        }

        // Strong policy adds complexity + breach checks on top of the configured min.
        try {
            if (AppSettings::bool('security_strong_password_enabled', true)) {
                return [
                    'required',
                    'string',
                    Password::min($minLength)
                        ->letters()
                        ->mixedCase()
                        ->numbers()
                        ->symbols()
                        ->uncompromised(),
                    'confirmed',
                ];
            }
        } catch (\Throwable) {
            // Fall through to plain min-length default when settings not yet available.
        }

        return [
            'required',
            'string',
            Password::min($minLength),
            'confirmed',
        ];
    }
}
