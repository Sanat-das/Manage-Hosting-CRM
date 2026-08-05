<?php
// Dashboard access test: login then GET /admin/dashboard
$base = 'http://127.0.0.1:8099';

$ch = curl_init();
$cookieJar = __DIR__.'/cookies2.txt';
@unlink($cookieJar);

// GET login page
curl_setopt_array($ch, [
    CURLOPT_URL => $base.'/login',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookieJar,
    CURLOPT_COOKIEFILE => $cookieJar,
]);
$html = curl_exec($ch);
preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $m);
$token = $m[1] ?? null;
if (!$token) { echo "NO CSRF TOKEN\n"; exit(1); }

// POST login
curl_setopt_array($ch, [
    CURLOPT_URL => $base.'/login',
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['_token' => $token, 'email' => 'admin@localhost.com', 'password' => 'Admin@123']),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HEADER => true,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "POST /login -> $code\n";

// GET dashboard with the session cookie
curl_setopt_array($ch, [
    CURLOPT_URL => $base.'/admin/dashboard',
    CURLOPT_POST => false,
    CURLOPT_HEADER => false,
    CURLOPT_RETURNTRANSFER => true,
]);
$dash = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
echo "GET /admin/dashboard -> $code err=[$err]\n";
echo 'Has "Welcome back": '.(str_contains($dash, 'Welcome back') ? 'YES' : 'NO')."\n";
echo 'Has sidebar: '.(str_contains($dash, 'sidebar') ? 'YES' : 'NO')."\n";

// Try client dashboard (should be 403 for admin)
curl_setopt_array($ch, [
    CURLOPT_URL => $base.'/client/dashboard',
    CURLOPT_RETURNTRANSFER => true,
]);
$cd = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "GET /client/dashboard (admin, expect 403) -> $code\n";

// Impersonation route (user 2 = demo client; user 1 = self should 403)
curl_setopt_array($ch, [
    CURLOPT_URL => $base.'/admin/impersonate/1',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_FOLLOWLOCATION => false,
]);
$imp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$loc = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
echo "GET /admin/impersonate/1 (self, expect 403) -> $code redirect=[$loc]\n";

curl_setopt_array($ch, [
    CURLOPT_URL => $base.'/admin/impersonate/2',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_FOLLOWLOCATION => false,
]);
$imp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$loc = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
echo "GET /admin/impersonate/2 (client, expect 302) -> $code redirect=[$loc]\n";

// After impersonation, client dashboard should be 200 (acting as client)
curl_setopt_array($ch, [
    CURLOPT_URL => $base.'/client/dashboard',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => false,
]);
$cd = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$banner = str_contains($cd, 'impersonation') || str_contains($cd, 'Impersonating');
echo "GET /client/dashboard (impersonated, expect 200) -> $code banner=$banner\n";

// Stop impersonation
curl_setopt_array($ch, [
    CURLOPT_URL => $base.'/admin/impersonate/stop',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_FOLLOWLOCATION => false,
]);
$imp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$loc = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
echo "GET /admin/impersonate/stop -> $code redirect=[$loc]\n";

// Back to admin: admin dashboard 200 again
curl_setopt_array($ch, [
    CURLOPT_URL => $base.'/admin/dashboard',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => false,
]);
$dash = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "GET /admin/dashboard (back to admin) -> $code\n";

curl_close($ch);
@unlink($cookieJar);
