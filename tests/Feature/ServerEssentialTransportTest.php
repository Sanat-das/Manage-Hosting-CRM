<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\ServerController;
use App\Models\Server;
use App\ViewModels\Admin\ServerDetailViewModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Todo 14 evidence: Hyper-V transport matrix for ServerDetailViewModel.
 *
 * Sources: connection_meta host/port (nested wins) vs servers.api_url vs
 * servers.ip_address, with the useSsl 5986/5985 default. Also pins boolean
 * normalization and that the controller persist helper keeps ONLY the
 * allow-listed transport keys (never telemetry/derived).
 */
final class ServerEssentialTransportTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function makeServer(array $overrides = []): Server
    {
        ++self::$seq;

        return Server::create(array_merge([
            'name' => 'Essential Transport Srv '.self::$seq,
            'ip_address' => '192.0.2.'.(100 + self::$seq),
            'server_type' => 'hyperv',
            'status' => 'active',
            'connection_status' => 'connected',
        ], $overrides));
    }

    public function test_meta_only_wins_without_api_url(): void
    {
        $server = $this->makeServer([
            'api_url' => null,
            'connection_meta' => ['host' => 'meta-only.example.com', 'port' => 5599],
        ]);

        $vm = ServerDetailViewModel::fromServer($server->refresh());

        $this->assertSame('meta-only.example.com', $vm->transportHost);
        $this->assertSame(5599, $vm->transportPort);

        fwrite(STDERR, "\n[EVIDENCE transport-meta-only] host=meta-only.example.com port=5599\n");
    }

    public function test_api_url_only_parses_host_and_port(): void
    {
        $server = $this->makeServer([
            'api_url' => 'https://api-only.example.com:5990',
            'connection_meta' => [],
        ]);

        $vm = ServerDetailViewModel::fromServer($server->refresh());

        $this->assertSame('api-only.example.com', $vm->transportHost);
        $this->assertSame(5990, $vm->transportPort);

        fwrite(STDERR, "\n[EVIDENCE transport-api-only] host=api-only.example.com port=5990\n");
    }

    public function test_both_sources_meta_wins(): void
    {
        $server = $this->makeServer([
            'api_url' => 'https://api-loser.example.com:1111',
            'connection_meta' => ['host' => 'meta-winner.example.com', 'port' => 2222],
        ]);

        $vm = ServerDetailViewModel::fromServer($server->refresh());

        $this->assertSame('meta-winner.example.com', $vm->transportHost);
        $this->assertSame(2222, $vm->transportPort);

        fwrite(STDERR, "\n[EVIDENCE transport-both] meta wins host=meta-winner.example.com port=2222\n");
    }

    public function test_neither_falls_back_to_ip_with_ssl_default_port(): void
    {
        $plain = $this->makeServer([
            'api_url' => null,
            'ip_address' => '192.0.2.201',
            'connection_meta' => [],
        ]);
        $plainVm = ServerDetailViewModel::fromServer($plain->refresh());
        $this->assertSame('192.0.2.201', $plainVm->transportHost);
        $this->assertSame(5985, $plainVm->transportPort);

        $ssl = $this->makeServer([
            'api_url' => null,
            'ip_address' => '192.0.2.202',
            'connection_meta' => ['use_ssl' => true],
        ]);
        $sslVm = ServerDetailViewModel::fromServer($ssl->refresh());
        $this->assertSame('192.0.2.202', $sslVm->transportHost);
        $this->assertSame(5986, $sslVm->transportPort);

        fwrite(STDERR, "\n[EVIDENCE transport-neither] ip fallback ports 5985 (plain) / 5986 (ssl)\n");
    }

    public function test_ssl_string_variants_normalize_and_drive_default_port(): void
    {
        $truthy = $this->makeServer([
            'api_url' => null,
            'connection_meta' => ['use_ssl' => 'true', 'verify_tls' => 'false'],
        ]);
        $truthyVm = ServerDetailViewModel::fromServer($truthy->refresh());
        $this->assertTrue($truthyVm->metaUseSsl);
        $this->assertFalse($truthyVm->metaVerifyTls);
        $this->assertSame(5986, $truthyVm->transportPort);

        $falsy = $this->makeServer([
            'api_url' => null,
            'connection_meta' => ['use_ssl' => '0'],
        ]);
        $falsyVm = ServerDetailViewModel::fromServer($falsy->refresh());
        $this->assertFalse($falsyVm->metaUseSsl);
        $this->assertSame(5985, $falsyVm->transportPort);

        fwrite(STDERR, "\n[EVIDENCE transport-bool] 'true'->5986, '0'->5985, verify_tls 'false'->false\n");
    }

    public function test_nested_transport_beats_flat_and_api_url_port_parsed(): void
    {
        $server = $this->makeServer([
            'api_url' => 'http://api-port.example.com:1234',
            'connection_meta' => [
                'port' => 9999, // flat port loses to nested
                'meta' => ['host' => 'nested-host.example.com', 'port' => 5555],
            ],
        ]);

        $vm = ServerDetailViewModel::fromServer($server->refresh());

        $this->assertSame('nested-host.example.com', $vm->transportHost);
        $this->assertSame(5555, $vm->transportPort);

        fwrite(STDERR, "\n[EVIDENCE transport-nested] nested host/port win over flat + api_url\n");
    }

    public function test_persist_helper_keeps_only_allow_listed_transport_keys(): void
    {
        $server = $this->makeServer([
            'api_url' => 'https://persist.example.com:5986',
            'ip_address' => '192.0.2.210',
            'connection_meta' => [
                'host' => 'persist.example.com',
                'port' => 5986,
                'use_ssl' => true,
                'verify_tls' => false,
                // Telemetry that must never be persisted by the GET path.
                'ramTotal' => 12345,
                'meta' => ['ramTotal' => 12345, 'vmCounts' => ['total' => 9]],
            ],
        ]);

        $controller = app(ServerController::class);
        $method = new \ReflectionMethod($controller, 'freshTransportForPersist');
        $method->setAccessible(true);
        $transport = $method->invoke($controller, $server->refresh());

        $this->assertSame('persist.example.com', $transport['host'] ?? null);
        $this->assertSame(5986, $transport['port'] ?? null);
        $this->assertTrue($transport['use_ssl'] ?? null);
        $this->assertArrayHasKey('verify_tls', $transport);
        $this->assertArrayNotHasKey('ramTotal', $transport);
        $this->assertArrayNotHasKey('meta', $transport);
        $this->assertArrayNotHasKey('vmCounts', $transport);

        fwrite(STDERR, "\n[EVIDENCE transport-persist] allow-list keys only, telemetry excluded\n");
    }
}
