<?php

namespace Database\Seeders\Demo;

use Database\Seeders\Demo\Traits\WithIdempotentSeed;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Demo seeder for the audit & log domain.
 *
 * TABLES OWNED BY THIS SEEDER
 * ---------------------------
 * - `audit_log`       (DummyDataConfig::ROWS = 15) who changed what
 * - `activity_log`    (DummyDataConfig::ROWS = 15) human-readable timeline
 * - `automation_log`  (DummyDataConfig::ROWS = 10) unattended job outcomes
 * - `cron_logs`       (DummyDataConfig::ROWS =  5) scheduler run history
 *
 * WHY IDEMPOTENCY IS THE HARD PART HERE
 * -------------------------------------
 * None of these four tables carries a UNIQUE index - they are append-only by
 * nature, so a naive seeder doubles their size on every run. Every write here
 * goes through `WithIdempotentSeed`, matched on the natural key declared in
 * `DummyDataConfig::NATURAL_KEYS`:
 *
 *   audit_log       user_id + action + entity_type + entity_id
 *   activity_log    user_id + action + description
 *   automation_log  action + entity_type + entity_id
 *   cron_logs       job_name + command
 *
 * Two extra rules keep those keys stable across runs:
 *
 * 1. NO VOLATILE VALUES IN A NATURAL KEY. Actions, entity types and
 *    descriptions are constants; `entity_id` is resolved from a real business
 *    row (never random, never a bare auto-increment guess).
 * 2. NO `now()` ANYWHERE. Every timestamp is derived from the fixed `EPOCH`
 *    anchor below, so a re-run rewrites byte-identical values instead of
 *    drifting. (`created_at` is not part of any natural key, but a drifting
 *    value would still make "identical after two runs" unprovable.)
 *
 * ORGANIC ROWS ARE NEVER TOUCHED
 * ------------------------------
 * The dev database already holds ~20 `activity_log` rows written by the real
 * application (logins, impersonation, order status changes). Those rows are
 * left completely alone: every description this seeder writes is prefixed
 * with `Demo:`, which cannot collide with an application-generated one, so
 * `updateOrInsert` can only ever match a row this seeder itself created.
 *
 * ORPHAN DEMO ROWS ARE PURGED BEFORE SEEDING
 * -------------------------------------------
 * None of these log tables enforces its entity reference - the migration
 * declares plain unsigned integers with no foreign keys, so a bad id fails
 * silently instead of erroring. When an entity is later rebuilt (SupportSeeder
 * recreates the tickets, for example) the old auto-increment ids vanish while
 * their log rows survive, and because the natural key is
 * `action + entity_type + entity_id` a re-run INSERTS a fresh row next to the
 * stale one instead of updating it - leaving both an orphan reference and a
 * duplicate action. `purgeOrphanDemoRows()` therefore deletes every `Demo:`-
 * prefixed row whose entity reference no longer resolves to a real row in the
 * mapped table (customer->customers, product->products, user->users,
 * order->orders, ticket->tickets, hosting_account->hosting_accounts,
 * server->servers, setting->settings) before any seeding starts. Only `Demo:`-
 * marked rows are ever deleted, so organic application rows are safe. After
 * the first run nothing is left to purge, so the step is itself idempotent
 * and the byte-identical re-run guarantee below still holds.
 *
 * NULLS AND NATURAL KEYS DO NOT MIX
 * ---------------------------------
 * `DB::table()->where(['entity_id' => null])` compiles to `entity_id = NULL`,
 * which matches nothing, so a nullable natural-key column holding NULL would
 * insert a duplicate on every run. Every `entity_id` / `user_id` written here
 * is therefore guaranteed non-null and resolved from a real row.
 *
 * ENUM VALUES ARE READ FROM THE MIGRATION, NOT GUESSED
 * ----------------------------------------------------
 * Copied verbatim from
 * `database/migrations/2026_07_30_120060_create_audit_tables.php`:
 *
 *   automation_log.status   pending | success | failed
 *   cron_logs.status        pending | running | success | failed
 *
 * `audit_log` and `activity_log` declare no enum column at all.
 *
 * SCHEMA NOTES THAT SHAPE THE DATA
 * --------------------------------
 * - All four tables have `created_at` only (no `updated_at`), and the Eloquent
 *   models declare `$timestamps = false`. The trait inspects the real column
 *   list at runtime, so nothing here special-cases it.
 * - `audit_log.entity_type` is NOT NULL; `entity_id`, `user_id` are nullable
 *   unsigned integers with no foreign key, so a bad id would fail silently
 *   rather than error - hence the runtime resolution below.
 * - `activity_log` carries two parallel column families: the reference set
 *   (`action`, `description`, `metadata`, `ip_address`) and the AdminLTE
 *   ActivityLogger set (`event`, `subject_type`, `subject_id`, `properties`,
 *   `user_agent`). Demo rows populate both so either reader renders them.
 * - `cron_logs` has three finish columns (`completed_at`, `finished_at`) plus
 *   `started_at`; both finish columns are written together for consistency.
 */
