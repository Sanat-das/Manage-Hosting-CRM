<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Order;
use App\Services\Provisioning\ProvisioningDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queued order lifecycle verb (suspend/unsuspend/terminate).
 *
 * The panel call that OrderService used to run inline in the status-change
 * request runs here on the `provisioning` queue instead. Never throws and
 * never changes the order's status: the transition is already committed and
 * audited, and the dispatcher's own event rows remain the verdict.
 */
class RunOrderLifecycleVerb implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public int $timeout = 600;

    /**
     * @var list<string>
     */
    public const VERBS = ['suspend', 'unsuspend', 'terminate'];

    public function __construct(
        public readonly int $orderId,
        public readonly string $verb,
        public readonly ?string $reason = null,
    ) {
        // Dedicated queue so lifecycle calls do not compete with the 1-minute
        // emails,default scheduled worker. Equivalent to: public string $queue = 'provisioning';
        $this->onQueue('provisioning');
    }

    /**
     * Called when the worker dies (timeout, killed process). Must never throw.
     */
    public function failed(?Throwable $exception): void
    {
        try {
            Log::error('Queued order lifecycle call interrupted before it finished', [
                'order_id' => $this->orderId,
                'verb' => $this->verb,
                'error' => $exception?->getMessage() ?? 'worker stopped',
            ]);
        } catch (Throwable) {
        }
    }

    public function handle(ProvisioningDispatcher $provisioning): void
    {
        try {
            if (! in_array($this->verb, self::VERBS, true)) {
                Log::warning('Unknown order lifecycle verb queued', [
                    'order_id' => $this->orderId,
                    'verb' => $this->verb,
                ]);

                return;
            }

            $order = Order::find($this->orderId);

            if ($order === null) {
                return;
            }

            $attempt = $provisioning->{$this->verb}($order, $this->reason);

            if (! $attempt->succeeded()) {
                Log::error('Provisioning module reported failure on order status change', [
                    'order_id' => $order->id,
                    'verb' => $this->verb,
                    'error' => $attempt->message,
                ]);
            }
        } catch (Throwable $e) {
            // The dispatcher already isolates module failures; this is the
            // belt-and-braces guard so a status change can never 500.
            Log::error('Order lifecycle provisioning call failed', [
                'order_id' => $this->orderId,
                'verb' => $this->verb,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
