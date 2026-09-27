<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use App\Modules\Proxmox\Services\ProxmoxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Proxmox Essential Information parity with Hyper-V: the show page renders
 * the transport strip, cluster uptime, RAM/storage bars, compute line, the
 * Proxmox supplement card (cluster/nodes/storage/templates) and the VM node
 * column from one faked cluster; a dead node degrades to its survivors; a
 * privilege-limited credential renders the frozen empty vocabulary, never 0.
 */
final class ServerEssentialProxmoxTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

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

    private function proxmoxServer(array $meta = []): Server
    {
        self::$seq++;

        return Server::create([
            'name' => 'pve-essential-'.self::$seq,
            'ip_address' => '10.0.0.'.(20 + self::$seq),
            'server_type' => 'proxmox',
            'api_url' => 'https://10.0.0.'.(20 + self::$seq).':8006',
            'api_username' => 'root@pam!automation',
            'api_password_encrypted' => 'TOKEN-SECRET',
            'status' => 'active',
            'connection_status' => 'connected',
            'connection_meta' => array_merge([
                'port' => 8006,
                'auth_type' => 'token',
                'verify_tls' => false,
                'proxmox_templates' => [
                    ['vmid' => '900', 'node' => 'pve1', 'label' => 'ubuntu-24'],
                    ['vmid' => '999', 'node' => 'pve1', 'label' => 'missing-one'],
                ],
                'proxmox_template_default' => '900',
            ], $meta),
        ]);
    }

    /**
     * Fakes the PVE surface the Essential block reads. Overrides win:
     * duplicate patterns take the caller's response.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function fakeCluster(array $overrides = []): void
    {
        Http::preventStrayRequests();

        Http::fake(array_merge([
            '*/api2/json/version' => Http::response(['data' => ['version' => '9.0.3', 'release' => '9.0']]),
            '*/api2/json/nodes' => Http::response(['data' => [
                ['node' => 'pve1', 'status' => 'online'],
                ['node' => 'pve2', 'status' => 'online'],
            ]]),
            '*/api2/json/cluster/status' => Http::response(['data' => [
                ['type' => 'cluster', 'name' => 'labcluster', 'quorate' => 1],
                ['type' => 'node', 'name' => 'pve1', 'ip' => '10.0.0.21', 'online' => 1],
                ['type' => 'node', 'name' => 'pve2', 'ip' => '10.0.0.22', 'online' => 1],
            ]]),
            '*/api2/json/nodes/pve1/status' => Http::response(['data' => [
                'uptime' => 200000,
                'cpu' => 0.25,
                'maxcpu' => 16,
                'memory' => ['total' => 68719476736, 'used' => 17179869184, 'free' => 51539607552],
            ]]),
            '*/api2/json/nodes/pve2/status' => Http::response(['data' => [
                'uptime' => 100000,
                'cpu' => 0.5,
                'maxcpu' => 8,
                'memory' => ['total' => 34359738368, 'used' => 8589934592, 'free' => 25769803776],
            ]]),
            '*/api2/json/nodes/pve1/storage' => Http::response(['data' => [
                ['storage' => 'local-zfs', 'type' => 'zfspool', 'active' => 1, 'total' => 500000000000, 'avail' => 200000000000, 'used' => 300000000000, 'content' => 'rootdir,images'],
                ['storage' => 'ceph', 'type' => 'rbd', 'active' => 1, 'total' => 3000000000000, 'avail' => 1000000000000, 'used' => 2000000000000, 'content' => 'rootdir,images'],
            ]]),
            '*/api2/json/nodes/pve2/storage' => Http::response(['data' => [
                ['storage' => 'ceph', 'type' => 'rbd', 'active' => 1, 'total' => 3000000000000, 'avail' => 1000000000000, 'used' => 2000000000000, 'content' => 'rootdir,images'],
                ['storage' => 'local', 'type' => 'dir', 'active' => 1, 'total' => 200000000000, 'avail' => 150000000000, 'used' => 50000000000, 'content' => 'rootdir,images,iso'],
            ]]),
            '*/api2/json/cluster/resources*' => Http::response(['data' => [
                ['vmid' => 101, 'type' => 'qemu', 'name' => 'web-1', 'status' => 'running', 'node' => 'pve1', 'maxmem' => 8589934592, 'maxcpu' => 2, 'cpu' => 0.5, 'mem' => 4294967296, 'maxdisk' => 107374182400, 'uptime' => 90000],
                ['vmid' => 102, 'type' => 'qemu', 'name' => 'db-1', 'status' => 'stopped', 'node' => 'pve2', 'maxmem' => 4294967296, 'maxcpu' => 2, 'cpu' => 0.0, 'mem' => 0, 'maxdisk' => 53687091200, 'uptime' => 0],
                ['vmid' => 900, 'type' => 'qemu', 'name' => 'ubuntu-24-template', 'status' => 'stopped', 'node' => 'pve1', 'template' => 1],
            ]]),
            '*/api2/json/access/permissions' => Http::response(['data' => ['/vms' => ['VM.Audit' => 1]]]),
        ], $overrides));
    }

    public function test_connected_show_renders_the_full_essential_block(): void
    {
        $server = $this->proxmoxServer();
        $this->fakeCluster();

        $html = $this->actingAs($this->adminWith(['hosting.view', 'hosting.manage']))
            ->get(route('admin.servers.show', $server))
            ->assertOk()
            ->getContent();

        // Transport strip: host:port code + Token badge + TLS warning.
        $this->assertStringContainsString('data-transport="strip"', $html);
        $this->assertStringContainsString(sprintf('10.0.0.%d:8006', 20 + self::$seq), $html);
        $this->assertStringContainsString('>Token<', $html);
        $this->assertStringContainsString('Verify TLS off', $html);

        // Uptime: longest node (200000s = 2d 7h 33m) + oldest-boot line + label.
        $this->assertStringContainsString('2d 7h 33m', $html);
        $this->assertStringContainsString('Oldest boot', $html);
        $this->assertStringContainsString('Cluster up (longest node)', $html);
        $this->assertStringNotContainsString('No data yet', $html);

        // Available: RAM 24 GB used of 96 GB · 25%, storage 2.1 TB of 3.4 TB · 64%.
        $this->assertStringContainsString('24 GB of 96 GB', $html);
        $this->assertStringContainsString('2.1 TB of 3.4 TB', $html);
        $this->assertStringContainsString('data-available="live-ram"', $html);
        $this->assertStringContainsString('data-available="live-storage"', $html);

        // Compute line: nodes · vCPU · avg load.
        $this->assertStringContainsString('2 nodes', $html);
        $this->assertStringContainsString('24 vCPU', $html);
        $this->assertStringContainsString('avg load', $html);
        $this->assertStringContainsString('38%', $html);

        // Census: the run/stop split, not a bare total.
        $this->assertStringContainsString('1 running · 2 stopped', $html);

        // Supplement card: cluster, nodes, storage pools, templates.
        $this->assertStringContainsString('essentialProxmoxSupplement', $html);
        $this->assertStringContainsString('labcluster', $html);
        $this->assertStringContainsString('quorate', $html);
        $this->assertStringContainsString('local-zfs:', $html);
        $this->assertStringContainsString('ceph:', $html);
        $this->assertStringContainsString('Template VMID:', $html);
        $this->assertStringContainsString('(default)', $html);
        $this->assertStringContainsString('(not on cluster)', $html);
        $this->assertSame(1, substr_count($html, '(not on cluster)'));

        // VM list: Node column for proxmox rows only.
        $this->assertStringContainsString('<th>Node</th>', $html);

        fwrite(STDERR, "\n[EVIDENCE proxmox-essential] strip + 2d 7h 33m + 24/96GB + 2.1/3.4TB + 2 nodes/24vCPU + supplement + node column\n");
    }

    public function test_meta_keys_cover_both_writers(): void
    {
        $server = $this->proxmoxServer();
        $this->fakeCluster();

        $dto = (new ProxmoxClient($server->refresh()))->getServerInfo();
        $meta = $dto->meta;

        foreach (['ramTotal', 'ramFree', 'storageTotal', 'storageFree', 'logicalCpu', 'cpuLoadPercent', 'uptime', 'bootTime', 'vmCounts', 'vms_running', 'vms_total', 'node_count', 'node', 'host', 'port', 'verify_tls', 'auth_type', 'clusterName', 'clusterNodes', 'storagePools'] as $key) {
            $this->assertArrayHasKey($key, $meta, "getServerInfo meta misses {$key}");
        }
        $this->assertSame(['running' => 1, 'stopped' => 2, 'total' => 3], $meta['vmCounts']);
        $this->assertSame(103079215104, $meta['ramTotal']);
        $this->assertSame(24, $meta['logicalCpu']);
        $this->assertSame(38, $meta['cpuLoadPercent']);
        // Shared ceph pool is deduped, not summed per node.
        $this->assertSame(3700000000000, $meta['storageTotal']);
        $this->assertCount(2, $meta['clusterNodes']);
        $this->assertCount(3, $meta['storagePools']);
        $this->assertArrayHasKey('checked_at', $meta['provenance'] ?? []);
        $this->assertStringNotContainsString('TOKEN-SECRET', json_encode($meta));

        $result = (new ProxmoxClient($server->refresh()))->testConnection();
        $this->assertTrue($result->ok, $result->message);
        foreach (['ramTotal', 'storageTotal', 'uptime', 'vmCounts', 'clusterNodes', 'storagePools', 'vms_visible', 'datastores_visible'] as $key) {
            $this->assertArrayHasKey($key, $result->meta, "testConnection meta misses {$key}");
        }
        $this->assertArrayHasKey('checked_at', $result->meta['provenance'] ?? []);

        fwrite(STDERR, "\n[EVIDENCE proxmox-meta-keys] render + persist writers share telemetry keys, no secret\n");
    }

    public function test_one_dead_node_degrades_to_its_survivors(): void
    {
        $server = $this->proxmoxServer();
        $this->fakeCluster([
            '*/api2/json/nodes/pve2/status' => Http::response(['message' => 'node offline'], 500),
            '*/api2/json/nodes/pve2/storage' => Http::response(['message' => 'node offline'], 500),
        ]);

        $html = $this->actingAs($this->adminWith(['hosting.view', 'hosting.manage']))
            ->get(route('admin.servers.show', $server))
            ->assertOk()
            ->getContent();

        // Survivor aggregates only: pve1 RAM 16 GB used of 64 GB.
        $this->assertStringContainsString('2d 7h 33m', $html);
        $this->assertStringContainsString('16 GB of 64 GB', $html);
        $this->assertStringContainsString('local-zfs:', $html);
        // The dead node still gets its badge row, without uptime.
        $this->assertStringContainsString('pve2', $html);

        fwrite(STDERR, "\n[EVIDENCE proxmox-degraded] dead node degrades, page 200, survivor data renders\n");
    }

    public function test_privilege_limited_credential_renders_empty_vocabulary_not_zeros(): void
    {
        $server = $this->proxmoxServer();
        Http::preventStrayRequests();
        Http::fake([
            '*/api2/json/version' => Http::response(['data' => ['version' => '9.0.3', 'release' => '9.0']]),
            '*/api2/json/nodes' => Http::response(['data' => [['node' => 'pve1', 'status' => 'online']]]),
            '*/api2/json/cluster/status' => Http::response(['data' => [['type' => 'cluster', 'name' => 'labcluster']]]),
            '*/api2/json/nodes/pve1/status' => Http::response(['message' => 'Permission check failed'], 403),
            '*/api2/json/nodes/pve1/storage' => Http::response(['data' => []]),
            '*/api2/json/cluster/resources*' => Http::response(['data' => []]),
        ]);

        $html = $this->actingAs($this->adminWith(['hosting.view', 'hosting.manage']))
            ->get(route('admin.servers.show', $server))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No remote data', $html);
        $this->assertStringContainsString('Limited data', $html);
        $this->assertStringNotContainsString('0 running', $html);
        $this->assertStringNotContainsString('0 stopped', $html);
        $this->assertStringNotContainsString('No data yet', $html);

        $dto = (new ProxmoxClient($server->refresh()))->getServerInfo();
        $this->assertArrayNotHasKey('vmCounts', $dto->meta);
        $this->assertArrayNotHasKey('ramTotal', $dto->meta);
        $this->assertArrayNotHasKey('storageTotal', $dto->meta);

        fwrite(STDERR, "\n[EVIDENCE proxmox-limited] blind credential renders empty vocabulary, counts omitted\n");
    }
}
