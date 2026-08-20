<?php

namespace Database\Seeders\Demo;

use Database\Seeders\Demo\Traits\WithIdempotentSeed;
use Database\Seeders\PaymentGatewaySeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Demo e-mail, notification and billing-config data (Task 19).
 *
 * SCHEMA (read from the migrations, never guessed)
 * ------------------------------------------------
 *   email_templates         id, name, subject varchar(255), body text,
 *                           status ENUM(active|inactive), timestamps.
 *                           Natural key: name.
 *   email_queue             id, to_email, subject, body text,
 *                           status ENUM(pending|sent|failed),
 *                           attempts tinyint, error text NULL,
 *                           created_at ONLY (+ sent_at datetime NULL).
 *                           No updated_at - the trait detects that.
 *                           Natural key: (to_email, subject).
 *   emails                  id, customer_id INT UNSIGNED NOT NULL (no FK),
 *                           to_email, subject varchar(500), body text,
 *                           template_name varchar(255),
 *                           status ENUM(sent|failed|queued), error text NULL,
 *                           timestamps. Natural key: (customer_id, to_email,
 *                           subject). Model: App\Models\EmailLog (table emails).
 *   notifications           id CHAR(36) PRIMARY KEY (UUID string), type,
 *                           notifiable_type, notifiable_id bigint, data text
 *                           (JSON), read_at NULL, timestamps.
 *                           Natural key: id.
 *   notification_preferences id bigint, preferrable_type, preferrable_id
 *                           bigint, type, channel, enabled tinyint(1),
 *                           timestamps. Compound UNIQUE (preferrable_type,
 *                           preferrable_id, type, channel) - the natural key.
 *   registrar_settings      id, registrar NOT NULL, setting_key NOT NULL,
 *                           setting_value text NULL, timestamps.
 *                           Natural key: (registrar, setting_key).
 *   tax_rates               id, name NULL, rate decimal(10,2) NOT NULL,
 *                           is_active tinyint(1) default 1, timestamps.
 *                           Natural key: name.
 *   payment_gateways        id, code, name, driver, mode ENUM(test|live),
 *                           enabled, sort_order, credentials, timestamps.
 *                           Owned by Database\Seeders\PaymentGatewaySeeder
 *                           (updateOrCreate on code) - this seeder only calls
 *                           it, it never writes gateway rows itself.
 *   gst_settings            single pre-existing row (id=1). NEVER touched
 *                           here - only reported so its count is asserted.
 *
 * FOREIGN KEYS ARE RESOLVED LAZILY AT RUN TIME
 * --------------------------------------------
 * `emails.customer_id` is an INT NOT NULL column WITHOUT a database FK, so an
 * unresolved customer would rot silently - every customer id is looked up in
 * `customers` and throws on failure. The real recipient e-mail is read from
 * the linked `users` row (`customers.user_id` -> `users.email`) at runtime.
 * `notification_preferences.preferrable_id` is a polymorphic reference - each
 * id is validated against `users` (the only preferrable type this seeder
 * writes). Same throw-on-failure rule.
 *
 * NEVER HARD-CODE A SURROGATE ID
 * ------------------------------
 * An earlier revision of this seeder embedded literal `customer_id` values
 * 5-10 and `user_id` values 1/6-9, because those were the auto-increment ids
 * the demo rows happened to receive on the shared dev database. On a FRESH
 * database (`migrate:fresh`, or the sqlite:memory: DB every Feature test gets
 * from RefreshDatabase) `CustomerSeeder` writes customers 1-5, so id 6 does
 * not exist and the whole seed chain aborted. Auto-increment ids are an
 * artifact of insert order, never a contract.
 *
 * Every reference is therefore an ORDINAL into the demo set, resolved through
 * `demoCustomerIds()` / `demoClientUserIds()` (ordered by the deterministic
 * `client{N}@example.com` login, which IS a contract) and wrapped with modulo
 * so the plan keeps working whatever `DummyDataConfig::CUSTOMERS` is set to.
 * The admin recipient is resolved by `DummyDataConfig::PROTECTED_ROWS`, not by
 * assuming the admin is user 1.
 *
 * ORGANIC DATA IS PRESERVED
 * -------------------------
 * `notifications` already carries one organic row (App\Notifications\
 * TestNotification, notifiable_id=2, random UUID). The four demo rows use
 * fixed literal UUIDs (v4 format) that can never collide with it, and are
 * upserted on `id` so re-runs converge on the same four rows. The organic row
 * is never deleted or modified.
 *
 * IDEMPOTENCY
 * -----------
 * Every write goes through `seedRow()` (updateOrInsert on the natural key).
 * Timestamps are fixed (EPOCH + hour offset) and never `now()`, so a re-run
 * produces byte-identical rows.
 */
