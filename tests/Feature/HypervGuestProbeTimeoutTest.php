<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Modules\HyperV\Services\HyperVClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HypervGuestProbeTimeoutTest extends TestCase
{
    use RefreshDatabase;

    private const VM = 'guestprobe1';

    private const GUID = '99999999-aaaa-bbbb-cccc-dddddddddddd';

    public function test_verify_guest_admin_credentials_soap_body_does_not_contain_operation_timeout(): void
    {
        $server = $this->makeServer();
        $client = new HyperVClient($server);

        $capturedBodies = [];

        Http::fake(function ($request) use (&$capturedBodies) {
            $body = (string) $request->body();
            $capturedBodies[] = $body;

            if (str_contains($body, 'Invoke-Command -VMName')) {
                return Http::response(['verified' => true, 'guest' => 'GUEST1']);
            }

            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => self::VM, 'state' => 'Running', 'vmId' => self::GUID]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $result = $client->verifyGuestAdminCredentials(self::VM, null, 'Administrator', 'Secret123', 1, 0);

        $this->assertArrayHasKey('verified', $result);
        $this->assertTrue($result['verified']);

        $probeBodies = array_filter($capturedBodies, fn ($b) => str_contains($b, 'Invoke-Command -VMName'));
        $this->assertNotEmpty($probeBodies, 'No Invoke-Command -VMName request was captured');

        foreach ($probeBodies as $b) {
            $this->assertStringContainsString('Invoke-Command -VMName', $b);
            $this->assertStringNotContainsString('OperationTimeoutSec', $b);
            $this->assertStringContainsString('-ErrorAction Stop', $b);
            $this->assertStringContainsString('-ArgumentList', $b);
        }
    }

    public function test_reset_guest_admin_password_soap_body_does_not_contain_operation_timeout(): void
    {
        $server = $this->makeServer();
        $client = new HyperVClient($server);

        $capturedBodies = [];

        Http::fake(function ($request) use (&$capturedBodies) {
            $body = (string) $request->body();
            $capturedBodies[] = $body;

            if (str_contains($body, 'Invoke-Command -VMName')) {
                return Http::response(['ok' => true, 'vmName' => self::VM]);
            }

            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => self::VM, 'state' => 'Running', 'vmId' => self::GUID]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $result = $client->resetGuestAdminPassword(self::VM, null, 'Administrator', 'OldSecret123', 'NewSecret123');

        $this->assertArrayHasKey('ok', $result);
        $this->assertTrue($result['ok']);

        $resetBodies = array_filter($capturedBodies, fn ($b) => str_contains($b, 'Invoke-Command -VMName'));
        $this->assertNotEmpty($resetBodies, 'No Invoke-Command -VMName request was captured for reset');

        foreach ($resetBodies as $b) {
            $this->assertStringContainsString('Invoke-Command -VMName', $b);
            $this->assertStringNotContainsString('OperationTimeoutSec', $b);
            $this->assertStringContainsString('-ErrorAction Stop', $b);
            $this->assertStringContainsString('-ArgumentList', $b);
        }
    }

    public function test_getVmState_missing_vm_body_uses_return_not_exit(): void
    {
        $server = $this->makeServer();
        $client = new HyperVClient($server);

        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            $captured = (string) $request->body();
            return Http::response(['exists' => false]);
        });

        $client->getVmState('missing-guard-vm', null);

        $this->assertNotNull($captured, 'No request body captured for getVmState');
        $this->assertStringContainsString('return', $captured);
        $this->assertStringNotContainsString('exit ', $captured);
        $this->assertStringContainsString('exists = $false', $captured);
    }

    public function test_startVm_missing_vm_body_uses_return_not_exit(): void
    {
        $server = $this->makeServer();
        $client = new HyperVClient($server);

        $capturedBodies = [];
        Http::fake(function ($request) use (&$capturedBodies) {
            $body = (string) $request->body();
            $capturedBodies[] = $body;

            // First call is getVmState check inside startVm — fake as exists to reach the guard PS
            if (str_contains($body, 'Start-VM -VM')) {
                // This is the guard PS we want to inspect — return dummy success (not used)
                return Http::response(['state' => 'Running', 'name' => 'missing-guard-vm', 'vmId' => self::GUID]);
            }

            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => 'missing-guard-vm', 'state' => 'Off', 'vmId' => self::GUID]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $client->startVm('missing-guard-vm', null);

        // Find the Start-VM body (the guard we care about)
        $guardBodies = array_filter($capturedBodies, fn ($b) => str_contains($b, 'Start-VM -VM'));
        $this->assertNotEmpty($guardBodies, 'No Start-VM request body captured');

        foreach ($guardBodies as $b) {
            $this->assertStringContainsString('return', $b);
            $this->assertStringNotContainsString('exit ', $b);
            // Must still contain the "does not exist" error JSON payload
            $this->assertStringContainsString('does not exist', $b);
        }
    }

    public function test_bounded_process_runner_returns_timeout_error_fast(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Bounded PowerShell runner only runs on Windows.');
        }

        $server = $this->makeServer();
        $client = new HyperVClient($server);

        $ref = new \ReflectionMethod(HyperVClient::class, 'runPowerShellScriptWithTimeout');
        $ref->setAccessible(true);

        $start = microtime(true);
        /** @var array{error?:string,output?:string} $result */
        $result = $ref->invoke($client, 'Start-Sleep -Seconds 30', 2);
        $elapsed = microtime(true) - $start;

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('timed out after 2s', strtolower((string) $result['error']));
        $this->assertLessThan(10, $elapsed, 'Bounded runner took too long: '.$elapsed.'s (expected <10s)');
    }

    private function makeServer(): Server
    {
        return Server::create([
            'name' => 'hv-probe-'.uniqid(),
            'ip_address' => '10.0.0.99',
            'server_type' => 'hyperv',
            'api_url' => 'http://10.0.0.99:5985',
            'api_username' => 'admin',
            'api_password_encrypted' => 'SECRET',
            'max_accounts' => 0,
            'status' => 'active',
        ]);
    }
}
