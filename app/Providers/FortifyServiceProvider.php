<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use App\Support\AppSettings;
use App\Support\MathCaptcha;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            LoginResponse::class,
            \App\Http\Responses\LoginResponse::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Global password defaults — deferred check so it runs at validation time (after DB migrated).
        // Wrapped in try/catch for early boot when settings table not yet created (RefreshDatabase).
        Password::defaults(function () {
            try {
                if (AppSettings::bool('security_strong_password_enabled', true)) {
                    return Password::min(12)
                        ->letters()
                        ->mixedCase()
                        ->numbers()
                        ->symbols()
                        ->uncompromised();
                }
            } catch (\Throwable) {
                // Fall through to weak default when settings not yet available.
            }

            return Password::min(8);
        });

        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        // Render Fortify views with the AdminLTE auth layout.
        Fortify::loginView(fn () => view('adminlte::auth.login'));
        Fortify::registerView(fn () => view('auth.register'));
        Fortify::requestPasswordResetLinkView(fn () => view('adminlte::auth.passwords.email'));
        Fortify::resetPasswordView(fn (Request $request) => view('adminlte::auth.passwords.reset', [
            'token' => $request->route('token'),
            'email' => $request->email,
        ]));
        Fortify::confirmPasswordView(fn () => view('adminlte::auth.confirm-password'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));

        // Login brute-force protection — 5 attempts/min per email|IP (60 in dusk
        // to avoid throttling the test suite's fixture logins). Limiter is active;
        // Fortify clears it on successful authentication automatically.
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            // Dusk re-runs the fixture logins (same email + IP) many times per
            // minute; a strict 5/min cap would throttle the suite's own logins.
            $limit = app()->environment('dusk') ? 60 : 5;

            return Limit::perMinute($limit)->by($throttleKey)->response(fn () => response('Too many attempts', 429));
        });

        // Two-factor challenge brute-force protection — 5 attempts/min per pending login.
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        // Math captcha verification for login — gated by security_math_captcha_enabled toggle (default: disabled).
        // Uses authenticateUsing to intercept login before credential check; respects 2FA pipeline
        // since Fortify still handles two-factor challenges after successful authentication.
        Fortify::authenticateUsing(function (Request $request) {
            if (AppSettings::bool('security_math_captcha_enabled', false)) {
                if (! MathCaptcha::verify($request, $request->input('math_captcha'))) {
                    throw ValidationException::withMessages(['math_captcha' => 'Incorrect captcha answer.']);
                }
            }

            $username = $request->input(Fortify::username());
            $password = $request->input('password');

            // Case-insensitive email lookup to match typical login UX.
            $user = null;
            if (is_string($username) && $username !== '') {
                $user = User::whereRaw('LOWER(email) = ?', [Str::lower($username)])->first();
            }

            if ($user && Hash::check((string) $password, $user->getAuthPassword())) {
                // Rehash if needed (e.g. legacy cost-10 hash upgraded to current BCRYPT_ROUNDS).
                // Mirrors EloquentUserProvider::rehashPasswordIfRequired but via password_hash column.
                if (Hash::needsRehash($user->getAuthPassword())) {
                    $user->forceFill([
                        $user->getAuthPasswordName() => Hash::make((string) $password),
                    ])->save();
                }

                return $user;
            }

            return null;
        });
    }
}
