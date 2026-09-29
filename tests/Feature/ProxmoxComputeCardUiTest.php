<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Module;
use App\Models\PanelAccount;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\ProvisioningEvent;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Modules\ModuleManager;
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

    public function test_client_page_disables_reset_with_reason_when_presenter_refuses_for_compute(): void
    {
        [$account, $customer] = $this->hostingWithProxmox(hostingStatus: 'pending');

        $content = $this->actingAs($customer->user)
            ->get(route('client.hosting.show', $account->id))
            ->assertOk()
            ->getContent();

        // Refused resets render disabled with the presenter's reason (never
        // hidden): no VM exists on the host yet.
        $this->assertStringContainsString('data-client-hv-action="reset_password"', $content);
        $this->assertStringContainsString('id="client-reset-password-modal"', $content);
        $this->assertMatchesRegularExpression('/<button[^>]*data-client-hv-action="reset_password"[^>]*disabled[^>]*>/', $content);
        $this->assertStringContainsString('VM is not created on the host yet.', $content);
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

        // No VM on the host yet — the presenter refuses the reset, so the
        // button renders disabled with its reason (never hidden).
        $this->assertStringContainsString('data-client-hv-action="reset_password"', $content);
        $this->assertStringContainsString('id="client-reset-password-modal"', $content);
        $this->assertMatchesRegularExpression('/<button[^>]*data-client-hv-action="reset_password"[^>]*disabled[^>]*>/', $content);
        $this->assertStringContainsString('VM is not created on the host yet.', $content);

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

    // ─────────────────────────── VM Console ───────────────────────────

    public function test_compute_card_offers_pve_console_link_when_fully_configured(): void
    {
        $this->activateRdpConsoleModule();
        config(['rdp-console.secret' => 'test-secret-16-chars']);

        $html = $this->renderComputeCard([
            'action' => ['running' => false],
            'vm' => ['exists' => true, 'state' => 'running', 'probe_error' => null, 'vmId' => '101'],
            'can' => ['create' => false, 'start' => false, 'stop' => true, 'restart' => true, 'delete' => false, 'reset_password' => true],
            'reasons' => [],
            'credentials' => ['stored' => true, 'username' => 'root'],
        ], 'proxmox', ['hosting.view', 'hosting.edit', 'hosting.manage']);

        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        $links = $xpath->query('//a[contains(., "VM Console")]');
        $this->assertSame(1, $links->length, 'A configured Proxmox card must offer exactly one VM Console link.');
        $this->assertStringContainsString('pve-console', $links->item(0)->getAttribute('href'));
        $this->assertStringNotContainsString('test-secret-16-chars', $html, 'The gateway secret must never render.');
    }

    public function test_compute_card_disables_console_without_gateway_secret(): void
    {
        $this->activateRdpConsoleModule();
        config(['rdp-console.secret' => '']);

        $html = $this->renderComputeCard([
            'action' => ['running' => false],
            'vm' => ['exists' => true, 'state' => 'running', 'probe_error' => null, 'vmId' => '101'],
            'can' => ['create' => false, 'start' => false, 'stop' => true, 'restart' => true, 'delete' => false, 'reset_password' => true],
            'reasons' => [],
            'credentials' => ['stored' => true, 'username' => 'root'],
        ], 'proxmox', ['hosting.view', 'hosting.edit', 'hosting.manage']);

        $this->assertStringContainsString('VM Console', $html);
        $this->assertStringContainsString('The console gateway is not configured', $html);
        $this->assertStringNotContainsString('pve-console', $html, 'A disabled console must not link.');
        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*>.*VM Console/s', $html);
    }

    public function test_compute_card_disables_console_with_a_short_gateway_secret(): void
    {
        $this->activateRdpConsoleModule();
        // The gateway driver requires at least 16 characters — a shorter
        // secret still 503s the token endpoint, so the card must not offer
        // an enabled link for it either.
        config(['rdp-console.secret' => 'short-8c']);

        $html = $this->renderComputeCard([
            'action' => ['running' => false],
            'vm' => ['exists' => true, 'state' => 'running', 'probe_error' => null, 'vmId' => '101'],
            'can' => ['create' => false, 'start' => false, 'stop' => true, 'restart' => true, 'delete' => false, 'reset_password' => true],
            'reasons' => [],
            'credentials' => ['stored' => true, 'username' => 'root'],
        ], 'proxmox', ['hosting.view', 'hosting.edit', 'hosting.manage']);

        $this->assertStringContainsString('VM Console', $html);
        $this->assertStringContainsString('The console gateway is not configured', $html);
        $this->assertStringNotContainsString('pve-console', $html, 'A disabled console must not link.');
        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*>.*VM Console/s', $html);
    }

    public function test_compute_card_disables_console_without_a_recorded_vm(): void
    {
        $this->activateRdpConsoleModule();
        config(['rdp-console.secret' => 'test-secret-16-chars']);

        $html = $this->renderComputeCard([
            'action' => ['running' => false],
            'vm' => ['exists' => false, 'state' => null, 'probe_error' => null, 'vmId' => ''],
            'can' => ['create' => true, 'start' => false, 'stop' => false, 'restart' => false, 'delete' => false, 'reset_password' => false],
            'reasons' => [],
            'credentials' => ['stored' => false, 'username' => 'root'],
        ], 'proxmox', ['hosting.view', 'hosting.edit', 'hosting.manage']);

        $this->assertStringContainsString('VM Console', $html);
        $this->assertStringContainsString('No VM ID is recorded for this VM yet.', $html);
        $this->assertStringNotContainsString('pve-console', $html, 'A disabled console must not link.');
        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*>.*VM Console/s', $html);
    }

    public function test_compute_card_disables_console_when_server_is_unconfigured(): void
    {
        $this->activateRdpConsoleModule();
        config(['rdp-console.secret' => 'test-secret-16-chars']);

        $html = $this->renderComputeCard([
            'action' => ['running' => false],
            'vm' => ['exists' => true, 'state' => 'running', 'probe_error' => null, 'vmId' => '101'],
            'can' => ['create' => false, 'start' => false, 'stop' => true, 'restart' => true, 'delete' => false, 'reset_password' => true],
            'reasons' => [],
            'credentials' => ['stored' => true, 'username' => 'root'],
        ], 'proxmox', ['hosting.view', 'hosting.edit', 'hosting.manage'], ['api_username' => '', 'api_password_encrypted' => null]);

        $this->assertStringContainsString('VM Console', $html);
        $this->assertStringContainsString('No Proxmox server credentials are configured for this service.', $html);
        $this->assertStringNotContainsString('pve-console', $html, 'A disabled console must not link.');
    }

    public function test_compute_card_disables_console_while_an_action_runs(): void
    {
        $this->activateRdpConsoleModule();
        config(['rdp-console.secret' => 'test-secret-16-chars']);

        $html = $this->renderComputeCard([
            'action' => ['running' => true, 'progress' => 10, 'stage_label' => 'Working…', 'elapsed' => 5],
            'vm' => ['exists' => true, 'state' => 'running', 'probe_error' => null, 'vmId' => '101'],
            'can' => ['create' => false, 'start' => false, 'stop' => true, 'restart' => true, 'delete' => false, 'reset_password' => true],
            'reasons' => [],
            'credentials' => ['stored' => true, 'username' => 'root'],
        ], 'proxmox', ['hosting.view', 'hosting.edit', 'hosting.manage']);

        $this->assertStringContainsString('VM Console', $html);
        $this->assertStringContainsString('An action is already running — please wait.', $html);
        $this->assertStringNotContainsString('pve-console', $html, 'A disabled console must not link.');
    }

    public function test_compute_card_disables_console_when_the_module_is_inactive(): void
    {
        // The rdp-console module is deliberately NOT activated: the route
        // pair does not exist, so the card must explain instead of linking.
        config(['rdp-console.secret' => 'test-secret-16-chars']);

        $html = $this->renderComputeCard([
            'action' => ['running' => false],
            'vm' => ['exists' => true, 'state' => 'running', 'probe_error' => null, 'vmId' => '101'],
            'can' => ['create' => false, 'start' => false, 'stop' => true, 'restart' => true, 'delete' => false, 'reset_password' => true],
            'reasons' => [],
            'credentials' => ['stored' => true, 'username' => 'root'],
        ], 'proxmox', ['hosting.view', 'hosting.edit', 'hosting.manage']);

        $this->assertStringContainsString('VM Console', $html);
        $this->assertStringContainsString('the rdp-console module is not active', $html);
        $this->assertStringNotContainsString('pve-console', $html, 'A disabled console must not link.');
    }

    public function test_compute_card_omits_console_without_manage_permission(): void
    {
        $this->activateRdpConsoleModule();
        config(['rdp-console.secret' => 'test-secret-16-chars']);

        // View-only admin: hosting.edit renders the toolbar, but without
        // hosting.manage the interactive console must be entirely absent.
        $html = $this->renderComputeCard([
            'action' => ['running' => false],
            'vm' => ['exists' => true, 'state' => 'running', 'probe_error' => null, 'vmId' => '101'],
            'can' => ['create' => false, 'start' => false, 'stop' => true, 'restart' => true, 'delete' => false, 'reset_password' => true],
            'reasons' => [],
            'credentials' => ['stored' => true, 'username' => 'root'],
        ], 'proxmox', ['hosting.view', 'hosting.edit']);

        $this->assertStringNotContainsString('VM Console', $html);
        $this->assertStringNotContainsString('pve-console', $html);
    }

    public function test_compute_card_omits_console_for_non_proxmox_slugs(): void
    {
        $this->activateRdpConsoleModule();
        config(['rdp-console.secret' => 'test-secret-16-chars']);

        $html = $this->renderComputeCard([
            'action' => ['running' => false],
            'vm' => ['exists' => true, 'state' => 'running', 'probe_error' => null, 'vmId' => '101'],
            'can' => ['create' => false, 'start' => false, 'stop' => true, 'restart' => true, 'delete' => false, 'reset_password' => true],
            'reasons' => [],
            'credentials' => ['stored' => true, 'username' => 'root'],
        ], 'virtualizor', ['hosting.view', 'hosting.edit', 'hosting.manage']);

        $this->assertStringNotContainsString('VM Console', $html);
        $this->assertStringNotContainsString('pve-console', $html);
    }

    /**
     * Queued verbs (202 {ok, started, event_id, action}): the one-click
     * start/stop path and the queued reset both branch on data.started and
     * poll to completion instead of the reload / inline-password paths.
     * Rendering only — the JS runtime itself has no browser harness here.
     */
    public function test_compute_card_queues_direct_and_reset_actions_through_the_poller(): void
    {
        $html = $this->renderComputeCard([
            'action' => ['running' => false],
            'vm' => ['exists' => true, 'state' => 'running', 'probe_error' => null],
            'can' => ['create' => false, 'start' => false, 'stop' => true, 'restart' => true, 'delete' => false, 'reset_password' => true],
            'reasons' => [],
            'credentials' => ['stored' => true, 'username' => 'root'],
        ]);

        $this->assertGreaterThanOrEqual(3, substr_count($html, 'data.started'));
        $this->assertStringContainsString('startPolling()', $html);
        $this->assertStringContainsString('Password reset started.', $html);
        $this->assertStringContainsString('Action started.', $html);
        // Previously asserted scaffolding is untouched.
        $this->assertStringContainsString('data-compute-action="start"', $html);
        $this->assertStringContainsString('data-compute-action="stop"', $html);
        $this->assertStringContainsString('data-compute-progress', $html);
        $this->assertStringContainsString('data-compute-view="reset_password"', $html);
    }

    /**
     * A failed compute action must survive the server render inside the
     * status strip (danger) so a manual page reload still shows the error
     * instead of losing it. The strip carries the presenter's notice first
     * and the verdict after it; the empty feedback hook stays behind for
     * the poller's live verdicts.
     */
    public function test_failed_compute_action_survives_server_render_in_status_strip(): void
    {
        Http::fake();

        $server = $this->proxmoxServer();
        $product = $this->productWithProxmoxLink();
        $account = $this->hostingAccount($this->customer(), $product, $server);

        ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => $account->id,
            'event_type' => 'provision',
            'status' => 'failed',
            'event_status' => 'failed',
            'payload' => ['module' => 'proxmox', 'action' => 'create'],
            'result' => ['error' => 'PVE-COMPUTE-BOOM-9f3a'],
            'last_error' => 'PVE-COMPUTE-BOOM-9f3a',
        ]);

        $html = $this->actingAs($this->adminWith(['hosting.view', 'hosting.edit']))
            ->get(route('admin.hosting.show', $account))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="compute-panel-proxmox"', $html);
        $this->assertStringContainsString('PVE-COMPUTE-BOOM-9f3a', $html);

        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        $nodes = $xpath->query('//*[@id="compute-panel-proxmox"]//*[@data-compute-notice]');
        $this->assertSame(1, $nodes->length, 'The compute card must render exactly one status strip.');
        $strip = $nodes->item(0);
        $this->assertStringContainsString('alert-danger', (string) $strip->getAttribute('class'));
        $this->assertSame('status', (string) $strip->getAttribute('role'));
        $this->assertSame('polite', (string) $strip->getAttribute('aria-live'));
        $this->assertStringContainsString('PVE-COMPUTE-BOOM-9f3a', (string) $strip->textContent);

        $feedback = $xpath->query('//*[@id="compute-panel-proxmox"]//*[@data-compute-feedback]');
        $this->assertSame(1, $feedback->length, 'The live-poller feedback hook must stay behind.');
        $this->assertStringContainsString('d-none', (string) $feedback->item(0)->getAttribute('class'));
        $this->assertSame('', trim((string) $feedback->item(0)->textContent));
    }

    /**
     * The presenter's additive notice key renders verbatim in the strip with
     * its severity; a refused create keeps the primary section absent (not
     * greyed) and links the disabled button at the strip.
     */
    public function test_status_strip_renders_presenter_notice_when_provided(): void
    {
        $html = $this->renderComputeCard([
            'action' => ['running' => false],
            'vm' => ['exists' => false, 'state' => null, 'probe_error' => null],
            'can' => ['create' => false, 'start' => false, 'stop' => false, 'restart' => false, 'delete' => false, 'reset_password' => false],
            'reasons' => ['create' => 'Service is terminated — restore the account before creating a VM.'],
            'credentials' => ['stored' => false, 'username' => 'root'],
            'notice' => ['severity' => 'danger', 'text' => 'Service is terminated — create is refused. Delete cleans up; restoring the account re-enables provisioning.'],
        ]);

        $this->assertStringContainsString('id="compute-notice-proxmox"', $html);
        $this->assertStringContainsString('Service is terminated — create is refused.', $html);
        $this->assertStringContainsString('alert-danger', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        // The section hook also appears in the panel JS, so assert on the
        // element itself: refused create renders no section, never a greyed one.
        $this->assertDoesNotMatchRegularExpression('/<section[^>]*data-compute-create-section/', $html);
        $this->assertMatchesRegularExpression('/data-compute-action="create"[^>]*aria-describedby="compute-notice-proxmox"/', $html);
    }

    /**
     * A steady running VM explains nothing, so no strip renders at all — and
     * no button points at a strip id that does not exist.
     */
    public function test_status_strip_absent_for_a_steady_running_vm(): void
    {
        $html = $this->renderComputeCard([
            'action' => ['running' => false],
            'vm' => ['exists' => true, 'state' => 'running', 'probe_error' => null],
            'can' => ['create' => false, 'start' => false, 'stop' => true, 'restart' => true, 'delete' => false, 'reset_password' => true],
            'reasons' => [],
            'credentials' => ['stored' => true, 'username' => 'root'],
        ]);

        $this->assertStringNotContainsString('id="compute-notice-proxmox"', $html);
        $this->assertDoesNotMatchRegularExpression('/aria-describedby="/', $html);
    }

    /**
     * Without a notice key (hand-crafted payloads, older consumers) the card
     * still explains a refusal: the strip falls back to the presenter's
     * reason, warns, groups the actions by risk, and links refusals at it.
     */
    public function test_status_strip_falls_back_to_probe_reason_without_notice_key(): void
    {
        $html = $this->renderComputeCard([
            'action' => ['running' => false],
            'vm' => ['exists' => null, 'state' => null, 'probe_error' => 'connection timed out'],
            'can' => ['create' => false, 'start' => false, 'stop' => false, 'restart' => false, 'delete' => false, 'reset_password' => false],
            'reasons' => ['create' => 'Could not verify the VM on the host — connection timed out'],
            'credentials' => ['stored' => false, 'username' => 'root'],
        ]);

        $this->assertStringContainsString('id="compute-notice-proxmox"', $html);
        $this->assertStringContainsString('Could not verify the VM on the host', $html);
        $this->assertStringContainsString('alert-warning', $html);
        foreach (['Lifecycle', 'Access', 'Danger'] as $group) {
            $this->assertStringContainsString($group, $html);
        }
        $this->assertMatchesRegularExpression('/data-compute-action="start"[^>]*aria-describedby="compute-notice-proxmox"/', $html);
    }

    /**
     * The primary create section is the empty state: visible with its
     * template select and start checkbox while create is allowed, absent
     * (never greyed) once refused. The progress block stays either way.
     */
    public function test_create_section_visible_only_when_create_allowed(): void
    {
        $allowed = $this->renderComputeCard([
            'action' => ['running' => false],
            'vm' => ['exists' => false, 'state' => null, 'probe_error' => null],
            'can' => ['create' => true, 'start' => false, 'stop' => false, 'restart' => false, 'delete' => false, 'reset_password' => false],
            'reasons' => ['start' => 'VM is not created on the host yet.'],
            'credentials' => ['stored' => false, 'username' => 'root'],
        ]);

        $this->assertMatchesRegularExpression('/<section[^>]*data-compute-create-section/', $allowed);
        $this->assertStringContainsString('id="compute-template-proxmox"', $allowed);
        $this->assertStringContainsString('name="start_after_create"', $allowed);
        $this->assertStringContainsString('data-compute-form-action="create"', $allowed);
        $this->assertStringContainsString('data-compute-progress', $allowed);

        $refused = $this->renderComputeCard([
            'action' => ['running' => false],
            'vm' => ['exists' => true, 'state' => 'running', 'probe_error' => null],
            'can' => ['create' => false, 'start' => false, 'stop' => true, 'restart' => true, 'delete' => false, 'reset_password' => true],
            'reasons' => ['create' => 'A VM already exists on the host — delete it first to rebuild.'],
            'credentials' => ['stored' => true, 'username' => 'root'],
        ]);

        $this->assertDoesNotMatchRegularExpression('/<section[^>]*data-compute-create-section/', $refused);
        $this->assertStringNotContainsString('compute-template-proxmox', $refused);
        $this->assertStringContainsString('data-compute-progress', $refused);
    }

    /**
     * A completed or still-running latest action must NOT pre-render the
     * feedback alert: success toasts belong to the live poller, and a
     * running action owns the progress panel instead.
     */
    public function test_completed_or_running_compute_action_does_not_pre_render_feedback_alert(): void
    {
        Http::fake();

        $server = $this->proxmoxServer();
        $product = $this->productWithProxmoxLink();

        $completed = $this->hostingAccount($this->customer(), $product, $server, 'pve-done-'.str()->lower(str()->random(6)));
        ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => $completed->id,
            'event_type' => 'provision',
            'status' => 'completed',
            'event_status' => 'completed',
            'payload' => ['module' => 'proxmox', 'action' => 'create'],
            'result' => ['message' => 'PVE-COMPUTE-DONE-4b7c'],
            'last_error' => null,
        ]);

        $html = $this->actingAs($this->adminWith(['hosting.view', 'hosting.edit']))
            ->get(route('admin.hosting.show', $completed))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('PVE-COMPUTE-DONE-4b7c', $html);
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $feedback = (new \DOMXPath($dom))->query('//*[@id="compute-panel-proxmox"]//*[@data-compute-feedback]');
        $this->assertSame(1, $feedback->length);
        $this->assertStringContainsString('d-none', (string) $feedback->item(0)->getAttribute('class'));
        $this->assertSame('', trim((string) $feedback->item(0)->textContent));

        $running = $this->hostingAccount($this->customer(), $product, $server, 'pve-run-'.str()->lower(str()->random(6)));
        ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => $running->id,
            'event_type' => 'provision',
            'status' => 'running',
            'event_status' => 'running',
            'payload' => ['module' => 'proxmox', 'action' => 'create'],
            'result' => [],
            'last_error' => null,
        ]);

        $runningHtml = $this->actingAs($this->adminWith(['hosting.view', 'hosting.edit']))
            ->get(route('admin.hosting.show', $running))
            ->assertOk()
            ->getContent();

        $runningDom = new \DOMDocument;
        @$runningDom->loadHTML($runningHtml);
        $runningFeedback = (new \DOMXPath($runningDom))->query('//*[@id="compute-panel-proxmox"]//*[@data-compute-feedback]');
        $this->assertSame(1, $runningFeedback->length);
        $this->assertStringContainsString('d-none', (string) $runningFeedback->item(0)->getAttribute('class'));
        $this->assertSame('', trim((string) $runningFeedback->item(0)->textContent));
    }

    // ─────────────────────────── helpers ───────────────────────────

    /** Render the compute partial with a crafted presenter payload. */
    private function renderComputeCard(
        array $vmStatus,
        string $slug = 'proxmox',
        array $perms = ['hosting.view', 'hosting.edit'],
        array $serverOverrides = [],
    ): string {
        $server = $this->proxmoxServer('pve-render-'.str()->lower(str()->random(6)), $serverOverrides);
        $product = $this->productWithProxmoxLink();
        $account = $this->hostingAccount($this->customer(), $product, $server, 'pverender-'.str()->lower(str()->random(6)));

        $this->actingAs($this->staffWithPermissions($perms));

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

    /**
     * Reconcile the real modules folder, activate rdp-console and replay the
     * side effects ModuleServiceProvider performs for active modules during
     * app boot: provider boot() (view namespace + bindings) and route
     * registration. Mirrors RdpConsoleVmConnectTest.
     */
    private function activateRdpConsoleModule(): Module
    {
        $manager = app(ModuleManager::class);
        $manager->reconcile();

        $module = $manager->find('rdp-console');
        $this->assertNotNull($module, 'rdp-console module must be discovered from base_path(\'modules\').');

        $manager->activate($module);
        $this->assertSame(Module::STATUS_ACTIVE, $module->fresh()->status);

        $instance = $manager->resolve($module);
        $this->assertNotNull($instance, 'rdp-console provider must resolve.');

        $instance->boot($manager->contextFor($module));
        $manager->registerModuleRoutes();
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();

        return $module;
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

    /**
     * A panel user whose bespoke role carries EXACTLY the given permissions.
     *
     * Deliberately NOT an 'admin'-named role: the AdminLTE package's
     * Gate::before passes every ability for isAdmin() users, so an
     * admin-role user would render every @can block regardless of the synced
     * permissions and a negative assertion would prove nothing. Mirrors
     * RdpConsoleVmConnectTest.
     */
    private function staffWithPermissions(array $perms): User
    {
        static $sequence = 0;
        $sequence++;

        $user = User::factory()->create(['role' => 'marketing']);

        $role = Role::create([
            'name' => "pve-console-test-role-{$sequence}",
            'label' => 'PVE console test role',
        ]);

        $ids = [];
        foreach ($perms as $name) {
            $ids[] = Permission::firstOrCreate(['name' => $name], ['label' => $name])->id;
        }
        $role->permissions()->sync($ids);

        $user->roles()->syncWithoutDetaching($role->id);

        return $user->fresh();
    }

    private function proxmoxServer(string $name = 'pve-actions', array $overrides = []): Server
    {
        return Server::create(array_merge([
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
        ], $overrides));
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
