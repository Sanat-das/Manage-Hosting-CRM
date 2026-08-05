<?php

use App\Models\Customer;
use App\Models\CustomerWallet;
use App\Models\GstSetting;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\GstTaxService;
use App\Services\Billing\ProrationCalculator;

/**
 * End-to-end test for the billing engine services (Session 3A.1).
 * Pure Eloquent — no HTTP. Bootstrap pattern from test_customers.php.
 * Requires a migrated + seeded DB. Run: php test_billing_services.php
 */

// Bootstrap the app.
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$fail = 0;
$check = function (string $label, bool $ok, string $detail = '') use (&$fail) {
    echo ($ok ? 'PASS' : 'FAIL')."  $label".($detail !== '' ? "  [$detail]" : '')."\n";
    if (! $ok) {
        $fail++;
    }
};
$floatEq = fn (float $a, float $b, float $eps = 0.01): bool => abs($a - $b) <= $eps;

// ─── Test fixtures ────────────────────────────────────────────────
$suffix = substr(uniqid(), -6);
$userIds = [];
$customerIds = [];
$orderIds = [];
$productIds = [];

$makeUser = function (string $email) use (&$userIds): User {
    $user = User::create([
        'email' => $email,
        'password_hash' => bcrypt('billing-test'),
        'role' => 'client',
        'first_name' => 'Test',
        'last_name' => 'Billing',
        'status' => 'active',
    ]);
    $userIds[] = $user->id;

    return $user;
};

$makeCustomer = function (string $email, ?string $stateCode) use (&$customerIds, $makeUser): Customer {
    $customer = Customer::create([
        'user_id' => $makeUser($email)->id,
        'company' => 'Billing Co',
        'tax_id' => 'GSTIN123',
        'state_code' => $stateCode,
        'balance' => 0,
        'credit' => 0,
        'status' => 'active',
    ]);
    $customerIds[] = $customer->id;

    return $customer;
};

$makeProduct = function (array $attrs) use (&$productIds): Product {
    $product = Product::create(array_merge([
        'name' => 'Test Product',
        'type' => 'shared_hosting',
        'billing_cycle' => 'monthly',
        'price' => 0,
        'status' => 'active',
        'gst_enabled' => false,
        'gst_type' => 'standard',
    ], $attrs));
    $productIds[] = $product->id;

    return $product;
};

// Enable GST for the duration of the test; snapshot for restore.
$gst = GstSetting::find(1);
$priorEnabled = (int) $gst->enabled;
$priorMode = (string) $gst->tax_mode;
$gst->update(['enabled' => 1, 'tax_mode' => 'global']);
$service = new BillingService();