class AuditSeeder extends Seeder
{
    use WithIdempotentSeed;

    /**
     * Fixed timeline anchor. Every timestamp below is `EPOCH + n minutes`,
     * which makes a re-run byte-identical instead of merely row-count-stable.
     */
    private const EPOCH = '2026-07-01 08:00:00';

    /** Marker that keeps demo descriptions from colliding with real app rows. */
    private const DEMO_PREFIX = 'Demo:';

    /** Documentation-range address (RFC 5737) used for every demo log entry. */
    private const DEMO_IPS = ['192.0.2.24', '192.0.2.51', '198.51.100.17', '203.0.113.9'];

    private const DEMO_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) DemoSeeder/1.0';

    /**
     * Audit trail plan. `entity` is a PREFERRED type - `pickEntity()` falls
     * back to a type that actually has rows, so the seeder still reaches its
     * minimum on a database where, say, no invoices exist yet.
     *
     * @var list<array{actor:string,action:string,entity:string,slot:int,details:string}>
     */
    private const AUDIT_PLAN = [
        ['actor' => 'admin@localhost.com', 'action' => 'user.login', 'entity' => 'user', 'slot' => 0, 'details' => 'Administrator signed in from the office network.'],
        ['actor' => 'admin@localhost.com', 'action' => 'user.created', 'entity' => 'user', 'slot' => 1, 'details' => 'Client login provisioned during demo onboarding.'],
        ['actor' => 'admin@localhost.com', 'action' => 'user.role.assigned', 'entity' => 'user', 'slot' => 2, 'details' => 'Role membership granted through the RBAC screen.'],
        ['actor' => 'sales@example.com', 'action' => 'customer.created', 'entity' => 'customer', 'slot' => 0, 'details' => 'Customer account opened from an inbound enquiry.'],
        ['actor' => 'sales@example.com', 'action' => 'customer.updated', 'entity' => 'customer', 'slot' => 1, 'details' => 'Billing address and tax id corrected.'],
        ['actor' => 'support@example.com', 'action' => 'customer.note.added', 'entity' => 'customer', 'slot' => 2, 'details' => 'Call summary attached to the customer record.'],
        ['actor' => 'marketing@example.com', 'action' => 'customer.exported', 'entity' => 'customer', 'slot' => 3, 'details' => 'Consented contacts exported for a newsletter run.'],
        ['actor' => 'admin@localhost.com', 'action' => 'product.created', 'entity' => 'product', 'slot' => 0, 'details' => 'Catalog entry published to the order form.'],
        ['actor' => 'admin@localhost.com', 'action' => 'product.updated', 'entity' => 'product', 'slot' => 1, 'details' => 'Feature list and quota summary revised.'],
        ['actor' => 'admin@localhost.com', 'action' => 'product.pricing.updated', 'entity' => 'product', 'slot' => 2, 'details' => 'Annual price reduced for the summer campaign.'],
        ['actor' => 'sales@example.com', 'action' => 'order.placed', 'entity' => 'order', 'slot' => 0, 'details' => 'Order captured on behalf of the customer by phone.'],
        ['actor' => 'sales@example.com', 'action' => 'order.approved', 'entity' => 'order', 'slot' => 1, 'details' => 'Fraud review cleared, order released to provisioning.'],
        ['actor' => 'support@example.com', 'action' => 'ticket.opened', 'entity' => 'ticket', 'slot' => 0, 'details' => 'Support request raised on behalf of the customer.'],
        ['actor' => 'support@example.com', 'action' => 'ticket.closed', 'entity' => 'ticket', 'slot' => 1, 'details' => 'Issue resolved and confirmed by the requester.'],
        ['actor' => 'admin@localhost.com', 'action' => 'hosting_account.suspended', 'entity' => 'hosting_account', 'slot' => 0, 'details' => 'Account suspended for a non-payment reminder cycle.'],
        ['actor' => 'admin@localhost.com', 'action' => 'hosting_account.unsuspended', 'entity' => 'hosting_account', 'slot' => 1, 'details' => 'Account restored after the balance was cleared.'],
        ['actor' => 'admin@localhost.com', 'action' => 'server.updated', 'entity' => 'server', 'slot' => 0, 'details' => 'Node capacity limits adjusted after a RAM upgrade.'],
        ['actor' => 'admin@localhost.com', 'action' => 'settings.updated', 'entity' => 'setting', 'slot' => 0, 'details' => 'Invoice numbering prefix changed in company settings.'],
    ];

    /**
     * Activity timeline plan. `subject` is a preferred entity type used for the
     * AdminLTE `subject_type`/`subject_id` pair; `customer` links the row to a
     * real customer where the action is customer-facing.
     *
     * @var list<array{actor:string,action:string,event:string,subject:string,slot:int,text:string,customer:bool}>
     */
    private const ACTIVITY_PLAN = [
        ['actor' => 'admin@localhost.com', 'action' => 'auth.login', 'event' => 'auth.login', 'subject' => 'user', 'slot' => 0, 'text' => 'signed in to the admin panel', 'customer' => false],
        ['actor' => 'support@example.com', 'action' => 'auth.login', 'event' => 'auth.login', 'subject' => 'user', 'slot' => 1, 'text' => 'support agent signed in for the morning shift', 'customer' => false],
        ['actor' => 'sales@example.com', 'action' => 'customer.created', 'event' => 'created', 'subject' => 'customer', 'slot' => 0, 'text' => 'created a customer account', 'customer' => true],
        ['actor' => 'sales@example.com', 'action' => 'customer.updated', 'event' => 'updated', 'subject' => 'customer', 'slot' => 1, 'text' => 'updated customer billing details', 'customer' => true],
        ['actor' => 'support@example.com', 'action' => 'customer.note.added', 'event' => 'created', 'subject' => 'customer', 'slot' => 2, 'text' => 'logged a call note against the customer', 'customer' => true],
        ['actor' => 'sales@example.com', 'action' => 'order.placed', 'event' => 'created', 'subject' => 'order', 'slot' => 0, 'text' => 'placed an order for a hosting plan', 'customer' => true],
        ['actor' => 'sales@example.com', 'action' => 'order.status.changed', 'event' => 'updated', 'subject' => 'order', 'slot' => 1, 'text' => "moved an order from 'pending' to 'active'", 'customer' => true],
        ['actor' => 'admin@localhost.com', 'action' => 'invoice.paid', 'event' => 'updated', 'subject' => 'order', 'slot' => 2, 'text' => 'marked an invoice as paid after a bank transfer', 'customer' => true],
        ['actor' => 'admin@localhost.com', 'action' => 'product.created', 'event' => 'created', 'subject' => 'product', 'slot' => 0, 'text' => 'published a new product to the catalog', 'customer' => false],
        ['actor' => 'admin@localhost.com', 'action' => 'product.pricing.updated', 'event' => 'updated', 'subject' => 'product', 'slot' => 1, 'text' => 'revised the annual price of a product', 'customer' => false],
        ['actor' => 'support@example.com', 'action' => 'ticket.opened', 'event' => 'created', 'subject' => 'ticket', 'slot' => 0, 'text' => 'opened a support ticket for the customer', 'customer' => true],
        ['actor' => 'support@example.com', 'action' => 'ticket.replied', 'event' => 'created', 'subject' => 'ticket', 'slot' => 1, 'text' => 'replied to a support ticket', 'customer' => true],
        ['actor' => 'support@example.com', 'action' => 'ticket.closed', 'event' => 'updated', 'subject' => 'ticket', 'slot' => 2, 'text' => 'closed a resolved support ticket', 'customer' => true],
        ['actor' => 'admin@localhost.com', 'action' => 'hosting_account.suspended', 'event' => 'updated', 'subject' => 'hosting_account', 'slot' => 0, 'text' => 'suspended a hosting account for non-payment', 'customer' => true],
        ['actor' => 'admin@localhost.com', 'action' => 'hosting_account.unsuspended', 'event' => 'updated', 'subject' => 'hosting_account', 'slot' => 1, 'text' => 'restored a hosting account after payment', 'customer' => true],
        ['actor' => 'marketing@example.com', 'action' => 'marketing.consent.recorded', 'event' => 'created', 'subject' => 'customer', 'slot' => 3, 'text' => 'recorded a marketing consent opt-in', 'customer' => true],
        ['actor' => 'admin@localhost.com', 'action' => 'settings.updated', 'event' => 'updated', 'subject' => 'user', 'slot' => 0, 'text' => 'changed the invoice numbering prefix', 'customer' => false],
        ['actor' => 'admin@localhost.com', 'action' => 'auth.logout', 'event' => 'auth.logout', 'subject' => 'user', 'slot' => 0, 'text' => 'signed out of the admin panel', 'customer' => false],
    ];

    /**
     * Unattended automation outcomes. Statuses cover the whole
     * `pending|success|failed` enum.
     *
     * @var list<array{action:string,entity:string,slot:int,status:string,message:string}>
     */
    private const AUTOMATION_PLAN = [
        ['action' => 'invoice.generate', 'entity' => 'customer', 'slot' => 0, 'status' => 'success', 'message' => 'Recurring invoice generated for the monthly billing run.'],
        ['action' => 'invoice.reminder', 'entity' => 'customer', 'slot' => 1, 'status' => 'success', 'message' => 'First overdue reminder emailed to the billing contact.'],
        ['action' => 'invoice.overdue.suspend', 'entity' => 'customer', 'slot' => 2, 'status' => 'pending', 'message' => 'Suspension queued, awaiting the 7-day grace period.'],
        ['action' => 'payment.retry', 'entity' => 'customer', 'slot' => 3, 'status' => 'failed', 'message' => 'Gateway declined the stored card (do_not_honour).'],
        ['action' => 'service.provision', 'entity' => 'hosting_account', 'slot' => 0, 'status' => 'success', 'message' => 'cPanel account created and welcome email dispatched.'],
        ['action' => 'service.suspend', 'entity' => 'hosting_account', 'slot' => 1, 'status' => 'success', 'message' => 'Account suspended by the overdue-invoice automation.'],
        ['action' => 'service.terminate', 'entity' => 'hosting_account', 'slot' => 2, 'status' => 'pending', 'message' => 'Termination scheduled after the retention window.'],
        ['action' => 'domain.renew', 'entity' => 'product', 'slot' => 0, 'status' => 'failed', 'message' => 'Registrar API timed out after 3 attempts.'],
        ['action' => 'ssl.renew', 'entity' => 'product', 'slot' => 1, 'status' => 'success', 'message' => 'Certificate renewed and installed on the node.'],
        ['action' => 'backup.verify', 'entity' => 'server', 'slot' => 0, 'status' => 'success', 'message' => 'Nightly backup checksum verified, 0 corrupt archives.'],
        ['action' => 'usage.aggregate', 'entity' => 'server', 'slot' => 1, 'status' => 'success', 'message' => 'Hourly usage counters rolled up into usage_records.'],
        ['action' => 'ticket.autoclose', 'entity' => 'ticket', 'slot' => 0, 'status' => 'pending', 'message' => 'Awaiting customer response before auto-closing.'],
    ];

    /**
     * Scheduler history. Statuses cover the whole
     * `pending|running|success|failed` enum.
     *
     * @var list<array{job_name:string,command:string,status:string,message:string,processed:int,errors:int,minutes:int}>
     */
    private const CRON_PLAN = [
        ['job_name' => 'billing:generate-invoices', 'command' => 'php artisan billing:generate-invoices', 'status' => 'success', 'message' => 'Generated 4 invoices, skipped 1 suspended account.', 'processed' => 5, 'errors' => 0, 'minutes' => 3],
        ['job_name' => 'billing:send-reminders', 'command' => 'php artisan billing:send-reminders --days=7', 'status' => 'success', 'message' => 'Queued 3 overdue reminders.', 'processed' => 3, 'errors' => 0, 'minutes' => 1],
        ['job_name' => 'domains:sync-expiry', 'command' => 'php artisan domains:sync-expiry', 'status' => 'failed', 'message' => 'Registrar API returned HTTP 503 for 2 domains.', 'processed' => 8, 'errors' => 2, 'minutes' => 6],
        ['job_name' => 'services:suspend-overdue', 'command' => 'php artisan services:suspend-overdue', 'status' => 'success', 'message' => 'Suspended 1 hosting account past its grace period.', 'processed' => 1, 'errors' => 0, 'minutes' => 2],
        ['job_name' => 'usage:collect', 'command' => 'php artisan usage:collect --interval=hourly', 'status' => 'running', 'message' => 'Collecting counters from 4 nodes.', 'processed' => 2, 'errors' => 0, 'minutes' => 0],
        ['job_name' => 'ssl:renew-expiring', 'command' => 'php artisan ssl:renew-expiring --within=30', 'status' => 'pending', 'message' => 'Scheduled, waiting for the next scheduler tick.', 'processed' => 0, 'errors' => 0, 'minutes' => 0],
        ['job_name' => 'backups:verify', 'command' => 'php artisan backups:verify --all', 'status' => 'success', 'message' => 'Verified 4 nightly archives, all checksums matched.', 'processed' => 4, 'errors' => 0, 'minutes' => 12],
    ];

    /** @var array<string, int> email => users.id */
    private array $users = [];

    /** @var array<string, list<int>> entity type => real primary keys */
    private array $entities = [];

    public function run(): void
    {
        $this->users = $this->resolveUsers();
        $this->entities = $this->resolveEntities();

        if ($this->users === []) {
            $this->command?->warn('  AuditSeeder: no users found - run UserSeeder first. Skipping.');

            return;
        }

        $this->purgeOrphanDemoRows();

        $this->seedAuditLog();
        $this->seedActivityLog();
        $this->seedAutomationLog();
        $this->seedCronLogs();

        $this->report();
    }

    /**
     * Staff logins the demo log entries are attributed to, resolved by email so
     * no primary key is ever assumed. Falls back to the lowest existing user
     * when a named mailbox is absent.
     *
     * @return array<string, int>
     */
    private function resolveUsers(): array
    {
        $emails = ['admin@localhost.com', 'support@example.com', 'sales@example.com', 'marketing@example.com'];

        $found = DB::table('users')
            ->whereIn('email', $emails)
            ->pluck('id', 'email')
            ->map(fn ($id) => (int) $id)
            ->all();

        $fallback = $found !== [] ? reset($found) : DB::table('users')->min('id');

        if ($fallback === null) {
            return [];
        }

        foreach ($emails as $email) {
            $found[$email] ??= (int) $fallback;
        }

        return $found;
    }

    /**
     * Real primary keys per logical entity type, ordered so the same slot maps
     * to the same row on every run. Empty tables are dropped from the map and
     * handled by `pickEntity()`'s fallback chain.
     *
     * @return array<string, list<int>>
     */
    private function resolveEntities(): array
    {
        $sources = [
            'customer' => ['customers', 'id'],
            'product' => ['products', 'id'],
            'user' => ['users', 'id'],
            'order' => ['orders', 'id'],
            'ticket' => ['tickets', 'id'],
            'hosting_account' => ['hosting_accounts', 'id'],
            'server' => ['servers', 'id'],
            'setting' => ['settings', 'id'],
        ];

        $entities = [];

        foreach ($sources as $type => [$table, $column]) {
            $ids = DB::table($table)->orderBy($column)->limit(10)->pluck($column)->all();

            if ($ids !== []) {
                $entities[$type] = array_map('intval', $ids);
            }
        }

        return $entities;
    }

    /**
     * Delete every `Demo:`-prefixed log row whose entity reference no longer
     * resolves to a real row. See the class docblock for why this is needed.
     *
     * Deletion is scoped by the `Demo:` marker column (`details`/`message`/
     * `description`) so organic application rows are never touched, and is
     * done by PRIMARY KEY rather than by (entity_type, entity_id) because
     * several plan entries legitimately share one referenced row under
     * different actions - deleting on the pair would take valid siblings too.
     * Unknown entity types are skipped rather than guessed. Runs inside a
     * transaction and is itself idempotent: after the first run every
     * surviving demo reference resolves, so later runs delete nothing.
     */
    private function purgeOrphanDemoRows(): void
    {
        $sources = [
            'customer' => ['customers', 'id'],
            'product' => ['products', 'id'],
            'user' => ['users', 'id'],
            'order' => ['orders', 'id'],
            'ticket' => ['tickets', 'id'],
            'hosting_account' => ['hosting_accounts', 'id'],
            'server' => ['servers', 'id'],
            'setting' => ['settings', 'id'],
        ];

        // log table => [entity type column, entity id column, Demo: marker column]
        $tables = [
            'audit_log' => ['entity_type', 'entity_id', 'details'],
            'automation_log' => ['entity_type', 'entity_id', 'message'],
            'activity_log' => ['subject_type', 'subject_id', 'description'],
        ];

        DB::transaction(function () use ($sources, $tables): void {
            foreach ($tables as $table => [$typeColumn, $idColumn, $markerColumn]) {
                $rows = DB::table($table)
                    ->where($markerColumn, 'like', self::DEMO_PREFIX.'%')
                    ->get(['id', $typeColumn, $idColumn]);

                foreach ($rows as $row) {
                    $type = $row->{$typeColumn};

                    if ($row->{$idColumn} === null || ! isset($sources[$type])) {
                        continue;
                    }

                    [$refTable, $refColumn] = $sources[$type];

                    if (! DB::table($refTable)->where($refColumn, $row->{$idColumn})->exists()) {
                        DB::table($table)->where('id', $row->id)->delete();
                    }
                }
            }
        });
    }

    /**
     * Resolve a preferred entity type + slot to a real (type, id) pair.
     *
     * The fallback chain guarantees a NON-NULL id, which matters twice over:
     * `entity_id` is half of every natural key here, and a NULL in a natural
     * key silently defeats `updateOrInsert` (see the class docblock).
     *
     * @return array{string, int}
     */
    private function pickEntity(string $preferred, int $slot): array
    {
        foreach ([$preferred, 'customer', 'user'] as $type) {
            if (isset($this->entities[$type])) {
                $ids = $this->entities[$type];

                return [$type, $ids[$slot % count($ids)]];
            }
        }

        throw new \RuntimeException('AuditSeeder: no business rows available to reference.');
    }

    /** Deterministic timestamp: the fixed epoch plus a fixed offset. */
    private function at(int $minutes): Carbon
    {
        return Carbon::parse(self::EPOCH)->addMinutes($minutes);
    }

    private function userId(string $email): int
    {
        return $this->users[$email] ?? (int) reset($this->users);
    }

    /**
     * Who changed what. Natural key: user_id + action + entity_type + entity_id
     * - all four constant per plan entry, so a re-run updates in place.
     */
    private function seedAuditLog(): void
    {
        $rows = [];

        foreach (self::AUDIT_PLAN as $i => $entry) {
            [$type, $entityId] = $this->pickEntity($entry['entity'], $entry['slot']);

            $rows[] = [
                'user_id' => $this->userId($entry['actor']),
                'action' => $entry['action'],
                'entity_type' => $type,
                'entity_id' => $entityId,
                'details' => self::DEMO_PREFIX.' '.$entry['details'],
                'ip_address' => self::DEMO_IPS[$i % count(self::DEMO_IPS)],
                'user_agent' => self::DEMO_AGENT,
                'created_at' => $this->at($i * 17),
            ];
        }

        $this->seedRows('audit_log', $rows);
    }

    /**
     * Human-readable timeline. Natural key: user_id + action + description.
     *
     * Descriptions carry the `Demo:` prefix so they can never collide with the
     * rows the live application writes - those are left exactly as they are.
     */
    private function seedActivityLog(): void
    {
        $customerIds = $this->entities['customer'] ?? [];
        $rows = [];

        foreach (self::ACTIVITY_PLAN as $i => $entry) {
            [$type, $subjectId] = $this->pickEntity($entry['subject'], $entry['slot']);
            $actorId = $this->userId($entry['actor']);
            $customerId = $entry['customer'] && $customerIds !== []
                ? $customerIds[$entry['slot'] % count($customerIds)]
                : null;

            $rows[] = [
                'customer_id' => $customerId,
                'user_id' => $actorId,
                'action' => $entry['action'],
                'description' => self::DEMO_PREFIX.' '.$entry['actor'].' '.$entry['text'],
                'metadata' => json_encode([
                    'actor' => $entry['actor'],
                    'source' => 'demo-seeder',
                    'subject_type' => $type,
                    'subject_id' => $subjectId,
                ]),
                'ip_address' => self::DEMO_IPS[$i % count(self::DEMO_IPS)],
                'event' => $entry['event'],
                'subject_type' => $type,
                'subject_id' => $subjectId,
                'properties' => json_encode([
                    'demo' => true,
                    'sequence' => $i + 1,
                ]),
                'user_agent' => self::DEMO_AGENT,
                'created_at' => $this->at(($i * 23) + 5),
            ];
        }

        $this->seedRows('activity_log', $rows);
    }

    /**
     * Unattended job outcomes. Natural key: action + entity_type + entity_id
     * (no user column - these rows have no human actor).
     */
    private function seedAutomationLog(): void
    {
        $rows = [];

        foreach (self::AUTOMATION_PLAN as $i => $entry) {
            [$type, $entityId] = $this->pickEntity($entry['entity'], $entry['slot']);
            $settled = $entry['status'] !== 'pending';
            $startedAt = $this->at($i * 31);

            $rows[] = [
                'action' => $entry['action'],
                'entity_type' => $type,
                'entity_id' => $entityId,
                'status' => $entry['status'],
                'message' => self::DEMO_PREFIX.' '.$entry['message'],
                'created_at' => $startedAt,
                'completed_at' => $settled ? $startedAt->copy()->addMinutes(2) : null,
            ];
        }

        $this->seedRows('automation_log', $rows);
    }

    /**
     * Scheduler history. Natural key: job_name + command.
     *
     * `started_at` is null for a queued run and `completed_at`/`finished_at`
     * are null for anything that has not settled, mirroring what the real
     * scheduler writes.
     */
    private function seedCronLogs(): void
    {
        $rows = [];

        foreach (self::CRON_PLAN as $i => $entry) {
            $queued = $entry['status'] === 'pending';
            $settled = in_array($entry['status'], ['success', 'failed'], true);
            $createdAt = $this->at($i * 60);
            $startedAt = $queued ? null : $createdAt->copy()->addMinute();
            $finishedAt = $settled ? $createdAt->copy()->addMinutes(1 + $entry['minutes']) : null;

            $rows[] = [
                'job_name' => $entry['job_name'],
                'command' => $entry['command'],
                'status' => $entry['status'],
                'message' => self::DEMO_PREFIX.' '.$entry['message'],
                'domains_processed' => $entry['processed'],
                'errors_count' => $entry['errors'],
                'started_at' => $startedAt,
                'completed_at' => $finishedAt,
                'finished_at' => $finishedAt,
                'created_at' => $createdAt,
            ];
        }

        $this->seedRows('cron_logs', $rows);
    }

    private function report(): void
    {
        foreach (['audit_log', 'activity_log', 'automation_log', 'cron_logs'] as $table) {
            $this->command?->info(sprintf(
                '  %-16s %4d rows (min %d)',
                $table,
                DB::table($table)->count(),
                DummyDataConfig::minRows($table)
            ));
        }
    }
}
