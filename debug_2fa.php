<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

$user = User::where('email', 'admin@localhost.com')->first();

// 1. Direct: what does the raw attribute look like?
echo 'raw two_factor_secret len: '.strlen((string) $user->getRawOriginal('two_factor_secret')).PHP_EOL;
echo 'encrypted? starts with base64/eyJ: '.(str_starts_with((string) $user->getRawOriginal('two_factor_secret'), 'eyJ') ? 'yes' : 'no').PHP_EOL;

// 2. Enable 2FA through the action (same path as the controller)
$provider = app(TwoFactorAuthenticationProvider::class);
$enable = new EnableTwoFactorAuthentication($provider);
$enable($user, force: true);

$user->refresh();
$secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
echo 'decrypted secret: '.$secret.' (len='.strlen($secret).')'.PHP_EOL;

// 3. Generate a TOTP code with google2fa directly
$g2fa = new Google2FA;
$code = $g2fa->getCurrentOtp($secret);
echo 'generated code: '.$code.PHP_EOL;

// 4. Verify through Fortify's provider (what the challenge uses)
$verified = $provider->verify($secret, $code);
echo 'provider->verify(generated code): '.($verified ? 'TRUE' : 'FALSE').PHP_EOL;

// 5. Verify with google2fa directly
$direct = $g2fa->verifyKey($secret, $code);
echo 'google2fa->verifyKey: '.($direct ? 'TRUE' : 'FALSE').PHP_EOL;

// 6. What window does the provider use?
$ref = new ReflectionClass($provider);
foreach ($ref->getProperties() as $prop) {
    if ($prop->getName() !== 'google2fa') {
        echo 'provider prop '.$prop->getName().' = '.json_encode($prop->getValue($provider)).PHP_EOL;
    }
}

// 7. Clean up: disable 2FA again
$disable = new DisableTwoFactorAuthentication;
$disable($user);
$user->refresh();
echo '2FA disabled, secret now: '.var_export($user->two_factor_secret, true).PHP_EOL;
