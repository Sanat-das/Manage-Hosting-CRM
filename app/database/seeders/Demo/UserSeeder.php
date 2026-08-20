<?php

namespace Database\Seeders\Demo;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\Demo\Traits\WithIdempotentSeed;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class UserSeeder extends Seeder
{
    use WithIdempotentSeed;

    /** Deterministic timestamp anchor for token dates. Never `now()`. */
    private const EPOCH = '2026-07-01 00:00:00';

    public function run(): void
    {
        $this->preserveAdmin();
        $this->seedStaff();
        $this->seedClientUsers();
        $this->seedTestUsers();
        $this->attachRoles();
        $this->seedPasskey();
        $this->seedImpersonationTokens();
    }

    /**
     * Verify admin@localhost.com exists and leave it untouched.
     */
    private function preserveAdmin(): void
    {
        $admin = User::where('email', 'admin@localhost.com')->first();

        if (! $admin) {
            $this->command->warn('admin@localhost.com not found — InitialDataSeeder has not run.');
        } else {
            $this->command->info('admin@localhost.com present — preserved, hash unchanged.');
        }
    }

    /**
     * Create 1 staff user for each of support, sales, marketing.
     */
    private function seedStaff(): void
    {
        $staff = [
            ['email' => 'support@example.com', 'role' => 'support', 'first_name' => 'Sophia', 'last_name' => 'Support'],
            ['email' => 'sales@example.com', 'role' => 'sales', 'first_name' => 'Liam', 'last_name' => 'Sales'],
            ['email' => 'marketing@example.com', 'role' => 'marketing', 'first_name' => 'Olivia', 'last_name' => 'Marketing'],
        ];

        foreach ($staff as $user) {
            $this->seedRowOnce('users', array_merge($user, [
                'password_hash' => Hash::make('password'),
                'phone' => null,
                'company' => null,
                'address' => null,
                'status' => 'active',
            ]));
        }

        $this->command->info('Seeded 3 staff users (support/sales/marketing).');
    }

    /**
     * Confirm 5 client users exist — creates them if Task 4 has not run yet.
     */
    private function seedClientUsers(): void
    {
        for ($i = 1; $i <= DummyDataConfig::CUSTOMERS; $i++) {
            $this->seedRowOnce('users', [
                'email' => "client{$i}@example.com",
                'password_hash' => Hash::make('password'),
                'role' => 'client',
                'first_name' => "Client{$i}",
                'last_name' => 'User',
                'phone' => null,
                'company' => null,
                'address' => null,
                'status' => 'active',
            ]);
        }

        $this->command->info('Confirmed 5 client users (client1-5@example.com).');
    }

    /**
     * Create 2 additional test users.
     */
    private function seedTestUsers(): void
    {
        $testUsers = [
            ['email' => 'test1@example.com', 'role' => 'staff', 'first_name' => 'Test', 'last_name' => 'One'],
            ['email' => 'test2@example.com', 'role' => 'staff', 'first_name' => 'Test', 'last_name' => 'Two'],
        ];

        foreach ($testUsers as $user) {
            $this->seedRowOnce('users', array_merge($user, [
                'password_hash' => Hash::make('password'),
                'phone' => null,
                'company' => null,
                'address' => null,
                'status' => 'active',
            ]));
        }

        $this->command->info('Seeded 2 test users (test1/test2@example.com).');
    }

    /**
     * Attach roles to staff users via syncWithoutDetaching.
     */
    private function attachRoles(): void
    {
        $roleMap = [
            'support@example.com' => 'support',
            'sales@example.com' => 'sales',
            'marketing@example.com' => 'marketing',
        ];

        $roles = Role::whereIn('name', array_values($roleMap))
            ->get()
            ->keyBy('name');

        foreach ($roleMap as $email => $roleName) {
            $user = User::where('email', $email)->first();
            $role = $roles->get($roleName);

            if ($user && $role) {
                $user->roles()->syncWithoutDetaching($role);
                $this->command->info("Attached role '{$roleName}' to {$email}.");
            }
        }
    }

