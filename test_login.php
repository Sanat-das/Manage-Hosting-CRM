<?php
// Full login flow test: GET /login (CSRF + cookies) -> POST /login -> follow redirect -> assert dashboard
$base = 'http://127.0.0.1:8099';

$ch = curl_init();
$cookieJar = __DIR__.'/cookies.txt';
@unlink($cookieJar);

// 1. GET login page
curl_setopt_array($ch, [
    CURLOPT_URL => $base.'/login',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookieJar,
    CURLOPT_COOKIEFILE => $cookieJar,
]);
$html = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "GET /login -> HTTP $httpCode\n";

// Extract CSRF token
if (!preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $m)) {
    // fallback: hidden input
    preg_match('/name="_token" value="([^"]+)"/', $html, $m2);
    $token = $m2[1] ?? null;
} else {
    $token = $m[1];
}
echo 'CSRF token: '.($token ? substr($token, 0, 12).'...' : 'NOT FOUND')."\n";
if (!$token) { exit(1); }

// 2. POST login
curl_setopt_array($ch, [
    CURLOPT_URL => $base.'/login',
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        '_token' => $token,
        'email' => 'admin@localhost.com',
        'password' => 'Admin@123',
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HEADER => true,
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$redirect = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
echo "POST /login -> HTTP $httpCode, redirect: $redirect\n";

// 3. Follow to dashboard
if ($redirect) {
    curl_setopt_array($ch, [
        CURLOPT_URL => $base.$redirect,
        CURLOPT_POST => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => false,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $dash = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "GET $redirect -> HTTP $httpCode\n";
    echo 'Dashboard content check (welcome): '.(str_contains($dash, 'Welcome back') ? 'PASS' : 'CHECK')."\n";
    echo 'Dashboard content check (sidebar): '.(str_contains($dash, 'sidebar') || str_contains($dash, 'Dashboard') ? 'PASS' : 'CHECK')."\n";
}

curl_close($ch);
@unlink($cookieJar);