class NotificationEmailSeeder extends Seeder
{
    use WithIdempotentSeed;

    /**
     * Fixed, deterministic timestamp base for every table. Never `now()`.
     */
    private const EPOCH = '2026-07-01 00:00:00';

    /**
     * Six demo e-mail templates; `password_reset` is deliberately inactive so
     * the status ENUM's second branch is exercised.
     *
     * @var list<array{name: string, subject: string, status: string, body: string}>
     */
    private const TEMPLATES = [
        [
            'name' => 'welcome',
            'subject' => 'Welcome to Demo Hosting!',
            'status' => 'active',
            'body' => "Hi {{name}},\n\nWelcome to Demo Hosting! Your account is ready to use.\n\nThanks,\nDemo Hosting Team",
        ],
        [
            'name' => 'order_confirmation',
            'subject' => 'Order {{order_no}} confirmed',
            'status' => 'active',
            'body' => "Hi {{name}},\n\nYour order {{order_no}} has been received and is being processed. Order total: {{total}}.\n\nThanks,\nDemo Hosting Team",
        ],
        [
            'name' => 'invoice_created',
            'subject' => 'Your demo invoice is ready',
            'status' => 'active',
            'body' => "Hi {{name}},\n\nYour demo invoice {{invoice_no}} is ready. Please review and pay it by {{due_date}}.\n\nThanks,\nDemo Hosting Team",
        ],
        [
            'name' => 'invoice_overdue_reminder',
            'subject' => 'Demo invoice overdue reminder',
            'status' => 'active',
            'body' => "Hi {{name}},\n\nYour demo invoice {{invoice_no}} is overdue. Please arrange payment to avoid service interruption.\n\nThanks,\nDemo Hosting Team",
        ],
        [
            'name' => 'payment_received',
            'subject' => 'Payment received - thank you!',
            'status' => 'active',
            'body' => "Hi {{name}},\n\nWe received your payment of {{amount}} for {{invoice_no}}. Thank you!\n\nThanks,\nDemo Hosting Team",
        ],
        [
            'name' => 'password_reset',
            'subject' => 'Reset your Demo Hosting password',
            'status' => 'inactive',
            'body' => "Hi {{name}},\n\nUse the link below to reset your Demo Hosting password.\n\nThanks,\nDemo Hosting Team",
        ],
        [
            'name' => 'support_ticket_reply',
            'subject' => 'New reply on your support ticket',
            'status' => 'active',
            'body' => "Hi {{name}},\n\nThere is a new reply on your support ticket {{ticket_no}}.\n\nThanks,\nDemo Hosting Team",
        ],
    ];

    /**
     * Five queued e-mails: pending x2, sent x2, failed x1. `error` only for
     * the failed row, `attempts` 0 for pending and 1-2 otherwise.
     *
     * `customer` is an ORDINAL into the demo customer set (0-based, wrapped
     * with modulo), never a database id - see the class docblock.
     *
     * @var list<array{customer: int, subject: string, status: string, attempts: int, error: ?string}>
     */
    private const QUEUE = [
        ['customer' => 0, 'subject' => 'Your invoice DEMO-INV-2026-0001 is ready', 'status' => 'pending', 'attempts' => 0, 'error' => null],
        ['customer' => 1, 'subject' => 'Reminder: invoice DEMO-INV-2026-0003 is overdue', 'status' => 'pending', 'attempts' => 0, 'error' => null],
        ['customer' => 2, 'subject' => 'Payment confirmation for DEMO-INV-2026-0005', 'status' => 'sent', 'attempts' => 1, 'error' => null],
        ['customer' => 3, 'subject' => 'Your invoice DEMO-INV-2026-0006 is ready', 'status' => 'sent', 'attempts' => 2, 'error' => null],
        ['customer' => 4, 'subject' => 'Delivery failure notice for DEMO-INV-2026-0008', 'status' => 'failed', 'attempts' => 2, 'error' => 'SMTP 550: mailbox unavailable'],
    ];

