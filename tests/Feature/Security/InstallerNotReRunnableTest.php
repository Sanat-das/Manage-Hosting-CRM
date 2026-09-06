<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\Installer\InstallerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression guard: the unauthenticated installer must not be re-runnable on a
 * live application, and db_host must not be a DSN-injection / outbound-connect
 * primitive.
 *
 * The wizard rewrites .env (including DB_*), migrates, seeds, and creates an
 * administrator. Its only gate was is_file(base_path('install.lock')) — and
 * install.lock is gitignored, so any deploy that failed to preserve it (fresh
 * checkout, restore from backup, sync that skips ignored paths) reopened full
 * unauthenticated takeover on a site with real data in it.
 */
class InstallerNotReRunnableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        InstallerService::forgetProvisionedCache();
    }

    protected function tearDown(): void
    {
        InstallerService::forgetProvisionedCache();

        parent::tearDown();
    }

    /**
     * The core of the finding: a populated database counts as installed even
     * with no lock file present.
     */
    public function test_populated_database_counts_as_installed_without_a_lock_file(): void
    {
        User::factory()->create();

        InstallerService::forgetProvisionedCache();

        $this->assertTrue(InstallerService::databaseProvisioned());
        $this->assertTrue(InstallerService::isInstalled());
    }

    /**
     * The other half: a genuine first run must still reach the wizard, or this
     * fix would brick new installs.
     */
    public function test_empty_database_is_not_treated_as_installed(): void
    {
        $this->assertFalse(InstallerService::databaseProvisioned());
    }

    public function test_run_refuses_to_reinstall_over_a_live_application(): void
    {
        User::factory()->create();

        InstallerService::forgetProvisionedCache();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already installed');

        // Must throw before touching .env or the database. db_host is RFC 5737
        // TEST-NET-3 on purpose: if this guard ever regresses, run() proceeds to
        // verifyConnection(), and a reachable host would let it CREATE DATABASE
        // and then rewrite the developer's real .env. An unroutable address
        // makes the failure mode a connect timeout instead of data loss.
        app(InstallerService::class)->run([
            'app_name' => 'Pwned',
            'db_host' => '203.0.113.1',
            'db_port' => 3306,
            'db_database' => 'evil',
            'db_username' => 'root',
            'db_password' => '',
            'first_name' => 'Evil',
            'last_name' => 'Admin',
            'email' => 'attacker@example.test',
            'password' => 'Passw0rdXyz',
        ]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedHosts(): iterable
    {
        // DSN parameter injection — the DSN is a ';'-delimited key=value list.
        yield 'dsn injection via semicolon' => ['127.0.0.1;unix_socket=/tmp/evil.sock'];
        yield 'dsn injection via equals' => ['localhost;dbname=other'];
        yield 'backslash' => ['host\\evil'];
        yield 'forward slash' => ['evil.test/path'];
        yield 'whitespace' => ['127.0.0.1 evil'];
        yield 'quote' => ["127.0.0.1'"];
        // Cloud instance metadata.
        yield 'ipv4 link-local metadata' => ['169.254.169.254'];
        yield 'ipv6 link-local' => ['fe80::1'];
        yield 'empty' => [''];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedHosts(): iterable
    {
        yield 'loopback' => ['127.0.0.1'];
        yield 'localhost' => ['localhost'];
        yield 'fqdn' => ['db-01.internal.example.com'];
        yield 'ipv6 loopback' => ['::1'];
        yield 'bracketed ipv6' => ['[::1]'];
        // Private LAN database servers are the normal deployment and must keep
        // working — blocking them to stop a weak SSRF primitive is not a trade
        // worth making.
        yield 'private 10/8' => ['10.0.0.5'];
        yield 'private 192.168/16' => ['192.168.1.50'];
        yield 'private 172.16/12' => ['172.16.0.9'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedHosts')]
    public function test_dangerous_db_hosts_are_rejected(string $host): void
    {
        $this->assertFalse(
            InstallerService::isValidDatabaseHost($host),
            "Host should have been rejected: {$host}"
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('acceptedHosts')]
    public function test_legitimate_db_hosts_are_accepted(string $host): void
    {
        $this->assertTrue(
            InstallerService::isValidDatabaseHost($host),
            "Host should have been accepted: {$host}"
        );
    }

    /**
     * Structural guard against an infinite redirect.
     *
     * EnsureAppInstalled sends every request to /install when the app looks
     * uninstalled; RedirectIfInstalled sends /install back to / when it looks
     * installed. If the two ever consult DIFFERENT predicates — one the lock
     * file, the other the database — a site whose install.lock went missing
     * bounces between them forever.
     *
     * Asserted structurally rather than over HTTP: install.lock exists on a
     * working checkout, so reproducing the missing-lock path live would mean
     * deleting the real lock file and risking leaving the site in installer
     * mode if the run aborted.
     */
    public function test_both_install_middleware_share_one_predicate(): void
    {
        $middleware = [
            \App\Http\Middleware\EnsureAppInstalled::class,
            \App\Http\Middleware\RedirectIfInstalled::class,
        ];

        foreach ($middleware as $class) {
            $source = (string) file_get_contents(
                (new \ReflectionClass($class))->getFileName()
            );

            $this->assertStringContainsString(
                'InstallerService::isInstalled()',
                $source,
                "{$class} must gate on isInstalled()."
            );

            $this->assertStringNotContainsString(
                'InstallerService::lockExists()',
                $source,
                "{$class} must not gate on lockExists() alone — the two middleware would disagree and loop."
            );
        }
    }
}
