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
        $user = User::factory()->create();
        $user->assignRole('admin');
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/customers');

        $response->assertStatus(200);
    }

    public function test_api_customer_store_validates(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/customers', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email', 'first_name', 'last_name', 'password']);
    }

    public function test_api_customer_store_creates_customer(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
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

    public function test_api_client_can_list_customers(): void
    {
        // API routes use auth:sanctum only — no role guard on customer endpoints
        $user = User::factory()->create();
        $user->assignRole('client');
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/customers');

        $response->assertStatus(200);
    }
}