    /**
     * Ten logged e-mails (sent x7, queued x2, failed x1) spread across the
     * demo customer set. `to_email` and `body` are derived at run time from
     * the live customer row and the matching template row.
     *
     * `customer` is an ORDINAL into the demo customer set (0-based, wrapped
     * with modulo), never a database id - see the class docblock.
     *
     * @var list<array{customer: int, template: string, subject: string, status: string, error: ?string}>
     */
    private const EMAILS = [
        ['customer' => 0, 'template' => 'invoice_created', 'subject' => 'Your invoice DEMO-INV-2026-0001 is ready', 'status' => 'sent', 'error' => null],
        ['customer' => 0, 'template' => 'payment_received', 'subject' => 'Payment received for DEMO-INV-2026-0002', 'status' => 'sent', 'error' => null],
        ['customer' => 1, 'template' => 'welcome', 'subject' => 'Welcome to Demo Hosting!', 'status' => 'sent', 'error' => null],
        ['customer' => 1, 'template' => 'invoice_overdue_reminder', 'subject' => 'Reminder: invoice DEMO-INV-2026-0003 is overdue', 'status' => 'sent', 'error' => null],
        ['customer' => 2, 'template' => 'support_ticket_reply', 'subject' => 'New reply on support ticket #TKT-DEMO-0001', 'status' => 'sent', 'error' => null],
        ['customer' => 2, 'template' => 'payment_received', 'subject' => 'Payment received for DEMO-INV-2026-0005', 'status' => 'queued', 'error' => null],
        ['customer' => 3, 'template' => 'invoice_created', 'subject' => 'Your invoice DEMO-INV-2026-0006 is ready', 'status' => 'queued', 'error' => null],
        ['customer' => 3, 'template' => 'password_reset', 'subject' => 'Reset your Demo Hosting password', 'status' => 'sent', 'error' => null],
        ['customer' => 4, 'template' => 'payment_received', 'subject' => 'Payment received for DEMO-INV-2026-0008', 'status' => 'sent', 'error' => null],
        ['customer' => 5, 'template' => 'invoice_overdue_reminder', 'subject' => 'Delivery failed: invoice DEMO-INV-2026-0010', 'status' => 'failed', 'error' => 'SMTP 550: recipient rejected'],
    ];

    /**
     * Four demo notifications on deterministic UUID v4 ids (8-4-4-4-12 hex),
     * keyed on `id` so re-runs upsert the same rows. The organic
     * TestNotification row uses a different, random UUID and is untouched.
     *
     * `client` is an ORDINAL into the demo CLIENT USER set (0-based, wrapped
     * with modulo), never a database id - see the class docblock.
     *
     * @var list<array{id: string, type: string, client: int, title: string, message: string}>
     */
    private const NOTIFICATIONS = [
        [
            'id' => '11111111-1111-4111-8111-111111111101',
            'type' => 'App\Notifications\OrderConfirmed',
            'client' => 0,
            'title' => 'Order Confirmed',
            'message' => 'Demo: your order ORD-2026-0101 has been confirmed and is being processed.',
        ],
        [
            'id' => '11111111-1111-4111-8111-111111111102',
            'type' => 'App\Notifications\InvoicePaid',
            'client' => 1,
            'title' => 'Invoice Paid',
            'message' => 'Demo: payment received in full for invoice DEMO-INV-2026-0002.',
        ],
        [
            'id' => '11111111-1111-4111-8111-111111111103',
            'type' => 'App\Notifications\TicketCreated',
            'client' => 2,
            'title' => 'Support Ticket Created',
            'message' => 'Demo: support ticket TKT-DEMO-0001 has been created and assigned.',
        ],
        [
            'id' => '11111111-1111-4111-8111-111111111104',
            'type' => 'App\Notifications\ServiceSuspended',
            'client' => 3,
            'title' => 'Service Suspended',
            'message' => 'Demo: your service has been suspended for non-payment.',
        ],
        // The dev database happened to carry one organic TestNotification row,
        // which masked the fact that four demo rows miss the minimum of 5. On a
        // fresh database there is no organic row, so the demo set must reach
        // the minimum on its own.
        [
            'id' => '11111111-1111-4111-8111-111111111105',
            'type' => 'App\Notifications\InvoiceOverdue',
            'client' => 4,
            'title' => 'Invoice Overdue',
            'message' => 'Demo: invoice DEMO-INV-2026-0003 is past its due date.',
        ],
    ];

