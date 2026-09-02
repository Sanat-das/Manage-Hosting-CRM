<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

echo User::count().' users | '.Role::count().' roles | '.Permission::count()." perms\n";

$u = User::where('email', 'admin@localhost.com')->first();
echo 'Admin roles: '.$u->roles->pluck('name')->join(',')."\n";
echo 'Admin full_name: '.$u->full_name."\n";
echo 'Admin has dashboard.view: '.var_export($u->hasPermission('dashboard.view'), true)."\n";
echo 'getAuthPassword length: '.strlen($u->getAuthPassword())."\n";

// Test password check against hash
echo 'Password check Admin@123: '.var_export(Hash::check('Admin@123', $u->getAuthPassword()), true)."\n";
