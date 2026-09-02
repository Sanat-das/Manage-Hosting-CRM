<?php

// Pin the application base path to the checkout that owns this suite.
//
// Worktrees often share one composer vendor directory through an NTFS
// junction. PHP resolves the junction to its real target, so Laravel's
// Application::inferBasePath() would derive the base path from the vendor
// location and silently boot a DIFFERENT checkout's application while
// running this checkout's tests. Declaring APP_BASE_PATH before the
// autoloader loads makes the base path follow the tests, junction or not.
if (! isset($_ENV['APP_BASE_PATH'], $_SERVER['APP_BASE_PATH'])) {
    $_ENV['APP_BASE_PATH'] = $_SERVER['APP_BASE_PATH'] = dirname(__DIR__);
}

require __DIR__.'/../vendor/autoload.php';
