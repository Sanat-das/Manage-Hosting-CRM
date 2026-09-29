<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PanelAccount;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Modules\Proxmox\Proxmox;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Provisioning\ProxmoxTemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Proxmox VE curated templates: the PVE twin of the Hyper-V catalog.
 *
 * Proxmox identifies a template by VMID (not name), and a clone must address the
 * node the template lives on while targeting the node the new VM lands on — so
 * these tests pin both the catalog semantics and the clone routing.
 */
final class ProxmoxTemplateCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function server(array $meta = [], string $name = 'pve-1'): Server
    {
        return Server::create([
            'name' => $name,
            'ip_address' => '10.100.1.30',
            'server_type' => 'proxmox',
            'api_username' => 'root@pam!automation',
            'api_password_encrypted' => 'TOKEN-SECRET',
            'max_accounts' => 0,
            'status' => 'active',
            'connection_meta' => array_merge([
                'port' => 8006,
                'auth_type' => 'token',
                'verify_tls' => false,
            ], $meta),
        ]);
    }

    private function service(Server $server): ServiceInstance
    {
        $customer = Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);

        return ServiceInstance::create([
            'customer_id' => $customer->id,
            'server_id' => $server->id,
            'service_tag' => 'PVE-TPL-'.random_int(100000, 999999),
            'username' => 'acme',
            'domain' => 'acme.test',
            'provisioning_method' => 'proxmox',
            'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function fakePve(array $overrides = []): void
    {
        // A stray request would otherwise reach a REAL Proxmox host — one of
        // these tests pointed at a live cluster before this was added. Fail
        // loudly on any unfaked URL instead.
        Http::preventStrayRequests();

        Http::fake(array_merge($overrides, [
            '*/api2/json/cluster/nextid?vmid=*' => Http::response(['data' => '900']),
            '*/api2/json/cluster/nextid' => Http::response(['data' => '900']),
            '*/api2/json/nodes' => Http::response(['data' => [
                ['node' => 'pve1', 'status' => 'online'],
                ['node' => 'pve2', 'status' => 'online'],
            ]]),
            '*/api2/json/nodes/pve1/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
            '*/api2/json/nodes/pve2/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
            '*/api2/json/nodes/pve1/storage' => Http::response(['data' => [
                ['storage' => 'local-zfs', 'type' => 'zfspool', 'active' => 1, 'avail' => 40000000000, 'content' => 'rootfs,images'],
            ]]),
            '*/api2/json/nodes/pve2/storage' => Http::response(['data' => [
                ['storage' => 'local-zfs', 'type' => 'zfspool', 'active' => 1, 'avail' => 40000000000, 'content' => 'rootfs,images'],
            ]]),
            // Empty-VM builds POST here.
            '*/api2/json/nodes/pve1/qemu' => Http::response(['data' => null]),
            '*/api2/json/nodes/pve2/qemu' => Http::response(['data' => null]),
            // Template 110 lives on pve1; template 113 lives on pve2.
            '*/api2/json/nodes/pve1/qemu/110/status/current' => Http::response(['data' => ['status' => 'stopped']]),
            '*/api2/json/nodes/pve2/qemu/110/status/current' => Http::response(['message' => 'Configuration file \'nodes/pve2/qemu-server/110.conf\' does not exist'], 500),
            '*/api2/json/nodes/pve2/qemu/113/status/current' => Http::response(['data' => ['status' => 'stopped']]),
            '*/api2/json/nodes/pve1/qemu/113/status/current' => Http::response(['message' => 'Configuration file \'nodes/pve1/qemu-server/113.conf\' does not exist'], 500),
            '*/api2/json/nodes/pve1/qemu/112/status/current' => Http::response(['message' => 'Configuration file \'nodes/pve1/qemu-server/112.conf\' does not exist'], 500),
            '*/api2/json/nodes/pve2/qemu/112/status/current' => Http::response(['data' => ['status' => 'stopped']]),
            // 4242 does not exist anywhere.
            '*/api2/json/nodes/pve1/qemu/4242/status/current' => Http::response(['message' => 'Configuration file does not exist'], 500),
            '*/api2/json/nodes/pve2/qemu/4242/status/current' => Http::response(['message' => 'Configuration file does not exist'], 500),
            '*/api2/json/nodes/pve1/qemu/900/status/current' => Http::response(['data' => ['status' => 'running']]),
            '*/api2/json/nodes/pve2/qemu/900/status/current' => Http::response(['message' => 'does not exist'], 500),
            '*qemu/*/clone' => Http::response(['data' => 'UPID:clone']),
            '*qemu/*/config' => Http::response(['data' => ['scsi0' => 'local-zfs:20,size=20G']]),
            '*qemu/*/resize' => Http::response(['data' => 'UPID:resize']),
            '*qemu/*/status/start' => Http::response(['data' => 'UPID:start']),
            '*qemu/*/status/current' => Http::response(['data' => ['status' => 'running']]),
        ]));
    }

    // ─────────────────────────────── catalog ───────────────────────────────

    public function test_sanitize_keeps_only_usable_entries_and_falls_back_to_vmid_labels(): void
    {
        $out = Server::sanitizeProxmoxTemplates([
            ['vmid' => 110, 'node' => 'pve1', 'label' => 'Alma 9'],
            ['vmid' => '110', 'node' => 'pve2', 'label' => 'duplicate dropped'],
            ['vmid' => 113, 'node' => 'pve2'],                    // no label -> vmid
            ['vmid' => 'abc'],                                     // not numeric
            ['vmid' => 0],                                         // not a positive id
            'not-an-array',
        ]);

        $this->assertSame([
            ['vmid' => '110', 'node' => 'pve1', 'label' => 'Alma 9'],
            ['vmid' => '113', 'node' => 'pve2', 'label' => '113'],
        ], $out);
    }

    public function test_union_dedupes_by_vmid_across_servers_and_sorts_by_label(): void
    {
        $this->server(['proxmox_templates' => [
            ['vmid' => '113', 'node' => 'pve2', 'label' => 'Zulu'],
            ['vmid' => '110', 'node' => 'pve1', 'label' => 'Alpha'],
        ]], 'pve-a');

        // Second server on the same cluster curates the same VMID plus a new one.
        $this->server(['proxmox_templates' => [
            ['vmid' => '113', 'node' => 'pve2', 'label' => 'Zulu duplicate'],
            ['vmid' => '112', 'node' => 'pve2', 'label' => 'Mike'],
        ]], 'pve-b');

        $union = ProxmoxTemplateCatalog::unionOptions();

        $this->assertSame(['110', '112', '113'], array_column($union, 'vmid'));
        // First occurrence wins for the duplicate VMID.
        $this->assertSame('Zulu', $union[2]['label']);
        $this->assertSame(['110', '112', '113'], ProxmoxTemplateCatalog::unionVmIds());
    }

    public function test_inactive_servers_are_excluded_from_the_union(): void
    {
        $server = $this->server(['proxmox_templates' => [['vmid' => '110', 'node' => 'pve1', 'label' => 'A']]]);
        $server->update(['status' => 'inactive']);

        $this->assertSame([], ProxmoxTemplateCatalog::unionOptions());
    }

    public function test_default_is_only_reported_while_it_is_still_curated(): void
    {
        $server = $this->server([
            'proxmox_templates' => [['vmid' => '110', 'node' => 'pve1', 'label' => 'A']],
            'proxmox_template_default' => '110',
        ]);

        $this->assertSame('110', $server->proxmoxDefaultTemplate());

        // Removing the template from the curated list must drop the default too.
        $server->update(['connection_meta' => [
            'port' => 8006, 'auth_type' => 'token', 'verify_tls' => false,
            'proxmox_templates' => [['vmid' => '113', 'node' => 'pve2', 'label' => 'B']],
            'proxmox_template_default' => '110',
        ]]);

        $this->assertNull($server->fresh()->proxmoxDefaultTemplate());
    }

    public function test_sanitize_allowed_rejects_non_numeric_values(): void
    {
        $this->assertSame(['110', '113'], ProxmoxTemplateCatalog::sanitizeAllowed(['110', '113', 'abc', '', '0', '-5', '11.5']));
    }

    public function test_effective_templates_applies_the_product_restriction(): void
    {
        $server = $this->server(['proxmox_templates' => [
            ['vmid' => '110', 'node' => 'pve1', 'label' => 'A'],
            ['vmid' => '113', 'node' => 'pve2', 'label' => 'B'],
        ]]);

        // Empty allow-list = everything curated.
        $this->assertSame(['110', '113'], array_column(ProxmoxTemplateCatalog::effectiveTemplates($server, []), 'vmid'));
        // Restricted to a subset.
        $this->assertSame(['113'], array_column(ProxmoxTemplateCatalog::effectiveTemplates($server, ['113']), 'vmid'));
        // Allowed but not curated on this server = nothing.
        $this->assertSame([], ProxmoxTemplateCatalog::effectiveTemplates($server, ['999']));
    }

    // ────────────────────────── provision enforcement ──────────────────────────

    private function proxmoxProduct(Server $server, array $config): Product
    {
        $product = Product::create([
            'name' => 'PVE Plan '.random_int(1, 99999),
            'price' => 100,
            'provisioning_module' => 'proxmox',
        ]);

        ProductModule::create([
            'product_id' => $product->id,
            'module_slug' => 'proxmox',
            'enabled' => true,
            'config' => $config,
        ]);

        return $product;
    }

    public function test_a_restricted_product_cannot_clone_a_template_outside_its_tier(): void
    {
        $server = $this->server(['proxmox_templates' => [
            ['vmid' => '110', 'node' => 'pve1', 'label' => 'Gold'],
            ['vmid' => '113', 'node' => 'pve2', 'label' => 'Budget'],
        ]]);

        $this->fakePve();

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2, 'ram' => 2048, 'disk' => 20,
            // Pinned to Budget, but the product is restricted to Gold only.
            'template_vmid' => '113',
            'allowed_templates' => ['110'],
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not available to this product', $result->message);
        $this->assertStringContainsString('110', $result->message);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/clone'));
        $this->assertSame(0, PanelAccount::count());
    }

    public function test_an_allowed_template_is_cloned_on_its_own_node_targeting_the_provision_node(): void
    {
        $server = $this->server(['proxmox_templates' => [
            ['vmid' => '113', 'node' => 'pve2', 'label' => 'Budget'],
        ]]);

        $this->fakePve();

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2, 'ram' => 2048, 'disk' => 20,
            'template_vmid' => '113',
            'node' => 'pve1',
        ]);

        $this->assertTrue($result->success, $result->message);

        // The clone is POSTed to the TEMPLATE's node (pve2) and targets pve1.
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/nodes/pve2/qemu/113/clone')
            && str_contains($r->body(), '"target":"pve1"'));

        $account = PanelAccount::sole();
        $this->assertSame('pve2', $account->meta['meta']['template_node']);
        $this->assertSame('113', $account->meta['meta']['template_vmid']);
        $this->assertSame('pve1', $account->meta['meta']['node']);
    }

    public function test_the_server_default_is_used_when_the_product_names_no_template(): void
    {
        $server = $this->server([
            'proxmox_templates' => [['vmid' => '110', 'node' => 'pve1', 'label' => 'Gold']],
            'proxmox_template_default' => '110',
        ]);

        $this->fakePve();

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2, 'ram' => 2048, 'disk' => 20,
        ]);

        $this->assertTrue($result->success, $result->message);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/nodes/pve1/qemu/110/clone'));
    }

    /**
     * A default that the product's restriction excludes must NOT be used — the
     * default can never widen a restriction.
     */
    public function test_a_restricted_default_is_not_used_and_the_provision_fails_loudly(): void
    {
        $server = $this->server([
            'proxmox_templates' => [
                ['vmid' => '110', 'node' => 'pve1', 'label' => 'Gold'],
                ['vmid' => '113', 'node' => 'pve2', 'label' => 'Budget'],
            ],
            'proxmox_template_default' => '110',
        ]);

        $this->fakePve();

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2, 'ram' => 2048, 'disk' => 20,
            'allowed_templates' => ['113'],
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('No template is selected', $result->message);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/clone'));
        $this->assertSame(0, PanelAccount::count());
    }

    public function test_an_uncu_rated_server_still_honours_an_explicit_vmid(): void
    {
        // No curation at all — the plain-VMID workflow must keep working.
        $server = $this->server();

        $this->fakePve();

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2, 'ram' => 2048, 'disk' => 20,
            'template_vmid' => '113',
            'node' => 'pve1',
        ]);

        $this->assertTrue($result->success, $result->message);
        // Its node was resolved live, so the clone addresses pve2.
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/nodes/pve2/qemu/113/clone'));
    }

    public function test_an_uncu_rated_server_with_no_template_builds_an_empty_vm(): void
    {
        $server = $this->server();

        $this->fakePve();

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2, 'ram' => 2048, 'disk' => 20,
            'node' => 'pve1',
        ]);

        $this->assertTrue($result->success, $result->message);
        $this->assertStringContainsString('still needs an OS install', $result->message);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/clone'));
    }

    public function test_an_unknown_explicit_vmid_fails_with_a_clear_message(): void
    {
        $server = $this->server();

        $this->fakePve();

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2, 'ram' => 2048, 'disk' => 20,
            'template_vmid' => '4242',
            'node' => 'pve1',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('4242', $result->message);
        $this->assertStringContainsString('was not found on any node', $result->message);
    }

    /**
     * A curated entry saved without a node is resolved live; when it cannot be
     * found, the error must name the server. The closure used to reference an
     * uncaptured `$server`, so this branch raised "Undefined variable $server"
     * instead of the real reason.
     */
    public function test_an_unresolvable_curated_template_names_the_server_in_the_error(): void
    {
        $server = $this->server(['proxmox_templates' => [
            ['vmid' => '777', 'node' => '', 'label' => 'ghost'],
        ]]);

        // The template is not on either node.
        $this->fakePve([
            '*/api2/json/nodes/pve1/qemu/777/status/current' => Http::response(['message' => 'Configuration file does not exist'], 500),
            '*/api2/json/nodes/pve2/qemu/777/status/current' => Http::response(['message' => 'Configuration file does not exist'], 500),
        ]);

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2, 'ram' => 2048, 'disk' => 20,
            'template_vmid' => '777',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('cannot be located on any node', $result->message);
        $this->assertStringContainsString('pve-1', $result->message);
        $this->assertStringNotContainsString('Undefined variable', $result->message);
    }

    /**
     * When the product's restriction excludes every curated template, the
     * "Pick one of: " list renders empty — the error must say why.
     */
    public function test_a_product_restricted_to_none_gets_a_clear_error_when_no_template_is_selected(): void
    {
        $server = $this->server(['proxmox_templates' => [
            ['vmid' => '110', 'node' => 'pve1', 'label' => 'Gold'],
        ]]);

        $this->fakePve();

        $result = (new Proxmox)->provision($this->service($server), [
            'cpu' => 2, 'ram' => 2048, 'disk' => 20,
            'allowed_templates' => ['999'],
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('restricted to none', $result->message);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/clone'));
    }

    // ───────────────────── product restriction endpoint ─────────────────────

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

    public function test_the_restriction_endpoint_saves_for_proxmox_preserving_other_config(): void
    {
        $this->server(['proxmox_templates' => [
            ['vmid' => '110', 'node' => 'pve1', 'label' => 'Gold'],
            ['vmid' => '113', 'node' => 'pve2', 'label' => 'Budget'],
        ]], 'pve-a');

        $product = $this->proxmoxProduct($this->server(), [
            'cpu' => 2, 'ram' => 2048, 'disk' => 20, 'node' => 'pve1',
        ]);

        $this->actingAs($this->adminWith(['products.edit']))
            ->put(route('admin.products.modules.templates', [$product, 'proxmox']), [
                'allowed_templates' => ['110'],
            ])
            ->assertRedirect(route('admin.products.edit', [$product, 'tab' => 'modules']));

        $pivot = ProductModule::where('product_id', $product->id)->where('module_slug', 'proxmox')->firstOrFail();
        $dec = app(IntegrationRegistry::class)->decryptConfigFor('proxmox', $pivot->config);

        $this->assertSame(['110'], $dec['allowed_templates']);
        // Unrelated config survives the merge-save.
        $this->assertSame(2, $dec['cpu']);
        $this->assertSame(2048, $dec['ram']);
        $this->assertSame('pve1', $dec['node']);
    }

    public function test_the_restriction_endpoint_rejects_an_unknown_vmid(): void
    {
        $this->server(['proxmox_templates' => [['vmid' => '110', 'node' => 'pve1', 'label' => 'Gold']]]);
        $product = $this->proxmoxProduct($this->server(), ['cpu' => 2, 'ram' => 2048, 'disk' => 20]);

        $this->actingAs($this->adminWith(['products.edit']))
            ->put(route('admin.products.modules.templates', [$product, 'proxmox']), [
                'allowed_templates' => ['999'],
            ])
            ->assertSessionHasErrors('allowed_templates');
    }

    public function test_the_restriction_endpoint_rejects_a_non_numeric_vmid(): void
    {
        $this->server(['proxmox_templates' => [['vmid' => '110', 'node' => 'pve1', 'label' => 'Gold']]]);
        $product = $this->proxmoxProduct($this->server(), ['cpu' => 2, 'ram' => 2048, 'disk' => 20]);

        // 'evil-tpl' sanitizes away to nothing, so the save is a no-op clearing —
        // it must never be stored as a template id.
        $this->actingAs($this->adminWith(['products.edit']))
            ->put(route('admin.products.modules.templates', [$product, 'proxmox']), [
                'allowed_templates' => ['evil-tpl'],
            ]);

        $pivot = ProductModule::where('product_id', $product->id)->where('module_slug', 'proxmox')->firstOrFail();
        $dec = app(IntegrationRegistry::class)->decryptConfigFor('proxmox', $pivot->config);

        $this->assertArrayNotHasKey('allowed_templates', $dec);
    }

    public function test_the_product_edit_page_renders_the_proxmox_template_card(): void
    {
        $this->server(['proxmox_templates' => [
            ['vmid' => '110', 'node' => 'pve1', 'label' => 'Alma Gold'],
        ]], 'pve-a');

        $product = $this->proxmoxProduct($this->server(), ['cpu' => 2, 'ram' => 2048, 'disk' => 20]);

        $this->actingAs($this->adminWith(['products.edit', 'products.view']))
            ->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('Proxmox VE templates (this product)', false)
            ->assertSee('Alma Gold', false)
            ->assertSee('value="110"', false)
            ->assertSee('Restrict to selected', false)
            ->assertSee('All curated templates', false)
            ->assertSee(route('admin.products.modules.templates', [$product, 'proxmox']), false);
    }

    // ───────────────────────── server curation round-trip ─────────────────────────

    public function test_curating_templates_on_the_server_persists_them_with_labels_and_default(): void
    {
        $server = $this->server();

        $this->actingAs($this->adminWith(['hosting.manage']))
            ->put(route('admin.servers.update', $server), [
                'name' => $server->name,
                'status' => 'active',
                'server_type' => 'proxmox',
                'ip_address' => '10.100.1.30',
                'host' => '10.100.1.30',
                'port' => 8006,
                'auth_type' => 'token',
                'api_username' => 'root@pam!automation',
                'proxmox_templates_present' => '1',
                'proxmox_templates_selected' => ['110', '113'],
                'proxmox_nodes' => ['110' => 'pve1', '113' => 'pve2'],
                'proxmox_labels' => ['110' => 'Alma Gold', '113' => ''],
                'proxmox_template_default' => '110',
            ])
            ->assertRedirect();

        $fresh = $server->fresh();

        $this->assertSame([
            ['vmid' => '110', 'node' => 'pve1', 'label' => 'Alma Gold'],
            ['vmid' => '113', 'node' => 'pve2', 'label' => '113'],
        ], $fresh->proxmoxTemplates());

        $this->assertSame('110', $fresh->proxmoxDefaultTemplate());
        // Credentials never land in the meta.
        $this->assertArrayNotHasKey('api_key', $fresh->connection_meta);
        $this->assertArrayNotHasKey('api_password', $fresh->connection_meta);
    }

    /**
     * The edit form renders no transport inputs for Proxmox (port/auth_type/
     * verify_tls come from the create schema), so a real save from
     * /admin/servers/{id}/edit carries the curation payload only. The meta gate
     * used to require a transport key, which made the picked default vanish on
     * every save from that page.
     */
    public function test_the_edit_form_saves_the_default_template_without_transport_fields(): void
    {
        $server = $this->server();

        $this->actingAs($this->adminWith(['hosting.manage']))
            ->put(route('admin.servers.update', $server), [
                'name' => $server->name,
                'status' => 'active',
                'server_type' => 'proxmox',
                'ip_address' => '10.100.1.30',
                'proxmox_templates_present' => '1',
                'proxmox_templates_selected' => ['110', '113'],
                'proxmox_nodes' => ['110' => 'pve1', '113' => 'pve2'],
                'proxmox_labels' => ['110' => 'Alma Gold', '113' => ''],
                'proxmox_template_default' => '110',
            ])
            ->assertRedirect();

        $fresh = $server->fresh();

        $this->assertSame('110', $fresh->proxmoxDefaultTemplate());
        // Transport prefs saved earlier must survive a curation-only save.
        $this->assertSame(8006, $fresh->connection_meta['port'] ?? null);
        $this->assertSame('token', $fresh->connection_meta['auth_type'] ?? null);
    }

    public function test_clearing_the_curation_removes_templates_and_the_default(): void
    {
        $server = $this->server([
            'proxmox_templates' => [['vmid' => '110', 'node' => 'pve1', 'label' => 'Gold']],
            'proxmox_template_default' => '110',
        ]);

        $this->actingAs($this->adminWith(['hosting.manage']))
            ->put(route('admin.servers.update', $server), [
                'name' => $server->name,
                'status' => 'active',
                'server_type' => 'proxmox',
                'ip_address' => '10.100.1.30',
                'host' => '10.100.1.30',
                'port' => 8006,
                'auth_type' => 'token',
                'proxmox_templates_present' => '1',
                // Nothing ticked: the browser sends no proxmox_templates_selected.
            ])
            ->assertRedirect();

        $fresh = $server->fresh();

        $this->assertSame([], $fresh->proxmoxTemplates());
        $this->assertNull($fresh->proxmoxDefaultTemplate());
    }

    public function test_a_save_without_the_curation_payload_leaves_the_list_untouched(): void
    {
        $server = $this->server([
            'proxmox_templates' => [['vmid' => '110', 'node' => 'pve1', 'label' => 'Gold']],
            'proxmox_template_default' => '110',
        ]);

        // A transport-only edit: no curation fields at all.
        $this->actingAs($this->adminWith(['hosting.manage']))
            ->put(route('admin.servers.update', $server), [
                'name' => 'renamed-pve',
                'status' => 'active',
                'server_type' => 'proxmox',
                'ip_address' => '10.100.1.30',
                'host' => '10.100.1.30',
                'port' => 8006,
                'auth_type' => 'token',
            ])
            ->assertRedirect();

        $fresh = $server->fresh();

        $this->assertSame('renamed-pve', $fresh->name);
        $this->assertSame([['vmid' => '110', 'node' => 'pve1', 'label' => 'Gold']], $fresh->proxmoxTemplates());
        $this->assertSame('110', $fresh->proxmoxDefaultTemplate());
    }

    public function test_the_server_edit_page_renders_the_curation_picker(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/api2/json/nodes' => Http::response(['data' => [['node' => 'pve1', 'status' => 'online']]]),
            '*/api2/json/access/permissions' => Http::response(['data' => ['/vms' => ['VM.Audit' => 1]]]),
            '*/api2/json/nodes/pve1/qemu' => Http::response(['data' => [
                ['vmid' => 110, 'name' => 'Alma9Template', 'status' => 'stopped', 'template' => 1],
                ['vmid' => 900, 'name' => 'not-a-template', 'status' => 'running', 'template' => 0],
            ]]),
            '*/api2/json/cluster/resources*' => Http::response(['data' => []]),
        ]);

        $server = $this->server();

        $this->actingAs($this->adminWith(['hosting.manage']))
            ->get(route('admin.servers.edit', $server))
            ->assertOk()
            ->assertSee('Clone templates', false)
            ->assertSee('Alma9Template', false)
            ->assertSee('proxmox_templates_selected[]', false)
            // A non-template guest must never be offered as a clone source.
            ->assertDontSee('not-a-template', false);
    }

    /**
     * The default picker must not offer a discovered-but-uncurated template:
     * proxmoxConnectionMeta() drops a default outside the curated list, so the
     * option would vanish after the save with no error shown.
     */
    public function test_the_default_template_picker_only_offers_curated_templates(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/api2/json/nodes' => Http::response(['data' => [['node' => 'pve1', 'status' => 'online']]]),
            '*/api2/json/access/permissions' => Http::response(['data' => ['/vms' => ['VM.Audit' => 1]]]),
            '*/api2/json/nodes/pve1/qemu' => Http::response(['data' => [
                ['vmid' => 110, 'name' => 'Alma9Template', 'status' => 'stopped', 'template' => 1],
                ['vmid' => 113, 'name' => 'Copy-of-VM', 'status' => 'stopped', 'template' => 1],
            ]]),
            '*/api2/json/cluster/resources*' => Http::response(['data' => []]),
        ]);

        $server = $this->server(['proxmox_templates' => [
            ['vmid' => '110', 'node' => 'pve1', 'label' => 'Alma Gold'],
        ]]);

        $this->actingAs($this->adminWith(['hosting.manage']))
            ->get(route('admin.servers.edit', $server))
            ->assertOk()
            ->assertSee('<option value="110"', false)
            ->assertDontSee('<option value="113"', false);
    }

    /**
     * The false-green: a privilege-separated token with no ACL sees zero VMs on
     * every node, which used to render as "No templates were discovered" — the
     * same page as a healthy cluster with no templates. It must say why instead.
     */
    public function test_the_server_edit_page_reports_a_discovery_failure_instead_of_no_templates(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/api2/json/nodes' => Http::response(['data' => [['node' => 'pve1', 'status' => 'online']]]),
            '*/api2/json/nodes/pve1/qemu' => Http::response(['data' => []]),
            // Exactly what a privilege-separated token with no ACL returns.
            '*/api2/json/access/permissions' => Http::response(['data' => []]),
            '*/api2/json/cluster/resources*' => Http::response(['data' => []]),
        ]);

        $server = $this->server();

        $this->actingAs($this->adminWith(['hosting.manage']))
            ->get(route('admin.servers.edit', $server))
            ->assertOk()
            ->assertSee('no effective privileges', false)
            ->assertSee('PVEVMAdmin', false)
            ->assertDontSee('No templates were discovered', false);
    }
}
