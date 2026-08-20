<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketCustomerPreselectTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $perm = Permission::firstOrCreate(['name' => 'tickets.create'], ['label' => 'Create tickets']);
        $role->permissions()->syncWithoutDetaching($perm->id);
        $user->assignRole('admin');

        return $user;
    }

    private function customer(string $company): Customer
    {
        $user = User::factory()->create();

        return Customer::create([
            'user_id' => $user->id,
            'company' => $company,
            'status' => 'active',
        ]);
    }

    public function test_create_page_preselects_customer_from_query(): void
    {
        $customer = $this->customer('Preselected Corp');

        $this->actingAs($this->adminUser())
            ->get(route('admin.tickets.create', ['customer_id' => $customer->id]))
            ->assertOk()
            ->assertSee("value=\"{$customer->id}\" selected", false);
    }

    public function test_create_page_without_query_has_no_preselection(): void
    {
        $customer = $this->customer('No Preselect Corp');

        $this->actingAs($this->adminUser())
            ->get(route('admin.tickets.create'))
            ->assertOk()
            ->assertDontSee("value=\"{$customer->id}\" selected", false);
    }
}