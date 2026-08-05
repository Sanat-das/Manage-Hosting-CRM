<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;

$u = User::where('email', 'admin@localhost.com')->first();
$u->forceFill([
    'two_factor_secret' => null,
    'two_factor_recovery_codes' => null,
    'two_factor_confirmed_at' => null,
])->save();
echo '2FA reset for '.($u->full_name ?? $u->email).PHP_EOL;
