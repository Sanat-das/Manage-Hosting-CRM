<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
if (! is_file(__DIR__.'/../vendor/autoload.php')) {
    // vendor/ is gitignored, so a fresh clone hits this instead of a raw PHP fatal.
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');

    echo "Composer dependencies are not installed.\n\n";
    echo "From the application root, run:\n\n";
    echo "    composer install\n\n";
    echo "For the full install (.env, app key, migrations, npm build, storage:link):\n\n";
    echo "    composer run setup\n\n";
    echo "For the front-end assets:\n\n";
    echo "    npm ci && npm run build\n";

    exit(1);
}

require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
