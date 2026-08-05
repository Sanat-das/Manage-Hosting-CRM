<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_loads(): void
    {
        $response = $this->get('/login');
        $response->assertStatus(200);
    }

    public function test_unauthenticated_client_redirected_to_client_login(): void
    {
        // Guests hitting non-admin pages are sent to the client login, which
        // is the home page ('/') — see redirectGuestsTo in bootstrap/app.php.
        $response = $this->get('/client');
        $response->assertRedirect(route('client.login'));
    }

    public function test_unauthenticated_user_redirected_to_admin_login(): void
    {
        // Guests hitting admin pages are sent to '/admin', which itself
        // redirects to the shared Fortify login page ('/login').
        $response = $this->get('/admin/dashboard');
        $response->assertRedirect(route('admin.login'));
    }

    public function test_admin_dashboard_requires_auth(): void
    {
        $response = $this->get('/admin/dashboard');
        $response->assertRedirect();
    }

    public function test_admin_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@test.com',
            'password_hash' => bcrypt('password'),
        ]);
        $user->assignRole('admin');

        $response = $this->post('/login', [
            'email' => 'admin@test.com',
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_admin_login_with_invalid_credentials(): void
    {
        $response = $this->post('/login', [
            'email' => 'nonexistent@test.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertInvalid(['email']);
    }

    public function test_logout(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $response = $this->actingAs($user)->post('/logout');
        $response->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_login_rehashes_password_hash_column_not_password(): void
    {
        // Regression: the reference schema stores the hash in `password_hash`,
        // not `password`. Laravel's rehash-on-login path writes through
        // User::getAuthPasswordName(); before that override existed, a login
        // with an outdated-cost hash tried to write a `password` column that
        // does not exist and threw a QueryException. The imported reference
        // users (cost-10 hashes) all hit this on first login.
        $user = User::factory()->create([
            'email' => 'legacy@test.com',
            // cost-10 bcrypt for "password" (the reference seed hash)
            'password_hash' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);
        $user->assignRole('admin');

        $this->post('/login', [
            'email' => 'legacy@test.com',
            'password' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);

        $user->refresh();
        $this->assertSame('password_hash', (new User)->getAuthPasswordName());
        $this->assertNotSame('$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', $user->password_hash, 'stale hash should have been replaced via password_hash column');
        $this->assertFalse(Hash::needsRehash($user->password_hash), 'hash should have been upgraded to the configured cost (BCRYPT_ROUNDS in test env)');
        $this->assertTrue(Hash::check('password', $user->password_hash));
    }
}
