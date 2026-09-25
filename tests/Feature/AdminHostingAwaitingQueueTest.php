<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminHostingAwaitingQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_awaiting_manual_filter_returns_only_pending_hyperv_manual(): void
    {
        [$matching, $pendingNonHyperv, $pendingHypervAuto, $activeHypervManual] = $this->seedFourAccounts();

        $response = $this->actingAsAdminWith(['hosting.view'])
            ->get(route('admin.hosting.index', ['status' => 'awaiting_manual']))
            ->assertOk();

        $response->assertSee($matching->host_name);
        $response->assertDontSee($pendingNonHyperv->host_name);
        $response->assertDontSee($pendingHypervAuto->host_name);
        $response->assertDontSee($activeHypervManual->host_name);
    }

    public function test_awaiting_manual_badge_renders_only_for_matching_row(): void
    {
        [$matching, $pendingNonHyperv, $pendingHypervAuto, $activeHypervManual] = $this->seedFourAccounts();

        // Unfiltered list: badge must appear for matching row and not multiplied for others
        $response = $this->actingAsAdminWith(['hosting.view'])
            ->get(route('admin.hosting.index'))
            ->assertOk();

        $content = $response->getContent();
        $this->assertStringContainsString('Awaiting VM', $content);
        $this->assertStringContainsString('Awaiting manual VM provisioning', $content);
        $this->assertSame(1, substr_count($content, 'Awaiting VM'), 'Badge must appear exactly once for the single matching account in unfiltered list');
        $this->assertSame(1, substr_count($content, 'title="Awaiting manual VM provisioning"'), 'Warning badge title must appear exactly once');

        // Filtered awaiting_manual list: matching row badge present
        $filtered = $this->actingAsAdminWith(['hosting.view'])
            ->get(route('admin.hosting.index', ['status' => 'awaiting_manual']))
            ->assertOk();

        $filteredContent = $filtered->getContent();
        $this->assertStringContainsString('Awaiting VM', $filteredContent);
        $this->assertSame(1, substr_count($filteredContent, 'Awaiting VM'));
        // Ensure filtered list does not contain other host_names (already tested) but badge count confirms no extras
    }

    public function test_dropdown_contains_awaiting_manual_option(): void
    {
        $this->actingAsAdminWith(['hosting.view'])
            ->get(route('admin.hosting.index'))
            ->assertOk()
            ->assertSee('Awaiting manual provisioning', false)
            ->assertSee('value="awaiting_manual"', false);
    }

    public function test_existing_status_filter_still_works(): void
    {
        [$matching, $pendingNonHyperv, $pendingHypervAuto, $activeHypervManual] = $this->seedFourAccounts();

        $activeResponse = $this->actingAsAdminWith(['hosting.view'])
            ->get(route('admin.hosting.index', ['status' => 'active']))
            ->assertOk();

        $activeResponse->assertSee($activeHypervManual->host_name);
        $activeResponse->assertDontSee($matching->host_name);
        $activeResponse->assertDontSee($pendingNonHyperv->host_name);
        $activeResponse->assertDontSee($pendingHypervAuto->host_name);

        $pendingResponse = $this->actingAsAdminWith(['hosting.view'])
            ->get(route('admin.hosting.index', ['status' => 'pending']))
            ->assertOk();

        $pendingResponse->assertSee($matching->host_name);
        $pendingResponse->assertSee($pendingNonHyperv->host_name);
        $pendingResponse->assertSee($pendingHypervAuto->host_name);
        $pendingResponse->assertDontSee($activeHypervManual->host_name);
    }

    public function test_search_and_awaiting_manual_combines(): void
    {
        [$matching, $pendingNonHyperv] = $this->seedFourAccounts();

        // Search by matching host_name inside awaiting_manual filter should still find it
        $this->actingAsAdminWith(['hosting.view'])
            ->get(route('admin.hosting.index', ['status' => 'awaiting_manual', 'search' => $matching->host_name]))
            ->assertOk()
            ->assertSee($matching->host_name);

        // Searching for non-matching host_name inside awaiting_manual should yield empty
        // Note: the search term itself appears in the search input value, so we
        // assert on the row-specific domain rather than the host_name.
        $this->actingAsAdminWith(['hosting.view'])
            ->get(route('admin.hosting.index', ['status' => 'awaiting_manual', 'search' => $pendingNonHyperv->host_name]))
            ->assertOk()
            ->assertDontSee('plain.example.com')
            ->assertSee('No products/services found.');
    }

    /**
     * @return array{0:HostingAccount,1:HostingAccount,2:HostingAccount,3:HostingAccount}
     */
    private function seedFourAccounts(): array
    {
        $customer = $this->makeCustomer();

        // 1) Matching: pending + hyperv enabled manual
        $productManual = Product::create([
            'name' => 'HV Manual Pending',
            'price' => 100,
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ]);
        ProductModule::create([
            'product_id' => $productManual->id,
            'module_slug' => 'hyperv',
            'enabled' => true,
            'provisioning_mode' => 'manual',
            'config' => [],
        ]);
        $matching = HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $productManual->id,
            'host_name' => 'host-await-match-'.uniqid(),
            'domain' => 'match.example.com',
            'status' => 'pending',
        ]);

        // 2) Pending non-hyperv (no module link)
        $productNonHyperv = Product::create([
            'name' => 'Shared Plain',
            'price' => 50,
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ]);
        $pendingNonHyperv = HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $productNonHyperv->id,
            'host_name' => 'host-plain-'.uniqid(),
            'domain' => 'plain.example.com',
            'status' => 'pending',
        ]);

        // 3) Pending hyperv auto (enabled but auto mode)
        $productAuto = Product::create([
            'name' => 'HV Auto Pending',
            'price' => 80,
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ]);
        ProductModule::create([
            'product_id' => $productAuto->id,
            'module_slug' => 'hyperv',
            'enabled' => true,
            'provisioning_mode' => 'auto',
            'config' => [],
        ]);
        $pendingHypervAuto = HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $productAuto->id,
            'host_name' => 'host-auto-'.uniqid(),
            'domain' => 'auto.example.com',
            'status' => 'pending',
        ]);

        // 4) Active hyperv manual (should not appear in awaiting_manual, badge not shown)
        $productManualActive = Product::create([
            'name' => 'HV Manual Active',
            'price' => 120,
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ]);
        ProductModule::create([
            'product_id' => $productManualActive->id,
            'module_slug' => 'hyperv',
            'enabled' => true,
            'provisioning_mode' => 'manual',
            'config' => [],
        ]);
        $activeHypervManual = HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $productManualActive->id,
            'host_name' => 'host-active-manual-'.uniqid(),
            'domain' => 'activemanual.example.com',
            'status' => 'active',
        ]);

        return [$matching->fresh(), $pendingNonHyperv->fresh(), $pendingHypervAuto->fresh(), $activeHypervManual->fresh()];
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Test Corp '.uniqid(),
            'status' => 'active',
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
        // Ensure admin role has at least the requested perms (syncWithoutDetaching to keep other perms)
        $role->permissions()->syncWithoutDetaching($ids);
        $user->assignRole('admin');

        return $this->actingAs($user);
    }
}
