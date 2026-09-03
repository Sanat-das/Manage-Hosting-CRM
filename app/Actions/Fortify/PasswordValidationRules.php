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
        // Gated by security_strong_password_enabled toggle (default: enabled).
        // Wrapped for early boot / missing table (testing RefreshDatabase).
        try {
            if (AppSettings::bool('security_strong_password_enabled', true)) {
                return [
                    'required',
                    'string',
                    Password::min(12)
                        ->letters()
                        ->mixedCase()
                        ->numbers()
                        ->symbols()
                        ->uncompromised(),
                    'confirmed',
                ];
            }
        } catch (\Throwable) {
            // Fall through to weak default when settings not yet available.
        }

        return [
            'required',
            'string',
            Password::min(8),
            'confirmed',
        ];
    }
}
