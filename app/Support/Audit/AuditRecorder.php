<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\DomainSyncLog;
use App\Models\ModuleLog;
use App\Support\Logging\AppLog;
use App\Support\Logging\RequestContext;
use App\Support\SecretRedactor;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The single entry point for DB-backed audit records.
 *
 * Every stream writes through here so that, regardless of call site:
 * - `created_at` is ALWAYS stamped (the legacy writers left it NULL, which
 *   defeated retention and ordering — see .opencode/reports/2026-10-09);
 * - `request_id` links the row to the file-log pipeline's correlation id;
 * - metadata/details/payload/error strings pass through SecretRedactor;
 * - a failed write is escalated to the security log channel instead of
 *   disappearing.
 */
final class AuditRecorder
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function activity(
        AuditEvent|string $event,
        ?Customer $customer = null,
        array $metadata = [],
        ?string $description = null,
        ?Model $subject = null,
        ?int $actorId = null,
    ): void {
        $key = $event instanceof AuditEvent ? $event->value : $event;
        $metadata = $this->redact($metadata);

        $log = new ActivityLog([
            'customer_id' => $customer?->id,
            'user_id' => $actorId ?? auth()->id(),
            'action' => $key,
            'description' => $description,
            'metadata' => $metadata === [] ? null : $metadata,
            'ip_address' => request()->ip(),
        ]);

        // Columns outside the model's fillable set — set explicitly so the
        // dual legacy schemas (event/properties vs action/metadata) stay
        // readable through one convention: action+metadata canonical, event
        // mirrors the key for compatibility with the vendor auth rows.
        $log->event = $key;
        $log->subject_type = $subject?->getMorphClass();
        $log->subject_id = $subject?->getKey();
        $userAgent = request()->userAgent();
        $log->user_agent = $userAgent === null ? null : substr($userAgent, 0, 255);
        $log->request_id = $this->requestId();
        $log->created_at = now();

        $this->persist('activity_log', fn () => $log->save());
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function entity(string $action, Model $entity, array $details = [], ?int $actorId = null): void
    {
        $this->entityRef($action, $entity->getMorphClass(), $entity->getKey(), $details, $actorId);
    }

    /**
     * Entity trail for records that may already be deleted (chat conversations,
     * messages) — same table, referenced by type + id instead of a live model.
     *
     * @param  array<string, mixed>  $details
     */
    public function entityRef(string $action, string $entityType, int|string|null $entityId, array $details = [], ?int $actorId = null): void
    {
        $details = $this->redact($details);

        $log = new AuditLog([
            'user_id' => $actorId ?? auth()->id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => $details === [] ? null : json_encode($details),
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 255),
        ]);

        $log->request_id = $this->requestId();
        $log->created_at = now();

        $this->persist('audit_log', fn () => $log->save());
    }

    public function module(
        ?int $moduleId,
        string $event,
        ?int $serviceInstanceId = null,
        string $status = 'info',
        ?string $error = null,
    ): void {
        $log = new ModuleLog([
            'module_id' => $moduleId,
            'event' => $event,
            'service_instance_id' => $serviceInstanceId,
            'status' => $status,
            'error' => $error === null ? null : SecretRedactor::redact($error),
        ]);

        $log->created_at = now();

        $this->persist('module_log', fn () => $log->save());
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function domainSync(
        string $provider,
        string $operation,
        string $status,
        ?array $payload = null,
        ?string $error = null,
    ): void {
        $log = new DomainSyncLog([
            'provider' => $provider,
            'operation' => $operation,
            'status' => $status,
            'payload' => $payload === null ? null : $this->redact($payload),
            'error' => $error === null ? null : SecretRedactor::redact($error),
        ]);

        $log->created_at = now();

        $this->persist('domain_sync_log', fn () => $log->save());
    }

    private function persist(string $stream, callable $write): void
    {
        try {
            $write();
        } catch (Throwable $e) {
            // An audit write that fails is itself a security-relevant event.
            AppLog::security()->error('Audit record failed to persist.', [
                'stream' => $stream,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function requestId(): ?string
    {
        if (! app()->bound(RequestContext::class)) {
            return null;
        }

        return app(RequestContext::class)->requestId();
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function redact(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_string($item)) {
                $value[$key] = SecretRedactor::redact($item);
            } elseif (is_array($item)) {
                $value[$key] = $this->redact($item);
            }
        }

        return $value;
    }
}
