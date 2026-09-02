<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

$u = User::where('email', 'client@demo.com')->first();
if (! $u) {
    $u = User::create([
        'first_name' => 'Demo',
        'last_name' => 'Client',
        'email' => 'client@demo.com',
        'password_hash' => bcrypt('Client@123'),
        'role' => 'client',
        'status' => 'active',
    ]);
    echo "created id={$u->id}\n";
} else {
    echo "exists id={$u->id}\n";
}
echo 'role='.$u->role.' status='.$u->status."\n";
