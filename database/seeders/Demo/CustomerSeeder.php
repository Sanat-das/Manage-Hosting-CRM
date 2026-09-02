<?php

namespace Database\Seeders\Demo;

use Database\Seeders\Demo\Traits\WithIdempotentSeed;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Demo customers module.
 *
 * Seeds `DummyDataConfig::CUSTOMERS` demo customers, each backed by its own
 * `users` login row (role `client`, password `password`), plus the CRM
 * satellites that hang off a customer: contacts, notes, wallet movements and
 * credits.
 *
 * IDEMPOTENCY
 * -----------
 * Every write goes through `WithIdempotentSeed`, which matches on the natural
 * key declared in `DummyDataConfig::NATURAL_KEYS`
 * (`users.email`, `customers.user_id`, ...). Re-running adds zero rows.
 *
 * PROTECTED DATA
 * --------------
 * `admin@localhost.com` belongs to `InitialDataSeeder` and is never written
 * to here - it is only read, so demo notes can be attributed to a staff user.
 *
 * ENUMS (read from the migrations, never guessed)
 * -----------------------------------------------
 * - users.role           admin|staff|client|support|sales|marketing
 * - users.status         active|inactive|suspended
 * - customers.status     active|inactive|suspended
 * - customer_contacts.status  active|inactive
 * - customer_wallet.type      deposit|credit|debit|invoice_payment
 * - customer_wallet.balance_type account|credit
 * - credits.type              added|used|expired|refund
 */
class CustomerSeeder extends Seeder
{
    use WithIdempotentSeed;

    /** Deterministic anchor for billing-cycle dates. Never `now()`. */
    private const EPOCH = '2026-07-01 00:00:00';

    /**
     * Demo customer profiles. Fictional names, `@example.com` mailboxes and
     * `.test` companies only - no real people, domains or addresses.
     *
     * @var list<array<string, string>>
     */
    private const PROFILES = [
        ['first' => 'Asha', 'last' => 'Verma', 'company' => 'Northwind Labs', 'domain' => 'northwind.test', 'city' => 'Pune', 'state_code' => '27'],
        ['first' => 'Brian', 'last' => 'Okafor', 'company' => 'Blue Harbor Media', 'domain' => 'blueharbor.test', 'city' => 'Lagos', 'state_code' => '29'],
        ['first' => 'Chen', 'last' => 'Liu', 'company' => 'Cedar Point Retail', 'domain' => 'cedarpoint.test', 'city' => 'Singapore', 'state_code' => '09'],
        ['first' => 'Dana', 'last' => 'Kowalski', 'company' => 'Driftwood Studios', 'domain' => 'driftwood.test', 'city' => 'Krakow', 'state_code' => '36'],
        ['first' => 'Emeka', 'last' => 'Santos', 'company' => 'Everline Freight', 'domain' => 'everline.test', 'city' => 'Lisbon', 'state_code' => '19'],
    ];

    public function run(): void
    {
        $staffUserId = $this->staffAuthorId();

        foreach (self::PROFILES as $index => $profile) {
            $n = $index + 1;

            $userId = $this->seedClientUser($n, $profile);
            $customerId = $this->seedCustomer($userId, $n, $profile);

            $this->seedContacts($customerId, $n, $profile);
            $this->seedNotes($customerId, $staffUserId ?? $userId, $n, $profile);
            $this->seedWallet($customerId, $n);
            $this->seedCredits($customerId, $n);
            $this->seedBillingCycles($customerId, $n);
            $this->seedMarketingConsent($customerId, $n);
        }
    }

