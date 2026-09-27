<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\Integrations\ProvisioningResult;
use App\Jobs\RunVmOperation;
use App\Models\AuditLog;
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
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Provisioning\VmOperationConflictException;
use App\Services\Provisioning\VmOperationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Uniform queued VM operations: the dispatcher opens one durable running
 * event (stage `queued`) per verb and pushes RunVmOperation, a running
 * action blocks the next one, stale rows reconcile, and the job lands the
 * event completed/failed with the same local status effects the inline
 * flow applied.
 *
 * QUEUE_CONNECTION=sync in phpunit.xml, so a dispatched job runs inline
 * unless Queue::fake() holds it — the endpoint still returns the async
 * contract (202/queued flash) while the event row ends completed/failed
 * by assertion time.
 */
final class VmOperationJobTest extends TestCase
{
    use RefreshDatabase;

    private const VM = 'testvm1';

    private const GUID = '11111111-2222-3333-4444-555555555555';

    private const VHD = 'C:\\VMs\\testvm1.vhdx';

    /** @return array<string, string> verb => event type */
    private function verbs(): array
    {
        return [
            'start' => 'unsuspend',
            'stop' => 'suspend',
            'restart' => 'restart',
            'suspend' => 'suspend',
            'unsuspend' => 'unsuspend',
            'terminate' => 'terminate',
            'reset_password' => 'update',
        ];
    }

    /** @return array<string, int> verb => job timeout */
    private function timeouts(): array
    {
        return [
            'start' => 360,
            'stop' => 420,
            'restart' => 360,
            'suspend' => 420,
            'unsuspend' => 360,
            'terminate' => 600,
            'reset_password' => 300,
        ];
    }

    public function test_dispatch_per_verb_opens_queued_event_and_pushes_job(): void
    {
        Queue::fake();

        foreach ($this->verbs() as $verb => $eventType) {
            [$account] = $this->hostingWithHyperV();

            $event = app(VmOperationDispatcher::class)->dispatch(
                $account,
                $verb,
                $verb === 'reset_password' ? ['new_password' => 'NewSecret123'] : []
            );

            $this->assertSame('running', $event->status);
            $this->assertSame($eventType, $event->event_type);
            $this->assertSame('queued', $event->payload['stage']);
            $this->assertSame($verb, $event->payload['action']);
            $this->assertSame('hyperv', $event->payload['module']);

            Queue::assertPushedOn('provisioning', RunVmOperation::class, function (RunVmOperation $job) use ($account, $verb, $event): bool {
                return $job->eventId === $event->id
                    && $job->hostingAccountId === $account->id
                    && $job->verb === $verb
                    && $job->tries === 1
                    && $job->timeout === $this->timeouts()[$verb];
            });
        }
    }

