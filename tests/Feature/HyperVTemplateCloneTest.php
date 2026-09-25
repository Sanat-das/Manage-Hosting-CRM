<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PanelAccount;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Modules\HyperV\HyperV;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HyperVTemplateCloneTest extends TestCase
{
    use RefreshDatabase;

    private const VM = 'testvm1';

    private const GUID = '11111111-2222-3333-4444-555555555555';

    private const TEMPLATE = 'gold-win01';

    private const TEMPLATE_VHD = 'C:\\VMs\\gold-win01.vhdx';

    public function test_clone_from_template_success_copies_disk_and_uses_template_generation(): void
    {
        $server = $this->hypervServer(['template_vm' => self::TEMPLATE]);
        $service = $this->service($server);

        Http::fake(function ($request) {
            $body = (string) $request->body();

            // Final clone: contains Copy-Item and New-VM
            if (str_contains($body, 'Copy-Item')) {
                return Http::response([
                    'vmId' => self::GUID,
                    'name' => self::VM,
                    'state' => 'Off',
                    'vhdPath' => 'C:\\VMs\\'.self::VM.'.vhdx',
                    'switchName' => 'Default Switch',
                    'generation' => 1,
                    'cloned' => true,
                    'templateVm' => self::TEMPLATE,
                ]);
            }

            // Dest check: contains Test-Path and Join-Path .vhdx but no Copy-Item
            // Distinguish from template probe by absence of template name
            if (str_contains($body, 'Test-Path') && str_contains($body, '.vhdx') && ! str_contains($body, self::TEMPLATE) && ! str_contains($body, 'Copy-Item')) {
                // This matches dest check (contains Join-Path and .vhdx)
                // But existence check also not contains Test-Path, so safe
                // Also template probe contains Test-Path but includes template name, so excluded
                return Http::response(['vhdPath' => 'C:\\VMs\\'.self::VM.'.vhdx']);
            }

            // Template probe: contains template name and Get-VMHardDiskDrive
            if (str_contains($body, self::TEMPLATE) && str_contains($body, 'Get-VM')) {
                return Http::response(['generation' => 1, 'sourceVhd' => self::TEMPLATE_VHD]);
            }

            // Existence check: Get-VM for new VM
            // Host-verify probe after create (Get-VM -Id): the VM now exists.
            if (str_contains($body, 'Get-VM -Id')) {
                $verifyName = str_contains($body, 'testvm2') ? 'testvm2' : self::VM;
                return Http::response(['exists' => true, 'name' => $verifyName, 'state' => 'Off', 'vmId' => self::GUID]);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => false]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $result = app(HyperV::class)->provision($service, $this->vmConfig() + ['generation' => 2]);

        $this->assertTrue($result->success);
        $account = PanelAccount::sole();
        $this->assertNotNull($account);
        $this->assertSame(self::GUID, $account->external_id);
        $inner = is_array($account->meta['meta'] ?? null) ? $account->meta['meta'] : [];
        $vhdPath = $inner['vhdPath'] ?? null;
        $this->assertNotNull($vhdPath);
        $this->assertSame(self::VM.'.vhdx', basename(str_replace('\\', '/', (string) $vhdPath)));
        $this->assertSame(self::TEMPLATE, $inner['templateVm'] ?? null);

        $bodies = $this->bodies();
        $joined = implode("\n", $bodies);
        $this->assertStringContainsString('Copy-Item', $joined);
        $this->assertStringContainsString('Copy-Item -LiteralPath', $joined);
        $this->assertStringNotContainsString('New-VHD', $joined);
        // Grow-only: resize guarded so the disk is never shrunk.
        $this->assertStringContainsString('Resize-VHD', $joined);
        $this->assertStringContainsString('$diskBytes -gt $currentSize', $joined);
    }

    public function test_provision_without_template_uses_blank_disk_path(): void
    {
        $server = $this->hypervServer();
        $service = $this->service($server);

        Http::fake(function ($request) {
            $body = (string) $request->body();
            if (str_contains($body, 'New-VM')) {
                return Http::response(['vmId' => self::GUID, 'name' => self::VM, 'state' => 'Off', 'vhdPath' => 'C:\\VMs\\'.self::VM.'.vhdx', 'switchName' => 'Default Switch', 'generation' => 2]);
            }
            // Host-verify probe after create: the VM now exists.
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => self::VM, 'state' => 'Off', 'vmId' => self::GUID]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $result = app(HyperV::class)->provision($service, $this->vmConfig());

        $this->assertTrue($result->success);
        $bodies = $this->bodies();
        $joined = implode("\n", $bodies);
        $this->assertStringContainsString('New-VHD', $joined);
        $this->assertStringNotContainsString('Copy-Item', $joined);
    }

    public function test_clone_fails_when_template_is_running_without_copy(): void
    {
        $server = $this->hypervServer(['template_vm' => self::TEMPLATE]);
        $service = $this->service($server);

        Http::fake(function ($request) {
            $body = (string) $request->body();
            if (str_contains($body, self::TEMPLATE) && str_contains($body, 'Get-VM')) {
                return Http::response(['error' => "Template VM '".self::TEMPLATE."' is Running - shut it down first."], 500);
            }
            // Host-verify probe after create (Get-VM -Id): the VM now exists.
            if (str_contains($body, 'Get-VM -Id')) {
                $verifyName = str_contains($body, 'testvm2') ? 'testvm2' : self::VM;
                return Http::response(['exists' => true, 'name' => $verifyName, 'state' => 'Off', 'vmId' => self::GUID]);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => false]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $result = app(HyperV::class)->provision($service, $this->vmConfig());

        $this->assertFalse($result->success);
        $this->assertSame(0, PanelAccount::count());
        $bodies = $this->bodies();
        foreach ($bodies as $b) {
            $this->assertStringNotContainsString('Copy-Item', $b);
        }
    }

    public function test_clone_fails_when_template_missing_without_new_vm(): void
    {
        $server = $this->hypervServer(['template_vm' => self::TEMPLATE]);
        $service = $this->service($server);

        Http::fake(function ($request) {
            $body = (string) $request->body();
            if (str_contains($body, self::TEMPLATE) && str_contains($body, 'Get-VM')) {
                return Http::response(['error' => "Template VM '".self::TEMPLATE."' not found."], 500);
            }
            // Host-verify probe after create (Get-VM -Id): the VM now exists.
            if (str_contains($body, 'Get-VM -Id')) {
                $verifyName = str_contains($body, 'testvm2') ? 'testvm2' : self::VM;
                return Http::response(['exists' => true, 'name' => $verifyName, 'state' => 'Off', 'vmId' => self::GUID]);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => false]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $result = app(HyperV::class)->provision($service, $this->vmConfig());

        $this->assertFalse($result->success);
        $this->assertSame(0, PanelAccount::count());
        $bodies = $this->bodies();
        foreach ($bodies as $b) {
            $this->assertStringNotContainsString('New-VM', $b);
        }
    }

    public function test_clone_uses_template_generation_not_plan_generation(): void
    {
        $server = $this->hypervServer(['template_vm' => self::TEMPLATE]);
        $service = $this->service($server);

        Http::fake(function ($request) {
            $body = (string) $request->body();

            if (str_contains($body, 'Copy-Item')) {
                return Http::response([
                    'vmId' => self::GUID,
                    'name' => self::VM,
                    'state' => 'Off',
                    'vhdPath' => 'C:\\VMs\\'.self::VM.'.vhdx',
                    'switchName' => 'Default Switch',
                    'generation' => 1,
                    'cloned' => true,
                    'templateVm' => self::TEMPLATE,
                ]);
            }

            if (str_contains($body, 'Test-Path') && str_contains($body, '.vhdx') && ! str_contains($body, self::TEMPLATE) && ! str_contains($body, 'Copy-Item')) {
                return Http::response(['vhdPath' => 'C:\\VMs\\'.self::VM.'.vhdx']);
            }

            if (str_contains($body, self::TEMPLATE) && str_contains($body, 'Get-VM')) {
                return Http::response(['generation' => 1, 'sourceVhd' => self::TEMPLATE_VHD]);
            }

            // Host-verify probe after create (Get-VM -Id): the VM now exists.
            if (str_contains($body, 'Get-VM -Id')) {
                $verifyName = str_contains($body, 'testvm2') ? 'testvm2' : self::VM;
                return Http::response(['exists' => true, 'name' => $verifyName, 'state' => 'Off', 'vmId' => self::GUID]);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => false]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $result = app(HyperV::class)->provision($service, $this->vmConfig() + ['generation' => 2]);

        $this->assertTrue($result->success);
        $bodies = $this->bodies();
        $found = false;
        foreach ($bodies as $b) {
            if (str_contains($b, 'Copy-Item')) {
                $found = true;
                $this->assertStringContainsString('$generation = 1', $b);
                $this->assertStringNotContainsString('$generation = 2', $b);
            }
        }
        $this->assertTrue($found, 'Copy-Item request not found');
    }

    public function test_clone_fails_when_destination_disk_already_exists_without_new_vm(): void
    {
        $server = $this->hypervServer(['template_vm' => self::TEMPLATE]);
        $service = $this->service($server);

        Http::fake(function ($request) {
            $body = (string) $request->body();

            // Dest check returns error
            if (str_contains($body, 'Test-Path') && str_contains($body, '.vhdx') && ! str_contains($body, self::TEMPLATE) && ! str_contains($body, 'Copy-Item')) {
                return Http::response(['error' => 'Destination disk already exists: C:\\VMs\\'.self::VM.'.vhdx'], 500);
            }

            if (str_contains($body, self::TEMPLATE) && str_contains($body, 'Get-VM')) {
                return Http::response(['generation' => 1, 'sourceVhd' => self::TEMPLATE_VHD]);
            }

            // Host-verify probe after create (Get-VM -Id): the VM now exists.
            if (str_contains($body, 'Get-VM -Id')) {
                $verifyName = str_contains($body, 'testvm2') ? 'testvm2' : self::VM;
                return Http::response(['exists' => true, 'name' => $verifyName, 'state' => 'Off', 'vmId' => self::GUID]);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => false]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $result = app(HyperV::class)->provision($service, $this->vmConfig());

        $this->assertFalse($result->success);
        $this->assertSame(0, PanelAccount::count());
        $bodies = $this->bodies();
        foreach ($bodies as $b) {
            $this->assertStringNotContainsString('New-VM', $b);
        }
    }

    public function test_clone_escapes_template_name_to_prevent_injection(): void
    {
        $injection = "gold'; Remove-VM -Name x; '";
        $server = $this->hypervServer(['template_vm' => $injection]);
        $service = $this->service($server);
        $escaped = str_replace("'", "''", $injection);
        $quoted = "'".$escaped."'";

        Http::fake(function ($request) {
            $body = (string) $request->body();
            if (str_contains($body, 'Get-VM') && str_contains($body, 'gold')) {
                // return template missing to stop after probe
                return Http::response(['error' => 'Template VM not found.'], 500);
            }
            // Host-verify probe after create (Get-VM -Id): the VM now exists.
            if (str_contains($body, 'Get-VM -Id')) {
                $verifyName = str_contains($body, 'testvm2') ? 'testvm2' : self::VM;
                return Http::response(['exists' => true, 'name' => $verifyName, 'state' => 'Off', 'vmId' => self::GUID]);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => false]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $result = app(HyperV::class)->provision($service, $this->vmConfig());

        $this->assertFalse($result->success);
        $bodies = $this->bodies();
        $joined = implode("\n", $bodies);
        // Must contain escaped single-quoted version (doubled quotes)
        $this->assertStringContainsString($quoted, $joined);
        // Must NOT contain raw unescaped breakout
        $this->assertStringNotContainsString("'gold'; Remove-VM", $joined);
    }

    public function test_clone_is_idempotent_when_vm_already_exists_without_copy(): void
    {
        $server = $this->hypervServer(['template_vm' => self::TEMPLATE]);
        $service = $this->service($server);

        Http::fake(function ($request) {
            $body = (string) $request->body();
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => self::VM, 'state' => 'Off', 'vmId' => self::GUID]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $result = app(HyperV::class)->provision($service, $this->vmConfig());

        $this->assertTrue($result->success);
        $this->assertSame(1, PanelAccount::count());
        $bodies = $this->bodies();
        foreach ($bodies as $b) {
            $this->assertStringNotContainsString('Copy-Item', $b);
        }
        // Idempotent hit clones nothing: no provenance claim recorded.
        $inner = is_array(PanelAccount::sole()->meta['meta'] ?? null) ? PanelAccount::sole()->meta['meta'] : [];
        $this->assertArrayNotHasKey('cloned', $inner);
    }

    public function test_controller_persisted_template_drives_provisioning_then_clear_restores_blank_disk(): void
    {
        $server = $this->hypervServer();
        $service = $this->service($server);

        // Persist the template through the real admin HTTP route.
        $this->actingAsAdminWith(['hosting.manage'])
            ->put(route('admin.servers.update', $server), [
                'name' => $server->name,
                'ip_address' => '10.0.0.9',
                'server_type' => 'hyperv',
                'status' => 'active',
                'host' => '10.0.0.9',
                'port' => 5985,
                'use_ssl' => '0',
                'verify_tls' => '1',
                'password' => '',
                'template_vm' => self::TEMPLATE,
                'max_accounts' => 0,
            ])
            ->assertRedirect(route('admin.servers.show', $server));

        $this->assertSame(self::TEMPLATE, $server->fresh()->connection_meta['template_vm'] ?? null);

        // One fake for BOTH phases: Http::fake() stubs merge (first match
        // wins), so a second Http::fake() would NOT replace this one.
        Http::fake(function ($request) {
            $body = (string) $request->body();

            // Phase 2 blank-disk provision (testvm2) first: its createVm
            // script contains Test-Path/.vhdx which would otherwise match
            // the clone dest-check branch below.
            if (str_contains($body, 'testvm2') && str_contains($body, 'New-VM')) {
                return Http::response(['vmId' => self::GUID, 'name' => 'testvm2', 'state' => 'Off', 'vhdPath' => 'C:\\VMs\\testvm2.vhdx', 'switchName' => 'Default Switch', 'generation' => 2]);
            }

            if (str_contains($body, 'Copy-Item')) {
                return Http::response([
                    'vmId' => self::GUID,
                    'name' => self::VM,
                    'state' => 'Off',
                    'vhdPath' => 'C:\\VMs\\'.self::VM.'.vhdx',
                    'switchName' => 'Default Switch',
                    'generation' => 1,
                    'cloned' => true,
                    'templateVm' => self::TEMPLATE,
                ]);
            }

            if (str_contains($body, 'Test-Path') && str_contains($body, '.vhdx') && ! str_contains($body, self::TEMPLATE) && ! str_contains($body, 'Copy-Item')) {
                return Http::response(['vhdPath' => 'C:\\VMs\\'.self::VM.'.vhdx']);
            }

            if (str_contains($body, self::TEMPLATE) && str_contains($body, 'Get-VM')) {
                return Http::response(['generation' => 1, 'sourceVhd' => self::TEMPLATE_VHD]);
            }

            // Host-verify probe after create (Get-VM -Id): the VM now exists.
            if (str_contains($body, 'Get-VM -Id')) {
                $verifyName = str_contains($body, 'testvm2') ? 'testvm2' : self::VM;
                return Http::response(['exists' => true, 'name' => $verifyName, 'state' => 'Off', 'vmId' => self::GUID]);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => false]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $result = app(HyperV::class)->provision($service, $this->vmConfig());

        $this->assertTrue($result->success);
        $joined = implode("\n", $this->bodies());
        $this->assertStringContainsString('Copy-Item', $joined);
        $this->assertStringNotContainsString('New-VHD', $joined);
        $inner = is_array(PanelAccount::sole()->meta['meta'] ?? null) ? PanelAccount::sole()->meta['meta'] : [];
        $this->assertSame(self::TEMPLATE, $inner['templateVm'] ?? null);

        // Clear the template via the same route, then provision a SECOND
        // service (the first is now provisioned/idempotent).
        $this->actingAsAdminWith(['hosting.manage'])
            ->put(route('admin.servers.update', $server), [
                'name' => $server->name,
                'ip_address' => '10.0.0.9',
                'server_type' => 'hyperv',
                'status' => 'active',
                'host' => '10.0.0.9',
                'port' => 5985,
                'use_ssl' => '0',
                'verify_tls' => '1',
                'password' => '',
                'template_vm' => '',
                'max_accounts' => 0,
            ])
            ->assertRedirect(route('admin.servers.show', $server));

        $this->assertArrayNotHasKey('template_vm', $server->fresh()->connection_meta ?? []);

        $sentBefore = count(Http::recorded());
        $service2 = $this->service($server);

        $result2 = app(HyperV::class)->provision($service2, array_merge($this->vmConfig(), ['host_name' => 'testvm2']));

        $this->assertTrue($result2->success);
        $newBodies = array_slice($this->bodies(), $sentBefore);
        $this->assertNotEmpty($newBodies);
        $newJoined = implode("\n", $newBodies);
        $this->assertStringContainsString('New-VHD', $newJoined);
        $this->assertStringNotContainsString('Copy-Item', $newJoined);
    }

    // ── curated list / default resolution (task NEW RESOLUTION RULE) ──

    public function test_explicit_template_in_curated_list_clones(): void
    {
        $server = $this->hypervServer([
            'template_vms' => [self::TEMPLATE, 'other-tpl'],
            'template_vm' => self::TEMPLATE,
        ]);
        $service = $this->service($server);

        Http::fake(function ($request) {
            $body = (string) $request->body();
            if (str_contains($body, 'Copy-Item')) {
                return Http::response([
                    'vmId' => self::GUID,
                    'name' => self::VM,
                    'state' => 'Off',
                    'vhdPath' => 'C:\\VMs\\'.self::VM.'.vhdx',
                    'switchName' => 'Default Switch',
                    'generation' => 1,
                    'cloned' => true,
                    'templateVm' => 'other-tpl',
                ]);
            }
            if (str_contains($body, 'Test-Path') && str_contains($body, '.vhdx') && ! str_contains($body, 'other-tpl') && ! str_contains($body, self::TEMPLATE) && ! str_contains($body, 'Copy-Item')) {
                return Http::response(['vhdPath' => 'C:\\VMs\\'.self::VM.'.vhdx']);
            }
            if ((str_contains($body, 'other-tpl') || str_contains($body, self::TEMPLATE)) && str_contains($body, 'Get-VM')) {
                // template probe for the explicit one
                if (str_contains($body, 'other-tpl')) {
                    return Http::response(['generation' => 1, 'sourceVhd' => 'C:\\VMs\\other-tpl.vhdx']);
                }

                return Http::response(['generation' => 1, 'sourceVhd' => self::TEMPLATE_VHD]);
            }
            // Host-verify probe after create (Get-VM -Id): the VM now exists.
            if (str_contains($body, 'Get-VM -Id')) {
                $verifyName = str_contains($body, 'testvm2') ? 'testvm2' : self::VM;
                return Http::response(['exists' => true, 'name' => $verifyName, 'state' => 'Off', 'vmId' => self::GUID]);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => false]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $result = app(HyperV::class)->provision($service, $this->vmConfig() + ['template_vm' => 'other-tpl']);

        $this->assertTrue($result->success);
        $bodies = $this->bodies();
        $joined = implode("\n", $bodies);
        $this->assertStringContainsString('Copy-Item', $joined);
        $this->assertStringNotContainsString('New-VHD', $joined);
        // Must have used the explicit template, not the default
        $this->assertStringContainsString('other-tpl', $joined);
        $inner = is_array(PanelAccount::sole()->meta['meta'] ?? null) ? PanelAccount::sole()->meta['meta'] : [];
        $this->assertSame('other-tpl', $inner['templateVm'] ?? null);
    }

    public function test_explicit_template_not_in_curated_list_fails_without_host_call(): void
    {
        $server = $this->hypervServer([
            'template_vms' => [self::TEMPLATE, 'other-tpl'],
            'template_vm' => self::TEMPLATE,
        ]);
        $service = $this->service($server);

        Http::fake();

        $result = app(HyperV::class)->provision($service, $this->vmConfig() + ['template_vm' => 'evil-tpl']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('evil-tpl', (string) $result->message);
        $this->assertStringContainsString('hv-1', (string) $result->message);
        $this->assertSame(0, PanelAccount::count());
        Http::assertNothingSent();
    }

    public function test_explicit_template_case_mismatch_is_rejected_strict(): void
    {
        $server = $this->hypervServer([
            'template_vms' => [self::TEMPLATE],
            'template_vm' => self::TEMPLATE,
        ]);
        $service = $this->service($server);

        Http::fake();

        $result = app(HyperV::class)->provision($service, $this->vmConfig() + ['template_vm' => strtoupper(self::TEMPLATE)]);

        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }

    public function test_explicit_template_with_surrounding_spaces_is_trimmed_and_clones(): void
    {
        $server = $this->hypervServer([
            'template_vms' => [self::TEMPLATE],
            'template_vm' => self::TEMPLATE,
        ]);
        $service = $this->service($server);

        Http::fake(function ($request) {
            $body = (string) $request->body();
            if (str_contains($body, 'Copy-Item')) {
                return Http::response(['vmId' => self::GUID, 'name' => self::VM, 'state' => 'Off', 'vhdPath' => 'C:\\VMs\\'.self::VM.'.vhdx', 'switchName' => 'Default Switch', 'generation' => 1, 'cloned' => true, 'templateVm' => self::TEMPLATE]);
            }
            if (str_contains($body, 'Test-Path') && str_contains($body, '.vhdx') && ! str_contains($body, self::TEMPLATE) && ! str_contains($body, 'Copy-Item')) {
                return Http::response(['vhdPath' => 'C:\\VMs\\'.self::VM.'.vhdx']);
            }
            if (str_contains($body, self::TEMPLATE) && str_contains($body, 'Get-VM')) {
                return Http::response(['generation' => 1, 'sourceVhd' => self::TEMPLATE_VHD]);
            }
            // Host-verify probe after create (Get-VM -Id): the VM now exists.
            if (str_contains($body, 'Get-VM -Id')) {
                $verifyName = str_contains($body, 'testvm2') ? 'testvm2' : self::VM;
                return Http::response(['exists' => true, 'name' => $verifyName, 'state' => 'Off', 'vmId' => self::GUID]);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => false]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $result = app(HyperV::class)->provision($service, $this->vmConfig() + ['template_vm' => '  '.self::TEMPLATE.'  ']);
        $this->assertTrue($result->success);
    }

    public function test_no_explicit_with_curated_default_clones_with_default(): void
    {
        $default = 'default-tpl';
        $server = $this->hypervServer([
            'template_vms' => [$default, 'other-tpl'],
            'template_vm' => $default,
        ]);
        $service = $this->service($server);

        Http::fake(function ($request) use ($default) {
            $body = (string) $request->body();
            if (str_contains($body, 'Copy-Item')) {
                return Http::response([
                    'vmId' => self::GUID,
                    'name' => self::VM,
                    'state' => 'Off',
                    'vhdPath' => 'C:\\VMs\\'.self::VM.'.vhdx',
                    'switchName' => 'Default Switch',
                    'generation' => 1,
                    'cloned' => true,
                    'templateVm' => $default,
                ]);
            }
            if (str_contains($body, 'Test-Path') && str_contains($body, '.vhdx') && ! str_contains($body, $default) && ! str_contains($body, 'Copy-Item')) {
                return Http::response(['vhdPath' => 'C:\\VMs\\'.self::VM.'.vhdx']);
            }
            if (str_contains($body, $default) && str_contains($body, 'Get-VM')) {
                return Http::response(['generation' => 1, 'sourceVhd' => 'C:\\VMs\\'.$default.'.vhdx']);
            }
            // Host-verify probe after create (Get-VM -Id): the VM now exists.
            if (str_contains($body, 'Get-VM -Id')) {
                $verifyName = str_contains($body, 'testvm2') ? 'testvm2' : self::VM;
                return Http::response(['exists' => true, 'name' => $verifyName, 'state' => 'Off', 'vmId' => self::GUID]);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => false]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $result = app(HyperV::class)->provision($service, $this->vmConfig());

        $this->assertTrue($result->success);
        $joined = implode("\n", $this->bodies());
        $this->assertStringContainsString('Copy-Item', $joined);
        $this->assertStringContainsString($default, $joined);
        $this->assertStringNotContainsString('New-VHD', $joined);
        $inner = is_array(PanelAccount::sole()->meta['meta'] ?? null) ? PanelAccount::sole()->meta['meta'] : [];
        $this->assertSame($default, $inner['templateVm'] ?? null);
    }

    public function test_no_explicit_with_curated_non_empty_no_default_fails_without_host_call(): void
    {
        $server = $this->hypervServer([
            'template_vms' => [self::TEMPLATE, 'other-tpl'],
            // no template_vm key => no default
        ]);
        $service = $this->service($server);

        Http::fake();

        $result = app(HyperV::class)->provision($service, $this->vmConfig());

        $this->assertFalse($result->success);
        $this->assertStringContainsString('no template selected and no default template configured for server', strtolower((string) $result->message));
        $this->assertSame(0, PanelAccount::count());
        Http::assertNothingSent();
    }

    public function test_curated_list_empty_uses_blank_disk_path(): void
    {
        $server = $this->hypervServer([
            'template_vms' => [],
        ]);
        $service = $this->service($server);

        Http::fake(function ($request) {
            $body = (string) $request->body();
            if (str_contains($body, 'New-VM')) {
                return Http::response(['vmId' => self::GUID, 'name' => self::VM, 'state' => 'Off', 'vhdPath' => 'C:\\VMs\\'.self::VM.'.vhdx', 'switchName' => 'Default Switch', 'generation' => 2]);
            }
            // Host-verify probe after create: the VM now exists.
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => self::VM, 'state' => 'Off', 'vmId' => self::GUID]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $result = app(HyperV::class)->provision($service, $this->vmConfig());

        $this->assertTrue($result->success);
        $joined = implode("\n", $this->bodies());
        $this->assertStringContainsString('New-VHD', $joined);
        $this->assertStringNotContainsString('Copy-Item', $joined);
    }

    public function test_curated_list_empty_with_explicit_fails_instead_of_blank_disk(): void
    {
        $server = $this->hypervServer([
            'template_vms' => [],
        ]);
        $service = $this->service($server);

        Http::fake();

        $result = app(HyperV::class)->provision($service, $this->vmConfig() + ['template_vm' => self::TEMPLATE]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString(self::TEMPLATE, (string) $result->message);
        Http::assertNothingSent();
    }

    // ── helpers ──

    private function hypervServer(array $connectionMeta = []): Server
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
            'connection_meta' => $connectionMeta,
        ]);
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);
    }

    private function service(Server $server): ServiceInstance
    {
        return ServiceInstance::create([
            'customer_id' => $this->makeCustomer()->id,
            'server_id' => $server->id,
            'service_tag' => 'SVC-'.str()->random(8),
            'username' => self::VM,
            'status' => 'active',
        ]);
    }

    private function vmConfig(): array
    {
        return ['cpu' => 2, 'ram' => 2048, 'disk' => 50, 'switch' => 'Default Switch', 'generation' => 2, 'host_name' => self::VM];
    }

    /** @return list<string> */
    private function bodies(): array
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => (string) $pair[0]->body())
            ->all();
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