    /**
     * The user demo notes are attributed to: the existing admin when present
     * (read-only lookup), otherwise the caller falls back to the client user.
     */
    private function staffAuthorId(): ?int
    {
        $protected = DummyDataConfig::PROTECTED_ROWS['users'] ?? [];

        if ($protected === []) {
            return null;
        }

        $id = DB::table('users')->where($protected)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Guard against a demo login ever shadowing a row `DummyDataConfig` marks
     * as owned by another seeder (currently the InitialDataSeeder admin).
     */
    private function isProtectedUser(string $email): bool
    {
        $protected = DummyDataConfig::PROTECTED_ROWS['users'] ?? [];

        return ($protected['email'] ?? null) === $email;
    }

    /**
     * The `client` login backing a demo customer.
     *
     * `seedRowOnce` (firstOrCreate semantics) rather than `seedRow`, so a
     * re-run leaves an existing login - and its password hash - untouched.
     * Bcrypt produces a fresh salt every call, so an update would rewrite the
     * hash on every run and invalidate any session-independent expectations.
     *
     * @param  array<string, string>  $profile
     */
    private function seedClientUser(int $n, array $profile): int
    {
        $email = "client{$n}@example.com";

        if ($this->isProtectedUser($email)) {
            throw new \LogicException("Demo login {$email} collides with a protected row.");
        }

        return (int) $this->seedRowOnce('users', [
            'email' => $email,
            'password_hash' => Hash::make('password'),
            'role' => 'client',
            'first_name' => $profile['first'],
            'last_name' => $profile['last'],
            'phone' => sprintf('+1-555-01%02d', $n),
            'company' => $profile['company'],
            'address' => sprintf('%d Demo Street, %s', 100 + $n, $profile['city']),
            'status' => 'active',
        ]);
    }

    /** @param array<string, string> $profile */
    private function seedCustomer(int $userId, int $n, array $profile): int
    {
        return (int) $this->seedRow('customers', [
            'user_id' => $userId,
            'company' => $profile['company'],
            'tax_id' => sprintf('DEMO-TAX-%04d', $n),
            'state_code' => $profile['state_code'],
            'balance' => 100.00 * $n,
            'credit' => 25.00 * $n,
            'status' => 'active',
        ]);
    }

    /** @param array<string, string> $profile */
    private function seedContacts(int $customerId, int $n, array $profile): void
    {
        $this->seedRows('customer_contacts', [
            [
                'customer_id' => $customerId,
                'first_name' => $profile['first'],
                'last_name' => $profile['last'],
                'email' => "client{$n}@example.com",
                'phone' => sprintf('+1-555-01%02d', $n),
                'role' => 'Owner',
                'is_primary' => true,
                'status' => 'active',
            ],
            [
                'customer_id' => $customerId,
                'first_name' => 'Billing',
                'last_name' => $profile['last'],
                'email' => "billing{$n}@example.com",
                'phone' => sprintf('+1-555-02%02d', $n),
                'role' => 'Billing',
                'is_primary' => false,
                'status' => 'active',
            ],
        ]);
    }

    private function seedNotes(int $customerId, int $authorId, int $n, array $profile): void
    {
        $notes = [
            ["Welcome call completed for {$profile['company']}. Demo account {$n}.", false],
            ["Prefers email contact at client{$n}@example.com; billing queries go to billing{$n}@example.com.", false],
            ["Renewal review scheduled for {$profile['domain']}.", true],
        ];

        $rows = [];

        foreach ($notes as [$note, $important]) {
            $rows[] = [
                'customer_id' => $customerId,
                'user_id' => $authorId,
                'note' => $note,
                'is_important' => $important,
            ];
        }

        $this->seedRows('customer_notes', $rows);
    }

    private function seedWallet(int $customerId, int $n): void
    {
        $this->seedRows('customer_wallet', [
            [
                'customer_id' => $customerId,
                'type' => 'deposit',
                'amount' => 250.00 * $n,
                'balance_type' => 'account',
                'description' => "Opening demo deposit for customer {$n}",
            ],
            [
                'customer_id' => $customerId,
                'type' => 'invoice_payment',
                'amount' => 75.00 * $n,
                'balance_type' => 'account',
                'description' => "Demo invoice settlement for customer {$n}",
            ],
        ]);
    }

    private function seedCredits(int $customerId, int $n): void
    {
        $rows = [
            [
                'customer_id' => $customerId,
                'amount' => 25.00 * $n,
                'type' => 'added',
                'description' => "Goodwill credit issued to demo customer {$n}",
            ],
        ];

        if ($n % 2 === 1) {
            $rows[] = [
                'customer_id' => $customerId,
                'amount' => 10.00 * $n,
                'type' => 'used',
                'description' => "Credit applied to a demo invoice for customer {$n}",
            ];
        }

        $this->seedRows('credits', $rows);
    }

    /**
     * Two closed monthly billing cycles per customer (10 total).
     *
     * Schema (2026_07_31_000001_add_billing_columns.php): customer_id is a real
     * FK, cycle_start/cycle_end are DATEs, total_amount/paid_amount decimal(12,2),
     * status ENUM(pending|partial|paid|cancelled).
     *
     * Dates are anchored to a fixed EPOCH month rather than `now()`, because
     * cycle_start/cycle_end are BOTH part of the natural key
     * (customer_id, cycle_start, cycle_end) - a calendar-relative date would
     * mint a brand-new key every month and the table would grow without bound.
     *
     * paid_amount is kept coherent with status: paid => equal to total,
     * partial => strictly between 0 and total. Nothing in the schema enforces
     * that pairing, so the seeder must.
     */
    private function seedBillingCycles(int $customerId, int $n): void
    {
        $epoch = Carbon::parse(self::EPOCH)->startOfMonth();
        $rows = [];

        foreach ([0, 1] as $offset) {
            $start = $epoch->copy()->subMonths(2 - $offset);
            $total = round(150.00 * $n + 25.00 * $offset, 2);

            // Older cycle settled in full; the recent one only part-paid on
            // odd-numbered customers, so both enum branches are exercised.
            $paidInFull = $offset === 0 || $n % 2 === 0;

            $rows[] = [
                'customer_id' => $customerId,
                'cycle_start' => $start->toDateString(),
                'cycle_end' => $start->copy()->endOfMonth()->toDateString(),
                'total_amount' => $total,
                'paid_amount' => $paidInFull ? $total : round($total * 0.4, 2),
                'status' => $paidInFull ? 'paid' : 'partial',
            ];
        }

        $this->seedRows('billing_cycles', $rows);
    }

    /**
     * Two marketing-consent entries per customer (10 total), one per channel.
     *
     * Schema (2026_08_13_000003_create_marketing_consent_log_table.php):
     * customer_id FK, contact_type string, consent_status ENUM(opt_in|opt_out),
     * source/ip_address/user_agent nullable strings, timestamps.
     *
     * The natural key is (customer_id, contact_type), so each customer gets one
     * row per channel and re-runs converge. `seedRow` rather than `seedRowOnce`:
     * the app's own consent endpoint updates these rows in place
     * (see PortedTablesTest), and the demo state should be the declared state.
     *
     * ip_address/user_agent are documented demo literals, never real client
     * data - this table records consent evidence and must not carry anything
     * that looks like a genuine visitor fingerprint.
     */
    private function seedMarketingConsent(int $customerId, int $n): void
    {
        // Every other customer opts out of e-mail so both enum branches and
        // the "opted out of one channel but not the other" case are covered.
        $emailOptIn = $n % 2 === 1;

        $this->seedRows('marketing_consent_log', [
            [
                'customer_id' => $customerId,
                'contact_type' => 'marketing_email',
                'consent_status' => $emailOptIn ? 'opt_in' : 'opt_out',
                'source' => 'seeder',
                'ip_address' => '203.0.113.'.(10 + $n),
                'user_agent' => 'DemoSeeder/1.0 (marketing consent demo record)',
            ],
            [
                'customer_id' => $customerId,
                'contact_type' => 'marketing_sms',
                'consent_status' => 'opt_out',
                'source' => 'seeder',
                'ip_address' => '203.0.113.'.(10 + $n),
                'user_agent' => 'DemoSeeder/1.0 (marketing consent demo record)',
            ],
        ]);
    }
}
