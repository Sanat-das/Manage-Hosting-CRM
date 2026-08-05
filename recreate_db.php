<?php

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$name = config('database.connections.mysql.database');
DB::statement("DROP DATABASE IF EXISTS `{$name}`");
DB::statement("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "Database `{$name}` recreated.\n";
