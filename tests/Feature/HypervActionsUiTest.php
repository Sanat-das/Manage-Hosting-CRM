<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\PanelAccount;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\ProvisioningEvent;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HypervActionsUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_hosting_show_contains_hyperv_progress_and_action_controls(): void
    {
        Http::fake();

        $product = Product::create(['name' => 'HV', 'price' => 50]);
        ProductModule::create([
            'product_id' => $product->id, 'module_slug' => 'hyperv', 'enabled' => true, 'config' => [],
        ]);
        $account = HostingAccount::create([
            'customer_id' => $this->makeCustomer()->id,
            'product_id' => $product->id,
            'host_name' => 'hv-ui-01',
        ]);

        $html = $this->actingAsAdminWith(['hosting.view', 'hosting.edit'])
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('hv-action-progress', $html);
        $this->assertStringContainsString('hv-progress-bar', $html);
        $this->assertStringContainsString('hv-progress-label', $html);
        $this->assertStringContainsString('hv-progress-elapsed', $html);
        $this->assertStringContainsString('hv-vm-state', $html);
        // progressbar aria + striped animated
        $this->assertStringContainsString('role="progressbar"', $html);
        $this->assertStringContainsString('progress-bar-striped', $html);
        $this->assertStringContainsString('progress-bar-animated', $html);
        $this->assertStringContainsString('aria-valuenow', $html);

        foreach (['create','start','stop','restart','delete','reset_password'] as $act) {
            $this->assertStringContainsString('data-hv-action="'.$act.'"', $html);
        }

        // create view fields
        $this->assertStringContainsString('name="guest_username"', $html);
        $this->assertStringContainsString('name="guest_password"', $html);
        $this->assertStringContainsString('name="start_after_create"', $html);
        $this->assertStringContainsString('Start the VM after creation', $html);
        // helper text
        $this->assertStringContainsString('Stored encrypted; used for RDP', $html);

        // reset view + result block
        $this->assertStringContainsString('hv-reset-password-', $html);
        $this->assertStringContainsString('hv-reset-result', $html);
        $this->assertStringContainsString('name="password"', $html);
        $this->assertStringContainsString('name="password_confirmation"', $html);

        // Generate-button wiring: every data-hv-generate-target must resolve
        // to exactly one password <input> (a past duplicate id on a form
        // container made Generate silently write into a div instead).
        $domGen = new \DOMDocument;
        @$domGen->loadHTML($html);
        $xpathGen = new \DOMXPath($domGen);
        $genButtons = $xpathGen->query('//*[@data-hv-generate-target]');
        $this->assertGreaterThan(0, $genButtons->length);
        foreach ($genButtons as $btn) {
            $target = $btn->getAttribute('data-hv-generate-target');
            $this->assertNotSame('', $target);
            $targets = $xpathGen->query('//*[@id="'.$target.'"]');
            $this->assertSame(1, $targets->length, "Generate target #{$target} must be unique");
            $this->assertSame('input', strtolower($targets->item(0)->tagName));
        }
        // No element id may occur twice anywhere in the rendered page. This is
        // the design-independent form of the bug above: the Generate button
        // resolves its input by id, so any duplicate id can silently redirect
        // a value into the wrong element.
        $idCounts = [];
        foreach ($xpathGen->query('//*[@id]') as $elWithId) {
            $id = $elWithId->getAttribute('id');
            $idCounts[$id] = ($idCounts[$id] ?? 0) + 1;
        }
        $duplicateIds = array_keys(array_filter($idCounts, static fn (int $count): bool => $count > 1));
        $this->assertSame([], $duplicateIds, 'Duplicate element ids found: '.implode(', ', $duplicateIds));

        // aria-live on progress label + role status on reset result
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('role="status"', $html);
    }

    public function test_action_trigger_attribute_never_wraps_form_content(): void
    {
        Http::fake();

        $product = Product::create(['name' => 'HV', 'price' => 50]);
        ProductModule::create([
            'product_id' => $product->id, 'module_slug' => 'hyperv', 'enabled' => true, 'config' => [],
        ]);
        $account = HostingAccount::create([
            'customer_id' => $this->makeCustomer()->id,
            'product_id' => $product->id,
            'host_name' => 'hv-form-scope-01',
        ]);

        $html = $this->actingAsAdminWith(['hosting.view', 'hosting.edit'])
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->getContent();

        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        // `data-hv-action` identifies an action TRIGGER. A form must never carry
        // it: the delegated click handler resolves the trigger with
        // closest('[data-hv-action]'), so a click on any field inside such a
        // form would match the form itself and toggle the disclosure shut —
        // making every inline form uneditable. The form's own marker is the
        // distinct data-hv-form-action, which must never mark a button.
        $this->assertSame(0, $xpath->query('//form[@data-hv-action]')->length, 'A <form> must not carry the action-trigger attribute');

        $triggers = $xpath->query('//*[@data-hv-action]');
        $this->assertGreaterThan(0, $triggers->length);
        foreach ($triggers as $trigger) {
            $this->assertSame('button', strtolower($trigger->tagName), 'data-hv-action must only ever mark a <button>');
        }

        $formActions = $xpath->query('//*[@data-hv-form-action]');
        $this->assertSame(4, $formActions->length, 'create/restart/delete/reset forms each keep their own action marker');
        foreach ($formActions as $formWithAction) {
            $this->assertSame('form', strtolower($formWithAction->tagName), 'data-hv-form-action must only ever mark a <form>');
        }
    }

    public function test_running_provisioning_event_renders_buttons_disabled(): void
    {
        Http::fake();

        $product = Product::create(['name' => 'HV', 'price' => 50]);
        ProductModule::create([
            'product_id' => $product->id, 'module_slug' => 'hyperv', 'enabled' => true, 'config' => [],
        ]);
        $account = HostingAccount::create([
            'customer_id' => $this->makeCustomer()->id,
            'product_id' => $product->id,
            'host_name' => 'hv-running-01',
        ]);

        ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => $account->id,
            'event_type' => 'provision',
            'status' => 'running',
            'event_status' => 'running',
            'payload' => ['module' => 'hyperv', 'action' => 'create'],
            'result' => [],
            'last_error' => null,
        ]);

        $html = $this->actingAsAdminWith(['hosting.view', 'hosting.edit'])
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->getContent();

        // at least one disabled attribute inside the Hyper-V action row
        // We look for hv-actions-row + disabled
        $this->assertStringContainsString('hv-actions-row-', $html);
        // data-hv-action buttons should be disabled when running
        // Use regex: find a data-hv-action button with disabled
        $this->assertMatchesRegularExpression('/data-hv-action="create"[^>]*disabled/', $html);
        $this->assertMatchesRegularExpression('/data-hv-action="start"[^>]*disabled/', $html);
    }

    public function test_failed_build_alert_is_visible_outside_the_hidden_progress_panel(): void
    {
        Http::fake();

        $product = Product::create(['name' => 'HV', 'price' => 50]);
        ProductModule::create([
            'product_id' => $product->id, 'module_slug' => 'hyperv', 'enabled' => true, 'config' => [],
        ]);
        $account = HostingAccount::create([
            'customer_id' => $this->makeCustomer()->id,
            'product_id' => $product->id,
            'host_name' => 'hv-failed-01',
        ]);
        ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => $account->id,
            'event_type' => 'provision',
            'status' => 'failed',
            'event_status' => 'failed',
            'payload' => ['module' => 'hyperv', 'action' => 'create'],
            'result' => ['error' => 'HOST-BUILD-BOOM'],
            'last_error' => 'HOST-BUILD-BOOM',
        ]);

        $html = $this->actingAsAdminWith(['hosting.view', 'hosting.edit'])
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->getContent();

        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        // The progress panel is collapsed (nothing running): its slot is shut
        // by default (grid-template-rows: 0fr) and only the is-open class
        // expands it; aria-hidden mirrors that for assistive technology.
        $slot = $xpath->query('//*[@data-hv-progress-slot]');
        $this->assertSame(1, $slot->length);
        $this->assertStringNotContainsString('is-open', (string) $slot->item(0)->getAttribute('class'));
        $this->assertSame('true', $slot->item(0)->getAttribute('aria-hidden'));

        // … so the failure must live OUTSIDE it or the operator would never see it.
        $this->assertSame(0, $xpath->query('//*[@id="hv-action-progress"]//*[contains(@class,"alert-danger")]')->length);
        $this->assertGreaterThan(0, $xpath->query('//*[contains(@class,"alert-danger")]')->length);
        $this->assertStringContainsString('HOST-BUILD-BOOM', $html);
    }

    public function test_hosting_show_contains_credentials_reveal_and_apply_password_opt_in(): void
    {
        Http::fake();

        $product = Product::create(['name' => 'HV', 'price' => 50]);
        ProductModule::create([
            'product_id' => $product->id, 'module_slug' => 'hyperv', 'enabled' => true, 'config' => [],
        ]);
        $account = HostingAccount::create([
            'customer_id' => $this->makeCustomer()->id,
            'product_id' => $product->id,
            'host_name' => 'hv-creds-01',
        ]);

        $html = $this->actingAsAdminWith(['hosting.view', 'hosting.edit'])
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->getContent();

        // Credentials action next to Reset password (disabled: nothing stored).
        $this->assertStringContainsString('data-hv-action="credentials"', $html);
        $this->assertStringContainsString('>Credentials</button>', $html);
        $this->assertMatchesRegularExpression('/data-hv-action="credentials"[^>]*disabled/', $html);
        $this->assertStringContainsString('No Administrator credentials are stored for this VM.', $html);

        // Reveal modal shell (password itself is fetched on demand, never rendered).
        $this->assertStringContainsString('hv-credentials-', $html);
        $this->assertStringContainsString('hv-credentials-password-', $html);
        $this->assertStringContainsString('hv-credentials-show-', $html);
        $this->assertStringContainsString('hv-credentials-copy-', $html);
        $this->assertStringContainsString('hv-credentials-feedback-', $html);
        // The on-demand fetch URL is wired into the page JS (route name never renders — the path does).
        $this->assertStringContainsString('vm-credentials', $html);

        // Create modal opt-in checkbox + helper text.
        $this->assertStringContainsString('name="apply_password"', $html);
        $this->assertStringContainsString('Set a new Administrator password after the VM starts', $html);
        $this->assertStringContainsString('view it under Credentials', $html);
    }

    public function test_hosting_show_never_renders_the_stored_password(): void
    {
        Http::fake();

        $product = Product::create(['name' => 'HV', 'price' => 50]);
        ProductModule::create([
            'product_id' => $product->id, 'module_slug' => 'hyperv', 'enabled' => true, 'config' => [],
        ]);
        $account = HostingAccount::create([
            'customer_id' => $this->makeCustomer()->id,
            'product_id' => $product->id,
            'host_name' => 'hv-creds-02',
        ]);
        $service = \App\Models\ServiceInstance::create([
            'customer_id' => $account->customer_id,
            'order_id' => null,
            'server_id' => null,
            'domain' => 'vm.test',
            'service_tag' => 'HOST-'.$account->id,
            'username' => 'hv-creds-02',
            'provisioning_method' => 'hyperv',
            'status' => 'pending',
        ]);
        \App\Models\PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => null,
            'panel' => 'hyperv',
            'username' => 'hv-creds-02',
            'external_id' => '22222222-3333-4444-5555-666666666666',
            'guest_username' => 'Administrator',
            'guest_password_encrypted' => 'NeverRenderMe42',
            'meta' => ['vmName' => 'hv-creds-02'],
            'status' => \App\Models\PanelAccount::STATUS_ACTIVE,
        ]);

        $html = $this->actingAsAdminWith(['hosting.view', 'hosting.edit'])
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('NeverRenderMe42', $html);
        // …but the reveal entry point is present and enabled now that creds exist.
        $this->assertStringContainsString('data-hv-action="credentials"', $html);
    }

    public function test_probe_failure_renders_retry_affordance(): void
    {
        // WHY every action is disabled when the host cannot be reached — the
        // retry button is the escape hatch, not a relaxation of the guards.
        Http::fake(fn () => Http::response(['error' => 'WinRM boom'], 500));

        [$account] = $this->hostingWithPanel('hv-retry-fail-01');

        $html = $this->actingAsAdminWith(['hosting.view', 'hosting.edit'])
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-hv-retry', $html);
        $this->assertStringContainsString('hv-retry-status-', $html);
        $this->assertStringContainsString('WinRM boom', $html);
        // Pill must explain the unknown state.
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $pill = $xpath->query('//*[@id="hv-vm-state"]');
        $this->assertSame(1, $pill->length);
        $this->assertSame('Unknown', trim((string) $pill->item(0)->textContent));
        // Retry affordance lives in the always-visible action row, not the hidden progress panel.
        $this->assertSame(0, $xpath->query('//*[@id="hv-action-progress"]//*[@data-hv-retry]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-hv-retry]')->length);
        $this->assertStringContainsString('Host unreachable', $html);
        // Conservative gating: a probe error still disables the power actions.
        $this->assertMatchesRegularExpression('/data-hv-action="start"[^>]*disabled/', $html);
    }

    public function test_probe_success_renders_no_retry(): void
    {
        Http::fake(function ($request) {
            $body = (string) $request->body();
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => 'hv-retry-ok-01', 'state' => 'Off', 'vmId' => '11111111-2222-3333-4444-555555555555']);
            }
            return Http::response(['error' => 'unexpected host call'], 500);
        });

        [$account] = $this->hostingWithPanel('hv-retry-ok-01');

        $html = $this->actingAsAdminWith(['hosting.view', 'hosting.edit'])
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->getContent();

        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        // The retry control is always rendered so the JS can reveal it
        // without a re-render; a successful probe must leave it hidden —
        // itself or the probe container ancestor carries the attribute.
        $retry = $xpath->query('//*[@data-hv-retry]');
        $this->assertSame(1, $retry->length);
        $hidden = false;
        for ($node = $retry->item(0); $node instanceof \DOMElement; $node = $node->parentNode) {
            if ($node->hasAttribute('hidden')) {
                $hidden = true;
                break;
            }
        }
        $this->assertTrue($hidden, 'Retry control must be hidden (itself or an ancestor) when the probe succeeds');

        $pill = $xpath->query('//*[@id="hv-vm-state"]');
        $this->assertSame(1, $pill->length);
        $this->assertSame('Off', trim((string) $pill->item(0)->textContent));
    }

    public function test_vm_status_endpoint_honours_refresh(): void
    {
        Http::fake(fn () => Http::response(['error' => 'WinRM boom'], 500));

        [$account] = $this->hostingWithPanel('hv-retry-refresh-01');

        $json = $this->actingAsAdminWith(['hosting.view'])
            ->getJson(route('admin.hosting.vm-status', $account).'?refresh=1')
            ->assertOk()
            ->json();

        $this->assertTrue($json['ok']);
        $this->assertArrayHasKey('vm', $json);
        $this->assertArrayHasKey('probe_error', $json['vm']);
        // Probe failure keeps vm unknown but the key must be present.
        $this->assertTrue($json['vm']['probe_error'] !== null || array_key_exists('probe_error', $json['vm']));
    }

    private function hostingWithPanel(string $hostName): array
    {
        $server = Server::create([
            'name' => 'hv-'.substr($hostName, 0, 8),
            'ip_address' => '10.0.0.9',
            'server_type' => 'hyperv',
            'api_url' => 'http://10.0.0.9:5985',
            'api_username' => 'admin',
            'api_password_encrypted' => 'SECRET',
            'max_accounts' => 0,
            'status' => 'active',
        ]);
        $product = Product::create(['name' => 'HV', 'price' => 50]);
        ProductModule::create([
            'product_id' => $product->id, 'module_slug' => 'hyperv', 'enabled' => true, 'config' => [],
        ]);
        $customer = $this->makeCustomer();
        $account = HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'server_id' => $server->id,
            'domain' => 'vm.test',
            'host_name' => $hostName,
            'status' => 'active',
        ]);
        $service = ServiceInstance::create([
            'customer_id' => $customer->id,
            'order_id' => null,
            'server_id' => $server->id,
            'domain' => 'vm.test',
            'service_tag' => 'HOST-'.$account->id,
            'username' => $hostName,
            'provisioning_method' => 'hyperv',
            'status' => 'pending',
        ]);
        PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'hyperv',
            'username' => $hostName,
            'external_id' => '11111111-2222-3333-4444-555555555555',
            'meta' => ['vmName' => $hostName],
            'status' => PanelAccount::STATUS_ACTIVE,
        ]);

        return [$account->fresh(), $service];
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'user_id' => User::factory()->create()->id,
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
        $role->permissions()->sync($ids);
        $user->assignRole('admin');

        return $this->actingAs($user);
    }
}