    /**
     * Ten per-user notification preferences; every (user, type, channel)
     * triple is unique. Two rows are disabled to exercise the toggle.
     *
     * `client` is an ORDINAL into the demo CLIENT USER set (0-based, wrapped
     * with modulo); `client => null` means the protected admin login, resolved
     * through DummyDataConfig::PROTECTED_ROWS rather than assuming id 1.
     *
     * @var list<array{client: ?int, type: string, channel: string, enabled: int}>
     */
    private const PREFERENCES = [
        ['client' => null, 'type' => 'App\Notifications\OrderConfirmed', 'channel' => 'mail', 'enabled' => 1],
        ['client' => null, 'type' => 'App\Notifications\InvoicePaid', 'channel' => 'database', 'enabled' => 1],
        ['client' => 0, 'type' => 'App\Notifications\OrderConfirmed', 'channel' => 'mail', 'enabled' => 1],
        ['client' => 0, 'type' => 'App\Notifications\InvoicePaid', 'channel' => 'mail', 'enabled' => 1],
        ['client' => 0, 'type' => 'App\Notifications\TicketCreated', 'channel' => 'database', 'enabled' => 1],
        ['client' => 1, 'type' => 'App\Notifications\OrderConfirmed', 'channel' => 'database', 'enabled' => 1],
        ['client' => 1, 'type' => 'App\Notifications\ServiceSuspended', 'channel' => 'mail', 'enabled' => 0],
        ['client' => 2, 'type' => 'App\Notifications\TicketCreated', 'channel' => 'mail', 'enabled' => 1],
        ['client' => 2, 'type' => 'App\Notifications\ServiceSuspended', 'channel' => 'database', 'enabled' => 0],
        ['client' => 3, 'type' => 'App\Notifications\InvoicePaid', 'channel' => 'slack', 'enabled' => 1],
    ];

    /**
     * Per-registrar demo setting values, keyed the same way REGISTRAR_KEYS is.
     *
     * @var array<string, array<string, string>>
     */
    private const REGISTRAR_VALUES = [
        'resellerclub' => [
            'api_key' => 'demo_rc_apikey_9f3a2b1c',
            'test_mode' => '1',
            'username' => 'demo.reseller',
        ],
        'godaddy' => [
            'api_key' => 'demo_gd_apikey_7c1e4d8f',
            'secret' => 'demo_gd_secret_2b8f0a5e',
            'enabled' => '0',
        ],
    ];

    /**
     * Six registrar settings (2 registrars x 3 keys).
     *
     * @var array<string, list<string>>
     */
    private const REGISTRAR_KEYS = [
        'resellerclub' => ['api_key', 'test_mode', 'username'],
        'godaddy' => ['api_key', 'secret', 'enabled'],
    ];

    /**
     * Three tax rates; `Exempt` is inactive for status spread.
     *
     * @var list<array{name: string, rate: float, is_active: int}>
     */
    private const TAX_RATES = [
        ['name' => 'GST 5%', 'rate' => 5.00, 'is_active' => 1],
        ['name' => 'GST 18%', 'rate' => 18.00, 'is_active' => 1],
        ['name' => 'Exempt', 'rate' => 0.00, 'is_active' => 0],
    ];

    /**
     * Self-report thresholds. The matrix minima come from
     * DummyDataConfig::ROWS; payment_gateways and gst_settings are owned by
     * other seeders/config and are only asserted here.
     *
     * @var array<string, int>
     */
    private const REPORT_MIN = [
        'email_templates' => 6,
        'email_queue' => 5,
        'emails' => 10,
        'notifications' => 5,
        'notification_preferences' => 10,
        'registrar_settings' => 6,
        'tax_rates' => 3,
        'payment_gateways' => 3,
        'gst_settings' => 1,
    ];

    /**
     * Demo customer ids in `client{N}@example.com` order, resolved once per run.
     *
     * @var list<int>|null
     */
    private ?array $customerIds = null;

    /**
     * Demo client user ids in `client{N}@example.com` order, resolved once per run.
     *
     * @var list<int>|null
     */
    private ?array $clientUserIds = null;

    public function run(): void
    {
        DB::transaction(function (): void {
            // Gateways are owned by the existing seeder - confirm, don't rewrite.
            $this->call(PaymentGatewaySeeder::class);

            $this->seedEmailTemplates();
            $this->seedEmailQueue();
            $this->seedEmails();
            $this->seedNotifications();
            $this->seedNotificationPreferences();
            $this->seedRegistrarSettings();
            $this->seedTaxRates();

            $this->report();
        });
    }

