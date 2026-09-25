<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\PanelAccount;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\ProvisioningEvent;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Client portal VM actions: async provisioning with progress polling,
 * vm-status contract scoping, and customer password reset.
 *
 * QUEUE_CONNECTION=sync in phpunit.xml, so the dispatched build job runs
 * inline — the POST still returns the async contract (202/info) while the
 * event row ends completed/failed by assertion time.
 */
class ClientHostingVmActionsTest extends TestCase
{
    use RefreshDatabase;

    private const VM = 'testvm1';

    private const GUID = '11111111-2222-3333-4444-555555555555';

    private const VHD = 'C:\\VMs\\testvm1.vhdx';

    public function test_provisioning_dispatches_job_with_exactly_one_completed_event(): void
    {
        [$account, $customer] = $this->hostingWithHyperV(hostingStatus: 'pending');
        $state = 'Off';
        $this->fakeBuildHost($state);

        $this->actingAs($customer->user)
            ->post(route('client.hosting.provision', $account))
            ->assertRedirect()
            ->assertSessionHas('info');

        $events = ProvisioningEvent::where('hosting_account_id', $account->id)
            ->where('event_type', 'provision')
            ->get();

        $this->assertCount(1, $events);
        $this->assertSame('completed', $events->sole()->status);
        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'New-VM'));
    }

    public function test_provisioning_json_returns_started_contract(): void
    {
        [$account, $customer] = $this->hostingWithHyperV(hostingStatus: 'pending');
        $state = 'Off';
        $this->fakeBuildHost($state);

        $response = $this->actingAs($customer->user)
            ->postJson(route('client.hosting.provision', $account));

        $response->assertStatus(202)->assertJson(['ok' => true, 'started' => true, 'action' => 'create']);
        $this->assertNotNull($response->json('event_id'));
        $this->assertSame('Your VM build has started.', $response->json('message'));
    }

    public function test_provisioning_refused_while_build_running(): void
    {
        [$account, $customer] = $this->hostingWithHyperV(hostingStatus: 'pending');
        ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => $account->id,
            'event_type' => 'provision',
            'status' => 'running',
            'event_status' => 'running',
            'payload' => ['module' => 'hyperv', 'action' => 'create', 'stage' => 'cloning'],
        ]);
        Http::fake();

        $this->actingAs($customer->user)
            ->postJson(route('client.hosting.provision', $account))
            ->assertStatus(409)
            ->assertJson(['ok' => false, 'message' => 'A VM build is already running for this service.']);

        $this->actingAs($customer->user)
            ->post(route('client.hosting.provision', $account))
            ->assertRedirect()
            ->assertSessionHas('error', 'A VM build is already running for this service.');

        Http::assertNothingSent();
    }

    public function test_vm_status_returns_contract_for_owner_and_404_for_other_customer(): void
    {
        [$account, $customer] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');
        $state = 'Off';
        $this->fakeHost($state);

        $json = $this->actingAs($customer->user)
            ->getJson(route('client.hosting.vm-status', $account))
            ->assertOk()
            ->json();

        $this->assertTrue($json['ok']);
        $this->assertTrue($json['vm']['exists']);
        $this->assertSame('Off', $json['vm']['state']);
        $this->assertFalse($json['can']['create']);
        $this->assertTrue($json['can']['start']);
        $this->assertFalse($json['can']['reset_password']);

        $other = Customer::create(['user_id' => User::factory()->create()->id, 'status' => 'active']);

        $this->actingAs($other->user)
            ->getJson(route('client.hosting.vm-status', $account))
            ->assertNotFound();
    }

    public function test_reset_vm_password_succeeds_with_stored_credentials(): void
    {
        [$account, $customer, $service] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active', withGuest: true);
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

        $response = $this->actingAs($customer->user)
            ->postJson(route('client.hosting.reset-vm-password', $account), [
                'password' => 'NewSecret123',
                'password_confirmation' => 'NewSecret123',
            ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertStringContainsString('RDP', (string) $response->json('message'));
        // The password the customer typed is never echoed back.
        $this->assertArrayNotHasKey('password', $response->json());
        $this->assertStringNotContainsString('NewSecret123', (string) $response->getContent());
        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'Invoke-Command -VMName'));

        // New password persisted encrypted (decrypts back), never in meta.
        $fresh = PanelAccount::where('service_instance_id', $service->id)->first();
        $this->assertSame('NewSecret123', $fresh->guest_password_encrypted);
        $raw = DB::table('panel_accounts')->where('id', $fresh->id)->value('guest_password_encrypted');
        $this->assertNotSame('NewSecret123', $raw);
        $this->assertStringNotContainsString('NewSecret123', (string) json_encode($fresh->meta));

        $event = ProvisioningEvent::where('hosting_account_id', $account->id)
            ->where('event_type', 'update')->orderByDesc('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('completed', $event->status);
        $this->assertStringNotContainsString('NewSecret123', (string) json_encode($event->payload));
        $this->assertStringNotContainsString('NewSecret123', (string) json_encode($event->result));
    }

    public function test_reset_vm_password_fails_without_stored_credentials(): void
    {
        [$account, $customer] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');
        Http::fake();

        $response = $this->actingAs($customer->user)
            ->postJson(route('client.hosting.reset-vm-password', $account), [
                'password' => 'NewSecret123',
                'password_confirmation' => 'NewSecret123',
            ]);

        $response->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertStringContainsString('contact support', strtolower((string) $response->json('message')));
        Http::assertNothingSent();
    }

    public function test_show_page_has_progress_panel_reset_button_and_modal(): void
    {
        [$account, $customer] = $this->hostingWithHyperV(hostingStatus: 'pending');

        // can.reset_password is false with no VM yet — the button renders
        // disabled with its reason, never a dead placeholder link.
        $content = $this->actingAs($customer->user)
            ->get(route('client.hosting.show', $account->id))
            ->assertOk()
            ->getContent();

        foreach (['client-vm-progress', 'client-vm-progress-label', 'client-vm-progress-elapsed', 'client-vm-progress-bar', 'client-vm-state'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $content);
        }
        $this->assertStringContainsString('data-client-hv-action="reset_password"', $content);
        $this->assertStringContainsString('id="client-reset-password-modal"', $content);
        $this->assertStringContainsString('id="client-reset-password"', $content);
        $this->assertStringContainsString('id="client-reset-password-confirm"', $content);
        $this->assertMatchesRegularExpression('/<button[^>]*data-client-hv-action="reset_password"[^>]*disabled[^>]*>/', $content);
        $this->assertStringContainsString('VM is not created on the host yet.', $content);
        $this->assertStringNotContainsString('Change Password', $content);
    }

    // ────────── active-account self-provisioning + customer power actions ──────────

    public function test_provisioning_active_account_without_vm_succeeds(): void
    {
        // hyperv-manual orders activate before the VM exists — an active
        // account with no VM is waiting for self-provisioning.
        [$account, $customer] = $this->hostingWithHyperV(hostingStatus: 'active');
        $state = 'Off';
        $this->fakeBuildHost($state);

        $this->actingAs($customer->user)
            ->post(route('client.hosting.provision', $account))
            ->assertRedirect()
            ->assertSessionHas('info');

        $events = ProvisioningEvent::where('hosting_account_id', $account->id)
            ->where('event_type', 'provision')
            ->get();

        $this->assertCount(1, $events);
        $this->assertSame('completed', $events->sole()->status);
        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'New-VM'));
    }

    public function test_provisioning_active_account_with_vm_is_refused(): void
    {
        [$account, $customer] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');
        $state = 'Off';
        $this->fakeHost($state);

        $this->actingAs($customer->user)
            ->postJson(route('client.hosting.provision', $account))
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => 'This service already has a VM.']);

        $this->actingAs($customer->user)
            ->post(route('client.hosting.provision', $account))
            ->assertRedirect()
            ->assertSessionHas('error', 'This service already has a VM.');

        $this->assertSame(0, ProvisioningEvent::where('hosting_account_id', $account->id)->where('event_type', 'provision')->count());
        Http::assertNotSent(fn ($r) => str_contains((string) $r->body(), 'New-VM'));
    }

    public function test_provisioning_suspended_account_is_refused(): void
    {
        [$account, $customer] = $this->hostingWithHyperV(hostingStatus: 'suspended');
        Http::fake();

        $this->actingAs($customer->user)
            ->postJson(route('client.hosting.provision', $account))
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => 'This service cannot be provisioned in its current status.']);

        $this->actingAs($customer->user)
            ->post(route('client.hosting.provision', $account))
            ->assertRedirect()
            ->assertSessionHas('error', 'This service cannot be provisioned in its current status.');

        $this->assertSame(0, ProvisioningEvent::where('hosting_account_id', $account->id)->count());
        Http::assertNothingSent();
    }

    public function test_vm_power_start_succeeds_without_touching_billing_status(): void
    {
        [$account, $customer] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');
        $state = 'Off';
        $this->fakeHost($state);

        $response = $this->actingAs($customer->user)
            ->postJson(route('client.hosting.vm-power', $account), ['action' => 'start']);

        $response->assertOk()->assertJson(['ok' => true, 'action' => 'start', 'state' => 'Running']);
        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'Start-VM -VM'));

        $event = ProvisioningEvent::where('hosting_account_id', $account->id)
            ->where('event_type', 'unsuspend')->orderByDesc('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('completed', $event->status);
        $this->assertSame('start', $event->payload['action'] ?? null);

        $this->assertTrue(AuditLog::where('entity_type', 'hosting_account')->where('entity_id', $account->id)->where('action', 'hosting.module_action')->exists());

        // Powering on is not a billing suspension — the account stays active.
        $this->assertSame('active', $account->fresh()->status);
    }

    public function test_vm_power_stop_succeeds_without_touching_billing_status(): void
    {
        [$account, $customer] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');
        $state = 'Running';
        $this->fakeHost($state);

        $response = $this->actingAs($customer->user)
            ->postJson(route('client.hosting.vm-power', $account), ['action' => 'stop']);

        $response->assertOk()->assertJson(['ok' => true, 'action' => 'stop', 'state' => 'Off']);
        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'Stop-VM -VM'));

        $event = ProvisioningEvent::where('hosting_account_id', $account->id)
            ->where('event_type', 'suspend')->orderByDesc('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('completed', $event->status);
        $this->assertSame('stop', $event->payload['action'] ?? null);

        // Powering off is not a billing suspension — the account stays active.
        $this->assertSame('active', $account->fresh()->status);
    }

    public function test_vm_power_404_for_other_customer(): void
    {
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');
        Http::fake();
        $other = Customer::create(['user_id' => User::factory()->create()->id, 'status' => 'active']);

        $this->actingAs($other->user)
            ->postJson(route('client.hosting.vm-power', $account), ['action' => 'start'])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_vm_power_422_for_suspended_account(): void
    {
        [$account, $customer] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'suspended');
        Http::fake();

        // Customer power actions must never wake a suspended service.
        $this->actingAs($customer->user)
            ->postJson(route('client.hosting.vm-power', $account), ['action' => 'start'])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => 'This service is not active.']);

        Http::assertNothingSent();
    }

    public function test_vm_power_409_while_build_running(): void
    {
        [$account, $customer] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');
        ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => $account->id,
            'event_type' => 'provision',
            'status' => 'running',
            'event_status' => 'running',
            'payload' => ['module' => 'hyperv', 'action' => 'create', 'stage' => 'cloning'],
        ]);
        Http::fake();

        $this->actingAs($customer->user)
            ->postJson(route('client.hosting.vm-power', $account), ['action' => 'stop'])
            ->assertStatus(409)
            ->assertJson(['ok' => false, 'message' => 'A VM build is already running for this service.']);

        $this->actingAs($customer->user)
            ->post(route('client.hosting.vm-power', $account), ['action' => 'stop'])
            ->assertRedirect()
            ->assertSessionHas('error', 'A VM build is already running for this service.');

        Http::assertNothingSent();
    }

    public function test_show_page_shows_provisioning_card_for_active_account_without_vm(): void
    {
        // Curated template so the card renders its full self-provision form.
        [$account, $customer] = $this->hostingWithHyperV(hostingStatus: 'active', withTemplates: true);

        $content = $this->actingAs($customer->user)
            ->get(route('client.hosting.show', $account->id))
            ->assertOk()
            ->getContent();

        // The card follows the VM, not the billing status.
        $this->assertStringContainsString('id="client-provision-form"', $content);
        $this->assertStringContainsString('id="client-vm-state"', $content);
        $this->assertStringContainsString('data-client-hv-action="start"', $content);
        $this->assertStringContainsString('data-client-hv-action="stop"', $content);
        $this->assertStringContainsString('id="client-vm-stop-modal"', $content);
        $this->assertStringContainsString('Gracefully shut down this VM?', $content);
        // No VM yet — both power buttons disabled with the frozen reason.
        $this->assertMatchesRegularExpression('/<button[^>]*data-client-hv-action="start"[^>]*disabled[^>]*>/', $content);
        $this->assertMatchesRegularExpression('/<button[^>]*data-client-hv-action="stop"[^>]*disabled[^>]*>/', $content);
        $this->assertStringContainsString('VM is not created on the host yet.', $content);
    }

    public function test_show_page_hides_provisioning_card_when_vm_exists_and_enables_stop(): void
    {
        [$account, $customer] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');
        $state = 'Running';
        $this->fakeHost($state);

        $content = $this->actingAs($customer->user)
            ->get(route('client.hosting.show', $account->id))
            ->assertOk()
            ->getContent();

        // The card follows the VM: with a VM on the host it is gone even
        // though the account is still active (state pill lives in the card).
        $this->assertStringNotContainsString('id="client-vm-state"', $content);
        $this->assertStringNotContainsString('id="client-provision-form"', $content);
        // Running VM: Start disabled ("already running"), Stop enabled.
        $this->assertMatchesRegularExpression('/<button[^>]*data-client-hv-action="start"[^>]*disabled[^>]*>/', $content);
        $this->assertStringContainsString('VM is already running.', $content);
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*data-client-hv-action="stop"[^>]*disabled[^>]*>/', $content);
    }

    public function test_vm_power_restart_requires_the_typed_host_name(): void
    {
        [$account, $customer] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');
        $state = 'Running';
        $this->fakeHost($state);

        // No confirm at all.
        $response = $this->actingAs($customer->user)
            ->postJson(route('client.hosting.vm-power', $account), ['action' => 'restart']);
        $response->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertStringContainsString('to confirm restart', (string) $response->json('message'));

        // Wrong confirm.
        $this->actingAs($customer->user)
            ->postJson(route('client.hosting.vm-power', $account), ['action' => 'restart', 'confirm' => 'not-'.$account->host_name])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        // Nothing destructive happened: no host reboot, no event, no billing change.
        Http::assertNotSent(fn ($r) => str_contains((string) $r->body(), 'Restart-VM'));
        $this->assertSame(0, ProvisioningEvent::where('hosting_account_id', $account->id)->where('event_type', 'restart')->count());
        $this->assertSame('active', $account->fresh()->status);
    }

    public function test_vm_power_restart_succeeds_without_touching_billing_status(): void
    {
        [$account, $customer] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');
        $state = 'Running';
        $this->fakeHost($state);

        $response = $this->actingAs($customer->user)
            ->postJson(route('client.hosting.vm-power', $account), [
                'action' => 'restart',
                'confirm' => $account->host_name,
            ]);

        $response->assertOk()->assertJson(['ok' => true, 'action' => 'restart', 'state' => 'Running']);
        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'Restart-VM'));

        $event = ProvisioningEvent::where('hosting_account_id', $account->id)
            ->where('event_type', 'restart')->orderByDesc('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('completed', $event->status);
        $this->assertSame('restart', $event->payload['action'] ?? null);

        $this->assertTrue(AuditLog::where('entity_type', 'hosting_account')->where('entity_id', $account->id)->where('action', 'hosting.module_action')->exists());

        // A reboot is not a billing event — the account stays active.
        $this->assertSame('active', $account->fresh()->status);
    }

    public function test_vm_power_restart_refuses_a_stopped_vm_and_fails_the_event(): void
    {
        [$account, $customer] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');
        $state = 'Off';
        $this->fakeHost($state);

        $response = $this->actingAs($customer->user)
            ->postJson(route('client.hosting.vm-power', $account), [
                'action' => 'restart',
                'confirm' => $account->host_name,
            ]);

        $response->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertStringContainsString('not Running', (string) $response->json('message'));

        // A stopped VM is never surprise-started: no Restart-VM reaches the host.
        Http::assertNotSent(fn ($r) => str_contains((string) $r->body(), 'Restart-VM'));

        $event = ProvisioningEvent::where('hosting_account_id', $account->id)
            ->where('event_type', 'restart')->orderByDesc('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('failed', $event->status);
        $this->assertSame('active', $account->fresh()->status);
    }

    public function test_show_page_renders_restart_button_and_typed_confirmation_modal(): void
    {
        [$account, $customer] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');
        $state = 'Running';
        $this->fakeHost($state);

        $content = $this->actingAs($customer->user)
            ->get(route('client.hosting.show', $account->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-client-hv-action="restart"', $content);
        $this->assertStringContainsString('id="client-vm-restart-modal"', $content);
        $this->assertStringContainsString('name="confirm"', $content);
        $this->assertStringContainsString('<code>'.$account->host_name.'</code> to confirm', $content);
        // Running VM on an active service: restart is offered.
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*data-client-hv-action="restart"[^>]*disabled[^>]*>/', $content);
    }

    public function test_show_page_disables_restart_on_a_suspended_service(): void
    {
        [$account, $customer] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'suspended');
        $state = 'Running';
        $this->fakeHost($state);

        $content = $this->actingAs($customer->user)
            ->get(route('client.hosting.show', $account->id))
            ->assertOk()
            ->getContent();

        // Billing gate: a suspended service never offers a reboot, even with a running VM.
        $this->assertMatchesRegularExpression('/<button[^>]*data-client-hv-action="restart"[^>]*disabled[^>]*>/', $content);
        $this->assertStringContainsString('This service is not active.', $content);
    }

    // ─────────────────────────── helpers ───────────────────────────

    private function hypervServer(bool $withTemplates = false): Server
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
            'connection_meta' => $withTemplates ? ['template_vms' => ['TPL1']] : null,
        ]);
    }

    private function makeCustomer(): Customer
    {
        $customer = Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);

        return $customer->fresh();
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
     * Fake the WinRM /wsman endpoint for a full client build: New-VM clones,
     * Start-VM powers on (client builds always start), Get-VM verifies.
     * Anything else is a loud error so stray calls cannot pass silently.
     */
    private function fakeBuildHost(string &$state): void
    {
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

    /**
     * @return array{0:HostingAccount,1:Customer,2?:ServiceInstance}
     */
    private function hostingWithHyperV(bool $withPanelAccount = false, string $hostingStatus = 'pending', bool $withGuest = false, bool $withTemplates = false): array
    {
        $server = $this->hypervServer($withTemplates);
        $product = Product::create(['name' => 'HV', 'price' => 50]);
        ProductModule::create([
            'product_id' => $product->id, 'module_slug' => 'hyperv', 'enabled' => true, 'config' => [],
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

            return [$account->fresh(), $customer, $service];
        }

        return [$account->fresh(), $customer];
    }
}
