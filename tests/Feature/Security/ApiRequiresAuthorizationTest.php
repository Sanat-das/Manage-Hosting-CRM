<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regression guard for the Sanctum API authorization gap.
 *
 * `auth:sanctum` proves *who* the caller is, not *what* they may do. A token is
 * issued to a user, so without a `permission:` gate any token holder — including
 * a client-portal customer — reached staff-only endpoints. The worst case was
 * `POST /api/users` with `role=admin`, which returned 201 and minted a working
 * administrator.
 *
 * The `/api/tickets*` and `/api/ticket-departments*` routes are deliberately
 * exempt: they carry GateTicketApiVisibility, which allows client tokens by
 * design and scopes them to their own customer_id inside the controller.
 */
class ApiRequiresAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function clientToken(): string
    {
        $client = User::create([
            'first_name' => 'Cli',
            'last_name' => 'Ent',
            'email' => 'authz-client@example.test',
            'password_hash' => bcrypt('Password123'),
            'role' => 'client',
            'status' => 'active',
        ]);

        return $client->createToken('regression')->plainTextToken;
    }

    private function asClient(): self
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->clientToken(),
            'Accept' => 'application/json',
        ]);
    }

    public function test_client_token_cannot_create_an_administrator(): void
    {
        $response = $this->asClient()->postJson('/api/users', [
            'first_name' => 'Evil',
            'last_name' => 'Admin',
            'email' => 'authz-evil-admin@example.test',
            'role' => 'admin',
            'status' => 'active',
            'phone' => '',
            'company' => '',
            'address' => '',
            'password' => 'Passw0rdXyz',
            'password_confirmation' => 'Passw0rdXyz',
        ]);

        $response->assertForbidden();

        $this->assertNull(
            User::where('email', 'authz-evil-admin@example.test')->first(),
            'A client token created an administrator account via POST /api/users.'
        );
    }

    /**
     * The optional `nullable` fields may be absent entirely. Before the fix the
     * controller read them with `?:` and 500'd, which masked the authorization
     * hole during testing: the request looked "blocked" when it had in fact
     * already cleared auth and validation.
     */
    public function test_missing_optional_fields_do_not_500(): void
    {
        $this->asClient()->postJson('/api/users', [
            'first_name' => 'No',
            'last_name' => 'Optionals',
            'email' => 'authz-no-optionals@example.test',
            'role' => 'staff',
            'status' => 'active',
            'password' => 'Passw0rdXyz',
            'password_confirmation' => 'Passw0rdXyz',
        ])->assertForbidden();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function staffOnlyEndpoints(): iterable
    {
        yield 'list users' => ['getJson', '/api/users'];
        yield 'list customers' => ['getJson', '/api/customers'];
        yield 'list hosting' => ['getJson', '/api/hosting'];
        yield 'list orders' => ['getJson', '/api/orders'];
        yield 'list products' => ['getJson', '/api/products'];
        yield 'list ssl' => ['getJson', '/api/ssl'];
        yield 'list kb' => ['getJson', '/api/kb'];
    }

    #[DataProvider('staffOnlyEndpoints')]
    public function test_client_token_is_forbidden_from_staff_endpoints(string $method, string $uri): void
    {
        $this->asClient()->{$method}($uri)->assertForbidden();
    }

    /**
     * Structural invariant: every authenticated `/api/*` route must carry an
     * authorization gate, not just `auth:sanctum`. This is what actually stops
     * the class of bug recurring when a new API module is added.
     */
    public function test_every_authenticated_api_route_has_an_authorization_gate(): void
    {
        $ungated = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            $isAuthenticated = (bool) array_filter(
                $middleware,
                fn ($m) => is_string($m) && str_starts_with($m, 'auth:sanctum')
            );

            if (! $isAuthenticated) {
                continue;
            }

            $hasGate = (bool) array_filter($middleware, fn ($m) => is_string($m) && (
                str_starts_with($m, 'permission:')
                || str_starts_with($m, 'role:')
                || $m === \App\Http\Middleware\AdminMiddleware::class
                || $m === \App\Http\Middleware\GateTicketApiVisibility::class
            ));

            if (! $hasGate) {
                $ungated[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertSame(
            [],
            $ungated,
            "These authenticated /api/* routes have no authorization gate:\n".implode("\n", $ungated)
        );
    }
}
