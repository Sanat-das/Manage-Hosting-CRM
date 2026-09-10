<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\System\AppInfoService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * How the About card decides what version to display.
 *
 * VERSION is a tracked file, so on a git checkout it holds whatever placeholder
 * was committed ("1.0.0") and never the commit actually deployed — the updater
 * refuses to write it there because a dirty tracked file wedges the updater
 * shut. The card used to show that placeholder, because config('app.version')
 * was itself derived from VERSION and short-circuited the git lookup below it.
 *
 * These run the real service against a relocated base path rather than a stub,
 * so the resolution order is exercised end to end.
 */
final class AppInfoVersionResolutionTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    private string $originalBasePath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalBasePath = $this->app->basePath();
    }

    protected function tearDown(): void
    {
        // Restored before the parent teardown, which still needs real paths.
        if ($this->originalBasePath !== '') {
            $this->app->setBasePath($this->originalBasePath);
        }

        foreach ($this->tempDirs as $dir) {
            $this->deleteTree($dir);
        }

        $this->tempDirs = [];

        parent::tearDown();
    }

    private function tempRoot(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mh_appinfo_' . bin2hex(random_bytes(5));
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach ((array) glob($dir . DIRECTORY_SEPARATOR . '*') as $item) {
            is_dir($item) ? $this->deleteTree($item) : @unlink($item);
        }

        @rmdir($dir);
    }

    private function version(): string
    {
        return (new AppInfoService)->version();
    }

    // ------------------------------------------------------------------
    // Git checkout
    // ------------------------------------------------------------------

    public function test_a_git_checkout_reports_the_commit_not_the_committed_version_file(): void
    {
        if (! is_dir(base_path('.git'))) {
            $this->markTestSkipped('Not running inside a git checkout.');
        }

        $committed = is_file(base_path('VERSION'))
            ? trim((string) file_get_contents(base_path('VERSION')))
            : null;

        $describe = new Process(['git', 'describe', '--tags', '--always'], base_path());
        $describe->run();

        if (! $describe->isSuccessful() || trim($describe->getOutput()) === '') {
            $this->markTestSkipped('git describe is unavailable here.');
        }

        $expected = trim($describe->getOutput());

        $this->assertSame($expected, $this->version());

        // The actual regression: "1.0.0" is what the card used to show.
        if ($committed !== null && $committed !== '' && strtolower($committed) !== 'dev') {
            $this->assertNotSame(
                $committed,
                $this->version(),
                'A git checkout must report its commit, not the placeholder committed in VERSION.'
            );
        }
    }

    // ------------------------------------------------------------------
    // ZIP install
    // ------------------------------------------------------------------

    public function test_a_zip_install_reports_the_version_marker(): void
    {
        $root = $this->tempRoot();
        file_put_contents($root . DIRECTORY_SEPARATOR . 'VERSION', 'abc1234');

        $this->app->setBasePath($root);

        // No .git, so no subprocess is spawned and the marker the updater wrote
        // after its last successful run is authoritative.
        $this->assertSame('abc1234', $this->version());
    }

    public function test_a_zip_install_without_a_marker_reports_dev(): void
    {
        $this->app->setBasePath($this->tempRoot());

        $this->assertSame('dev', $this->version());
    }

    public function test_a_marker_containing_dev_is_not_treated_as_a_version(): void
    {
        $root = $this->tempRoot();
        file_put_contents($root . DIRECTORY_SEPARATOR . 'VERSION', "dev\n");

        $this->app->setBasePath($root);

        $this->assertSame('dev', $this->version());
    }

    public function test_a_checkout_whose_git_is_unusable_falls_back_to_the_marker(): void
    {
        $root = $this->tempRoot();

        // A .git file (the worktree/submodule form) with nonsense in it: the
        // directory looks like a checkout, but every git command will fail.
        file_put_contents($root . DIRECTORY_SEPARATOR . '.git', 'not a real gitfile');
        file_put_contents($root . DIRECTORY_SEPARATOR . 'VERSION', 'abc1234');

        $this->app->setBasePath($root);

        $this->assertSame('abc1234', $this->version());
    }

    // ------------------------------------------------------------------
    // Explicit override
    // ------------------------------------------------------------------

    public function test_an_explicit_override_wins_over_everything(): void
    {
        config(['app.version' => '2.5.0']);

        $this->assertSame('2.5.0', $this->version());
    }

    public function test_an_override_of_dev_is_ignored(): void
    {
        $root = $this->tempRoot();
        file_put_contents($root . DIRECTORY_SEPARATOR . 'VERSION', 'abc1234');

        $this->app->setBasePath($root);
        config(['app.version' => 'dev']);

        $this->assertSame('abc1234', $this->version());
    }

    public function test_the_override_is_no_longer_derived_from_the_version_file(): void
    {
        // config/app.php used to read VERSION, which made it the first thing
        // version() found and left the git branches unreachable.
        $this->assertNotSame(
            is_file(base_path('VERSION')) ? trim((string) file_get_contents(base_path('VERSION'))) : null,
            config('app.version'),
            'config(app.version) must be an operator override, not a copy of the VERSION file.'
        );
    }
}