    public function test_dispatch_conflicts_when_an_action_is_running(): void
    {
        Queue::fake();
        [$account] = $this->hostingWithHyperV();

        ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => $account->id,
            'event_type' => 'unsuspend',
            'status' => 'running',
            'event_status' => 'running',
            'payload' => ['module' => 'hyperv', 'action' => 'start', 'stage' => 'starting'],
        ]);

        $this->expectException(VmOperationConflictException::class);
        $this->expectExceptionMessage('An action is already running for this service.');

        app(VmOperationDispatcher::class)->dispatch($account, 'stop');
    }

    public function test_dispatch_reconciles_a_stale_event_then_proceeds(): void
    {
        Queue::fake();
        [$account] = $this->hostingWithHyperV();

        $stale = ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => $account->id,
            'event_type' => 'suspend',
            'status' => 'running',
            'event_status' => 'running',
            'payload' => ['module' => 'hyperv', 'action' => 'stop', 'stage' => 'stopping'],
        ]);
        $stale->created_at = now()->subHours(3);
        $stale->save();

        $event = app(VmOperationDispatcher::class)->dispatch($account, 'start');

        $this->assertSame('failed', $stale->fresh()->status);
        $this->assertSame('running', $event->status);
        $this->assertSame('queued', $event->payload['stage']);

        Queue::assertPushedOn('provisioning', RunVmOperation::class, function (RunVmOperation $job) use ($event): bool {
            return $job->eventId === $event->id;
        });
    }

    public function test_job_completes_start_and_reactivates_locally(): void
    {
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'suspended');
        $hostState = 'Off';
        $this->fakeHost($hostState);

        // Sync queue runs the job inline inside dispatch().
        $event = app(VmOperationDispatcher::class)->dispatch($account, 'start');

        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'Start-VM -VM'));

        $event = $event->fresh();
        $this->assertSame('completed', $event->status);
        $this->assertSame('starting', $event->payload['stage']);
        $this->assertSame('active', $account->fresh()->status);
        $this->assertSame(PanelAccount::STATUS_ACTIVE, PanelAccount::sole()->status);
    }

    public function test_power_only_stop_skips_the_local_status_effect_but_still_audits(): void
    {
        // RULING 1: client power actions are host-only — the VM stops on
        // the host while hosting_accounts.status stays active. The audit
        // line is still written.
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');
        $hostState = 'Running';
        $this->fakeHost($hostState);

        $event = app(VmOperationDispatcher::class)->dispatch($account, 'stop', ['power_only' => true]);

        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'Stop-VM -VM'));

        $event = $event->fresh();
        $this->assertSame('completed', $event->status);
        $this->assertSame('active', $account->fresh()->status);
        $this->assertTrue(AuditLog::where('entity_type', 'hosting_account')->where('entity_id', $account->id)->where('action', 'hosting.module_action')->exists());
    }

    public function test_stop_without_power_only_still_suspends_locally(): void
    {
        // The other direction: admin callers omit power_only, so the
        // billing semantics are unchanged — stop suspends the account.
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');
        $hostState = 'Running';
        $this->fakeHost($hostState);

        $event = app(VmOperationDispatcher::class)->dispatch($account, 'stop');

        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'Stop-VM -VM'));

        $event = $event->fresh();
        $this->assertSame('completed', $event->status);
        $this->assertSame('suspended', $account->fresh()->status);
    }

    public function test_job_failure_fails_the_event_without_rethrowing(): void
    {
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'suspended');
        Http::fake(fn ($r) => Http::response(['error' => 'HOST-START-BOOM-7'], 500));

        $event = app(VmOperationDispatcher::class)->dispatch($account, 'start');

        $event = $event->fresh();
        $this->assertSame('failed', $event->status);
        $this->assertSame('failed', $event->event_status);
        $this->assertStringContainsString('HOST-START-BOOM-7', (string) $event->last_error);
        $this->assertNull($event->completed_at);
        $this->assertSame('suspended', $account->fresh()->status);
    }

    public function test_reset_password_options_are_encrypted_in_the_queue_payload(): void
    {
        Queue::fake();
        [$account] = $this->hostingWithHyperV();

        app(VmOperationDispatcher::class)->dispatch($account, 'reset_password', [
            'new_password' => 'Sup3rSecretPW-9z',
            'username' => 'GxJ7CustomUser',
            'current_password' => 'OldSecret-4q',
        ]);

        $pushed = null;

        Queue::assertPushedOn('provisioning', RunVmOperation::class, function (RunVmOperation $job) use (&$pushed): bool {
            $pushed = $job;

            return true;
        });

        $this->assertNotNull($pushed);
        $this->assertFalse(property_exists($pushed, 'options'));

        $serialized = serialize($pushed);
        $this->assertStringNotContainsString('Sup3rSecretPW-9z', $serialized);
        $this->assertStringNotContainsString('OldSecret-4q', $serialized);
        $this->assertStringNotContainsString('GxJ7CustomUser', $serialized);
    }

    public function test_reset_password_job_decrypts_and_applies_the_password(): void
    {
        [$account] = $this->hostingWithHyperV();

        $fake = new class
        {
            /** @var array{0:string, 1:?string, 2:?string}|null */
            public static ?array $seen = null;

            public function resetGuestAdminPassword(ServiceInstance $service, string $newPassword, ?string $username = null, ?string $currentPassword = null): ProvisioningResult
            {
                self::$seen = [$newPassword, $username, $currentPassword];

                return ProvisioningResult::ok('password reset');
            }
        };
        $fake::$seen = null;

        $this->mock(IntegrationRegistry::class, function ($mock) use ($fake): void {
            $mock->shouldReceive('has')->with('hyperv')->andReturn(true);
            $mock->shouldReceive('instanceFor')->with('hyperv')->andReturn($fake);
            $mock->shouldReceive('decryptConfigFor')->andReturn([]);
        });

        // Sync queue runs the job inline inside dispatch().
        $event = app(VmOperationDispatcher::class)->dispatch($account, 'reset_password', [
            'new_password' => 'Sup3rSecretPW-9z',
            'username' => 'GxJ7CustomUser',
            'current_password' => 'OldSecret-4q',
        ]);

        $this->assertSame(['Sup3rSecretPW-9z', 'GxJ7CustomUser', 'OldSecret-4q'], $fake::$seen);
        $this->assertSame('completed', $event->fresh()->status);
    }

    public function test_tampered_options_payload_fails_the_event_safely(): void
    {
        // An undecryptable `encryptedOptions` ciphertext must fail the event
        // without throwing out of handle() and without leaking the payload.
        Queue::fake();
        [$account] = $this->hostingWithHyperV();

        $event = app(VmOperationDispatcher::class)->dispatch($account, 'reset_password', [
            'new_password' => 'Sup3rSecretPW-9z',
        ]);

        $tampered = 'tampered-ciphertext-not-encrypted-by-this-app-key';
        $job = new RunVmOperation($event->id, $account->id, 'reset_password', $tampered);

        app()->call([$job, 'handle']);

        $event = $event->fresh();
        $this->assertSame('failed', $event->status);
        $this->assertSame('failed', $event->event_status);
        $this->assertStringNotContainsString($tampered, (string) $event->last_error);
        $this->assertStringNotContainsString('Sup3rSecretPW-9z', (string) $event->last_error);
    }

    public function test_failed_callback_is_idempotent(): void
    {
        [$account] = $this->hostingWithHyperV();

        $completed = ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => $account->id,
            'event_type' => 'unsuspend',
            'status' => 'completed',
            'event_status' => 'completed',
            'payload' => ['module' => 'hyperv', 'action' => 'start'],
        ]);

        (new RunVmOperation($completed->id, $account->id, 'start'))->failed(new \RuntimeException('boom'));

        $this->assertSame('completed', $completed->fresh()->status);

        $running = ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => $account->id,
            'event_type' => 'suspend',
            'status' => 'running',
            'event_status' => 'running',
            'payload' => ['module' => 'hyperv', 'action' => 'stop'],
        ]);

        (new RunVmOperation($running->id, $account->id, 'stop'))->failed(new \RuntimeException('boom'));

        $running = $running->fresh();
        $this->assertSame('failed', $running->status);
        $this->assertStringContainsString('boom', (string) $running->last_error);

        // A missing event is a no-op, never a throw.
        (new RunVmOperation(999999, $account->id, 'stop'))->failed(new \RuntimeException('boom'));
        $this->assertTrue(true);
    }

    public function test_endpoint_returns_202_contract_and_409_on_conflict(): void
    {
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'suspended');
        $hostState = 'Off';
        $this->fakeHost($hostState);

        $response = $this->actingAsAdminWith(['hosting.edit'])
            ->postJson(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'start',
            ]);

        $response->assertStatus(202)->assertJson(['ok' => true, 'started' => true, 'action' => 'start']);
        $this->assertNotNull($response->json('event_id'));

        [$blocked] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'suspended');
        ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => $blocked->id,
            'event_type' => 'unsuspend',
            'status' => 'running',
            'event_status' => 'running',
            'payload' => ['module' => 'hyperv', 'action' => 'start', 'stage' => 'starting'],
        ]);

        $this->actingAsAdminWith(['hosting.edit'])
            ->postJson(route('admin.hosting.module-action', $blocked), [
                'module_slug' => 'hyperv',
                'action' => 'stop',
            ])
            ->assertStatus(409)
            ->assertJson(['ok' => false]);
    }

    public function test_endpoint_form_callers_keep_redirect_and_flash_keys(): void
    {
        [$account] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'suspended');
        $hostState = 'Off';
        $this->fakeHost($hostState);

        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $account), [
                'module_slug' => 'hyperv',
                'action' => 'start',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Start queued.');

        [$terminating] = $this->hostingWithHyperV(withPanelAccount: true, hostingStatus: 'active');

        $this->actingAsAdminWith(['hosting.edit'])
            ->post(route('admin.hosting.module-action', $terminating), [
                'module_slug' => 'hyperv',
                'action' => 'delete',
                'confirm' => $terminating->host_name,
                'delete_vhd' => true,
            ])
            ->assertRedirect(route('admin.hosting.index'))
            ->assertSessionHas('success', 'Delete queued.');

        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'Remove-VM -VM'));
        $this->assertSame('terminated', $terminating->fresh()->status);
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

    private function panelAccount(ServiceInstance $service, Server $server): PanelAccount
    {
        return PanelAccount::create([
            'service_instance_id' => $service->id,
            'server_id' => $server->id,
            'panel' => 'hyperv',
            'username' => self::VM,
            'external_id' => self::GUID,
            'meta' => ['vmName' => self::VM, 'meta' => ['vmName' => self::VM, 'vhdPath' => self::VHD]],
            'status' => PanelAccount::STATUS_ACTIVE,
        ]);
    }

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
            if (str_contains($body, 'Get-VM')) {
                return Http::response(['exists' => true, 'name' => self::VM, 'state' => $state, 'vmId' => self::GUID]);
            }

            return Http::response(['error' => 'unexpected host call'], 500);
        });
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

    /**
     * @return array{0:HostingAccount}
     */
    private function hostingWithHyperV(bool $withPanelAccount = false, string $hostingStatus = 'suspended'): array
    {
        $server = $this->hypervServer();
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
            $this->panelAccount($service, $server);
        }

        return [$account->fresh()];
    }
}