    private function seedEmailTemplates(): void
    {
        foreach (self::TEMPLATES as $index => $template) {
            $stamp = $this->stamp($index + 1);

            $this->seedRow('email_templates', $template + [
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
        }
    }

    private function seedEmailQueue(): void
    {
        foreach (self::QUEUE as $index => $row) {
            $toEmail = $this->customerEmail($this->customerId($row['customer']));

            $this->seedRow('email_queue', [
                'to_email' => $toEmail,
                'subject' => $row['subject'],
                'body' => sprintf(
                    "Demo queued e-mail to %s re: %s.\n\nThis is a seeded demo e-mail for testing.",
                    $toEmail,
                    $row['subject']
                ),
                'status' => $row['status'],
                'attempts' => $row['attempts'],
                'error' => $row['error'],
                'created_at' => $this->stamp($index + 1),
                'sent_at' => $row['status'] === 'sent' ? $this->stamp(30 + $index) : null,
            ]);
        }
    }

    private function seedEmails(): void
    {
        foreach (self::EMAILS as $index => $row) {
            $customerId = $this->customerId($row['customer']);
            $toEmail = $this->customerEmail($customerId);
            $templateBody = DB::table('email_templates')->where('name', $row['template'])->value('body');

            if ($templateBody === null) {
                throw new RuntimeException(sprintf(
                    'NotificationEmailSeeder: email template "%s" not found.',
                    $row['template']
                ));
            }

            $stamp = $this->stamp($index + 1);

            $this->seedRow('emails', [
                'customer_id' => $customerId,
                'to_email' => $toEmail,
                'subject' => $row['subject'],
                'body' => sprintf(
                    "Demo e-mail for %s using the \"%s\" template.\n\n%s",
                    $toEmail,
                    $row['template'],
                    $templateBody
                ),
                'template_name' => $row['template'],
                'status' => $row['status'],
                'error' => $row['error'],
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
        }
    }

    private function seedNotifications(): void
    {
        foreach (self::NOTIFICATIONS as $index => $row) {
            $userId = $this->clientUserId($row['client']);
            $stamp = $this->stamp($index + 1);

            $this->seedRow('notifications', [
                'id' => $row['id'],
                'type' => $row['type'],
                'notifiable_type' => 'App\Models\User',
                'notifiable_id' => $userId,
                'data' => json_encode(
                    ['title' => $row['title'], 'message' => $row['message']],
                    JSON_UNESCAPED_SLASHES
                ),
                'read_at' => null,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
        }
    }

    private function seedNotificationPreferences(): void
    {
        foreach (self::PREFERENCES as $index => $row) {
            $userId = $row['client'] === null
                ? $this->adminUserId()
                : $this->clientUserId($row['client']);
            $stamp = $this->stamp($index + 1);

            $this->seedRow('notification_preferences', [
                'preferrable_type' => 'App\Models\User',
                'preferrable_id' => $userId,
                'type' => $row['type'],
                'channel' => $row['channel'],
                'enabled' => $row['enabled'],
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
        }
    }

    private function seedRegistrarSettings(): void
    {
        $index = 0;

        foreach (self::REGISTRAR_KEYS as $registrar => $keys) {
            foreach ($keys as $key) {
                $index++;
                $stamp = $this->stamp($index);

                $this->seedRow('registrar_settings', [
                    'registrar' => $registrar,
                    'setting_key' => $key,
                    'setting_value' => self::REGISTRAR_VALUES[$registrar][$key],
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ]);
            }
        }
    }

    private function seedTaxRates(): void
    {
        foreach (self::TAX_RATES as $index => $row) {
            $stamp = $this->stamp($index + 1);

            $this->seedRow('tax_rates', [
                'name' => $row['name'],
                'rate' => $row['rate'],
                'is_active' => $row['is_active'],
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
        }
    }

    /**
     * Resolve the real recipient e-mail for a customer, throwing rather than
     * writing a NULL/blank `emails.to_email`.
     *
     * The `customers` table stores no e-mail itself - the address lives on the
     * linked `users` row (`customers.user_id` -> `users.email`), so the lookup
     * joins through `customers` and throws when either side is missing.
     */
    private function customerEmail(int $customerId): string
    {
        $email = DB::table('customers')
            ->join('users', 'users.id', '=', 'customers.user_id')
            ->where('customers.id', $customerId)
            ->value('users.email');

        if ($email === null || $email === '') {
            throw new RuntimeException(sprintf(
                'NotificationEmailSeeder: customer id %d has no linked user e-mail - run CustomerSeeder first.',
                $customerId
            ));
        }

        return (string) $email;
    }

    /**
     * Demo customer ids, ordered by their `client{N}@example.com` login.
     *
     * The login is the contract (CustomerSeeder::PROFILES is indexed 1..N);
     * the auto-increment id is not. Ordering by the numeric part of the local
     * part keeps `client10` after `client9` should the profile ever grow past
     * nine, which a plain lexical sort would get wrong.
     *
     * @return list<int>
     */
    private function demoCustomerIds(): array
    {
        return $this->customerIds ??= $this->orderedByClientNumber(
            DB::table('customers')
                ->join('users', 'users.id', '=', 'customers.user_id')
                ->where('users.email', 'like', 'client%@example.com')
                ->pluck('users.email', 'customers.id')
                ->all(),
            'customers'
        );
    }

    /**
     * Demo client user ids, ordered by the same `client{N}@example.com` login.
     *
     * @return list<int>
     */
    private function demoClientUserIds(): array
    {
        return $this->clientUserIds ??= $this->orderedByClientNumber(
            DB::table('users')
                ->where('email', 'like', 'client%@example.com')
                ->pluck('email', 'id')
                ->all(),
            'users'
        );
    }

    /**
     * Sort an [id => client{N}@example.com] map by N and return just the ids.
     *
     * @param  array<int|string, string>  $map
     * @return list<int>
     */
    private function orderedByClientNumber(array $map, string $table): array
    {
        if ($map === []) {
            throw new RuntimeException(sprintf(
                'NotificationEmailSeeder: no demo client rows in [%s] - run UserSeeder and CustomerSeeder first.',
                $table
            ));
        }

        uasort($map, static function (string $a, string $b): int {
            preg_match('/(\d+)/', $a, $ma);
            preg_match('/(\d+)/', $b, $mb);

            return ((int) ($ma[1] ?? 0)) <=> ((int) ($mb[1] ?? 0));
        });

        return array_map('intval', array_keys($map));
    }

    /**
     * Resolve a 0-based ordinal into a real `customers.id`.
     *
     * Wraps with modulo so a plan entry never falls off the end of a shorter
     * demo set (e.g. DummyDataConfig::CUSTOMERS reduced to 3).
     */
    private function customerId(int $ordinal): int
    {
        $ids = $this->demoCustomerIds();

        return $ids[$ordinal % count($ids)];
    }

    /** Resolve a 0-based ordinal into a real client `users.id`, wrapping. */
    private function clientUserId(int $ordinal): int
    {
        $ids = $this->demoClientUserIds();

        return $ids[$ordinal % count($ids)];
    }

    /**
     * The protected admin login's id, read from DummyDataConfig rather than
     * assumed to be 1 (auto-increment ids are not a contract).
     */
    private function adminUserId(): int
    {
        $protected = DummyDataConfig::PROTECTED_ROWS['users'] ?? [];

        if ($protected === []) {
            throw new RuntimeException('NotificationEmailSeeder: no protected admin row declared in DummyDataConfig.');
        }

        $id = DB::table('users')->where($protected)->value('id');

        if ($id === null) {
            throw new RuntimeException(sprintf(
                'NotificationEmailSeeder: admin user [%s] not found - run InitialDataSeeder first.',
                $protected['email'] ?? '?'
            ));
        }

        return (int) $id;
    }

    /**
     * Deterministic fixed timestamp (EPOCH + hours), identical on every run.
     */
    private function stamp(int $hoursFromEpoch): string
    {
        return Carbon::parse(self::EPOCH)->addHours($hoursFromEpoch)->toDateTimeString();
    }

    private function report(): void
    {
        $this->command?->info('NotificationEmailSeeder: e-mail, notification and billing-config demo data seeded.');

        foreach (self::REPORT_MIN as $table => $minimum) {
            $count = (int) DB::table($table)->count();

            $this->command?->info(sprintf(
                '  %-22s %3d  (min %d)  [%s]',
                $table,
                $count,
                $minimum,
                $count >= $minimum ? 'OK' : 'FAIL'
            ));
        }
    }
}
