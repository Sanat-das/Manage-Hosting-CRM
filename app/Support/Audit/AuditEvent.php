<?php

declare(strict_types=1);

namespace App\Support\Audit;

/**
 * Canonical activity-stream event keys — the vocabulary the audit reader,
 * filters and reports rely on.
 *
 * Values are the EXACT strings already persisted by the legacy writers
 * (mixed snake_case / dot notation kept for compatibility, e.g.
 * `order_created` vs `customer.created`); renaming a case value rewrites
 * history and breaks readers, so treat them as append-only.
 *
 * Dynamic actions composed at runtime (module verbs, `hosting.module_action`)
 * stay on `AuditRecorder::activity(..., string $event)`.
 */
enum AuditEvent: string
{
    // Orders
    case OrderCreated = 'order_created';
    case OrderStatusChanged = 'order_status_changed';

    // Customer lifecycle
    case CustomerCreated = 'customer.created';
    case CustomerUpdated = 'customer.updated';
    case CustomerDeleted = 'customer.deleted';

    // Customer notes / contacts / wallet
    case NoteAdded = 'note_added';
    case NoteDeleted = 'note_deleted';
    case NoteUpdated = 'note_updated';
    case ContactCreated = 'contact_created';
    case ContactUpdated = 'contact_updated';
    case ContactDeleted = 'contact_deleted';
    case WalletAdjusted = 'wallet_adjusted';

    // Hosting accounts
    case HostingCreated = 'hosting.created';
    case HostingUpdated = 'hosting.updated';
    case HostingSuspended = 'hosting.suspended';
    case HostingUnsuspended = 'hosting.unsuspended';
    case HostingPackageChanged = 'hosting.package_changed';
    case HostingTerminated = 'hosting.terminated';
    case HostingPasswordChanged = 'hosting.password_changed';
    case HostingNoteAdded = 'hosting.note_added';
    case HostingNoteUpdated = 'hosting.note_updated';
    case HostingNoteDeleted = 'hosting.note_deleted';
    case HostingModuleAction = 'hosting.module_action';

    // Impersonation
    case ImpersonationStarted = 'impersonation_started';
    case ImpersonationStopped = 'impersonation_stopped';

    // Billing
    case AddonAttached = 'addon.attached';
    case AddonCancelled = 'addon.cancelled';
    case UpgradeApplied = 'upgrade.applied';

    // Product options
    case OptionGroupAttached = 'option_group_attached';
    case OptionGroupDetached = 'option_group_detached';
    case OptionGroupSynced = 'option_group_synced';

    // Staff users
    case UserCreated = 'user_created';
    case UserUpdated = 'user_updated';
    case UserDeleted = 'user_deleted';
    case StatusChanged = 'status_changed';
    case PasswordResetEmail = 'password_reset_email';
    case PasswordSet = 'password_set';

    // Settings & system
    case SettingsUpdated = 'settings.updated';
    case SystemUpdated = 'system.updated';
    case SystemRolledback = 'system.rolledback';

    // Service instances
    case ServiceMoved = 'service.moved';
}
