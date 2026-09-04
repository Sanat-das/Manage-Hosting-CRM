<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_without_token_returns_unauthorized(): void
    {
        $response = $this->getJson('/api/customers');
        $response->assertStatus(401);
    }

    public function test_api_with_valid_token_returns_data(): void
    {
        $user = $this->staffUser();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/customers');

        $response->assertStatus(200);
    }

    public function test_api_customer_store_validates(): void
    {
        $user = $this->staffUser();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/customers', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email', 'first_name', 'last_name', 'password']);
    }

    public function test_api_customer_store_creates_customer(): void
    {
        $user = $this->staffUser();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/customers', [
            'email' => 'new@test.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'password' => 'Password123',
        ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['email' => 'new@test.com']);
    }

    /**
     * Previously asserted the opposite ("API routes use auth:sanctum only — no
     * role guard on customer endpoints") and so locked the vulnerability in as
     * expected behaviour. A token proves identity, not entitlement: a client
     * token must not read the whole customer book.
     */
    public function test_api_client_cannot_list_customers(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $user->assignRole('client');
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/customers');

        $response->assertStatus(403);
    }

    /**
     * `role` is the column HasRoles::hasRole() consults first. assignRole()
     * alone is a no-op under RefreshDatabase, which leaves adminlte_roles empty.
     */
    private function staffUser(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->assignRole('admin');

        return $user;
    }
}
