<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProvisioningEvent;
use App\Models\User;
use App\Services\Provisioning\ProvisioningEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProvisioningReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const ACTION_TYPES = ['provision', 'unsuspend', 'suspend', 'restart', 'terminate', 'update'];

    public function test_fails_stale_running_rows_for_every_action_event_type(): void
    {
        $ids = [];

        foreach (self::ACTION_TYPES as $type) {
            $ids[$type] = $this->staleRunning($type)->id;
        }

        $this->artisan('provisioning:reconcile')->assertSuccessful();

        foreach (self::ACTION_TYPES as $type) {
            $row = ProvisioningEvent::find($ids[$type]);

            $this->assertSame('failed', $row->status, "stale {$type} row must be failed");
            $this->assertSame('failed', $row->event_status, "stale {$type} row must be failed");
            $this->assertSame(ProvisioningEventRecorder::INTERRUPTED_MESSAGE, $row->last_error);
            $this->assertSame(ProvisioningEventRecorder::INTERRUPTED_MESSAGE, $row->result['error'] ?? null);
            $this->assertNull($row->completed_at);
        }
    }

    public function test_stale_provision_row_is_included(): void
    {
        $event = $this->staleRunning('provision');

        $this->artisan('provisioning:reconcile')->assertSuccessful();

        $row = $event->fresh();
        $this->assertSame('failed', $row->status);
        $this->assertSame('failed', $row->event_status);
        $this->assertSame(ProvisioningEventRecorder::INTERRUPTED_MESSAGE, $row->last_error);
    }

    public function test_leaves_fresh_and_terminal_and_out_of_scope_rows_alone(): void
    {
        $fresh = $this->running('suspend');

        $completed = $this->staleRunning('restart');
        $completed->status = 'completed';
        $completed->event_status = 'completed';
        $completed->result = ['message' => 'done'];
        $completed->last_error = null;
        $completed->completed_at = now();
        $completed->save();

        $failed = $this->staleRunning('terminate');
        $failed->status = 'failed';
        $failed->event_status = 'failed';
        $failed->result = ['error' => 'earlier failure'];
        $failed->last_error = 'earlier failure';
        $failed->save();

        $pending = ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => null,
            'event_type' => 'provision',
            'status' => 'pending',
            'event_status' => 'pending',
            'payload' => ['module' => 'hyperv'],
        ]);
        $pending->created_at = now()->subSeconds(ProvisioningEvent::RUNNING_STALE_AFTER_SECONDS + 300);
        $pending->save();

        $outOfScope = $this->staleRunning('snapshot');

        $this->artisan('provisioning:reconcile')->assertSuccessful();

        $this->assertSame('running', $fresh->fresh()->status, 'fresh running row must stay running');
        $this->assertSame('completed', $completed->fresh()->status, 'terminal completed row must be untouched');
        $this->assertSame('done', $completed->fresh()->result['message']);
        $this->assertSame('failed', $failed->fresh()->status, 'terminal failed row must be untouched');
        $this->assertSame('earlier failure', $failed->fresh()->last_error);
        $this->assertSame('pending', $pending->fresh()->status, 'pending row must be untouched');
        $this->assertSame('running', $outOfScope->fresh()->status, 'non-action event type must be untouched');
    }

    public function test_dry_run_changes_nothing(): void
    {
        $stale = $this->staleRunning('restart');

        $this->artisan('provisioning:reconcile', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame('running', $stale->fresh()->status);
        $this->assertNull($stale->fresh()->last_error);
        $this->assertSame(1, ProvisioningEvent::where('status', 'running')->count());
    }

    public function test_output_reports_failed_counts(): void
    {
        $this->staleRunning('provision');
        $this->staleRunning('suspend');

        $exit = Artisan::call('provisioning:reconcile');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('failed 2 of 2 running events', $output);
        $this->assertStringContainsString('stranded orders', $output);
    }

    public function test_fails_a_stranded_provisioning_order(): void
    {
        $order = $this->staleProvisioningOrder();

        $this->artisan('provisioning:reconcile')->assertSuccessful();

        $order = $order->refresh();
        $this->assertSame(Order::STATUS_FAILED, $order->status);
        $this->assertStringContainsString(
            'interrupted',
            (string) $order->statusHistory()->latest()->first()?->notes
        );
    }

    public function test_leaves_recent_provisioning_orders_alone(): void
    {
        $order = $this->staleProvisioningOrder();
        $order->updated_at = now();
        $order->save();

        $this->artisan('provisioning:reconcile')->assertSuccessful();

        $this->assertSame(Order::STATUS_PROVISIONING, $order->refresh()->status);
    }

    public function test_leaves_an_order_with_a_fresh_running_event_alone(): void
    {
        $order = $this->staleProvisioningOrder();
        $account = HostingAccount::create([
            'customer_id' => $order->customer_id,
            'product_id' => $order->product_id,
            'order_id' => $order->id,
            'username' => 'stranded-user',
            'domain' => 'stranded.test',
            'status' => 'pending',
        ]);

        $event = $this->running('provision');
        $event->hosting_account_id = $account->id;
        $event->save();

        $this->artisan('provisioning:reconcile')->assertSuccessful();

        $this->assertSame(Order::STATUS_PROVISIONING, $order->refresh()->status);
        $this->assertSame('running', $event->fresh()->status);
    }

    public function test_dry_run_leaves_stranded_orders_unchanged(): void
    {
        $order = $this->staleProvisioningOrder();

        $this->artisan('provisioning:reconcile', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(Order::STATUS_PROVISIONING, $order->refresh()->status);
    }

    private function staleProvisioningOrder(): Order
    {
        $customer = Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);
        $product = Product::create(['name' => 'Stranded '.uniqid(), 'price' => 10]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 10.00,
            'status' => Order::STATUS_PROVISIONING,
        ]);

        Order::whereKey($order->id)->update([
            'created_at' => now()->subSeconds(ProvisioningEvent::RUNNING_STALE_AFTER_SECONDS + 300),
            'updated_at' => now()->subSeconds(ProvisioningEvent::RUNNING_STALE_AFTER_SECONDS + 300),
        ]);

        return $order->refresh();
    }

    private function staleRunning(string $type): ProvisioningEvent
    {
        $event = $this->running($type);
        $event->created_at = now()->subSeconds(ProvisioningEvent::RUNNING_STALE_AFTER_SECONDS + 300);
        $event->save();

        return $event->refresh();
    }

    private function running(string $type): ProvisioningEvent
    {
        return ProvisioningEvent::create([
            'service_instance_id' => null,
            'hosting_account_id' => null,
            'event_type' => $type,
            'status' => 'running',
            'event_status' => 'running',
            'payload' => ['module' => 'hyperv', 'action' => $type],
        ]);
    }
}
