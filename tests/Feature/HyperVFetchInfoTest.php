<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use App\Modules\HyperV\Services\HyperVClient;
use Tests\TestCase;

/**
 * Pins the rich HyperVClient::fetchInfo shape.
 *
 * fetchInfo emits `$out | ConvertTo-Json -Compress -Depth 5` with
 * vmHost{hostname,hostOS,hypervVersion,logicalCpu,ramTotal,ramFree,osBuild,
 * uptime,bootTime,cpuLoadPercent}, vms[{Name,Count}], switches[{Name,
 * SwitchType}], storageTotal/Used/Free and volumes[{name,total,used,free}].
 *
 * The live WinRM SOAP reply embeds that JSON as command-output text, so
 * parseInfoBody() must accept both plain JSON (Http::fake style) and a SOAP
 * envelope embedding the same JSON as a {...} fragment. Both variants must
 * surface via testConnection()->ok with the DTO meta intact.
 */
class HyperVFetchInfoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_fetch_info_plain_json_pins_rich_shape(): void
    {
        $server = $this->hypervServer();
        $payload = $this->richPayload();

        Http::fake([
            '*/wsman' => function ($request) use ($payload) {
                $body = (string) $request->body();

                if (str_contains($body, 'Identify')) {
                    return Http::response($this->identifyEnvelope(), 200, [
                        'Content-Type' => 'application/soap+xml;charset=UTF-8',
                    ]);
                }

                return Http::response($payload, 200);
            },
        ]);

        $result = (new HyperVClient($server))->testConnection();

        $this->assertTrue($result->ok, 'testConnection should succeed: '.$result->message);
        $meta = $this->dtoMeta($result->meta);

        $this->assertSame('Microsoft Windows Server 2022 Datacenter', $meta['hostOS']);
        $this->assertSame('10.0.20348.0', $meta['hypervVersion']);
        $this->assertSame(16, $meta['logicalCpu']);
        $this->assertSame(68702699520, $meta['ramTotal']);
        $this->assertSame(42949672960, $meta['ramFree']);
        $this->assertSame('20348', $meta['osBuild']);
        $this->assertSame('2.05:00:00', $meta['uptime']);
        $this->assertSame(12, $meta['cpuLoadPercent']);

        $this->assertSame(['running' => 3, 'stopped' => 1, 'saved' => 1, 'total' => 5], $meta['vmCounts']);

        $this->assertCount(2, $meta['switches']);
        $this->assertContains('Default Switch', $meta['switches']);
        $this->assertContains('External-vSwitch', $meta['switches']);

        // switchDetails preserves the host-reported SwitchType alongside names.
        $this->assertSame([
            ['name' => 'Default Switch', 'type' => 'Internal'],
            ['name' => 'External-vSwitch', 'type' => 'External'],
        ], $meta['switchDetails']);

        $this->assertSame(1610612736000, $meta['storageTotal']);
        $this->assertSame(590558003200, $meta['storageUsed']);
        $this->assertSame(1020054732800, $meta['storageFree']);

        $this->assertCount(2, $meta['volumes']);
        $this->assertSame('C', $meta['volumes'][0]['name']);
        $this->assertSame(536870912000, $meta['volumes'][0]['total']);

        $this->assertSame(5, $result->meta['totalAccounts']);
    }

    public function test_fetch_info_soap_wrapped_pins_rich_shape(): void
    {
        $server = $this->hypervServer();
        $payload = $this->richPayload();
        $soap = $this->soapEnvelopeWithJson($payload);

        Http::fake([
            '*/wsman' => function ($request) use ($soap) {
                $body = (string) $request->body();

                if (str_contains($body, 'Identify')) {
                    return Http::response($this->identifyEnvelope(), 200, [
                        'Content-Type' => 'application/soap+xml;charset=UTF-8',
                    ]);
                }

                return Http::response($soap, 200, [
                    'Content-Type' => 'application/soap+xml;charset=UTF-8',
                ]);
            },
        ]);

        $result = (new HyperVClient($server))->testConnection();

        $this->assertTrue($result->ok, 'testConnection should succeed: '.$result->message);
        $meta = $this->dtoMeta($result->meta);

        $this->assertSame('Microsoft Windows Server 2022 Datacenter', $meta['hostOS']);
        $this->assertSame(['running' => 3, 'stopped' => 1, 'saved' => 1, 'total' => 5], $meta['vmCounts']);

        $this->assertCount(2, $meta['switches']);
        $this->assertContains('Default Switch', $meta['switches']);

        $this->assertSame(1610612736000, $meta['storageTotal']);
        $this->assertSame(590558003200, $meta['storageUsed']);
        $this->assertSame(1020054732800, $meta['storageFree']);

        $this->assertSame('2.05:00:00', $meta['uptime']);
        $this->assertSame('20348', $meta['osBuild']);
    }

    public function test_fetch_info_backward_compat_aliases_still_parse(): void
    {
        $server = $this->hypervServer();
        $payload = $this->legacyPayload();

        Http::fake([
            '*/wsman' => function ($request) use ($payload) {
                $body = (string) $request->body();

                if (str_contains($body, 'Identify')) {
                    return Http::response($this->identifyEnvelope(), 200, [
                        'Content-Type' => 'application/soap+xml;charset=UTF-8',
                    ]);
                }

                return Http::response($payload, 200);
            },
        ]);

        $result = (new HyperVClient($server))->testConnection();

        $this->assertTrue($result->ok, 'testConnection should succeed: '.$result->message);
        $meta = $this->dtoMeta($result->meta);

        // host{} alias resolves like vmHost{}.
        $this->assertSame('Microsoft Windows Server 2019 Standard', $meta['hostOS']);
        // vmCounts[] list alias resolves like vms[].
        $this->assertSame(['running' => 2, 'stopped' => 1, 'saved' => 0, 'total' => 3], $meta['vmCounts']);
        // vmSwitches[] alias resolves like switches[].
        $this->assertSame(['Legacy-Switch'], $meta['switches']);
    }

    public function test_switch_details_skips_blanks_and_normalizes_shapes(): void
    {
        $server = $this->hypervServer();
        $payload = [
            'vmHost' => ['hostname' => 'HV-SW-01'],
            'vms' => [],
            'switches' => [
                'Plain-Switch',
                '   ',
                '',
                ['Name' => 'Typed-Switch', 'SwitchType' => 'External'],
                ['name' => 'Lower-Switch', 'type' => 'Private'],
                ['Name' => '   ', 'SwitchType' => 'Internal'],
                ['SwitchType' => 'Internal'],
            ],
        ];

        Http::fake([
            '*/wsman' => function ($request) use ($payload) {
                $body = (string) $request->body();

                if (str_contains($body, 'Identify')) {
                    return Http::response($this->identifyEnvelope(), 200, [
                        'Content-Type' => 'application/soap+xml;charset=UTF-8',
                    ]);
                }

                return Http::response($payload, 200);
            },
        ]);

        $result = (new HyperVClient($server))->testConnection();

        $this->assertTrue($result->ok, 'testConnection should succeed: '.$result->message);
        $meta = $this->dtoMeta($result->meta);

        // Blanks skipped; string shape keeps working for backward compat.
        $this->assertSame(['Plain-Switch', 'Typed-Switch', 'Lower-Switch'], $meta['switches']);
        $this->assertSame([
            ['name' => 'Plain-Switch', 'type' => null],
            ['name' => 'Typed-Switch', 'type' => 'External'],
            ['name' => 'Lower-Switch', 'type' => 'Private'],
        ], $meta['switchDetails']);
    }

    // ─────────────────────────── helpers ───────────────────────────

    private function hypervServer(): Server
    {
        return Server::create([
            'name' => 'hv-1',
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
     * Canonical rich shape emitted by fetchInfo's ConvertTo-Json snippet.
     *
     * @return array<string, mixed>
     */
    private function richPayload(): array
    {
        $vmHost = [
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
        ];

        $vms = [
            ['Name' => 'Running', 'Count' => 3],
            ['Name' => 'Off', 'Count' => 1],
            ['Name' => 'Saved', 'Count' => 1],
        ];

        $switches = [
            ['Name' => 'Default Switch', 'SwitchType' => 'Internal'],
            ['Name' => 'External-vSwitch', 'SwitchType' => 'External'],
        ];

        $volumes = [
            ['name' => 'C', 'total' => 536870912000, 'used' => 322122547200, 'free' => 214748364800],
            ['name' => 'D', 'total' => 1073741824000, 'used' => 268435456000, 'free' => 805306368000],
        ];

        return [
            'vmHost' => $vmHost,
            'hostOS' => 'Microsoft Windows Server 2022 Datacenter',
            'hypervVersion' => '10.0.20348.0',
            'logicalCpu' => 16,
            'ramTotal' => 68702699520,
            'ramFree' => 42949672960,
            'osBuild' => '20348',
            'uptime' => '2.05:00:00',
            'bootTime' => '2026-09-17T04:00:00.0000000+00:00',
            'cpuLoadPercent' => 12,
            'vms' => $vms,
            'vmCounts' => $vms,
            'switches' => $switches,
            'vmSwitches' => $switches,
            'storageTotal' => 1610612736000,
            'storageUsed' => 590558003200,
            'storageFree' => 1020054732800,
            'volumes' => $volumes,
        ];
    }

    /**
     * Legacy alias shape: host{} instead of vmHost{}, vmCounts[] instead of
     * vms[], vmSwitches[] instead of switches[]. The parser must accept it.
     *
     * @return array<string, mixed>
     */
    private function legacyPayload(): array
    {
        return [
            'host' => [
                'hostname' => 'HV-LEGACY-01',
                'hostOS' => 'Microsoft Windows Server 2019 Standard',
            ],
            'vmCounts' => [
                ['Name' => 'Running', 'Count' => 2],
                ['Name' => 'Off', 'Count' => 1],
            ],
            'vmSwitches' => [
                ['Name' => 'Legacy-Switch', 'SwitchType' => 'Private'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function soapEnvelopeWithJson(array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        return '<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope">'
            . '<s:Body><CommandResponse xmlns="http://schemas.microsoft.com/wbem/wsman/1/windows/shell">'
            . '<Output>' . $json . '</Output>'
            . '</CommandResponse></s:Body></s:Envelope>';
    }

    private function identifyEnvelope(): string
    {
        return '<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope">'
            . '<s:Body><IdentifyResponse xmlns="http://schemas.dmtf.org/wbem/wsman/identity/1/wsmanidentity.xsd">'
            . '<ProductVersion>OS: 10.0.20348 SP: 0.0 Stack: 3.0</ProductVersion>'
            . '</IdentifyResponse></s:Body></s:Envelope>';
    }

    /**
     * testConnection() merges ServerInfoDTO::toArray() into result meta, so
     * the DTO fields live one level down under the "meta" key.
     *
     * @param  array<string, mixed>  $resultMeta
     * @return array<string, mixed>
     */
    private function dtoMeta(array $resultMeta): array
    {
        $inner = $resultMeta['meta'] ?? [];

        $this->assertIsArray($inner, 'testConnection meta should nest the ServerInfoDTO meta.');

        return $inner;
    }
}
