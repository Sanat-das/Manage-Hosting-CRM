<?php

namespace Tests\Feature\Security;

use App\Services\Installer\InstallerService;
use Tests\TestCase;

/**
 * Regression guard: completing the installer must leave the app in a production
 * environment, not the developer defaults it was seeded with.
 *
 * bootstrap/app.php copies .env.example -> .env on first boot and patches only
 * APP_KEY. .env.example ships APP_ENV=local and APP_DEBUG=true, and the
 * installer previously wrote only DB_* keys — so a finished production install
 * stayed in debug mode, turning any unhandled exception into a stack trace that
 * discloses DB_PASSWORD and APP_KEY.
 */
class InstallerHardensEnvironmentTest extends TestCase
{
    private string $tempEnv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempEnv = storage_path('framework/testing/installer-env-'.uniqid().'.env');

        @mkdir(dirname($this->tempEnv), 0755, true);

        // The developer defaults the installer has to overwrite.
        file_put_contents($this->tempEnv, implode("\n", [
            'APP_NAME=Laravel',
            'APP_ENV=local',
            'APP_KEY=base64:abc',
            'APP_DEBUG=true',
            'DB_CONNECTION=sqlite',
            // .env.example ships an empty DB_PASSWORD, so setEnvValue always
            // takes its replace branch for it -- which is the branch that used
            // to mangle the value. Without this line the fixture would only
            // ever exercise the (safe) append branch.
            'DB_PASSWORD=',
        ])."\n");
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempEnv)) {
            @unlink($this->tempEnv);
        }

        parent::tearDown();
    }

    private function serviceWritingTo(string $path): InstallerService
    {
        // Anonymous subclass so the real project .env is never touched.
        return new class($path) extends InstallerService
        {
            public function __construct(private readonly string $target) {}

            public function envPath(): string
            {
                return $this->target;
            }
        };
    }

    public function test_env_values_are_written_unquoted_and_replace_existing_lines(): void
    {
        $service = $this->serviceWritingTo($this->tempEnv);

        $service->setEnvValue('APP_ENV', 'production');
        $service->setEnvValue('APP_DEBUG', 'false');

        $contents = (string) file_get_contents($this->tempEnv);

        // Unquoted, or the framework reads the string "false" (truthy) rather
        // than a boolean false.
        $this->assertStringContainsString("APP_ENV=production\n", $contents);
        $this->assertStringContainsString("APP_DEBUG=false\n", $contents);

        $this->assertStringNotContainsString('APP_ENV=local', $contents);
        $this->assertStringNotContainsString('APP_DEBUG=true', $contents);

        // Replaced in place, not appended alongside the originals.
        $this->assertSame(1, substr_count($contents, 'APP_ENV='));
        $this->assertSame(1, substr_count($contents, 'APP_DEBUG='));
    }

    /**
     * Every value the installer writes must survive the round trip through .env.
     *
     * Two ways it did not:
     *
     * - preg_replace() reads $1 / ${1} / \1 in the replacement as backreferences.
     *   A real install with DB_PASSWORD='$6btNmZR5sPb_abW' wrote
     *   DB_PASSWORD=btNmZR5sPb_abW. The wizard still reported success, because
     *   verifyConnection() and applyDatabaseConfig() use the submitted value --
     *   only later requests read .env, and every one of them died on
     *   "1045 Access denied ... (using password: YES)".
     *
     * - '#' was escaped to '\#', which then tripped the backslash branch of the
     *   quoting check and was escaped a second time.
     *
     * Asserted against the real parser, not a regex, so the check is what the
     * booted application actually sees.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('awkwardEnvValues')]
    public function test_awkward_values_round_trip_through_the_env_file(string $value): void
    {
        $service = $this->serviceWritingTo($this->tempEnv);

        $service->setEnvValue('DB_PASSWORD', $value);

        $parsed = \Dotenv\Dotenv::createArrayBacked(
            dirname($this->tempEnv),
            basename($this->tempEnv)
        )->load();

        $this->assertSame(
            $value,
            $parsed['DB_PASSWORD'] ?? null,
            'Value written to .env did not read back unchanged.'
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function awkwardEnvValues(): array
    {
        return [
            'dollar then digit (the live outage)' => ['$6btNmZR5sPb_abW'],
            'dollar then digit, short' => ['$6abc'],
            'dollar then letter' => ['$abc'],
            'braced backreference' => ['${1}xyz'],
            'dollar mid-string' => ['ab$6cd'],
            'backslash then digit' => ['a\1b'],
            'hash' => ['Str0ng#Pass!'],
            'hash and dollar' => ['$6a#b'],
            'space' => ['has space'],
            'double quote' => ['has"quote'],
            'backslash' => ['back\slash'],
            'plain' => ['plainPass123'],
        ];
    }

    /**
     * Source-level policy guard. run() performs live DB work (connect, migrate,
     * seed, create admin) so it cannot be executed in a unit test, but the two
     * hardening calls must not be quietly removed from it.
     */
    public function test_installer_run_sets_production_env_and_disables_debug(): void
    {
        $source = (string) file_get_contents(
            (new \ReflectionClass(InstallerService::class))->getFileName()
        );

        $run = (new \ReflectionMethod(InstallerService::class, 'run'));
        $body = implode("\n", array_slice(
            explode("\n", $source),
            $run->getStartLine() - 1,
            $run->getEndLine() - $run->getStartLine() + 1
        ));

        $this->assertMatchesRegularExpression(
            "/setEnvValue\(\s*'APP_ENV'\s*,\s*'production'\s*\)/",
            $body,
            'InstallerService::run() must set APP_ENV=production.'
        );

        $this->assertMatchesRegularExpression(
            "/setEnvValue\(\s*'APP_DEBUG'\s*,\s*'false'\s*\)/",
            $body,
            'InstallerService::run() must set APP_DEBUG=false.'
        );

        $this->assertMatchesRegularExpression(
            "/setEnvValue\(\s*'SESSION_SECURE_COOKIE',\s*request\(\)->isSecure\(\)/",
            $body,
            'InstallerService::run() must set SESSION_SECURE_COOKIE from the request scheme.'
        );
    }

    /**
     * The pre-install environment must not ship a Secure session cookie.
     *
     * bootstrap/app.php copies .env.example -> .env before the app boots, so
     * this file IS the environment the installer wizard runs under. Over plain
     * http a Secure cookie is discarded by the browser, so the session is lost
     * between rendering the form and submitting it and every POST /install
     * fails CSRF with a 419 — the wizard becomes impossible to complete.
     * run() raises the flag again when the install is done over https.
     */
    public function test_env_example_does_not_ship_a_secure_session_cookie(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString(
            "SESSION_SECURE_COOKIE=false\n",
            $example,
            '.env.example must ship SESSION_SECURE_COOKIE=false or the installer 419s over http.'
        );

        $this->assertStringNotContainsString('SESSION_SECURE_COOKIE=true', $example);
    }
}
