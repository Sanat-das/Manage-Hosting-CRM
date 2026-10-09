<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HostingAccount;
use App\Models\Order;
use App\Models\ProvisioningEvent;
use App\Services\OrderService;
use App\Services\Provisioning\ProvisioningEventRecorder;
use App\Support\Logging\AppLog;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fail stale `running` provisioning operation events left behind when a
 * worker is killed before the action finished.
 *
 * Power/lifecycle verbs open a durable `running` row and are only reconciled
 * inside the create dispatch, so a killed start/stop row sits `running` —
 * and the compute card spins — until the 35-minute stale threshold. This
 * sweeper fails every stale action row every five minutes (see
 * routes/console.php) so interrupted operations clear on their own.
 *
 * The write reuses ProvisioningEventRecorder::markInterrupted(), the exact
 * verdict the recorder's shutdown guard writes, guarded by
 * WHERE status = 'running' so a concurrent complete()/fail() always wins.
 * Idempotent: re-running on already-terminal rows changes nothing.
 *
 * A paid order whose worker died before RunOrderProvisioning landed sits in
 * `provisioning` with nothing running for it — failed() only logs, so
 * nothing would ever move it. The same run therefore also sweeps stale
 * `provisioning` orders whose hosting account has no `running` event left
 * (the event sweep above runs first, so a remaining running row is fresh
 * and the order is still in flight) to `failed` via OrderService::fail().
 */
final class ReconcileProvisioningOperationsCommand extends Command
{
    protected $signature = 'provisioning:reconcile
                            {--dry-run : List stale running events without modifying them}';

    protected $description = 'Fail stale running provisioning operation events left behind by interrupted workers';

    /**
     * Operator-visible VM actions. Mirrors
     * VmStatusPresenter::ACTION_EVENT_TYPES, which is private there so the
     * list is repeated here — keep the two in sync.
     *
     * @var list<string>
     */
    private const ACTION_EVENT_TYPES = ['provision', 'unsuspend', 'suspend', 'restart', 'terminate', 'update'];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subSeconds(ProvisioningEvent::RUNNING_STALE_AFTER_SECONDS);

        $this->info(sprintf(
            'Reconciling provisioning events running since before %s%s',
            $cutoff->toDateTimeString(),
            $dryRun ? ' — dry run' : ''
        ));

        try {
            $stale = ProvisioningEvent::query()
                ->where('status', 'running')
                ->whereIn('event_type', self::ACTION_EVENT_TYPES)
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->get(['id', 'event_type', 'created_at']);
        } catch (Throwable $e) {
            AppLog::provisioning()->warning('provisioning:reconcile could not query stale events', ['error' => $e->getMessage()]);
            $this->error('Could not query provisioning events: '.$e->getMessage());

            return self::FAILURE;
        }

        $total = $stale->count();
        $staleIds = $stale->pluck('id')->all();

