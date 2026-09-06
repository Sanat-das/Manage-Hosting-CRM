<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Models\ProvisioningEvent;

/**
 * Outcome of one provisioning attempt for an order.
 *
 * Distinct from ProvisioningResult (which a module returns for a single
 * service instance): this is the dispatcher's verdict for the whole order,
 * including the "no module was available to run" case that ProvisioningResult
 * cannot express.
 */
final class ProvisioningAttempt
{
    /** A provisioning module ran and reported success. */
    public const PROVISIONED = 'provisioned';

    /** A provisioning module ran and reported failure (or threw). */
    public const FAILED = 'failed';

    /**
     * No module implementing the provisioning capability is installed and
     * enabled for this product. The order's local records are still created
     * by the state machine; the recorded event is the operator's cue that
     * nothing was created on a remote panel.
     */
    public const NO_MODULE = 'no_module';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $message = null,
        public readonly ?ProvisioningEvent $event = null,
    ) {}

    public static function provisioned(?string $message, ?ProvisioningEvent $event): self
    {
        return new self(self::PROVISIONED, $message, $event);
    }

    public static function failed(string $message, ?ProvisioningEvent $event): self
    {
        return new self(self::FAILED, $message, $event);
    }

    public static function noModule(?ProvisioningEvent $event): self
    {
        return new self(self::NO_MODULE, null, $event);
    }

    public function succeeded(): bool
    {
        return $this->outcome !== self::FAILED;
    }

    /**
     * The note written to the order's status-history row on activation. The
     * two success paths are deliberately worded differently: an operator
     * reading the audit trail must be able to tell a real remote provision
     * from a local-records-only activation.
     */
    public function activationNote(): string
    {
        return $this->outcome === self::PROVISIONED
            ? 'Provisioned by module: '.($this->message ?? 'success')
            : 'Activated without a provisioning module — no remote account was created';
    }
}
