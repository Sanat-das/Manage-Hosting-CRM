<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * Shared fixture plumbing for the module-system feature tests.
 *
 * Points the module manager at the fixture modules directory and keeps that
 * directory in a known-good state: the uninstall tests delete module folders
 * (a real side effect that RefreshDatabase cannot roll back), so any folder
 * that went missing is restored from the pristine copy before each test.
 */
trait InteractsWithModuleFixtures
{
    /**
     * Call from setUp() AFTER parent::setUp().
     */
    protected function setUpModuleFixtures(): void
    {
        config(['modules.path' => base_path('tests/Fixtures/modules')]);

        $this->restoreModuleFixtureFolders();
    }

    /**
     * Copy every fixture module folder from tests/Fixtures/modules-pristine/
     * into tests/Fixtures/modules/ when it is missing.
     */
    protected function restoreModuleFixtureFolders(): void
    {
        $pristine = base_path('tests/Fixtures/modules-pristine');
        $target = base_path('tests/Fixtures/modules');

        if (! is_dir($pristine)) {
            throw new RuntimeException("Module fixture pristine copy missing at [{$pristine}].");
        }

        if (! is_dir($target)) {
            mkdir($target, 0755, true);
        }

        $fs = new Filesystem();

        foreach (glob($pristine.'/*') ?: [] as $entry) {
            if (! is_dir($entry)) {
                continue;
            }

            $name = basename($entry);

            if (! is_dir($target.'/'.$name)) {
                $fs->copyDirectory($entry, $target.'/'.$name);
            }
        }
    }
}
