<?php

/**
 * verify2 falsify-A probe (Log::listen variant — Log::spy() does not record
 * magic __call methods in standalone boots).
 */

$env = [
    'APP_ENV' => 'testing', 'APP_MAINTENANCE_DRIVER' => 'file', 'BCRYPT_ROUNDS' => '4',
    'BROADCAST_CONNECTION' => 'null', 'CACHE_STORE' => 'array', 'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
];
foreach ($env as $k => $v) { putenv($k.'='.$v); $_ENV[$k] = $v; $_SERVER[$k] = $v; }

$root = __DIR__.'/../../../..';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Jobs\SendEmail;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceEmailService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL').' — '.$label."\n";
    if (! $ok) { $failures++; }
}

$kernel->call('migrate:fresh', ['--quiet' => true]);

Queue::fake();
Storage::fake('local');
$captured = [];
Log::listen(function ($event) use (&$captured) { $captured[] = (string) $event->message; });
Pdf::shouldReceive('loadView')->andThrow(new \Exception('boom'));

$user = User::factory()->create();
$customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);
$invoice = Invoice::create([
    'invoice_no' => 'INV-'.Str::upper(Str::random(8)),
    'customer_id' => $customer->id,
    'amount' => 100.00, 'tax' => 0, 'total' => 100.00,
    'status' => Invoice::STATUS_SENT,
    'due_date' => now()->addDays(7)->toDateString(),
]);
EmailTemplate::create([
    'name' => 'invoice_created', 'subject' => 'Invoice {{invoice_no}}',
    'body' => 'Hi {{customer_name}}, your invoice is ready.', 'status' => 'active',
]);

$result = app(InvoiceEmailService::class)->send($invoice);

check('send() returned true despite PDF failure', $result === true);
$pushed = Queue::pushed(SendEmail::class);
check('exactly one SendEmail job pushed', $pushed->count() === 1);
$job = $pushed->first();
check('job attachments === []', $job !== null && $job->attachments === []);
$files = Storage::disk('local')->allFiles('invoice-emails');
check('no file written under invoice-emails/', $files === []);
$degradeLogged = false;
foreach ($captured as $msg) {
    if (str_contains($msg, 'Invoice PDF attachment failed')) { $degradeLogged = true; }
}
check('degrade failure logged via Log::info', $degradeLogged);

echo "\n";
if ($failures > 0) {
    echo "FAIL: {$failures} degrade-path assertion(s) failed.\n";
    exit(1);
}
echo "PASS: degrade path holds — email sent without attachment, nothing on disk, logged.\n";
