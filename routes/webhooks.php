<?php

use App\Http\Controllers\Webhooks\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inbound webhooks
|--------------------------------------------------------------------------
|
| Server-to-server callbacks from third parties. Deliberately outside the
| `web` middleware group: a payment gateway carries no session and no CSRF
| token, and must not be redirected into the first-run installer either.
| Authenticity is the driver's signature check (PaymentWebhookDriver), and
| nothing is recorded until the gateway's own API confirms the payment.
|
| These are self-contained — do NOT require this file from web.php or api.php.
|
*/

Route::post('webhooks/payments/{gateway}', PaymentWebhookController::class)
    ->middleware('throttle:payment-webhooks')
    ->name('webhooks.payments');