    /**
     * Seed one passkey per demo owner, if the passkeys table exists.
     *
     * Two rows (staff + client) rather than one: DummyDataConfig::ROWS
     * requires a minimum of 2, and a single row exercised only the staff
     * branch of the passkey UI. credential_id is UNIQUE and is the natural
     * key, so the literal per-owner ids keep re-runs idempotent.
     *
     * Owners are resolved by e-mail, never by id - see the id-portability note
     * in NotificationEmailSeeder.
     */
    private function seedPasskey(): void
    {
        if (! Schema::hasTable('passkeys')) {
            $this->command->info('passkeys table not present — skipping passkey seed.');

            return;
        }

        $owners = [
            'support@example.com' => ['Demo Staff Passkey', 'demo-passkey-cred-0000000000000001'],
            'client1@example.com' => ['Demo Client Passkey', 'demo-passkey-cred-0000000000000002'],
        ];

        $seeded = 0;

        foreach ($owners as $email => [$name, $credentialId]) {
            $user = User::where('email', $email)->first();

            if (! $user) {
                continue;
            }

            $this->seedRowOnce('passkeys', [
                'user_id' => $user->id,
                'name' => $name,
                'credential_id' => $credentialId,
                'credential' => json_encode(['public_key' => "demo-key-{$credentialId}"]),
                'last_used_at' => null,
            ]);

            $seeded++;
        }

        $this->command->info("Seeded {$seeded} passkey(s) for demo users.");
    }

    /**
     * Two demo "log in as customer" tokens: one live, one already consumed.
     *
     * Schema (2026_07_30_120060_create_audit_tables.php): admin_user_id and
     * customer_user_id are real FKs to `users`, `token` is UNIQUE (and is the
     * natural key), `expires_at` is NOT NULL, `used_at` is nullable and the
     * table has `created_at` ONLY - no updated_at. The trait detects that.
     *
     * `seedRowOnce` rather than `seedRow`: expires_at/used_at are anchored to
     * a fixed EPOCH so a re-run is byte-identical either way, but firstOrCreate
     * also guarantees a token an operator generated by hand is never rewritten.
     *
     * Both users are resolved by e-mail; a missing side skips the row instead
     * of writing a dangling FK that the database would reject anyway.
     */
    private function seedImpersonationTokens(): void
    {
        if (! Schema::hasTable('impersonation_tokens')) {
            $this->command->info('impersonation_tokens table not present — skipping.');

            return;
        }

        $admin = User::where('email', 'admin@localhost.com')->first();

        if (! $admin) {
            $this->command->warn('admin@localhost.com missing — skipping impersonation tokens.');

            return;
        }

        $epoch = Carbon::parse(self::EPOCH);

        $tokens = [
            ['client1@example.com', 'demo-impersonation-token-0000000000000001', 6, null],
            ['client2@example.com', 'demo-impersonation-token-0000000000000002', -18, -20],
        ];

        $seeded = 0;

        foreach ($tokens as [$email, $token, $expiryHours, $usedHours]) {
            $target = User::where('email', $email)->first();

            if (! $target) {
                continue;
            }

            $this->seedRowOnce('impersonation_tokens', [
                'admin_user_id' => $admin->id,
                'customer_user_id' => $target->id,
                'token' => $token,
                'expires_at' => $epoch->copy()->addHours($expiryHours)->toDateTimeString(),
                'used_at' => $usedHours === null ? null : $epoch->copy()->addHours($usedHours)->toDateTimeString(),
                'created_at' => $epoch->copy()->addHours($expiryHours - 24)->toDateTimeString(),
            ]);

            $seeded++;
        }

        $this->command->info("Seeded {$seeded} impersonation token(s).");
    }
}