try {
    // ─── 1. GstTaxService unit checks ─────────────────────────────
    $check('isIntraState same state', GstTaxService::isIntraState('27', '27') === true);
    $check('isIntraState different state', GstTaxService::isIntraState('27', '10') === false);
    $check('isIntraState null customer state', GstTaxService::isIntraState('27', null) === false);

    // ─── 2. Intra-state invoice (company '27', customer '27') ─────
    // ₹10,000 @18% → CGST 900 + SGST 900 (per decisions/test spec).
    $intra = $makeCustomer("intra$suffix@example.com", '27');
    $inv = $service->createWithItems([
        'customer_id' => $intra->id,
        'amount' => 10000,
        'status' => 'draft',
        'due_date' => date('Y-m-d', strtotime('+7 days')),
    ], [[
        'description' => 'Shared Hosting - Monthly',
        'quantity' => 1,
        'unit_price' => 10000,
        'total' => 10000,
    ]], '27');

    $check('invoice_no pattern INV-YYYY-#####', preg_match('/^INV-\d{4}-\d{5}$/', $inv->invoice_no) === 1, $inv->invoice_no);
    $check('intra cgst_amount 900', $floatEq((float) $inv->cgst_amount, 900.0), (string) $inv->cgst_amount);
    $check('intra sgst_amount 900', $floatEq((float) $inv->sgst_amount, 900.0), (string) $inv->sgst_amount);
    $check('intra igst_amount 0', $floatEq((float) ($inv->igst_amount ?? 0), 0.0), (string) ($inv->igst_amount ?? 0));
    $check('intra tax 1800', $floatEq((float) $inv->tax, 1800.0), (string) $inv->tax);
    $check('intra total 11800', $floatEq((float) $inv->total, 11800.0), (string) $inv->total);
    $check('intra gst_enabled 1', (int) $inv->gst_enabled === 1);
    $check('intra line item tax persisted', $floatEq((float) $inv->items->first()->cgst_amount, 900.0));
    $breakdown = $inv->gst_breakdown;
    $check('gst_breakdown type intra', $breakdown['type'] === 'intra', $breakdown['type']);
    $check('gst_breakdown cgst 900', $floatEq($breakdown['cgst'], 900.0));

    // ─── 3. Inter-state invoice (customer '10') ───────────────────
    $inter = $makeCustomer("inter$suffix@example.com", '10');
    $invInter = $service->createWithItems([
        'customer_id' => $inter->id,
        'amount' => 10000,
        'status' => 'draft',
        'due_date' => date('Y-m-d', strtotime('+7 days')),
    ], [[
        'description' => 'Shared Hosting - Monthly',
        'quantity' => 1,
        'unit_price' => 10000,
        'total' => 10000,
    ]], '10');

    $check('inter cgst_amount 0', $floatEq((float) ($invInter->cgst_amount ?? 0), 0.0));
    $check('inter sgst_amount 0', $floatEq((float) ($invInter->sgst_amount ?? 0), 0.0));
    $check('inter igst_amount 1800', $floatEq((float) $invInter->igst_amount, 1800.0), (string) $invInter->igst_amount);
    $check('inter tax 1800', $floatEq((float) $invInter->tax, 1800.0));
    $check('inter total 11800', $floatEq((float) $invInter->total, 11800.0));
    $check('inter gst_breakdown type inter', $invInter->gst_breakdown['type'] === 'inter');

    // ─── 4. Per-item rounding + per-product rates (per_product mode) ─
    // item1 10000 @ 18% (cgst 9 + sgst 9) → 1800; item2 5000 @ 12% (6+6) → 600.
    // Tax = Σ per-line = 2400 — never 18% of 15000 (2700).
    $prod18 = $makeProduct(['name' => 'Prod18', 'gst_enabled' => true, 'cgst_rate' => 9, 'sgst_rate' => 9, 'igst_rate' => 18]);
    $prod12 = $makeProduct(['name' => 'Prod12', 'gst_enabled' => true, 'cgst_rate' => 6, 'sgst_rate' => 6, 'igst_rate' => 12]);
    $gst->update(['tax_mode' => 'per_product']);
    $invMulti = $service->createWithItems([
        'customer_id' => $intra->id,
        'amount' => 15000,
        'status' => 'draft',
        'due_date' => date('Y-m-d', strtotime('+7 days')),
    ], [
        ['product_id' => $prod18->id, 'description' => 'A', 'quantity' => 1, 'unit_price' => 10000, 'total' => 10000],
        ['product_id' => $prod12->id, 'description' => 'B', 'quantity' => 1, 'unit_price' => 5000, 'total' => 5000],
    ], '27');

    $lineTaxes = $invMulti->items->map(fn ($i) => (float) ($i->cgst_amount + $i->sgst_amount + $i->igst_amount));
    $check('per-line taxes [1800, 600]', $floatEq($lineTaxes[0], 1800.0) && $floatEq($lineTaxes[1], 600.0), $lineTaxes->join(','));
    $check('invoice tax is sum of lines 2400', $floatEq((float) $invMulti->tax, 2400.0), (string) $invMulti->tax);
    $check('multi total 17400', $floatEq((float) $invMulti->total, 17400.0), (string) $invMulti->total);
    $gst->update(['tax_mode' => 'global']);

    // ─── 5. Discount applied AFTER tax ────────────────────────────
    $invDisc = $service->createWithItems([
        'customer_id' => $intra->id,
        'amount' => 10000,
        'discount' => 500,
        'status' => 'draft',
        'due_date' => date('Y-m-d', strtotime('+7 days')),
    ], [[
        'description' => 'Hosting - Monthly',
        'quantity' => 1,
        'unit_price' => 10000,
        'total' => 10000,
    ]], '27');
    $check('discount total = amount + tax - discount (11300)', $floatEq((float) $invDisc->total, 11300.0), (string) $invDisc->total);
    $check('discount tax still 1800', $floatEq((float) $invDisc->tax, 1800.0), (string) $invDisc->tax);

    // ─── 6. Exempt product → all tax forced 0 ─────────────────────
    $prodExempt = $makeProduct(['name' => 'Exempt', 'gst_enabled' => true, 'gst_type' => 'exempt', 'cgst_rate' => 9, 'sgst_rate' => 9]);
    $gst->update(['tax_mode' => 'per_product']);
    $invExempt = $service->createWithItems([
        'customer_id' => $intra->id,
        'amount' => 10000,
        'status' => 'draft',
        'due_date' => date('Y-m-d', strtotime('+7 days')),
    ], [[
        'product_id' => $prodExempt->id,
        'description' => 'Exempt item',
        'quantity' => 1,
        'unit_price' => 10000,
        'total' => 10000,
    ]], '27');
    $check('exempt tax 0', $floatEq((float) $invExempt->tax, 0.0), (string) $invExempt->tax);
    $check('exempt cgst/sgst/igst 0', $floatEq((float) ($invExempt->cgst_amount ?? 0), 0.0)
        && $floatEq((float) ($invExempt->sgst_amount ?? 0), 0.0)
        && $floatEq((float) ($invExempt->igst_amount ?? 0), 0.0));
    $check('exempt total = amount', $floatEq((float) $invExempt->total, 10000.0), (string) $invExempt->total);
    $gst->update(['tax_mode' => 'global']);

    // ─── 7. Partial payment → status 'partial' ────────────────────
    $res = $service->recordPayment($inv->id, 5000, 'bank_transfer', 'txn-partial');
    $inv->refresh();
    $check('partial status', $res['status'] === 'partial' && $inv->status === 'partial', $res['status']);
    $check('partial paid_amount 5000', $floatEq((float) $inv->paid_amount, 5000.0), (string) $inv->paid_amount);
    $check('partial remaining_due 6800', $floatEq($res['remaining_due'], 6800.0), (string) $res['remaining_due']);
    $check('partial dueAmount helper', $floatEq($inv->dueAmount(), 6800.0));
    $check('isPartial helper', $inv->isPartial() === true && $inv->isFullyPaid() === false);

    // Full settlement of the same invoice → 'paid'.
    $resFull = $service->recordPayment($inv->id, 6800, 'bank_transfer', 'txn-full');
    $inv->refresh();
    $check('full settlement status paid', $resFull['status'] === 'paid' && $inv->status === 'paid', $resFull['status']);
    $check('full settlement paid_at set', $inv->paid_at !== null);
    $check('full settlement paid_amount 11800', $floatEq((float) $inv->paid_amount, 11800.0), (string) $inv->paid_amount);
    $check('isFullyPaid helper', $inv->isFullyPaid() === true);

    // ─── 8. Overpayment → wallet credit ───────────────────────────
    $invOver = $service->createWithItems([
        'customer_id' => $inter->id,
        'amount' => 10000,
        'status' => 'sent',
        'due_date' => date('Y-m-d', strtotime('+7 days')),
    ], [[
        'description' => 'Hosting - Monthly',
        'quantity' => 1,
        'unit_price' => 10000,
        'total' => 10000,
    ]], '10');
    $creditBefore = (float) $inter->fresh()->credit;
    $resOver = $service->recordPayment($invOver->id, 20000, 'razorpay', 'txn-over');
    $invOver->refresh();
    $check('overpayment status overpaid', $resOver['status'] === 'overpaid', $resOver['status']);
    $check('overpayment invoice paid', $invOver->status === 'paid');
    $check('overpayment amount 8200', $floatEq($resOver['overpayment'], 8200.0), (string) $resOver['overpayment']);
    $check('overpayment paid_amount clamped to total', $floatEq((float) $invOver->paid_amount, 11800.0), (string) $invOver->paid_amount);
    $creditAfter = (float) $inter->fresh()->credit;
    $check('overpayment customers.credit += 8200', $floatEq($creditAfter - $creditBefore, 8200.0), ($creditAfter - $creditBefore).'');
    $wallet = CustomerWallet::where('customer_id', $inter->id)->latest('id')->first();
    $check('overpayment wallet ledger row', $wallet !== null && $wallet->type === 'credit' && $floatEq((float) $wallet->amount, 8200.0), $wallet?->amount ?? 'none');

    // ─── 9. markPaid full ─────────────────────────────────────────
    $invMp = $service->createWithItems([
        'customer_id' => $intra->id,
        'amount' => 10000,
        'status' => 'sent',
        'due_date' => date('Y-m-d', strtotime('+7 days')),
    ], [[
        'description' => 'Hosting - Monthly',
        'quantity' => 1,
        'unit_price' => 10000,
        'total' => 10000,
    ]], '27');
    $paymentId = $service->markPaid($invMp->id, 11800, 'cash', '');
    $invMp->refresh();
    $check('markPaid returns payment id', is_int($paymentId) && $paymentId > 0);
    $check('markPaid status paid + paid_at', $invMp->status === 'paid' && $invMp->paid_at !== null);
    $check('markPaid paid_amount synced', $floatEq((float) $invMp->paid_amount, 11800.0), (string) $invMp->paid_amount);
    $check('markPaid partial path', (function () use ($service, $invInter) {
        $service->markPaid($invInter->id, 100, 'cash');
        return $invInter->refresh()->status === 'partial';
    })());

    // ─── 10. ProrationCalculator ──────────────────────────────────
    $prorated = ProrationCalculator::calculateProration(1000, 1500, '2026-01-01', '2026-01-31', '2026-01-16', 'upgrade');
    $check('proration days 15', $prorated['proration_days'] === 15, (string) $prorated['proration_days']);
    $check('proration credit 500', $floatEq($prorated['credit'], 500.0), (string) $prorated['credit']);
    $check('proration upgrade charge full 1500', $floatEq($prorated['charge'], 1500.0), (string) $prorated['charge']);

    $proratedDown = ProrationCalculator::calculateProration(1000, 1500, '2026-01-01', '2026-01-31', '2026-01-16', 'downgrade');
    $check('proration downgrade charge prorated 750', $floatEq($proratedDown['charge'], 750.0), (string) $proratedDown['charge']);

    $annual = ProrationCalculator::annualToMonthly(1200, '2026-01-01', '2026-12-31', '2026-01-16');
    // 364-day annual period, 15 used, 349 remaining (reference test
    // BillingProrationTest::testAnnualToMonthlyConversion uses actual days):
    // credit = round(1200 * 349/364, 2) = 1150.55; charge = round(100 * 349/364, 2) = 95.88.
    $check('annualToMonthly monthly prorated 95.88', $floatEq($annual['monthly_charge'], 95.88), (string) $annual['monthly_charge']);
    $check('annualToMonthly credit 1150.55', $floatEq($annual['credit'], 1150.55), (string) $annual['credit']);
    $check('annualToMonthly proration days 349', $annual['proration_days'] === 349, (string) $annual['proration_days']);

    $zero = ProrationCalculator::calculateProration(1000, 1500, '2026-01-01', '2026-01-01', '2026-01-16', 'downgrade');
    $check('proration zero-period guard charge full', $floatEq($zero['charge'], 1500.0) && $floatEq($zero['credit'], 0.0));

    // ─── 11. Recurring billing + renewal-IGST fix ─────────────────
    // Intra-state customer ('27' == company '27'): the renewal invoice must be
    // CGST+SGST, NOT IGST (reference bug — null customer state → always IGST).
    $prodRec = $makeProduct(['name' => 'Recurring Plan', 'billing_cycle' => 'monthly', 'price' => 10000]);
    $recCust = $makeCustomer("recur$suffix@example.com", '27');
    $recOrder = Order::create([
        'customer_id' => $recCust->id,
        'product_id' => $prodRec->id,
        'quantity' => 1,
        'total' => 10000,
        'status' => 'active',
        'next_billing_date' => null,
        'last_billing_date' => null,
    ]);
    $orderIds[] = $recOrder->id;
    OrderItem::create([
        'order_id' => $recOrder->id,
        'product_id' => $prodRec->id,
        'product_name' => 'Recurring Plan',
        'quantity' => 1,
        'unit_price' => 10000,
        'total' => 10000,
    ]);

    $today = Carbon\CarbonImmutable::today('Asia/Kolkata');
    $result = $service->processRecurringBilling($today);
    $check('recurring invoices_generated 1', $result['invoices_generated'] === 1, json_encode($result));
    $check('recurring errors 0', $result['errors'] === 0, json_encode($result));

    $renewal = Invoice::where('customer_id', $recCust->id)->latest('id')->first();
    $check('renewal invoice exists', $renewal !== null);
    if ($renewal) {
        $check('renewal status sent', $renewal->status === 'sent', $renewal->status);
        $check('renewal due_date today+7', $renewal->due_date->toDateString() === $today->addDays(7)->toDateString(), $renewal->due_date->toDateString());
        $check('renewal notes auto-generated', $renewal->notes === 'Auto-generated renewal invoice');
        $check('RENEWAL IGST FIX: intra-state renewal is CGST+SGST not IGST',
            $floatEq((float) $renewal->cgst_amount, 900.0)
            && $floatEq((float) $renewal->sgst_amount, 900.0)
            && $floatEq((float) ($renewal->igst_amount ?? 0), 0.0),
            "cgst={$renewal->cgst_amount} sgst={$renewal->sgst_amount} igst={$renewal->igst_amount}");
        $check('renewal total 11800', $floatEq((float) $renewal->total, 11800.0), (string) $renewal->total);
    }
    $recOrder->refresh();
    $check('recurring next_billing_date +1 month', $recOrder->next_billing_date?->toDateString() === $today->addMonths(1)->toDateString(), (string) $recOrder->next_billing_date);
    $check('recurring last_billing_date today', $recOrder->last_billing_date?->toDateString() === $today->toDateString(), (string) $recOrder->last_billing_date);

    // ─── 12. Status label helper ──────────────────────────────────
    $check('status label map', $inv->getStatusLabelAttribute() === 'Paid' && $invInter->getStatusLabelAttribute() === 'Partial');
} finally {
    // ─── Cleanup ──────────────────────────────────────────────────
    foreach ($orderIds as $oid) {
        Order::find($oid)?->delete();
    }
    foreach ($customerIds as $cid) {
        Customer::find($cid)?->delete();
    }
    foreach ($userIds as $uid) {
        User::find($uid)?->delete();
    }
    foreach ($productIds as $pid) {
        Product::find($pid)?->delete();
    }
    $gst->update(['enabled' => $priorEnabled, 'tax_mode' => $priorMode]);
}

echo "\n".($fail === 0 ? 'ALL PASS' : "$fail FAILURES")."\n";
exit($fail === 0 ? 0 : 1);
