<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\PanelAccount;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Proxmox VE admin compute-card parity with the Hyper-V card: credential
 * reveal + password reset affordances, and the client-area reset gate.
 *
 * Rendering only — the endpoint behavior belongs to the backend slice. The
 * partial is rendered directly with a crafted $vmStatus so the gating matrix
 * is exercised without depending on the presenter's per-slug capability
 * rollout.
 */
final class ProxmoxComputeCardUiTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────── admin card ───────────────────────────

    public function test_admin_page_renders_the_compute_reset_and_credentials_affordances(): void
    {
        $server = $this->proxmoxServer();
        $product = $this->productWithProxmoxLink();
        $account = $this->hostingAccount($this->customer(), $product, $server);

        $html = $this->actingAs($this->adminWith(['hosting.view', 'hosting.edit']))
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="compute-panel-proxmox"', $html);
        $this->assertStringContainsString('data-compute-action="reset_password"', $html);
        $this->assertStringContainsString('data-compute-action="credentials"', $html);
        $this->assertStringContainsString('data-compute-view="reset_password"', $html);
        $this->assertStringContainsString('data-compute-view="credentials"', $html);
        $this->assertStringContainsString('compute-reset-username-proxmox', $html);
        $this->assertStringContainsString('compute-reset-new-password-proxmox', $html);
        $this->assertStringContainsString('compute-reset-password-confirm-proxmox', $html);
        $this->assertStringContainsString('data-compute-generate-target="compute-reset-new-password-proxmox"', $html);
        $this->assertStringContainsString('id="compute-reset-result-proxmox"', $html);
        $this->assertStringContainsString('id="compute-reset-result-password-proxmox"', $html);
        $this->assertStringContainsString('id="compute-reset-copy-proxmox"', $html);
        $this->assertStringContainsString('compute-credentials-username-proxmox', $html);
        $this->assertStringContainsString('compute-credentials-password-proxmox', $html);
        $this->assertStringContainsString('compute-credentials-show-proxmox', $html);
        $this->assertStringContainsString('compute-credentials-copy-proxmox', $html);
        $this->assertStringContainsString('compute-credentials-feedback-proxmox', $html);
        $this->assertStringContainsString('compute-credentials-error-proxmox', $html);
        // Bullets, never a password; the on-demand fetch URL is wired into the page JS.
        $this->assertStringContainsString('••••••••', $html);
        $this->assertStringContainsString('vm-credentials', $html);
        $this->assertStringContainsString('reset-vm-password', $html);
    }

    public function test_compute_card_disables_reset_and_credentials_with_reasons(): void
    {
        $html = $this->renderComputeCard([
            'action' => ['running' => false],
            'vm' => ['exists' => true, 'state' => 'stopped', 'probe_error' => null],
            'can' => ['create' => false, 'start' => true, 'stop' => false, 'restart' => false, 'delete' => true, 'reset_password' => false],
            'reasons' => ['reset_password' => 'Start the VM to reset the password.'],
            'credentials' => ['stored' => false, 'username' => 'root'],
        ]);

        $this->assertMatchesRegularExpression('/data-compute-action="reset_password"[^>]*disabled/', $html);
        $this->assertStringContainsString('Start the VM to reset the password.', $html);
        $this->assertMatchesRegularExpression('/data-compute-action="credentials"[^>]*disabled/', $html);
        $this->assertStringContainsString('No credentials are stored for this VM.', $html);
    }

    public function test_compute_card_enables_reset_and_credentials_when_allowed(): void
    {
        $html = $this->renderComputeCard([
            'action' => ['running' => false],
            'vm' => ['exists' => true, 'state' => 'running', 'probe_error' => null],
            'can' => ['create' => false, 'start' => false, 'stop' => true, 'restart' => true, 'delete' => false, 'reset_password' => true],
            'reasons' => [],
            'credentials' => ['stored' => true, 'username' => 'root'],
        ]);

        foreach (['reset_password', 'credentials'] as $act) {
            $this->assertMatchesRegularExpression('/<button[^>]*data-compute-action="'.$act.'"[^>]*>/', $html);
            preg_match('/<button[^>]*data-compute-action="'.$act.'"[^>]*>/', $html, $m);
            $this->assertArrayHasKey(0, $m);
            $this->assertStringNotContainsString('disabled', $m[0], "data-compute-action=\"{$act}\" must be enabled");
        }

        $this->assertStringContainsString('Stored credentials are available for this VM.', $html);
        $this->assertStringContainsString('id="compute-credentials-username-proxmox">root<', $html);
    }

    public function test_compute_card_never_renders_the_stored_password(): void
    {
        // Even if a password key ever reached the view, it must not leak: the
        // contract only carries {stored, username}.
        $html = $this->renderComputeCard([
            'action' => ['running' => false],
            'vm' => ['exists' => true, 'state' => 'running', 'probe_error' => null],
            'can' => ['create' => false, 'start' => false, 'stop' => true, 'restart' => true, 'delete' => false, 'reset_password' => true],
            'reasons' => [],
            'credentials' => ['stored' => true, 'username' => 'root', 'password' => 'NeverRenderMe42'],
        ]);

        $this->assertStringNotContainsString('NeverRenderMe42', $html);
        $this->assertStringContainsString('••••••••', $html);
        $this->assertStringContainsString('data-compute-action="credentials"', $html);
    }

    public function test_compute_generate_targets_resolve_to_unique_password_inputs(): void
    {
        $html = $this->renderComputeCard([
            'action' => ['running' => false],
            'vm' => ['exists' => true, 'state' => 'running', 'probe_error' => null],
            'can' => ['create' => false, 'start' => false, 'stop' => true, 'restart' => true, 'delete' => false, 'reset_password' => true],
            'reasons' => [],
            'credentials' => ['stored' => true, 'username' => 'root'],
        ]);

        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        $genButtons = $xpath->query('//*[@data-compute-generate-target]');
        $this->assertGreaterThan(0, $genButtons->length);
        foreach ($genButtons as $btn) {
            $target = $btn->getAttribute('data-compute-generate-target');
            $this->assertNotSame('', $target);
            $targets = $xpath->query('//*[@id="'.$target.'"]');
            $this->assertSame(1, $targets->length, "Generate target #{$target} must be unique");
            $this->assertSame('input', strtolower($targets->item(0)->tagName));
        }

        $idCounts = [];
        foreach ($xpath->query('//*[@id]') as $elWithId) {
            $id = $elWithId->getAttribute('id');
            $idCounts[$id] = ($idCounts[$id] ?? 0) + 1;
        }
        $duplicateIds = array_keys(array_filter($idCounts, static fn (int $count): bool => $count > 1));
        $this->assertSame([], $duplicateIds, 'Duplicate element ids found: '.implode(', ', $duplicateIds));

        // `data-compute-action` identifies a button trigger only; the form's
        // own marker is the distinct data-compute-form-action.
        $this->assertSame(0, $xpath->query('//form[@data-compute-action]')->length);
        foreach ($xpath->query('//*[@data-compute-action]') as $trigger) {
            $this->assertSame('button', strtolower($trigger->tagName));
        }
        foreach ($xpath->query('//*[@data-compute-form-action]') as $formWithAction) {
            $this->assertSame('form', strtolower($formWithAction->tagName));
        }
    }

    /**
     * Two compute cards co-rendered on one admin page (e.g. a product linked
     * to both Proxmox VE and Virtualizor) must not share element ids — the
     * reset-result trio is slug-suffixed like every other new id, so each
     * panel's JS keeps addressing its own result block.
     */
    public function test_co_rendered_compute_cards_keep_unique_element_ids(): void
    {
        $vmStatus = [
            'action' => ['running' => false],
            'vm' => ['exists' => true, 'state' => 'running', 'probe_error' => null],
            'can' => ['create' => false, 'start' => false, 'stop' => true, 'restart' => true, 'delete' => false, 'reset_password' => true],
            'reasons' => [],
            'credentials' => ['stored' => true, 'username' => 'root'],
        ];

        $html = $this->renderComputeCard($vmStatus, 'proxmox')
            .$this->renderComputeCard($vmStatus, 'virtualizor');

        foreach (['proxmox', 'virtualizor'] as $slug) {
            $this->assertStringContainsString('id="compute-reset-result-'.$slug.'"', $html);
            $this->assertStringContainsString('id="compute-reset-result-password-'.$slug.'"', $html);
            $this->assertStringContainsString('id="compute-reset-copy-'.$slug.'"', $html);
        }

        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        $idCounts = [];
        foreach ($xpath->query('//*[@id]') as $elWithId) {
            $id = $elWithId->getAttribute('id');
            $idCounts[$id] = ($idCounts[$id] ?? 0) + 1;
        }
        $duplicateIds = array_keys(array_filter($idCounts, static fn (int $count): bool => $count > 1));
        $this->assertSame([], $duplicateIds, 'Duplicate element ids found: '.implode(', ', $duplicateIds));
    }

    /**
     * Presenter → card through the real admin page: a Proxmox account with a
     * running VM on a faked host must offer an enabled reset affordance (no
     * hand-crafted $vmStatus — the presenter builds it from the probe).
     */
    public function test_admin_page_enables_reset_for_a_running_proxmox_vm(): void
    {
        $server = $this->proxmoxServer('pve-live-'.str()->lower(str()->random(6)));
        $product = $this->productWithProxmoxLink();
        $customer = $this->customer();
        $account = $this->hostingAccount($customer, $product, $server, 'pvelive01');
        $account->status = 'active';
        $account->save();

        $service = ServiceInstance::create([
            'customer_id' => $customer->id,
            'order_id' => null,
            'server_id' => $server->id,
            'domain' => 'vm.test',
            'service_tag' => 'HOST-'.$account->id,
            'username' => 'pvelive01',
            'provisioning_method' => 'proxmox',
            'status' => 'active',
        ]);
        PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'proxmox',
            'username' => 'pvelive01',
            'external_id' => '901',
            'meta' => ['meta' => ['node' => 'pve1', 'vmid' => 901]],
            'status' => PanelAccount::STATUS_ACTIVE,
        ]);

        Http::fake([
            '*/api2/json/nodes/pve1/qemu/901/status/current' => Http::response(['data' => ['status' => 'running', 'vmid' => 901]]),
            '*/api2/json/nodes' => Http::response(['data' => [['node' => 'pve1', 'status' => 'online']]]),
        ]);
        Http::preventStrayRequests();

        $html = $this->actingAs($this->adminWith(['hosting.view', 'hosting.edit']))
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="compute-panel-proxmox"', $html);
        preg_match('/<button[^>]*data-compute-action="reset_password"[^>]*>/', $html, $m);
        $this->assertArrayHasKey(0, $m);
        $this->assertStringNotContainsString('disabled', $m[0], 'A running Proxmox VM must offer an enabled reset');
    }

    // ─────────────────────────── client gate ───────────────────────────

    public function test_client_page_hides_reset_when_presenter_refuses_for_compute(): void
    {
        [$account, $customer] = $this->hostingWithProxmox(hostingStatus: 'pending');

        $content = $this->actingAs($customer->user)
            ->get(route('client.hosting.show', $account->id))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-client-hv-action="reset_password"', $content);
        $this->assertStringNotContainsString('id="client-reset-password-modal"', $content);
    }

    public function test_client_page_offers_reset_when_presenter_allows_for_compute(): void
    {
        $server = $this->proxmoxServer('pve-client');
        $product = $this->productWithProxmoxLink();
        $customer = $this->customer();
        $account = $this->hostingAccount($customer, $product, $server, 'pveclient01');
        $account->status = 'active';
        $account->save();

        $content = $this->actingAs($customer->user)->get(route('client.hosting.show', $account->id))->assertOk()->getContent();

        // No VM on the host yet — the presenter refuses the reset, so a
        // compute (non-Hyper-V) service offers no reset button at all.
        $this->assertStringNotContainsString('data-client-hv-action="reset_password"', $content);

        $rendered = view('client.hosting.show', [
            'account' => $account->fresh(),
            'billing' => null,
            'modulePanels' => [],
            'provisionCard' => [
                'isCompute' => true,
                'isHyperv' => false,
                'slug' => 'proxmox',
                'templateKey' => 'template',
                'templateLabel' => 'Template VMID',
                'templates' => [],
                'default' => null,
                'curatedCount' => 0,
                'noEffective' => false,
            ],
            'vmStatus' => [
                'action' => ['running' => false],
                'vm' => ['exists' => true, 'state' => 'running', 'probe_error' => null],
                'can' => ['create' => false, 'start' => false, 'stop' => true, 'restart' => true, 'reset_password' => true],
                'reasons' => [],
                'credentials' => ['stored' => true, 'username' => 'root'],
            ],
            'configOptions' => [],
            'configCycle' => 'monthly',
        ])->render();

        $this->assertStringContainsString('data-client-hv-action="reset_password"', $rendered);
        $this->assertStringContainsString('id="client-reset-password-modal"', $rendered);
        $this->assertStringContainsString('id="client-reset-password"', $rendered);
        $this->assertStringContainsString('id="client-reset-password-confirm"', $rendered);
        preg_match('/<button[^>]*data-client-hv-action="reset_password"[^>]*>/', $rendered, $m);
        $this->assertArrayHasKey(0, $m);
        $this->assertStringNotContainsString('disabled', $m[0], 'An allowed reset must render enabled');
    }

    /**
     * Both client-page reset states through the real page (presenter → view,
     * not hand-crafted $vmStatus): refused when no VM exists on the host,
     * present and enabled once a running VM is probed. Either half fails if
     * the view gating is removed in either direction.
     */
    public function test_client_page_reset_follows_the_live_presenter_for_compute(): void
    {
        $server = $this->proxmoxServer('pve-client-live-'.str()->lower(str()->random(6)));
        $product = $this->productWithProxmoxLink();
        $customer = $this->customer();
        $account = $this->hostingAccount($customer, $product, $server, 'pveclientlive01');
        $account->status = 'active';
        $account->save();

        $service = ServiceInstance::create([
            'customer_id' => $customer->id,
            'order_id' => null,
            'server_id' => $server->id,
            'domain' => 'vm.test',
            'service_tag' => 'HOST-'.$account->id,
            'username' => 'pveclientlive01',
            'provisioning_method' => 'proxmox',
            'status' => 'active',
        ]);
        PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'proxmox',
            'username' => 'pveclientlive01',
            'external_id' => '901',
            'meta' => ['meta' => ['node' => 'pve1', 'vmid' => 901]],
            'status' => PanelAccount::STATUS_ACTIVE,
        ]);

        Http::fake([
            '*/api2/json/nodes/pve1/qemu/901/status/current' => Http::response(['data' => ['status' => 'running', 'vmid' => 901]]),
            '*/api2/json/nodes' => Http::response(['data' => [['node' => 'pve1', 'status' => 'online']]]),
        ]);
        Http::preventStrayRequests();

        $content = $this->actingAs($customer->user)
            ->get(route('client.hosting.show', $account->id))
            ->assertOk()
            ->getContent();

        // The presenter allows the reset for the running VM, so the real page
        // offers it enabled — with the modal wired up.
        $this->assertStringContainsString('data-client-hv-action="reset_password"', $content);
        $this->assertStringContainsString('id="client-reset-password-modal"', $content);
        preg_match('/<button[^>]*data-client-hv-action="reset_password"[^>]*>/', $content, $m);
        $this->assertArrayHasKey(0, $m);
        $this->assertStringNotContainsString('disabled', $m[0], 'An allowed reset must render enabled');
    }

    // ─────────────────────────── helpers ───────────────────────────

    /** Render the compute partial with a crafted presenter payload. */
    private function renderComputeCard(array $vmStatus, string $slug = 'proxmox'): string
    {
        $server = $this->proxmoxServer('pve-render-'.str()->lower(str()->random(6)));
        $product = $this->productWithProxmoxLink();
        $account = $this->hostingAccount($this->customer(), $product, $server, 'pverender-'.str()->lower(str()->random(6)));

        $this->actingAs($this->adminWith(['hosting.view', 'hosting.edit']));

        return view('admin.hosting.partials._compute-actions', [
            'slug' => $slug,
            'name' => $slug === 'proxmox' ? 'Proxmox VE' : ucfirst($slug),
            'mode' => 'manual',
            'templateKey' => 'template',
            'templateLabel' => 'Template VMID',
            'options' => [['id' => '113', 'label' => 'ubuntu-2204']],
            'default' => '113',
            'curatedCount' => 1,
            'noEffective' => false,
            'canRestart' => true,
            'startAfterCreateDefault' => true,
            'hostingAccount' => $account,
            'vmStatus' => $vmStatus,
        ])->render();
    }

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

    private function proxmoxServer(string $name = 'pve-actions'): Server
    {
        return Server::create([
            'name' => $name,
            'ip_address' => '10.0.0.20',
            'server_type' => 'proxmox',
            'api_username' => 'root@pam!automation',
            'api_password_encrypted' => 'TOKEN-SECRET',
            'max_accounts' => 0,
            'status' => 'active',
            'connection_meta' => [
                'port' => 8006,
                'auth_type' => 'token',
                'verify_tls' => false,
                'proxmox_templates' => [
                    ['vmid' => '113', 'node' => 'pve1', 'label' => 'ubuntu-2204'],
                ],
                'proxmox_template_default' => '113',
            ],
        ]);
    }

    private function productWithProxmoxLink(): Product
    {
        $product = Product::create([
            'name' => 'PVE Product '.uniqid(),
            'price' => 50,
            'provisioning_module' => 'proxmox',
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ]);

        ProductModule::create([
            'product_id' => $product->id,
            'module_slug' => 'proxmox',
            'enabled' => true,
            'provisioning_mode' => ProductModule::PROVISIONING_MODE_MANUAL,
            'config' => [],
        ]);

        return $product;
    }

    private function customer(): Customer
    {
        return Customer::create(['user_id' => User::factory()->create()->id, 'status' => 'active']);
    }

    private function hostingAccount(Customer $customer, Product $product, Server $server, string $hostName = 'pvevm01'): HostingAccount
    {
        return HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'server_id' => $server->id,
            'domain' => 'vm.test',
            'host_name' => $hostName,
            'status' => 'pending',
        ]);
    }

    /** @return array{0:HostingAccount,1:Customer} */
    private function hostingWithProxmox(string $hostingStatus = 'pending'): array
    {
        $server = $this->proxmoxServer('pve-'.str()->lower(str()->random(6)));
        $product = $this->productWithProxmoxLink();
        $customer = $this->customer();
        $account = $this->hostingAccount($customer, $product, $server, 'pve-web-'.str()->lower(str()->random(6)));
        $account->status = $hostingStatus;
        $account->save();

        return [$account->fresh(), $customer->fresh()];
    }
}
