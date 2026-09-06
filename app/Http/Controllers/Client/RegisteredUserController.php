<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Support\AppSettings;
use App\Support\MathCaptcha;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Handles self-registration for client accounts.
 * Replaces Fortify's built-in registration route so the
 * redirect can be role-aware and gated by the admin setting.
 */
class RegisteredUserController extends Controller
{
    public function store(Request $request, CreatesNewUsers $creator): RedirectResponse
    {
        // Honeypot bot trap — hidden field 'website' must remain empty.
        // Silent reject: log and return generic error to avoid revealing the trap.
        // Gated by security_honeypot_enabled toggle (default: enabled).
        if (AppSettings::bool('security_honeypot_enabled', true)) {
            if ($request->filled('website')) {
                Log::warning('Bot registration blocked by honeypot', [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'payload' => $request->except(['password', 'password_confirmation']),
                ]);

                return back()->withErrors(['email' => 'Registration failed. Please try again.'])->withInput();
            }
        }

        // Math captcha verification — gated by security_math_captcha_enabled toggle (default: disabled).
        // Validated before main input validation so the captcha error is distinct.
        if (AppSettings::bool('security_math_captcha_enabled', false)) {
            $request->validate([
                'math_captcha' => ['required', 'string'],
            ]);

            if (! MathCaptcha::verify($request, $request->input('math_captcha'))) {
                return back()->withErrors(['math_captcha' => 'Incorrect answer. Please try again.'])->withInput();
            }
        }

        // IP-aware throttling support — defense-in-depth alongside throttle:register middleware.
        $throttleKey = 'register:' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            Log::warning('Registration throttled', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'retry_after' => $seconds,
            ]);

            return back()->withErrors([
                'email' => 'Too many registration attempts. Please try again in ' . $seconds . ' seconds.',
            ])->withInput();
        }

        RateLimiter::hit($throttleKey, 60);

        // Normalize phone from split flag dropdown + number inputs
        if ($request->has('phone_code') || $request->has('phone_number')) {
            $code = trim((string) $request->input('phone_code', ''));
            $number = trim((string) $request->input('phone_number', ''));
            if ($code === '' && $number !== '') $code = '+91';
            $request->merge(['phone' => $number !== '' ? trim($code.' '.$number) : $code]);
        }

        // Password strength gated by security_strong_password_enabled toggle (default: enabled).
        $passwordRule = AppSettings::bool('security_strong_password_enabled', true)
            ? Password::min(12)->letters()->mixedCase()->numbers()->symbols()->uncompromised()
            : Password::min(8);

        // Strict input handling — whitelist only expected fields; honeypot must be empty when enabled.
        $honeypotEnabled = AppSettings::bool('security_honeypot_enabled', true);
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', $passwordRule, 'confirmed'],
            'password_confirmation' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'website' => $honeypotEnabled ? ['nullable', 'max:0'] : ['nullable', 'string', 'max:255'],
            'math_captcha' => ['nullable', 'string'],
        ]);

        try {
            $user = $creator->create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'password_confirmation' => $validated['password_confirmation'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'company' => $validated['company'] ?? null,
                'address_line1' => $validated['address_line1'] ?? null,
                'address_line2' => $validated['address_line2'] ?? null,
                'city' => $validated['city'] ?? null,
                'state' => $validated['state'] ?? null,
                'postcode' => $validated['postcode'] ?? null,
                'country' => $validated['country'] ?? null,
                'address' => $validated['address'] ?? null,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Validation failed inside CreatesNewUsers — keep throttle hit for brute-force accounting.
            throw $e;
        }

        Auth::login($user);

        // Session fixation protection — regenerate ID after privilege elevation.
        $request->session()->regenerate();

        Event::dispatch(new Registered($user));

        Log::info('User registered', [
            'email' => $user->email,
            'user_id' => $user->getAuthIdentifier(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        RateLimiter::clear($throttleKey);

        return redirect()->intended(route('client.dashboard'));
    }
}