        try {
            $strandedOrders = Order::query()
                ->where('status', Order::STATUS_PROVISIONING)
                ->where('updated_at', '<', $cutoff)
                ->orderBy('id')
                ->get(['id', 'updated_at']);
        } catch (Throwable $e) {
            AppLog::provisioning()->warning('provisioning:reconcile could not query stranded orders', ['error' => $e->getMessage()]);
            $this->error('Could not query provisioning orders: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($dryRun) {
            // List without changing anything: the live run below fails stale
            // events first, so the stranded check ignores them here to list
            // the same orders the live run would fail.
            $stranded = $strandedOrders->filter(fn (Order $order): bool => $this->orderIsStranded($order, $staleIds))->values();
            $orderTotal = $stranded->count();

            if ($total === 0 && $orderTotal === 0) {
                $this->info('failed 0 of 0 running events, failed 0 of 0 stranded orders — nothing stale.');

                return self::SUCCESS;
            }

            $this->line(sprintf('Found %d stale running event(s).', $total));
            $this->line(sprintf('Found %d stranded provisioning order(s).', $orderTotal));

            foreach ($stale as $event) {
                $this->line(sprintf(
                    '  #%-6d type=%s created_at=%s',
                    $event->id,
                    $event->event_type,
                    $event->created_at?->toDateTimeString() ?? 'null'
                ));
            }

            foreach ($stranded as $order) {
                $this->line(sprintf(
                    '  order #%-6d updated_at=%s',
                    $order->id,
                    $order->updated_at?->toDateTimeString() ?? 'null'
                ));
            }

            $this->info(sprintf('Dry run: %d of %d running events and %d of %d stranded orders would be failed (nothing changed).', $total, $total, $orderTotal, $orderTotal));

            return self::SUCCESS;
        }

        // Fail stale events FIRST, before the order filter below runs: an
        // order whose only running event is stale must read as stranded in
        // this same run instead of waiting a full extra cycle.
        foreach ($stale as $event) {
            try {
                // Never throws and only flips rows still `running`.
                ProvisioningEventRecorder::markInterrupted((int) $event->id);
            } catch (Throwable $e) {
                AppLog::provisioning()->warning('provisioning:reconcile could not fail stale event', [
                    'event_id' => $event->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error(sprintf('  Failed to reconcile #%d: %s', $event->id, $e->getMessage()));
            }
        }

        try {
            $failed = ProvisioningEvent::query()
                ->whereIn('id', $stale->pluck('id')->all())
                ->where('status', 'failed')
                ->count();
        } catch (Throwable $e) {
            AppLog::provisioning()->warning('provisioning:reconcile could not count reconciled events', ['error' => $e->getMessage()]);
            $this->error('Reconciled events but could not verify the result: '.$e->getMessage());

            return self::FAILURE;
        }

        // Only now evaluate the order filter: the sweep above already failed
        // every stale row, so a remaining `running` event for the account is
        // fresh — the build is still in flight and the order is left alone.
        $stranded = $strandedOrders->filter(fn (Order $order): bool => $this->orderIsStranded($order))->values();
        $orderTotal = $stranded->count();

        if ($total === 0 && $orderTotal === 0) {
            $this->info('failed 0 of 0 running events, failed 0 of 0 stranded orders — nothing stale.');

            return self::SUCCESS;
        }

        $this->line(sprintf('Found %d stale running event(s).', $total));
        $this->line(sprintf('Found %d stranded provisioning order(s).', $orderTotal));

        $failedOrders = 0;

        foreach ($stranded as $order) {
            try {
                app(OrderService::class)->fail(
                    $order->refresh(),
                    'Provisioning was interrupted — retry from the order.'
                );
                $failedOrders++;
            } catch (Throwable $e) {
                AppLog::provisioning()->warning('provisioning:reconcile could not fail stranded order', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error(sprintf('  Failed to reconcile order #%d: %s', $order->id, $e->getMessage()));
            }
        }

        $this->info(sprintf('failed %d of %d running events, failed %d of %d stranded orders', $failed, $total, $failedOrders, $orderTotal));

        return self::SUCCESS;
    }

    /**
     * A stale `provisioning` order with no `running` event left for its
     * hosting account is stranded — its worker died before anything could
     * move it. When $ignoreEventIds is given those rows are treated as
     * already failed (the dry-run simulation of the sweep, which itself
     * changes nothing).
     */
    private function orderIsStranded(Order $order, array $ignoreEventIds = []): bool
    {
        try {
            $accountId = $order->hostingAccount?->id
                ?? HostingAccount::where('order_id', $order->id)->value('id');
        } catch (Throwable) {
            return false;
        }

        if ($accountId === null) {
            return true;
        }

        try {
            return ! ProvisioningEvent::query()
                ->where('hosting_account_id', $accountId)
                ->where('status', 'running')
                ->when($ignoreEventIds !== [], fn ($query) => $query->whereNotIn('id', $ignoreEventIds))
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
