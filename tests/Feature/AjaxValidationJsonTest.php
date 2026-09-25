<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exception rendering contract for AJAX callers.
 *
 * `bootstrap/app.php` renders JSON when the request is an API route OR when
 * it asked for JSON. Before the second clause, an AJAX validation failure on
 * a web route produced a 302 redirect the panels' fetch() calls could not
 * read, so every AJAX form showed a generic "Request failed" instead of the
 * actual field error. Plain browser form posts must keep redirecting with the
 * error bag.
 */
class AjaxValidationJsonTest extends TestCase
{
    use RefreshDatabase;

    public function test_ajax_validation_failure_renders_json_on_a_web_route(): void
    {
        $account = $this->account();

        $this->actingAsAdminWith(['hosting.edit'])
            ->postJson(route('admin.hosting.module-action', $account), [
                // module_slug and action are required.
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['module_slug', 'action'])
            ->assertJsonStructure(['message', 'errors']);
    }

    public function test_plain_form_validation_failure_still_redirects_with_the_error_bag(): void
    {
        $account = $this->account();

        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [])
            ->assertRedirect()
            ->assertSessionHasErrors(['module_slug', 'action']);
    }

    private function account(): HostingAccount
    {
        $customer = Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);

        return HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => Product::create(['name' => 'HV', 'price' => 50])->id,
            'host_name' => 'ajax-validation-01',
        ]);
    }

    private function actingAsAdminWith(array $permissionNames): self
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $ids = [];
        foreach ($permissionNames as $name) {
            $ids[] = Permission::firstOrCreate(['name' => $name], ['label' => $name])->id;
        }
        $role->permissions()->sync($ids);
        $user->assignRole('admin');

        return $this->actingAs($user);
    }
}
