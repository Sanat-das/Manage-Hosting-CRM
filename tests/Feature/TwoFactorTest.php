<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Two-Factor Authentication (Fortify) end-to-end flow.
 *
 * The management UI (enable → QR/secret → confirm → recovery codes → disable)
 * on the client profile and admin profile pages relies entirely on these
 * Fortify routes. The `confirmPassword => true` feature gates enable/disable/
 * regenerate behind the password.confirm middleware.
 */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $user = User::factory()->create(['password_hash' => bcrypt('password')]);
        $user->assignRole('admin');

        return $user;
    }

    private function actingAsWithConfirmedPassword(User $user): static
    {
        // Satisfy the password.confirm middleware (confirmPassword => true)
        // by marking the password as confirmed within the timeout window.
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    }

    public function test_two_factor_enable_requires_password_confirmation(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->post('/user/two-factor-authentication')
            ->assertRedirect('/user/confirm-password');

        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_two_factor_enable_confirm_and_disable_flow(): void
    {
        $user = $this->user();

        // Enable (password confirmed).
        $this->actingAsWithConfirmedPassword($user)
            ->post('/user/two-factor-authentication')
            ->assertSessionHas('status');

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret, 'secret should be set after enabling');
        $this->assertNull($user->two_factor_confirmed_at, 'not confirmed until a code is verified');

        // QR code + secret key endpoints (used by the management UI).
        $this->actingAs($user)
            ->getJson('/user/two-factor-qr-code')
            ->assertOk()
            ->assertJsonStructure(['svg']);

        $secret = decrypt($user->two_factor_secret);

        $this->actingAs($user)
            ->getJson('/user/two-factor-secret-key')
            ->assertOk()
            ->assertJson(['secretKey' => $secret]);

        // Recovery codes are issued on enable (8 codes).
        $this->actingAs($user)
            ->getJson('/user/two-factor-recovery-codes')
            ->assertOk()
            ->assertJsonCount(8);

        // Confirm with a valid TOTP code.
        $code = (new Google2FA)->getCurrentOtp($secret);

        $this->actingAs($user)
            ->postJson('/user/confirmed-two-factor-authentication', ['code' => $code])
            ->assertOk();

        $user->refresh();
        $this->assertNotNull($user->two_factor_confirmed_at, '2FA should be confirmed after valid code');

        // Disable (password confirmed again).
        $this->actingAsWithConfirmedPassword($user)
            ->delete('/user/two-factor-authentication')
            ->assertSessionHas('status');

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_two_factor_confirm_rejects_invalid_code(): void
    {
        $user = $this->user();

        $this->actingAsWithConfirmedPassword($user)
            ->post('/user/two-factor-authentication');

        $user->refresh();

        // Fortify throws a ValidationException with the named error bag
        // 'confirmTwoFactorAuthentication'. bootstrap/app.php configures
        // shouldRenderJsonWhen() to only render JSON for api/*, so this web
        // route is rendered as a redirect back with the errors flashed.
        $this->from('/client/profile')
            ->actingAs($user)
            ->post('/user/confirmed-two-factor-authentication', ['code' => '000000'])
            ->assertRedirect('/client/profile')
            ->assertSessionHasErrorsIn('confirmTwoFactorAuthentication', ['code']);

        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_api_is_rate_limited_per_user(): void
    {
        // The `api` limiter (60/min, keyed per user when authenticated) is
        // applied to the whole `api` middleware group via throttleApi() in
        // bootstrap/app.php. Requests must be authenticated: Laravel's
        // middleware priority runs `auth:sanctum` before `throttle:api`, so
        // unauthenticated requests 401 before the limiter is ever consulted.
        $user = $this->user();

        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($user, 'sanctum')->getJson('/api/customers');
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/customers')
            ->assertStatus(429);
    }
}
