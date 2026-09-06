<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Permission;
use App\Models\Role;
$perms = Permission::count();
$roles = Role::count();
$admin = Role::where('name','admin')->first()->permissions()->count();
echo "perms=$perms roles=$roles admin=$admin";
