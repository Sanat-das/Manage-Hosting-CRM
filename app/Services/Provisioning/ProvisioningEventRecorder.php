<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Models\Order;
use App\Models\ProvisioningEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single writer for the `provisioning_events` log.
 *
 * Two write shapes, both preserving the payload/result conventions the
 * existing readers and tests rely on:
 *
 * - durable transitions: begin() opens a `running` row before the driver is
 *   called, complete()/fail() flip that same row — one row per attempt;
 * - one-shot terminal writes: record() writes a finished row in a single
 *   insert for paths with no driver call (pending queue entries, lifecycle
 *   verbs) and never throws, so event logging can never mask the operation
 *   it describes.
 */
final class ProvisioningEventRecorder
{
    /**
     * Keys stripped from module result data before it is written to the
     * audit row. A module hands back the credentials it generated so the
     * caller can deliver them; persisting those into an append-only log
     * would leave plaintext secrets in the database forever. Matched
     * case-insensitively as substrings.
     *
     * @var list<string>
     */
    private const SECRET_KEYS = ['password', 'secret', 'token', 'api_key', 'apikey', 'private_key'];

    /**
     * Open a durable `running` row before the driver call.
     */
    public function begin(
        string $eventType,
        array $payload,
        ?int $serviceInstanceId = null,
        ?int $hostingAccountId = null,
        ?int $triggeredBy = null,
    ): ProvisioningEvent {
        return ProvisioningEvent::create([
            'service_instance_id' => $serviceInstanceId,
            'hosting_account_id' => $hostingAccountId,
            'event_type' => $eventType,
            'status' => 'running',
            'event_status' => 'running',
            'triggered_by' => $triggeredBy ?? auth()->id(),
            'payload' => $payload,
        ]);
    }

    /**
     * Flip a running row to `completed`, persisting the success message plus
     * the redacted provider data.
     *
     * @param  array<string, mixed>  $data
     */
    public function complete(ProvisioningEvent $event, string $message, array $data = []): ProvisioningEvent
    {
        $event->status = 'completed';
        $event->event_status = 'completed';
        $event->result = ['message' => $message] + $this->redact($data);
        $event->completed_at = now();
        $event->last_error = null;
        $event->save();

        return $event;
    }

    /**
     * Flip a running row to `failed`, keeping completed_at null like the
     * historical failed rows.
     *
     * @param  array<string, mixed>  $data
     */
    public function fail(ProvisioningEvent $event, string $message, array $data = []): ProvisioningEvent
    {
        $event->status = 'failed';
        $event->event_status = 'failed';
        $event->result = ['error' => $message] + $this->redact($data);
        $event->last_error = $message;
        $event->completed_at = null;
        $event->save();

        return $event;
    }

    /**
     * One-shot terminal write for lifecycle and queue paths. Never throws:
     * a failed audit insert is logged and returns null so the operation it
     * describes still advances.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $data  extra provider data (redacted)
     */
    public function record(
        string $eventType,
        string $status,
        array $payload,
        ?string $message = null,
        array $data = [],
        ?int $serviceInstanceId = null,
        ?int $hostingAccountId = null,
        ?int $triggeredBy = null,
    ): ?ProvisioningEvent {
        try {
            $result = null;
            $completedAt = null;
            $lastError = null;

            if ($status === 'completed') {
                $result = ['message' => $message] + $this->redact($data);
                $completedAt = now();
            } elseif ($status === 'failed') {
                $result = ['error' => $message] + $this->redact($data);
                $lastError = $message;
            }

            return ProvisioningEvent::create([
                'service_instance_id' => $serviceInstanceId,
                'hosting_account_id' => $hostingAccountId,
                'event_type' => $eventType,
                'status' => $status,
                'event_status' => $status,
                'triggered_by' => $triggeredBy ?? auth()->id(),
                'payload' => $payload,
                'result' => $result,
                'last_error' => $lastError,
                'completed_at' => $completedAt,
            ]);
        } catch (Throwable $e) {
            // The audit row must never be the reason an operation fails.
            Log::warning('Could not record provisioning event', [
                'event_type' => $eventType,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Resolve this order's stale `awaiting_manual_vm` queue entries after
     * the VM was actually built. Only rows still `pending` are touched; the
     * payload is decoded and filtered in PHP (no JSON path queries) and
     * anything else for the order is left alone.
     *
     * @return int  number of rows flipped to completed
     */
    public function resolveAwaiting(Order $order, string $message): int
    {
        $resolved = 0;

        $pending = ProvisioningEvent::where('status', 'pending')
            ->where('event_type', 'provision')
            ->get();

        foreach ($pending as $event) {
            $payload = $event->payload;

            if (! is_array($payload)) {
                continue;
            }

            if (($payload['reason'] ?? null) !== 'awaiting_manual_vm') {
                continue;
            }

            if (! isset($payload['order_id']) || (int) $payload['order_id'] !== (int) $order->id) {
                continue;
            }

            $event->status = 'completed';
            $event->event_status = 'completed';
            $event->completed_at = now();
            $event->result = ['message' => $message, 'resolved_by' => 'manual_vm_build'];
            $event->save();

            $resolved++;
        }

        return $resolved;
    }

    /**
     * Update the stage on a running event without flipping its status.
     * Best-effort — a progress failure must never break provisioning.
     *
     * @param  array<string, mixed>  $extra
     */
    public function progress(ProvisioningEvent $event, string $stage, array $extra = []): void
    {
        if ($event->status !== 'running') {
            return;
        }

        try {
            $payload = is_array($event->payload) ? $event->payload : [];
            $payload['stage'] = $stage;
            foreach ($extra as $k => $v) {
                $payload[$k] = $v;
            }
            $event->payload = $payload;
            $event->save();
        } catch (\Throwable $e) {
            Log::warning('Could not record provisioning progress', [
                'event_id' => $event->id,
                'stage' => $stage,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Strip credential-bearing keys from module result data before it is
     * persisted. Nested arrays are walked so a module returning
     * `['account' => ['password' => …]]` is covered too.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function redact(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            $isSecret = false;

            foreach (self::SECRET_KEYS as $needle) {
                if (stripos((string) $key, $needle) !== false) {
                    $isSecret = true;
                    break;
                }
            }

            if ($isSecret) {
                $clean[$key] = '[redacted]';

                continue;
            }

            $clean[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $clean;
    }
}
