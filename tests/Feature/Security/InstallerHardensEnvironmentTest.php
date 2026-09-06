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
    }
}
