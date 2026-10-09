<?php

declare(strict_types=1);

namespace App\Support\Logging;

/**
 * The business domains of the centralized logging pipeline.
 *
 * Routing rule — the channel names the DOMAIN of the operation, not the file
 * that happens to run it:
 * - Security: authentication, registration abuse, encryption anomalies.
 * - Billing: orders, invoices, payments, upgrades, domains.
 * - Provisioning: hosting accounts, panels, VMs, servers, network/IPAM.
 * - Cron: scheduled tasks, queue drain infrastructure, mailbox/ticket polling.
 * - Ops: system update, rollback, deployment and app-info operations.
 * - App: everything else (chat, search, module bootstrapping, generic flows).
 * - Alert: the webhook/file seam for records that must page a human.
 *
 * Severity policy (PSR-3):
 * - error: an operation failed and needs human action or investigation.
 * - warning: the system degraded or fell back but recovered on its own.
 * - info: lifecycle events worth a paper trail.
 * - debug: diagnostics for local development; expected to be absent in
 *   production logs.
 */
enum LogChannel: string
{
    case App = 'app';
    case Security = 'security';
    case Billing = 'billing';
    case Provisioning = 'provisioning';
    case Cron = 'cron';
    case Ops = 'ops';
    case Alert = 'alert';
}
