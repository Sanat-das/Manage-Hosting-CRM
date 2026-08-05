<?php
/**
 * End-to-end test for the Customer pilot module (Phase 2.4).
 * Requires: dev server on 127.0.0.1:8099, seeded DB, admin@localhost.com / Admin@123.
 */

$base = 'http://127.0.0.1:8099';
$cookieJar = __DIR__.'/cookies_customers.txt';
@unlink($cookieJar);

// Bootstrap the app so we can mint a Sanctum token for the API section.
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ch = curl_init();
$c = function () use ($cookieJar) {
    return [
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => false,
    ];
};
$get = function (string $url, array $extra = []) use ($ch, $c) {
    curl_setopt_array($ch, $c() + [CURLOPT_URL => $url, CURLOPT_HTTPGET => true, CURLOPT_CUSTOMREQUEST => null] + $extra);
    $resp = curl_exec($ch);
    return [curl_getinfo($ch, CURLINFO_HTTP_CODE), (string) $resp, curl_getinfo($ch, CURLINFO_REDIRECT_URL)];
};
$post = function (string $url, array $fields = []) use ($ch, $c) {
    curl_setopt_array($ch, $c() + [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_CUSTOMREQUEST => null,
        CURLOPT_POSTFIELDS => http_build_query($fields),
    ]);
    $resp = curl_exec($ch);
    return [curl_getinfo($ch, CURLINFO_HTTP_CODE), (string) $resp, curl_getinfo($ch, CURLINFO_REDIRECT_URL)];
};
$request = function (string $method, string $url, array $fields = []) use ($ch, $c) {
    curl_setopt_array($ch, $c() + [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => http_build_query($fields),
    ]);
    $resp = curl_exec($ch);
    return [curl_getinfo($ch, CURLINFO_HTTP_CODE), (string) $resp, curl_getinfo($ch, CURLINFO_REDIRECT_URL)];
};
$csrf = function (string $html): string {
    if (preg_match('/name="_token" value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    return '';
};

$fail = 0;
$check = function (string $label, bool $ok, string $detail = '') use (&$fail) {
    echo ($ok ? 'PASS' : 'FAIL')."  $label".($detail !== '' ? "  [$detail]" : '')."\n";
    if (! $ok) {
        $fail++;
    }
};

// 1. Login as admin
[$code, $html] = $get($base.'/login');
$token = $csrf($html);
[$code, $html, $loc] = $post($base.'/login', ['_token' => $token, 'email' => 'admin@localhost.com', 'password' => 'Admin@123']);
$check('admin login', $code === 302, "code=$code loc=$loc");

// 2. Admin customers index
[$code, $html] = $get($base.'/admin/customers');
$check('admin/customers index 200', $code === 200, "code=$code");

// 3. Create page
[$code, $html] = $get($base.'/admin/customers/create');
$check('admin/customers/create 200', $code === 200, "code=$code");

// 4. Store a new customer
$token = $csrf($html);
$uniq = substr(uniqid(), -5);
[$code, $html, $loc] = $post($base.'/admin/customers', [
    '_token' => $token,
    'first_name' => 'Test', 'last_name' => 'Customer'.$uniq,
    'email' => "test$uniq@example.com", 'password' => 'Passw0rd!', 'password_confirmation' => 'Passw0rd!',
    'phone' => '+911234567890', 'company' => "Acme $uniq", 'tax_id' => 'GSTIN123', 'address' => 'Test Street',
    'status' => 'active',
]);
$check('store customer 302', $code === 302, "code=$code loc=$loc");
preg_match('#/admin/customers/(\d+)$#', (string) $loc, $m);
$customerId = $m[1] ?? null;
$check('customer id captured', $customerId !== null, "loc=$loc");

// 5. Show (tabbed detail)
[$code, $html] = $get($base."/admin/customers/$customerId");
$check('show customer 200', $code === 200, "code=$code");
$check('detail shows display id', str_contains($html, '#CLT-'), '');
$check('detail has tabs', str_contains($html, 'id="profile-tab"'), '');

// Capture the user id behind this customer (route is /admin/impersonate/{user}).
preg_match('#/admin/impersonate/(\d+)#', $html, $um);
$userId = $um[1] ?? null;
$check('user id captured from show page', $userId !== null, '');

// 6. Edit page + update
[$code, $html] = $get($base."/admin/customers/$customerId/edit");
$check('edit page 200', $code === 200, "code=$code");
$token = $csrf($html);
[$code, $html, $loc] = $request('PUT', $base."/admin/customers/$customerId", [
    '_token' => $token,
    'first_name' => 'Test', 'last_name' => 'Updated'.$uniq,
    'email' => "test$uniq@example.com", 'phone' => '+919999999999',
    'company' => "Acme $uniq", 'tax_id' => 'GSTIN123', 'address' => 'New Street',
    'status' => 'active',
]);
$check('update customer 302', $code === 302, "code=$code loc=$loc");

// 7. Add a note
[$code, $html] = $get($base."/admin/customers/$customerId?tab=notes");
$token = $csrf($html);
[$code, $html, $loc] = $post($base."/admin/customers/$customerId/notes", [
    '_token' => $token, 'note' => 'Test note from E2E', 'is_important' => 1,
]);
$check('store note 302', $code === 302, "code=$code loc=$loc");
[$code, $html] = $get($base."/admin/customers/$customerId?tab=notes");
$check('note visible on detail', str_contains($html, 'Test note from E2E'), "code=$code");

// 8. Add a contact
$token = $csrf($html);
[$code, $html, $loc] = $post($base."/admin/customers/$customerId/contacts", [
    '_token' => $token, 'first_name' => 'John', 'last_name' => 'Doe',
    'email' => "contact$uniq@example.com", 'phone' => '+911122334455', 'role' => 'Technical',
]);
$check('store contact 302', $code === 302, "code=$code loc=$loc");
[$code, $html] = $get($base."/admin/customers/$customerId?tab=contacts");
$check('contact visible + primary auto-set', str_contains($html, 'John Doe') && str_contains($html, 'Primary'), "code=$code");

// 9. Wallet adjustment (deposit)
$token = $csrf($html);
[$code, $html, $loc] = $post($base."/admin/customers/$customerId/wallet", [
    '_token' => $token, 'type' => 'deposit', 'amount' => '250.00', 'description' => 'E2E deposit',
]);
$check('wallet deposit 302', $code === 302, "code=$code loc=$loc");
[$code, $html] = $get($base."/admin/customers/$customerId?tab=billing");
$check('wallet balance reflects deposit', str_contains($html, '250.00'), "code=$code");

// 10. Activity logged
[$code, $html] = $get($base."/admin/customers/$customerId?tab=activity");
$check('activity shows customer.created', str_contains($html, 'customer.created'), "code=$code");
$check('activity shows wallet_adjusted', str_contains($html, 'wallet_adjusted'), '');

// 11. Impersonate this client, then verify client portal + profile
[$code, $html, $loc] = $get($base."/admin/impersonate/$userId", [CURLOPT_FOLLOWLOCATION => false]);
// impersonate start redirects to admin/dashboard as the client (who has no admin access -> 403). Check client portal instead.
[$code, $html] = $get($base.'/client/dashboard');
$check('client dashboard as impersonated user 200', $code === 200, "code=$code");
[$code, $html] = $get($base.'/client/profile');
$check('client profile 200', $code === 200, "code=$code");
$token = $csrf($html);
[$code, $html, $loc] = $request('PUT', $base.'/client/profile', [
    '_token' => $token, 'first_name' => 'Test', 'last_name' => 'Updated'.$uniq,
    'email' => "test$uniq@example.com", 'phone' => '+919988776655',
    'company' => "Acme $uniq", 'address' => 'Client Street',
]);
$check('client profile update 302', $code === 302, "code=$code loc=$loc");

// 12. Client cannot access admin panel
[$code, $html] = $get($base.'/admin/customers');
$check('client blocked from admin/customers 403', $code === 403, "code=$code");

// 13. Stop impersonation
[$code, $html, $loc] = $get($base.'/admin/impersonate/stop');
$check('impersonate stop 302', $code === 302, "code=$code loc=$loc");
[$code, $html] = $get($base.'/admin/customers');
$check('admin restored: customers index 200', $code === 200, "code=$code");

// 14. API: create a Sanctum token and hit /api/customers
$token = \App\Models\User::where('email', 'admin@localhost.com')->first()?->createToken('e2e');
if ($token === null) {
    $check('api token created', false, 'admin user not found');
} else {
    curl_setopt_array($ch, $c() + [
        CURLOPT_URL => $base.'/api/customers?per_page=5',
        CURLOPT_HTTPGET => true,
        CURLOPT_CUSTOMREQUEST => null,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$token->plainTextToken, 'Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    $apiCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $json = json_decode((string) $resp, true);
    $check('api customers 200', $apiCode === 200, "code=$apiCode");
    $check('api returns data array', isset($json['data']) && is_array($json['data']), '');
    $check('api customer has display_id', isset($json['data'][0]['display_id']), '');
}

// 15. Unauthenticated API blocked
curl_setopt_array($ch, $c() + [
    CURLOPT_URL => $base.'/api/customers',
    CURLOPT_HTTPGET => true,
    CURLOPT_CUSTOMREQUEST => null,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);
curl_exec($ch);
$apiCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$check('api without token 401', $apiCode === 401, "code=$apiCode");

// 16. Cleanup: delete the test customer
[$code, $html] = $get($base.'/admin/customers');
$token = $csrf($html);
[$code, $html, $loc] = $request('DELETE', $base."/admin/customers/$customerId", ['_token' => $token]);
$check('delete customer 302', $code === 302, "code=$code loc=$loc");

echo "\n".($fail === 0 ? 'ALL PASS' : "$fail FAILURES")."\n";
exit($fail === 0 ? 0 : 1);
