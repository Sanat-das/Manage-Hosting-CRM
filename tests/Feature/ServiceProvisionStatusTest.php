<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `service_instances.provision_status` was referenced by the service screen
 * (badge, select, index column, Retry-button gate) and by the list filter, but
 * the column did not exist and the model did not declare it fillable. The
 * select therefore saved nothing while reporting success, and filtering by it
 * errored. These tests pin the column, its persistence, and the filter.
 */
final class ServiceProvisionStatusTest extends TestCase
{
    use RefreshDatabase;

    private function adminWith(array $perms): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $ids = [];
        foreach ($perms as $name) {
            $ids[] = Permission::firstOrCreate(['name' => $name], ['label' => $name])->id;
        }
        $role->permissions()->sync($ids);
        $user->assignRole('admin');

        return $user;
    }

    private function service(array $overrides = []): ServiceInstance
    {
        $customer = Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);

        return ServiceInstance::create(array_merge([
            'customer_id' => $customer->id,
            'service_tag' => 'SVC-'.random_int(100000, 999999),
            'username' => 'ord'.random_int(1, 999),
            'provisioning_method' => 'proxmox',
            'status' => 'pending',
        ], $overrides));
    }

    public function test_the_column_exists_and_is_fillable(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('service_instances', 'provision_status'),
            'The provision_status column must exist — the service screen and list filter depend on it.',
        );

        $this->assertContains('provision_status', (new ServiceInstance)->getFillable());
    }

    /**
     * The regression that mattered: the form reported success and stored nothing.
     */
    public function test_updating_provision_status_actually_persists(): void
    {
        $service = $this->service();

        $this->actingAs($this->adminWith(['service-instances.manage']))
            ->put(route('admin.service-instances.update', $service), [
                'status' => 'active',
                'provision_status' => 'provisioned',
            ])
            ->assertRedirect(route('admin.service-instances.show', $service))
            ->assertSessionHas('success');

        $fresh = $service->fresh();
        $this->assertSame('provisioned', $fresh->provision_status);
        $this->assertSame('active', $fresh->status);
    }

    public function test_an_invalid_provision_status_is_rejected(): void
    {
        $service = $this->service();

        $this->actingAs($this->adminWith(['service-instances.manage']))
            ->put(route('admin.service-instances.update', $service), [
                'provision_status' => 'nonsense',
            ])
            ->assertSessionHasErrors('provision_status');

        $this->assertNull($service->fresh()->provision_status);
    }

    public function test_the_provision_status_constant_covers_what_the_controller_accepts(): void
    {
        $service = $this->service();
        $admin = $this->adminWith(['service-instances.manage']);

        foreach (ServiceInstance::PROVISION_STATUSES as $value) {
            $this->actingAs($admin)
                ->put(route('admin.service-instances.update', $service), ['provision_status' => $value])
                ->assertSessionHasNoErrors();

            $this->assertSame($value, $service->fresh()->provision_status);
        }
    }

    /**
     * The list filter used to error with "Unknown column".
     */
    public function test_the_list_can_be_filtered_by_provision_status(): void
    {
        $this->service(['provision_status' => 'provisioned']);
        $this->service(['provision_status' => 'failed']);

        $this->actingAs($this->adminWith(['service-instances.view']))
            ->get(route('admin.service-instances.index', ['provision_status' => 'failed']))
            ->assertOk();
    }

    public function test_the_service_page_renders_the_recorded_provision_status(): void
    {
        $service = $this->service(['status' => 'active', 'provision_status' => 'provisioned']);

        $this->actingAs($this->adminWith(['service-instances.view']))
            ->get(route('admin.service-instances.show', $service))
            ->assertOk()
            ->assertSee('Provision Status', false)
            ->assertSee('provisioned', false);
    }
}
