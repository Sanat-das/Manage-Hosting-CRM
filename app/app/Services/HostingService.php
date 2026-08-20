<?php

namespace App\Services;

use App\Exceptions\NoAvailableIpException;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\HostingAccount;
use App\Models\IpAddress;
use App\Models\Order;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Hosting business logic (Session 3A.2 port of the reference
 * Modules\Hosting\Application\HostingService).
 *
 * Owns every hosting_account status transition — suspend / reactivate
 * (unsuspend, also covers pending->active activation) / terminate /
 * change package — together with the audit trail. Every transition writes
 * to BOTH:
 *   - audit_log     (generic entity-scoped trail; entity_type=hosting_account)
 *   - activity_log  (the customer's activity feed, mirroring the pilot)
 *
 * Status values are the exact hosting_accounts.status enum from the
 * order_tables migration: pending / active / suspended / terminated.
 *
 * Divergences from the reference (documented):
 *   - reference persisted `suspension_reason`; the local schema names the
 *     column `suspended_reason` (used here).
 *   - reference `canSuspend()` allowed active only; port keeps that.
 *   - reference `canActivate()` allowed suspended|pending -> active; port
 *     keeps that (unsuspend is used for both).
 *   - changePackage is a port addition per the task spec: only active or
 *     suspended accounts; it records old->new product (package) and
 *     recalculates nothing yet (billing integration is a separate task).
 */
class HostingService
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_TERMINATED = 'terminated';

    /** Exact values from the hosting_accounts.status enum (order_tables migration). */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
        self::STATUS_TERMINATED,
    ];

    /** Statuses from which an account can be suspended (reference: active only). */
    public const CAN_SUSPEND_FROM = [self::STATUS_ACTIVE];

    /** Statuses from which an account can be (re)activated. */
    public const CAN_ACTIVATE_FROM = [self::STATUS_PENDING, self::STATUS_SUSPENDED];

    /** Statuses eligible for a package (product) change. */
    public const CAN_CHANGE_PACKAGE_FROM = [self::STATUS_ACTIVE, self::STATUS_SUSPENDED];

    /**
     * Suspend an account. Reference rule: only active accounts can be
     * suspended; a reason is recorded alongside suspended_at.
     *
     * @throws RuntimeException when the account status does not allow suspension
     */
    public function suspend(HostingAccount $account, ?string $reason = null): void
    {
        $this->assertStatus($account, self::CAN_SUSPEND_FROM, 'suspended');

        $this->mutate($account, 'hosting.suspended', "Hosting account {$account->username} suspended", [
            'status' => self::STATUS_SUSPENDED,
            'suspended_reason' => $this->normalizeReason($reason),
            'suspended_at' => now(),
        ]);
    }

    /**
     * Reactivate a suspended account (or activate a pending one).
     * Clears the suspension fields.
     *
     * A pending -> active activation on a product that requires an IP
     * (require_public_ip / require_private_ip flags) first leases the next
     * available address of the matching subnet type from the IPAM pool —
     * inside the same transaction. IP leasing is BEST-EFFORT: an exhausted
     * pool never blocks activation — the account activates without the lease
     * and IPs are assigned later from the hosting page (pull-ip / assign-ips).
     * Suspended -> active reactivations keep the lease the account already
     * holds.
     *
     * @throws RuntimeException when the account status does not allow activation
     */
    public function unsuspend(HostingAccount $account): void
    {
        $this->assertStatus($account, self::CAN_ACTIVATE_FROM, 'activated');

        $this->mutate($account, 'hosting.unsuspended', "Hosting account {$account->username} reactivated", [
            'status' => self::STATUS_ACTIVE,
            'suspended_reason' => null,
            'suspended_at' => null,
        ], before: function () use ($account) {
            $this->leaseIpForActivation($account);
        });
    }

    /**
     * Provision the hosting account for an order at activation time.
     *
     * Called inside the order's pending -> active transition (OrderService)
     * so the account creation and IP lease are atomic with the order
     * activation. IP leasing is best-effort: an exhausted pool never rolls
     * the order back — the account is created (status pending) without the
     * lease and IPs are assigned later from the hosting page.
     *
     * Creates the account (status pending) when the order's product is a
     * hosting service (its group is a hosting group, or it requires an IP)
     * and the order does not already have one, then leases the IPs the
     * product's flags declare (public and/or private) from the matching
     * subnet types when available. Idempotent: an order that already has a
     * hosting account is left untouched.
     */
    public function provisionFromOrder(Order $order): ?HostingAccount
    {
        if ($order->hostingAccount !== null) {
            return null; // already provisioned
        }

        $product = $order->product;

        if (! $product?->requiresIp() && ! (bool) $product->group?->is_hosting) {
            return null; // nothing to provision for non-hosting products
        }

        $account = HostingAccount::create([
            'customer_id' => $order->customer_id,
            'product_id' => $product->id,
            'order_id' => $order->id,
            'username' => $this->usernameForOrder($order),
            'domain' => $order->domain_name,
            'status' => self::STATUS_PENDING,
        ]);

        $this->audit($account, 'hosting.created', "Hosting account {$account->username} created from order {$order->order_number}");

        $this->leaseIpForActivation($account);

        return $account;
    }

    /**
     * Unique hosting account username derived from the order identity.
     * Distinct from the admin-entered usernames so auto-provisioned accounts
     * can never collide with manual ones.
     */
    private function usernameForOrder(Order $order): string
    {
        return 'ord'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Change the account's package (product). Only active or suspended
     * accounts are eligible. Records the old->new package in the audit
     * trail; recalculates nothing yet (billing integration is separate).
     *
     * @throws RuntimeException when the account status does not allow a change
     */
    public function changePackage(HostingAccount $account, int $newProductId): void
    {
        $this->assertStatus($account, self::CAN_CHANGE_PACKAGE_FROM, 'changed package');

        $oldProductId = $account->product_id;

        if ((int) $oldProductId === $newProductId) {
            return; // no-op
        }

        $this->mutate($account, 'hosting.package_changed', "Hosting account {$account->username} package changed", [
            'product_id' => $newProductId,
        ], [
            'old_product_id' => $oldProductId,
            'new_product_id' => $newProductId,
        ]);
    }

    /**
     * Terminate an account (reference soft-delete: status -> terminated).
     * Terminated accounts cannot be terminated again.
     *
     * @throws RuntimeException when the account is already terminated
     */
    public function terminate(HostingAccount $account, ?string $reason = null): void
    {
        if ($account->status === self::STATUS_TERMINATED) {
            throw new RuntimeException("Hosting account {$account->username} is already terminated.");
        }

        $this->mutate($account, 'hosting.terminated', "Hosting account {$account->username} terminated", [
            'status' => self::STATUS_TERMINATED,
            'suspended_reason' => $this->normalizeReason($reason) ?? $account->suspended_reason,
        ], ['reason' => $this->normalizeReason($reason)]);
    }

    /**
     * Write the audit trail for a hosting action. Public so CRUD controllers
     * can reuse the exact same audit shape (audit_log + customer activity_log).
     */
    public function audit(HostingAccount $account, string $action, string $description, array $details = []): void
    {
        $request = app('request');
        $userId = $request?->user()?->id ?? auth()->id();

        AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => 'hosting_account',
            'entity_id' => $account->id,
            'details' => $details !== [] ? json_encode($details) : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);

        if ($account->customer_id !== null) {
            ActivityLog::create([
                'customer_id' => $account->customer_id,
                'user_id' => $userId,
                'action' => $action,
                'description' => $description,
                'metadata' => $details !== [] ? $details : null,
                'ip_address' => $request?->ip(),
                'created_at' => now(),
            ]);
        }
    }

    /**
     * @throws RuntimeException when $account->status is not in $allowed
     */
    private function assertStatus(HostingAccount $account, array $allowed, string $actionLabel): void
    {
        if (! in_array($account->status, $allowed, true)) {
            throw new RuntimeException(
                "Hosting account {$account->username} cannot be {$actionLabel} from status '{$account->status}'."
            );
        }
    }

    /**
     * Apply the status transition and write the audit trail atomically.
     * The optional $before hook runs first inside the same transaction, so
     * a failure there (e.g. IP pool exhaustion on activation) rolls back
     * both the transition and the audit trail.
     */
    private function mutate(HostingAccount $account, string $action, string $description, array $attributes, array $details = [], ?Closure $before = null): void
    {
        DB::transaction(function () use ($account, $action, $description, $attributes, $details, $before) {
            if ($before !== null) {
                $before();
            }

            $account->update($attributes);
            $this->audit($account, $action, $description, $details);
        });
    }

    /**
     * Lease the IPs the product declares when a fresh (pending -> active)
     * activation needs them. The product's flags drive the leases:
     * require_public_ip pulls a 'public' network_type subnet address and
     * require_private_ip a 'private' one — any product type can require
     * either or both. Reactivations from suspended keep the lease they
     * already hold (account is no longer pending).
     *
     * IP leasing is best-effort: a pool that has no free address is logged
     * and skipped — activation still proceeds, and the admin assigns IPs
     * from the hosting page (pull-ip / assign-ips).
     */
    private function leaseIpForActivation(HostingAccount $account): void
    {
        if ($account->status !== self::STATUS_PENDING) {
            return;
        }

        $product = $account->product;

        if (! $product?->requiresIp()) {
            return;
        }

        $assignment = app(IpAssignmentService::class);

        if ($product->requiresPublicIp()) {
            try {
                $assignment->assignNextAvailable($account, networkType: 'public');
            } catch (NoAvailableIpException $e) {
                Log::warning('Public IP pool exhausted during activation — IP assigned later from the hosting page.', [
                    'hosting_account_id' => $account->id,
                    'product' => $product->name,
                ]);
            }
        }

        if ($product->requiresPrivateIp()) {
            try {
                $assignment->assignNextAvailable($account, networkType: 'private');
            } catch (NoAvailableIpException $e) {
                Log::warning('Private IP pool exhausted during activation — IP assigned later from the hosting page.', [
                    'hosting_account_id' => $account->id,
                    'product' => $product->name,
                ]);
            }
        }
    }

    private function normalizeReason(?string $reason): ?string
    {
        return $reason !== null && trim($reason) !== '' ? trim($reason) : null;
    }
}
