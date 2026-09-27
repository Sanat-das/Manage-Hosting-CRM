<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\Integrations\ServerInfoDTO;
use App\Models\Server;
use App\Modules\HyperV\Services\HyperVClient;
use App\Modules\Proxmox\Services\ProxmoxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Essential Information card reads live host stats through
 * <module>Client::cachedServerInfo(), which caches a ServerInfoDTO.
 *
 * `cache.serializable_classes` is false in this app (Laravel's gadget-chain
 * hardening), so every serializing store hands a cached object back as
 * __PHP_Incomplete_Class and the declared ServerInfoDTO return type throws —
 * the card then degrades to "Last refresh failed: ... __PHP_Incomplete_Class
 * returned". The rest of the suite runs on the array store ('serialize' =>
 * false), which stores objects by reference and never reproduces it, so these
 * pins run against the database store the app actually uses.
 */
final class ServerInfoCacheRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Production configuration under test: database store + the hardened
        // default that forbids unserializing classes out of the cache.
        config(['cache.default' => 'database', 'cache.serializable_classes' => false]);
        Cache::store('database')->flush();
    }

    public function test_hyperv_cached_server_info_round_trips_through_the_database_store(): void
    {
        $server = $this->hypervServer();

        Http::fake(['*/wsman' => Http::response($this->richPayload(), 200)]);

        $client = new HyperVClient($server, 8);

        $first = $client->cachedServerInfo(60);
        $this->assertInstanceOf(ServerInfoDTO::class, $first);
        $this->assertSame('Microsoft Windows Server 2022 Datacenter', $first->meta['hostOS']);

        // Second read is served from the database cache: the call that used to
        // return __PHP_Incomplete_Class.
        $second = $client->cachedServerInfo(60);

        $this->assertInstanceOf(ServerInfoDTO::class, $second, 'Cached server info must rehydrate to a ServerInfoDTO.');
        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertSame(['running' => 3, 'stopped' => 1, 'saved' => 1, 'total' => 5], $second->meta['vmCounts']);
        $this->assertSame(16, $second->meta['logicalCpu']);
        $this->assertSame(12, $second->meta['cpuLoadPercent']);

        // Payload invariant: the toArray() array shape, never a serialized object.
        $raw = (string) DB::table('cache')
            ->where('key', config('cache.prefix').'hyperv:server:'.$server->id.':info')
            ->value('value');

        $this->assertStringStartsWith('a:', $raw, 'ServerInfoDTO must be cached as an array, not an object.');
        $this->assertStringNotContainsString('O:', $raw, 'No serialized object may be cached.');

        fwrite(STDERR, PHP_EOL.'[EVIDENCE hyperv-cache-roundtrip] second read = ServerInfoDTO; host='.$second->hostname
            .' os='.$second->meta['hostOS']
            .' logicalCpu='.$second->meta['logicalCpu']
            .' avgCpuLoad='.$second->meta['cpuLoadPercent']
            .' ramTotal='.$second->meta['ramTotal']
            .' storageTotal='.$second->meta['storageTotal']
            .' vms='.$second->meta['vmCounts']['running'].' running/'.$second->meta['vmCounts']['stopped'].' stopped'
            .' cachedPayload=array'.PHP_EOL);
    }

    public function test_proxmox_cached_server_info_round_trips_through_the_database_store(): void
    {
        $server = Server::create([
            'name' => 'pve-1',
            'ip_address' => '10.0.0.20',
            'server_type' => 'proxmox',
            'api_url' => 'https://10.0.0.20:8006',
            'api_username' => 'root@pam',
            'api_key' => 'TOKENID=SECRET',
            'status' => 'active',
        ]);

        // Every PVE call answers empty: getServerInfo() degrades to a DTO
        // either way, which is exactly what the cache has to round-trip.
        Http::fake();

        $client = new ProxmoxClient($server, 8);

        $first = $client->cachedServerInfo(60);
        $this->assertInstanceOf(ServerInfoDTO::class, $first);

        $second = $client->cachedServerInfo(60);

        $this->assertInstanceOf(ServerInfoDTO::class, $second, 'Cached server info must rehydrate to a ServerInfoDTO.');
        $this->assertSame($first->toArray(), $second->toArray());

        $raw = (string) DB::table('cache')
            ->where('key', config('cache.prefix').'proxmox:server:'.$server->id.':info')
            ->value('value');

        $this->assertStringStartsWith('a:', $raw, 'ServerInfoDTO must be cached as an array, not an object.');

        fwrite(STDERR, "\n[EVIDENCE proxmox-cache-roundtrip] second read = ServerInfoDTO, cached as array\n");
    }

    private function hypervServer(): Server
    {
        return Server::create([
            'name' => 'hv-cache-1',
            'ip_address' => '10.0.0.9',
            'server_type' => 'hyperv',
            'api_url' => 'http://10.0.0.9:5985',
            'api_username' => 'admin',
            'api_password_encrypted' => 'SECRET',
            'max_accounts' => 0,
            'status' => 'active',
        ]);
    }

    /**
     * Minimal rich fetchInfo shape: OS, average CPU load, RAM, disk, VM states.
     *
     * @return array<string, mixed>
     */
    private function richPayload(): array
    {
        return [
            'vmHost' => [
                'hostname' => 'HV-HOST-01',
                'hostOS' => 'Microsoft Windows Server 2022 Datacenter',
                'hypervVersion' => '10.0.20348.0',
                'logicalCpu' => 16,
                'ramTotal' => 68702699520,
                'ramFree' => 42949672960,
                'osBuild' => '20348',
                'uptime' => '2.05:00:00',
                'bootTime' => '2026-09-17T04:00:00.0000000+00:00',
                'cpuLoadPercent' => 12,
            ],
            'vms' => [
                ['Name' => 'Running', 'Count' => 3],
                ['Name' => 'Off', 'Count' => 1],
                ['Name' => 'Saved', 'Count' => 1],
            ],
            'switches' => [['Name' => 'Default Switch', 'SwitchType' => 'Internal']],
            'storageTotal' => 1610612736000,
            'storageUsed' => 590558003200,
            'storageFree' => 1020054732800,
            'volumes' => [['name' => 'C', 'total' => 536870912000, 'used' => 322122547200, 'free' => 214748364800]],
        ];
    }
}
