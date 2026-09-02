<?php

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

foreach (DB::select('SHOW TABLES') as $table) {
    $values = array_values((array) $table);
    echo $values[0], PHP_EOL;
}
