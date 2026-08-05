<?php

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo App\Models\User::count().' users | '.App\Models\Role::count().' roles | '.App\Models\Permission::count()." perms\n";

$u = App\Models\User::where('email', 'admin@localhost.com')->first();
echo 'Admin roles: '.$u->roles->pluck('name')->join(',')."\n";
echo 'Admin full_name: '.$u->full_name."\n";
echo 'Admin has dashboard.view: '.var_export($u->hasPermission('dashboard.view'), true)."\n";
echo 'getAuthPassword length: '.strlen($u->getAuthPassword())."\n";

// Test password check against hash
echo 'Password check Admin@123: '.var_export(Illuminate\Support\Facades\Hash::check('Admin@123', $u->getAuthPassword()), true)."\n";
