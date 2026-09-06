<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T23 — `Api\TicketDepartmentController`. Read-only department directory for
 * client/automation use, gated by `tickets.view`.
 */
class ApiDepartmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TicketService::forgetDepartmentCache();
    }

    public function test_list_enabled_departments_ordered_by_sort_order(): void
    {
        TicketDepartment::query()->update(['sort_order' => 100]);
        TicketDepartment::where('slug', 'billing')->update(['sort_order' => 1]);
        TicketDepartment::where('slug', 'support')->update(['sort_order' => 2]);

        $token = $this->staffToken();

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/ticket-departments');

        $response->assertOk();

        $slugs = collect($response->json('data'))->pluck('slug')->all();

        $this->assertSame('billing', $slugs[0]);
        $this->assertSame('support', $slugs[1]);

        $first = $response->json('data.0');
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('slug', $first);
        $this->assertArrayHasKey('email_address', $first);
        $this->assertArrayHasKey('enabled', $first);
        $this->assertArrayHasKey('sort_order', $first);
        $this->assertArrayHasKey('is_default', $first);
        $this->assertArrayHasKey('description', $first);
        $this->assertArrayNotHasKey('imap_host', $first);
        $this->assertArrayNotHasKey('imap_username', $first);
        $this->assertArrayNotHasKey('imap_password', $first);
    }

    public function test_show_returns_department_by_slug(): void
    {
        $token = $this->staffToken();

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/ticket-departments/support');

        $response->assertOk();
        $this->assertSame('support', $response->json('data.slug'));
        $this->assertArrayNotHasKey('imap_password', $response->json('data'));
    }

    public function test_unknown_slug_returns_404(): void
    {
        $token = $this->staffToken();

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/ticket-departments/not-a-real-department');

        $response->assertStatus(404);
    }

    public function test_disabled_departments_are_not_listed_by_default(): void
    {
        TicketDepartment::where('slug', 'billing')->update(['enabled' => false]);

        $token = $this->staffToken();

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/ticket-departments');

        $response->assertOk();

        $slugs = collect($response->json('data'))->pluck('slug')->all();

        $this->assertNotContains('billing', $slugs);
    }

    private function staffToken(): string
    {
        $user = User::factory()->create(['role' => 'support']);

        $role = Role::firstOrCreate(['name' => 'support'], ['label' => 'Support']);
        $permission = Permission::firstOrCreate(['name' => 'tickets.view'], ['label' => 'tickets.view']);
        $role->permissions()->syncWithoutDetaching($permission->id);

        $user->assignRole('support');

        return $user->createToken('test-token')->plainTextToken;
    }
}
