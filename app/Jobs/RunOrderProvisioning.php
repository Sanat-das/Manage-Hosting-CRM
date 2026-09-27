<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Order;
use App\Services\OrderService;
use App\Services\Provisioning\ProvisioningDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queued order provisioning build.
 *
 * A clone can take 900s against a 30s web limit, so the dispatcher call that
 * OrderService used to run inline in the payment/activation request runs here
 * on the `provisioning` queue instead. The order stays `provisioning` until
 * this job lands the post-run transition; an already-`active` order keeps the
 * activation-hook semantics (log only, no status change).
 *
 * Never throws: a module refusal or a crash still lands the order in the
 * correct terminal status, and failed() only covers a killed worker.
 */
class RunOrderProvisioning implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public int $timeout = 1800;

    public function __construct(public readonly int $orderId)
    {
        // Dedicated queue so builds do not compete with the 1-minute
        // emails,default scheduled worker. Equivalent to: public string $queue = 'provisioning';
        $this->onQueue('provisioning');
    }

    /**
     * Called when the worker dies (timeout, killed process). The dispatcher's
     * own event rows remain the verdict — no status is invented here.
     */
    public function failed(?Throwable $exception): void
    {
        try {
            Log::error('Queued order provisioning interrupted before it finished', [
                'order_id' => $this->orderId,
                'error' => $exception?->getMessage() ?? 'worker stopped',
            ]);
        } catch (Throwable) {
        }
    }

    public function handle(OrderService $orders, ProvisioningDispatcher $provisioning): void
    {
        $order = Order::find($this->orderId);

        if ($order === null) {
            return;
        }

        if ($order->status === Order::STATUS_ACTIVE) {
            $this->runActivationHook($order, $provisioning);

            return;
        }

        if ($order->status !== Order::STATUS_PROVISIONING) {
            return;
        }

        $this->completeAdvance($order, $orders, $provisioning);
    }

    /**
     * The advanceAfterPayment completion: run the module, then land the
     * provisioning -> active/failed hop exactly as the inline path did.
     * `no_module` counts as success per ProvisioningAttempt::succeeded().
     */
    private function completeAdvance(Order $order, OrderService $orders, ProvisioningDispatcher $provisioning): void
    {
        $module = $order->product?->provisioning_module ?? 'manual';

        try {
            $attempt = $provisioning->run($order->refresh());

            if (! $attempt->succeeded()) {
                Log::error('Provisioning module reported failure', [
                    'order_id' => $order->id,
                    'module' => $module,
                    'error' => $attempt->message,
                ]);

                $orders->transition(
                    $order->refresh(),
                    Order::STATUS_FAILED,
                    'Provisioning failed: '.$attempt->message,
                );

                return;
            }

            $orders->transition($order->refresh(), Order::STATUS_ACTIVE, $attempt->activationNote());
        } catch (Throwable $e) {
            Log::error('Auto-provisioning failed after invoice payment', [
                'order_id' => $order->id,
                'module' => $module,
                'error' => $e->getMessage(),
            ]);

            try {
                // The failed activation rolled back, so the in-memory
                // status is stale — refresh before marking failed.
                $orders->transition($order->refresh(), Order::STATUS_FAILED, 'Auto-provisioning failed: '.$e->getMessage());
            } catch (Throwable $e2) {
                Log::warning('Order left in provisioning after failed auto-provisioning', [
                    'order_id' => $order->id,
                    'error' => $e2->getMessage(),
                ]);
            }
        }
    }

    /**
     * The pending/provisioning/failed -> active hook: the order is already
     * active either way, so a module refusal is only logged for the operator
     * to retry from the hosting page.
     */
    private function runActivationHook(Order $order, ProvisioningDispatcher $provisioning): void
    {
        try {
            // No installed provisioner: advanceAfterPayment already recorded
            // the gap event — running again would only duplicate it.
            if ($provisioning->moduleFor($order->product) === null) {
                return;
            }

            if ($provisioning->isManual($order->product)) {
                return;
            }

            if ($provisioning->alreadyProvisioned($order)) {
                return;
            }

            $attempt = $provisioning->run($order);

            if (! $attempt->succeeded()) {
                Log::error('Provisioning module reported failure on order activation', [
                    'order_id' => $order->id,
                    'error' => $attempt->message,
                ]);
            }
        } catch (Throwable $e) {
            Log::error('Auto-provisioning on order activation failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
