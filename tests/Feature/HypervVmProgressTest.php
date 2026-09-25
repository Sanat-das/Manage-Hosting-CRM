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

/**
 * Async Hyper-V VM builds: queued job, host verification, vm-status
 * contract, guest password reset and encrypted credential storage.
 *
 * QUEUE_CONNECTION=sync in phpunit.xml, so dispatched jobs run inline —
 * the POST still returns the async contract (202/info) while the event
 * row ends completed/failed by assertion time.
 */
class HypervVmProgressTest extends TestCase
{
    use RefreshDatabase;

    private const VM = 'testvm1';

    private const GUID = '11111111-2222-3333-4444-555555555555';

    private const VHD = 'C:\\VMs\\testvm1.vhdx';

    public function test_create_dispatches_job_with_exactly_one_completed_event(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'suspended');
        Http::fake(function ($request) {
            $body = (string) $request->body();
            if (str_contains($body, 'New-VM')) {
                return Http::response(['vmId' => self::GUID, 'name' => 'newvm', 'state' => 'Off']);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => 'newvm', 'state' => 'Off', 'vmId' => self::GUID]);
            }
            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'create',
            ])
            ->assertRedirect()
            ->assertSessionHas('info');

        $events = ProvisioningEvent::where('hosting_account_id', $account->id)
            ->where('event_type', 'provision')
            ->get();

        $this->assertCount(1, $events);
        $this->assertSame('completed', $events->sole()->status);
        $this->assertSame(self::GUID, PanelAccount::sole()->external_id);
        $this->assertSame('active', $account->fresh()->status);
    }

    public function test_create_json_returns_started_contract(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'suspended');
        Http::fake(function ($request) {
            $body = (string) $request->body();
            if (str_contains($body, 'New-VM')) {
                return Http::response(['vmId' => self::GUID, 'name' => 'newvm', 'state' => 'Off']);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => 'newvm', 'state' => 'Off', 'vmId' => self::GUID]);
            }
            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $response = $this->actingAsAdminWith(['hosting.edit'])
            ->postJson(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'create',
            ]);

        $response->assertStatus(202)->assertJson(['ok' => true, 'started' => true, 'action' => 'create']);
        $this->assertNotNull($response->json('event_id'));
    }

    public function test_recorded_but_missing_vm_triggers_rebuild(): void
    {
        // Active record whose VM was deleted out-of-band must rebuild,
        // not short-circuit with a false "already provisioned" success.
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');
        $built = false;
        Http::fake(function ($request) use (&$built) {
            $body = (string) $request->body();
            if (str_contains($body, 'New-VM')) {
                $built = true;
                return Http::response(['vmId' => self::GUID, 'name' => self::VM, 'state' => 'Off']);
            }
            if (str_contains($body, 'Get-VM')) {
                if ($built) {
                    return Http::response(['exists' => true, 'name' => self::VM, 'state' => 'Off', 'vmId' => self::GUID]);
                }
                return Http::response(['exists' => false]);
            }
            if (str_contains($body, 'Start-VM -VM')) {
                return Http::response(['state' => 'Running']);
            }
            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'create',
            ])
            ->assertRedirect()
            ->assertSessionHas('info');

        $this->assertTrue($built, 'Rebuild never sent New-VM to the host');
        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'New-VM'));
        $event = ProvisioningEvent::where('hosting_account_id', $account->id)
            ->where('event_type', 'provision')->orderByDesc('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('completed', $event->status);
    }

    public function test_start_after_create_calls_start_vm(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'suspended');
        $state = 'Off';
        Http::fake(function ($request) use (&$state) {
            $body = (string) $request->body();
            if (str_contains($body, 'New-VM')) {
                return Http::response(['vmId' => self::GUID, 'name' => 'newvm', 'state' => 'Off']);
            }
            if (str_contains($body, 'Start-VM -VM')) {
                $state = 'Running';
                return Http::response(['state' => 'Running', 'name' => 'newvm', 'vmId' => self::GUID]);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => 'newvm', 'state' => $state, 'vmId' => self::GUID]);
            }
            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'create',
                'start_after_create' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('info');

        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'Start-VM -VM'));
        $this->assertSame('Running', $state);
    }

    public function test_create_verification_failure_fails_loud_with_no_record(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'suspended');
        Http::fake(function ($request) {
            $body = (string) $request->body();
            if (str_contains($body, 'New-VM')) {
                return Http::response(['vmId' => self::GUID, 'name' => 'newvm', 'state' => 'Off']);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => false]);
            }
            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'create',
            ])
            ->assertRedirect()
            ->assertSessionHas('info');

        $event = ProvisioningEvent::where('hosting_account_id', $account->id)
            ->where('event_type', 'provision')->orderByDesc('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('failed', $event->status);
        $this->assertStringContainsString('not present on the host', (string) $event->last_error);
        $this->assertSame(0, PanelAccount::count());
    }

    public function test_vm_status_off_vm_can_flags(): void
    {
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');
        $state = 'Off';
        $this->fakeHost($state);

        $response = $this->actingAsAdminWith(['hosting.view'])
            ->getJson(route('admin.hosting.vm-status', $account));

        $response->assertOk();
        $json = $response->json();
        $this->assertTrue($json['ok']);
        $this->assertTrue($json['vm']['exists']);
        $this->assertSame('Off', $json['vm']['state']);
        $this->assertFalse($json['can']['create']);
        $this->assertTrue($json['can']['start']);
        $this->assertFalse($json['can']['stop']);
        $this->assertFalse($json['can']['restart']);
        $this->assertTrue($json['can']['delete']);
        $this->assertFalse($json['can']['reset_password']);
        $this->assertSame('A VM already exists on the host — delete it first to rebuild.', $json['reasons']['create']);
        $this->assertSame('Start the VM to reset the Administrator password.', $json['reasons']['reset_password']);
    }

    public function test_vm_status_running_vm_can_flags(): void
    {
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');
        $state = 'Running';
        $this->fakeHost($state);

        $json = $this->actingAsAdminWith(['hosting.view'])
            ->getJson(route('admin.hosting.vm-status', $account))->json();

        $this->assertTrue($json['vm']['exists']);
        $this->assertSame('Running', $json['vm']['state']);
        $this->assertTrue($json['can']['stop']);
        $this->assertTrue($json['can']['restart']);
        $this->assertTrue($json['can']['reset_password']);
        $this->assertFalse($json['can']['start']);
        $this->assertFalse($json['can']['delete']);
        $this->assertSame('Stop the VM first.', $json['reasons']['delete']);
    }

    public function test_vm_status_missing_vm_and_terminated(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'suspended');

        $json = $this->actingAsAdminWith(['hosting.view'])
            ->getJson(route('admin.hosting.vm-status', $account))->json();

        $this->assertFalse($json['vm']['exists']);
        $this->assertTrue($json['can']['create']);
        $this->assertFalse($json['can']['start']);
        $this->assertSame('VM is not created on the host yet.', $json['reasons']['start']);

        [$terminated] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'terminated');
        $state = 'Off';
        $this->fakeHost($state);

        $jsonT = $this->actingAsAdminWith(['hosting.view'])
            ->getJson(route('admin.hosting.vm-status', $terminated))->json();

        $this->assertFalse($jsonT['can']['start']);
        $this->assertFalse($jsonT['can']['stop']);
        $this->assertStringContainsString('terminated', strtolower((string) ($jsonT['reasons']['start'] ?? '')));
    }

    public function test_vm_status_running_action_blocks_everything(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'suspended');
        ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => $account->id,
            'event_type' => 'provision',
            'status' => 'running',
            'event_status' => 'running',
            'payload' => ['module' => 'hyperv', 'action' => 'create', 'stage' => 'cloning'],
        ]);

        $json = $this->actingAsAdminWith(['hosting.view'])
            ->getJson(route('admin.hosting.vm-status', $account))->json();

        $this->assertTrue($json['action']['running']);
        $this->assertSame('cloning', $json['action']['stage']);
        $this->assertSame('Creating the VM on the host', $json['action']['stage_label']);
        $this->assertSame(35, $json['action']['progress']);
        foreach (['create', 'start', 'stop', 'restart', 'delete', 'reset_password'] as $verb) {
            $this->assertFalse($json['can'][$verb]);
            $this->assertSame('An action is already running.', $json['reasons'][$verb]);
        }
    }

    public function test_reset_vm_password_succeeds_with_stored_credentials(): void
    {
        [$account, $service] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active', withGuest: true);
        $state = 'Running';
        Http::fake(function ($request) use (&$state) {
            $body = (string) $request->body();
            if (str_contains($body, 'Invoke-Command -VMName')) {
                return Http::response(['ok' => true, 'vmName' => self::VM]);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => self::VM, 'state' => $state, 'vmId' => self::GUID]);
            }
            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $response = $this->actingAsAdminWith(['hosting.edit'])
            ->postJson(route('admin.hosting.reset-vm-password', $account), [
                'password' => 'NewSecret123',
                'password_confirmation' => 'NewSecret123',
            ]);

        $response->assertOk()->assertJson(['ok' => true, 'username' => 'Administrator']);
        $this->assertSame('NewSecret123', $response->json('password'));
        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'Invoke-Command -VMName'));

        // New password persisted encrypted (decrypts back), never in meta.
        $fresh = PanelAccount::where('service_instance_id', $service->id)->first();
        $this->assertSame('NewSecret123', $fresh->guest_password_encrypted);
        $this->assertStringNotContainsString('NewSecret123', (string) json_encode($fresh->meta));

        $event = ProvisioningEvent::where('hosting_account_id', $account->id)
            ->where('event_type', 'update')->orderByDesc('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('completed', $event->status);
    }

    public function test_reset_vm_password_fails_without_stored_credentials(): void
    {
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');
        Http::fake();
        // No guest password stored and no current_password supplied.
        $response = $this->actingAsAdminWith(['hosting.edit'])
            ->postJson(route('admin.hosting.reset-vm-password', $account), [
                'password' => 'NewSecret123',
                'password_confirmation' => 'NewSecret123',
            ]);

        $response->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertStringContainsString('current password', strtolower((string) $response->json('message')));
        Http::assertNothingSent();
    }

    public function test_guest_credentials_stored_encrypted_never_in_meta(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'suspended');
        Http::fake(function ($request) {
            $body = (string) $request->body();
            if (str_contains($body, 'New-VM')) {
                return Http::response(['vmId' => self::GUID, 'name' => 'newvm', 'state' => 'Off']);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => 'newvm', 'state' => 'Off', 'vmId' => self::GUID]);
            }
            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'create',
                'guest_username' => 'Administrator',
                'guest_password' => 'GuestSecret99',
            ])
            ->assertRedirect();

        $panel = PanelAccount::sole();
        $this->assertSame('Administrator', $panel->guest_username);
        // Encrypted cast decrypts on read — raw column must differ from plaintext.
        $raw = \Illuminate\Support\Facades\DB::table('panel_accounts')->where('id', $panel->id)->value('guest_password_encrypted');
        $this->assertNotSame('GuestSecret99', $raw);
        $this->assertSame('GuestSecret99', $panel->guest_password_encrypted);
        $metaJson = (string) json_encode($panel->meta);
        $this->assertStringNotContainsString('GuestSecret99', $metaJson);
        $this->assertStringNotContainsString('guest_password', $metaJson);
    }

    public function test_early_validation_failure_fails_the_event_instead_of_leaving_it_running(): void
    {
        // Terminated account: ManualProvisioner refuses BEFORE the driver call.
        // The queued event must still end failed — a stranded `running` row
        // would block every later build and keep the UI progress bar alive.
        [$account] = $this->hostingWithHyperV(hostingStatus: 'terminated');
        Http::fake();

        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'create',
            ])
            ->assertRedirect();

        $event = ProvisioningEvent::where('hosting_account_id', $account->id)
            ->where('event_type', 'provision')->orderByDesc('id')->first();

        $this->assertNotNull($event);
        $this->assertSame('failed', $event->status);
        $this->assertStringContainsString('not pending', (string) $event->last_error);
        Http::assertNothingSent();
    }

    public function test_existing_vm_completes_the_queued_event_without_rebuilding(): void
    {
        // Recorded VM still on the host: the build must be a no-op that
        // COMPLETES the queued event (never leaves it running, never clones).
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');
        $state = 'Off';
        $this->fakeHost($state);

        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'create',
            ])
            ->assertRedirect()
            ->assertSessionHas('info');

        $event = ProvisioningEvent::where('hosting_account_id', $account->id)
            ->where('event_type', 'provision')->orderByDesc('id')->first();

        $this->assertNotNull($event);
        $this->assertSame('completed', $event->status);
        $this->assertStringContainsString('already exists', (string) ($event->result['message'] ?? ''));
        Http::assertNotSent(fn ($r) => str_contains((string) $r->body(), 'New-VM'));
    }

    public function test_start_after_create_failure_is_reported_not_silently_successful(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'suspended');
        Http::fake(function ($request) {
            $body = (string) $request->body();
            if (str_contains($body, 'New-VM')) {
                return Http::response(['vmId' => self::GUID, 'name' => 'newvm', 'state' => 'Off']);
            }
            if (str_contains($body, 'Start-VM -VM')) {
                return Http::response(['error' => 'HOST-START-REFUSED'], 500);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => 'newvm', 'state' => 'Off', 'vmId' => self::GUID]);
            }
            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'create',
                'start_after_create' => true,
            ])
            ->assertRedirect();

        $event = ProvisioningEvent::where('hosting_account_id', $account->id)
            ->where('event_type', 'provision')->orderByDesc('id')->first();

        // The VM exists, so the record is written and the event completes —
        // but the message must carry the start failure, never a bare success.
        $this->assertNotNull($event);
        $this->assertSame('completed', $event->status);
        $this->assertStringContainsString('failed to start', strtolower((string) ($event->result['message'] ?? '')));
        $this->assertSame(1, PanelAccount::count());
    }

    // ─────────────────── credential verification probe ───────────────────

    public function test_create_verifies_guest_credentials_when_running(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'suspended', linkConfig: [
            'credential_probe_attempts' => 1,
            'credential_probe_delay_seconds' => 0,
        ]);
        $state = 'Off';
        Http::fake(function ($request) use (&$state) {
            $body = (string) $request->body();
            if (str_contains($body, 'New-VM')) {
                return Http::response(['vmId' => self::GUID, 'name' => 'newvm', 'state' => 'Off']);
            }
            if (str_contains($body, 'Start-VM -VM')) {
                $state = 'Running';
                return Http::response(['state' => 'Running', 'name' => 'newvm', 'vmId' => self::GUID]);
            }
            if (str_contains($body, 'Invoke-Command -VMName')) {
                return Http::response(['verified' => true, 'guest' => 'NEWVM']);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => 'newvm', 'state' => $state, 'vmId' => self::GUID]);
            }
            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'create',
                'start_after_create' => true,
                'guest_username' => 'Administrator',
                'guest_password' => 'Secret123',
            ])
            ->assertRedirect();

        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'Invoke-Command -VMName')
            && str_contains((string) $r->body(), 'PSCredential'));

        $event = ProvisioningEvent::where('hosting_account_id', $account->id)
            ->where('event_type', 'provision')->orderByDesc('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('completed', $event->status);
        $this->assertStringNotContainsString('could not be verified', strtolower((string) ($event->result['message'] ?? '')));

        $panel = PanelAccount::sole();
        $this->assertTrue($panel->meta['credentials_verified'] ?? false);
        // The stored password itself never leaks into meta.
        $this->assertStringNotContainsString('Secret123', (string) json_encode($panel->meta));
    }

    public function test_create_unverifiable_credentials_complete_with_warning(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'suspended', linkConfig: [
            'credential_probe_attempts' => 1,
            'credential_probe_delay_seconds' => 0,
        ]);
        $state = 'Off';
        Http::fake(function ($request) use (&$state) {
            $body = (string) $request->body();
            if (str_contains($body, 'New-VM')) {
                return Http::response(['vmId' => self::GUID, 'name' => 'newvm', 'state' => 'Off']);
            }
            if (str_contains($body, 'Start-VM -VM')) {
                $state = 'Running';
                return Http::response(['state' => 'Running', 'name' => 'newvm', 'vmId' => self::GUID]);
            }
            if (str_contains($body, 'Invoke-Command -VMName')) {
                return Http::response(['error' => 'Invalid credentials']);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => 'newvm', 'state' => $state, 'vmId' => self::GUID]);
            }
            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'create',
                'start_after_create' => true,
                'guest_username' => 'Administrator',
                'guest_password' => 'WrongSecret',
            ])
            ->assertRedirect();

        // Creation still records and completes — verification failure is a warning, never a failure.
        $event = ProvisioningEvent::where('hosting_account_id', $account->id)
            ->where('event_type', 'provision')->orderByDesc('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('completed', $event->status);
        $this->assertStringContainsString('could not be verified', strtolower((string) ($event->result['message'] ?? '')));
        $this->assertSame(1, PanelAccount::count());
    }

    public function test_create_skips_probe_when_vm_not_running(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'suspended');
        Http::fake(function ($request) {
            $body = (string) $request->body();
            if (str_contains($body, 'New-VM')) {
                return Http::response(['vmId' => self::GUID, 'name' => 'newvm', 'state' => 'Off']);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => 'newvm', 'state' => 'Off', 'vmId' => self::GUID]);
            }
            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'create',
                'guest_username' => 'Administrator',
                'guest_password' => 'Secret123',
            ])
            ->assertRedirect();

        Http::assertNotSent(fn ($r) => str_contains((string) $r->body(), 'Invoke-Command -VMName'));
        $event = ProvisioningEvent::where('hosting_account_id', $account->id)
            ->where('event_type', 'provision')->orderByDesc('id')->first();
        $this->assertSame('completed', $event->status);
    }

    public function test_create_skips_probe_without_guest_credentials(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'suspended');
        $state = 'Off';
        Http::fake(function ($request) use (&$state) {
            $body = (string) $request->body();
            if (str_contains($body, 'New-VM')) {
                return Http::response(['vmId' => self::GUID, 'name' => 'newvm', 'state' => 'Off']);
            }
            if (str_contains($body, 'Start-VM -VM')) {
                $state = 'Running';
                return Http::response(['state' => 'Running', 'name' => 'newvm', 'vmId' => self::GUID]);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => 'newvm', 'state' => $state, 'vmId' => self::GUID]);
            }
            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'create',
                'start_after_create' => true,
            ])
            ->assertRedirect();

        Http::assertNotSent(fn ($r) => str_contains((string) $r->body(), 'Invoke-Command -VMName'));
    }

    // ─────────────────── credentials reveal endpoint ───────────────────

    public function test_vm_credentials_returns_stored_password(): void
    {
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active', withGuest: true);
        Http::fake();

        $response = $this->actingAsAdminWith(['hosting.edit'])
            ->getJson(route('admin.hosting.vm-credentials', $account));

        $response->assertOk()->assertJson([
            'ok' => true,
            'stored' => true,
            'username' => 'Administrator',
            'password' => 'OldSecret123',
        ]);
    }

    public function test_vm_credentials_forbidden_without_edit_permission(): void
    {
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active', withGuest: true);
        Http::fake();

        $this->actingAsAdminWith(['hosting.view'])
            ->getJson(route('admin.hosting.vm-credentials', $account))
            ->assertForbidden();
    }

    public function test_vm_credentials_unstored_returns_422_shape(): void
    {
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');
        Http::fake();

        $response = $this->actingAsAdminWith(['hosting.edit'])
            ->getJson(route('admin.hosting.vm-credentials', $account));

        $response->assertStatus(422)->assertJson(['ok' => false, 'stored' => false]);
        $this->assertStringContainsString('No Administrator credentials are stored', (string) $response->json('message'));
    }

    public function test_vm_credentials_reveal_is_audited(): void
    {
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active', withGuest: true);
        Http::fake();

        $this->actingAsAdminWith(['hosting.edit'])
            ->getJson(route('admin.hosting.vm-credentials', $account))
            ->assertOk();

        $this->assertTrue(
            \App\Models\AuditLog::where('entity_type', 'hosting_account')
                ->where('entity_id', $account->id)
                ->where('action', 'hosting.module_action')
                ->where('details', 'like', '%reveal_credentials%')
                ->exists(),
            'Successful credential reveal was not audited'
        );
    }

    // ─────────────── opt-in password rotation on create ───────────────

    public function test_create_with_apply_password_rotates_and_stores_new_password(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'suspended', linkConfig: [
            'credential_probe_attempts' => 1,
            'credential_probe_delay_seconds' => 0,
        ]);
        $state = 'Off';
        Http::fake(function ($request) use (&$state) {
            $body = (string) $request->body();
            if (str_contains($body, 'New-VM')) {
                return Http::response(['vmId' => self::GUID, 'name' => 'newvm', 'state' => 'Off']);
            }
            if (str_contains($body, 'Start-VM -VM')) {
                $state = 'Running';
                return Http::response(['state' => 'Running', 'name' => 'newvm', 'vmId' => self::GUID]);
            }
            if (str_contains($body, 'Invoke-Command -VMName')) {
                // Probe (reads COMPUTERNAME) vs rotation (Set-LocalUser).
                if (str_contains($body, 'Set-LocalUser')) {
                    return Http::response(['ok' => true, 'vmName' => 'newvm']);
                }
                return Http::response(['verified' => true, 'guest' => 'NEWVM']);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => 'newvm', 'state' => $state, 'vmId' => self::GUID]);
            }
            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'create',
                'start_after_create' => true,
                'guest_username' => 'Administrator',
                'guest_password' => 'CurrentSecret123',
                'apply_password' => true,
            ])
            ->assertRedirect();

        // Rotation ran inside the guest after a verified probe.
        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'Set-LocalUser'));

        $panel = PanelAccount::sole();
        $newPassword = $panel->guest_password_encrypted;
        $this->assertNotSame('CurrentSecret123', $newPassword);
        $this->assertStringEndsWith('!aA9', $newPassword);

        // The new password itself never leaks into meta (the rotated flag key
        // shares a prefix, so assert on the value + the exact secret key).
        $meta = is_array($panel->meta) ? $panel->meta : [];
        $this->assertArrayNotHasKey('guest_password', $meta);
        $this->assertStringNotContainsString($newPassword, (string) json_encode($meta));
        $this->assertTrue($meta['guest_password_rotated'] ?? false);

        $event = ProvisioningEvent::where('hosting_account_id', $account->id)
            ->where('event_type', 'provision')->orderByDesc('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('completed', $event->status);
        $message = strtolower((string) ($event->result['message'] ?? ''));
        $this->assertStringContainsString('a new administrator password was set', $message);
        $this->assertStringNotContainsString('could not be verified', $message);
        $this->assertStringNotContainsString('failed', $message);
    }

    public function test_create_with_apply_password_failure_keeps_original_with_warning(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'suspended', linkConfig: [
            'credential_probe_attempts' => 1,
            'credential_probe_delay_seconds' => 0,
        ]);
        $state = 'Off';
        Http::fake(function ($request) use (&$state) {
            $body = (string) $request->body();
            if (str_contains($body, 'New-VM')) {
                return Http::response(['vmId' => self::GUID, 'name' => 'newvm', 'state' => 'Off']);
            }
            if (str_contains($body, 'Start-VM -VM')) {
                $state = 'Running';
                return Http::response(['state' => 'Running', 'name' => 'newvm', 'vmId' => self::GUID]);
            }
            if (str_contains($body, 'Invoke-Command -VMName')) {
                if (str_contains($body, 'Set-LocalUser')) {
                    return Http::response(['error' => 'Invalid credentials']);
                }
                return Http::response(['verified' => true, 'guest' => 'NEWVM']);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => 'newvm', 'state' => $state, 'vmId' => self::GUID]);
            }
            return Http::response(['error' => 'unexpected host call'], 500);
        });

        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'create',
                'start_after_create' => true,
                'guest_username' => 'Administrator',
                'guest_password' => 'CurrentSecret123',
                'apply_password' => true,
            ])
            ->assertRedirect();

        // Creation still completes — rotation failure is a warning only.
        $event = ProvisioningEvent::where('hosting_account_id', $account->id)
            ->where('event_type', 'provision')->orderByDesc('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('completed', $event->status);
        $this->assertStringContainsString(
            'setting a new administrator password failed',
            strtolower((string) ($event->result['message'] ?? ''))
        );

        // The original credential is untouched.
        $this->assertSame('CurrentSecret123', PanelAccount::sole()->guest_password_encrypted);
    }

    public function test_create_with_apply_password_requires_current_password(): void
    {
        [$account] = $this->hostingWithHyperV(hostingStatus: 'suspended');
        Http::fake();

        $response = $this->actingAsAdminWith(['hosting.edit'])
            ->postJson(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'create',
                'apply_password' => true,
            ]);

        // AJAX validation renders as JSON (bootstrap/app.php now honours
        // expectsJson) so the front-end can show the real field error.
        $response->assertStatus(422)->assertJsonValidationErrors('guest_password');
        $this->assertStringContainsString(
            "Enter the template's current Administrator password",
            (string) $response->json('message')
        );
        Http::assertNothingSent();

        // Plain browser posts still redirect with the error bag.
        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'create',
                'apply_password' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('guest_password');
        Http::assertNothingSent();
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

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);
    }

    private function panelAccount(ServiceInstance $service, Server $server, bool $withGuest = false): PanelAccount
    {
        $data = [
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'hyperv',
            'username' => self::VM,
            'external_id' => self::GUID,
            'meta' => ['vmName' => self::VM, 'meta' => ['vmName' => self::VM, 'vhdPath' => self::VHD]],
            'status' => PanelAccount::STATUS_ACTIVE,
        ];
        if ($withGuest) {
            $data['guest_username'] = 'Administrator';
            $data['guest_password_encrypted'] = 'OldSecret123';
        }
        return PanelAccount::create($data);
    }

    /**
     * Fake the WinRM /wsman endpoint with a stateful host: Get-VM answers
     * the CURRENT value of $state, power verbs answer their post-action
     * state. Anything else is a loud error so stray calls cannot pass
     * silently.
     */
    private function fakeHost(string &$state): void
    {
        Http::fake(function ($request) use (&$state) {
            $body = (string) $request->body();

            if (str_contains($body, 'Remove-VM -VM')) {
                return Http::response(['deletedVhd' => str_contains($body, 'Remove-Item')]);
            }
            if (str_contains($body, 'Restart-VM -VM')) {
                return Http::response(['state' => 'Running']);
            }
            if (str_contains($body, 'Stop-VM -VM')) {
                return Http::response(['state' => 'Off']);
            }
            if (str_contains($body, 'Start-VM -VM')) {
                return Http::response(['state' => 'Running']);
            }
            if (str_contains($body, 'Invoke-Command -VMName')) {
                return Http::response(['ok' => true, 'vmName' => self::VM]);
            }
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => self::VM, 'state' => $state, 'vmId' => self::GUID]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });
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

    /**
     * @return array{0:HostingAccount,1?:ServiceInstance}
     */
    private function hostingWithHyperV(bool $withPanelAccount = false, string $hostingStatus = 'suspended', bool $withGuest = false, array $linkConfig = []): array
    {
        $server = $this->hypervServer();
        $product = Product::create(['name' => 'HV', 'price' => 50]);
        ProductModule::create([
            'product_id' => $product->id, 'module_slug' => 'hyperv', 'enabled' => true, 'config' => $linkConfig,
        ]);
        $customer = $this->makeCustomer();
        $account = HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'server_id' => $server->id,
            'domain' => 'vm.test',
            'host_name' => 'hv-web-'.str()->lower(str()->random(6)),
            'status' => $hostingStatus,
        ]);

        if ($withPanelAccount) {
            $service = ServiceInstance::create([
                'customer_id' => $customer->id,
                'order_id' => null,
                'server_id' => $server->id,
                'domain' => 'vm.test',
                'service_tag' => 'HOST-'.$account->id,
                'username' => self::VM,
                'provisioning_method' => 'hyperv',
                'status' => 'pending',
            ]);
            $this->panelAccount($service, $server, $withGuest);

            return [$account->fresh(), $service];
        }

        return [$account->fresh()];
    }
}
