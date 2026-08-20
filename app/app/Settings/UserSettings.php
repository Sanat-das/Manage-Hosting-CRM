<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * User / account defaults (new T4.2 group: user).
 */
class UserSettings extends Settings
{
    public string $user_default_timezone = 'Asia/Kolkata';

    public bool $user_email_verification = false;

    public bool $user_allow_social_login = false;

    public bool $user_profile_editable = true;

    public bool $user_allow_self_delete = false;

    public int $user_password_expiry_days = 0;

    public int $user_session_timeout_minutes = 120;

    public bool $user_two_factor_enforced = false;

    public int $user_inactive_lock_days = 0;

    public int $user_max_login_attempts = 5;

    public static function group(): string
    {
        return 'user';
    }

    public static function rules(): array
    {
        return [
            'user_default_timezone' => ['nullable', 'string', 'max:64'],
            'user_email_verification' => ['nullable', 'in:1,0,yes,no,true,false'],
            'user_allow_social_login' => ['nullable', 'in:1,0,yes,no,true,false'],
            'user_profile_editable' => ['nullable', 'in:1,0,yes,no,true,false'],
            'user_allow_self_delete' => ['nullable', 'in:1,0,yes,no,true,false'],
            'user_password_expiry_days' => ['nullable', 'integer', 'min:0'],
            'user_session_timeout_minutes' => ['nullable', 'integer', 'min:1'],
            'user_two_factor_enforced' => ['nullable', 'in:1,0,yes,no,true,false'],
            'user_inactive_lock_days' => ['nullable', 'integer', 'min:0'],
            'user_max_login_attempts' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
