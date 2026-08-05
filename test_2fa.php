<?php
// End-to-end 2FA flow test: enable -> confirm -> logout -> login -> challenge -> dashboard
$base = 'http://127.0.0.1:8099';
$jar = __DIR__.'/cookies_2fa.txt';
@unlink($jar);

require __DIR__.'/vendor/autoload.php';
$g2fa = new PragmaRX\Google2FA\Google2FA();

$ch = curl_init();
$c = function () use ($ch, $jar) {
    return [
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
    ];
};

$get = function (string $url) use ($ch, $c) {
    curl_setopt_array($ch, $c() + [CURLOPT_URL => $url, CURLOPT_POST => false]);
    $resp = curl_exec($ch);
    return [curl_getinfo($ch, CURLINFO_HTTP_CODE), $resp];
};

$post = function (string $url, array $fields) use ($ch, $c) {
    curl_setopt_array($ch, $c() + [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_CUSTOMREQUEST => null, // reset any leftover method override (e.g. DELETE)
        CURLOPT_POSTFIELDS => http_build_query($fields),
    ]);
    $resp = curl_exec($ch);
    return [curl_getinfo($ch, CURLINFO_HTTP_CODE), $resp, curl_getinfo($ch, CURLINFO_REDIRECT_URL)];
};

$csrf = function (string $html): string {
    preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $m);
    return $m[1] ?? '';
};

// 1. GET login, extract CSRF
[$code, $html] = $get($base.'/login');
$token = $csrf($html);
echo "1. GET /login -> $code\n";

// 2. POST login (email-based, 2FA not yet enabled)
[$code, $html, $loc] = $post($base.'/login', ['_token' => $token, 'email' => 'admin@localhost.com', 'password' => 'Admin@123']);
echo "2. POST /login -> $code redirect=$loc\n";

// 3. GET dashboard (authenticated, for CSRF token)
[$code, $html] = $get($base.'/admin/dashboard');
$token = $csrf($html);
echo "3. GET /admin/dashboard -> $code\n";

// 3b. Confirm password (Fortify requires it before enabling 2FA when
//     twoFactorAuthentication.confirmPassword is enabled)
[$code, $html] = $post($base.'/user/confirm-password', ['_token' => $token, 'password' => 'Admin@123']);
echo "3b. POST /user/confirm-password -> $code\n";

// 4. Enable 2FA
[$code, $html] = $post($base.'/user/two-factor-authentication', ['_token' => $token]);
echo "4. POST /user/two-factor-authentication -> $code\n";

$getBody = function (string $url) use ($ch, $jar) {
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => false,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => false,
    ]);
    $resp = curl_exec($ch);
    return [curl_getinfo($ch, CURLINFO_HTTP_CODE), $resp];
};

// 5. Fetch secret key (body-only; JSON)
[$code, $body] = $getBody($base.'/user/two-factor-secret-key');
$secret = json_decode($body, true);
$secretKey = is_array($secret) ? ($secret['secretKey'] ?? '') : '';
echo "5. GET /user/two-factor-secret-key -> $code (len=".strlen((string) $secretKey).")\n";

// 6. Generate current TOTP code and confirm 2FA
$totp = $g2fa->getCurrentOtp($secretKey);
[$code, $html] = $post($base.'/user/confirmed-two-factor-authentication', ['_token' => $token, 'code' => $totp]);
echo "6. POST /user/confirmed-two-factor-authentication -> $code\n";

// 7. Logout
[$code, $html, $loc] = $post($base.'/logout', ['_token' => $token]);
echo "7. POST /logout -> $code redirect=$loc\n";

// 8. Re-login -> should redirect to two-factor-challenge
[$code, $html] = $get($base.'/login');
$token = $csrf($html);
[$code, $html, $loc] = $post($base.'/login', ['_token' => $token, 'email' => 'admin@localhost.com', 'password' => 'Admin@123']);
echo "8. POST /login (2FA enabled) -> $code redirect=$loc\n";

// 9. GET challenge page
[$code, $html] = $get($base.'/two-factor-challenge');
$token = $csrf($html);
echo "9. GET /two-factor-challenge -> $code\n";

// 10. Submit TOTP code.
// Fortify's provider rejects a code already used in the same 30s window
// (verifyKeyNewer + replay cache), and the confirm step used the same window's
// code. Wait until the window rolls over, then submit the fresh code.
$confirmCode = $totp; // from step 6
do {
    usleep(100_000);
    $totp = $g2fa->getCurrentOtp($secretKey);
} while ($totp === $confirmCode);
echo "10. waiting for new window; code now: $totp\n";
[$code, $html, $loc] = $post($base.'/two-factor-challenge', ['_token' => $token, 'code' => $totp]);
echo "10. POST /two-factor-challenge -> $code redirect=$loc\n";

// 10b. Follow the redirect back to the challenge page and look for error text
[$code, $html] = $get($base.'/two-factor-challenge');
$hasErr = str_contains($html, 'alert-danger') || str_contains($html, 'invalid-feedback');
echo "10b. GET /two-factor-challenge after failed post -> $code hasError=$hasErr\n";
preg_match('/alert-danger[^>]*>(.*?)<\/div>/s', $html, $m);
if (! empty($m[1])) {
    echo '10b. error text: '.trim(strip_tags($m[1]))."\n";
}

// 11. Dashboard accessible?
[$code, $html] = $get($base.'/admin/dashboard');
echo "11. GET /admin/dashboard after 2FA -> $code\n";
$token = $csrf($html); // fresh token (session regenerated after 2FA login)

// 12. Disable 2FA (cleanup so the seeded admin stays login-friendly for dev)
curl_setopt_array($ch, $c() + [
    CURLOPT_URL => $base.'/user/two-factor-authentication',
    CURLOPT_CUSTOMREQUEST => 'DELETE',
    CURLOPT_POSTFIELDS => http_build_query(['_token' => $token]),
]);
$resp = curl_exec($ch);
echo "12. DELETE /user/two-factor-authentication -> ".curl_getinfo($ch, CURLINFO_HTTP_CODE)."\n";

// 13. Verify login works again without 2FA challenge
[$code, $html] = $get($base.'/login');
$token = $csrf($html);
[$code, $html, $loc] = $post($base.'/login', ['_token' => $token, 'email' => 'admin@localhost.com', 'password' => 'Admin@123']);
echo "13. POST /login after 2FA disabled -> $code redirect=$loc\n";

curl_close($ch);
@unlink($jar);
