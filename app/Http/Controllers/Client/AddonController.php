<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\HostingAccount;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Services\Billing\AddOnService;
use App\Services\InvoiceEmailService;
use App\Support\Logging\AppLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Client purchase of an add-on for a service the customer already owns.
 *
 * Thin by design, like the admin add-on path: the request is validated, the
 * service line is resolved, the add-on is re-checked against the product's
 * applicable add-ons, and the purchase itself is delegated to AddOnService —
 * the single writer for the add-on rows, the invoice and the audit row. The
 * customer only ever reaches their own service: the account is resolved
 * through the authenticated customer's own relation, so another customer's
 * account id is a 404.
 */
class AddonController extends Controller
{
    public function __construct(
        private readonly AddOnService $addons,
        private readonly InvoiceEmailService $invoiceEmails,
    ) {}

    public function store(Request $request, int $hostingAccount): RedirectResponse
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $account = $customer->hostingAccounts()->findOrFail($hostingAccount);

        $validated = $request->validate([
            'addon_id' => ['required', 'integer', 'exists:product_addons,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.Order::MAX_QUANTITY],
        ], [
            'addon_id.required' => $this->failureMessage($account, 'Choose an add-on.'),
            'addon_id.integer' => $this->failureMessage($account, 'Choose an add-on.'),
            'addon_id.exists' => $this->failureMessage($account, 'That add-on is no longer available.'),
            'quantity.required' => $this->failureMessage($account, 'Enter a quantity.'),
            'quantity.integer' => $this->failureMessage($account, 'Enter a quantity.'),
            'quantity.min' => $this->failureMessage($account, 'The quantity must be at least 1.'),
            'quantity.max' => $this->failureMessage($account, 'The quantity must not be greater than '.Order::MAX_QUANTITY.'.'),
        ]);

        $order = $account->order;

        if ($order === null) {
            return back()->withInput()->with('error', $this->failureMessage($account, 'This service has no order to add an add-on to.'));
        }

        // The service line — never an add-on line — is what the add-on hangs
        // off, and its product is the one the add-on is validated against.
        // The same rule prices the offer on the products page.
        $parentItem = $this->addons->serviceLineFor($order->items, $account->product_id);

        if ($parentItem === null) {
            return back()->withInput()->with('error', $this->failureMessage($account, 'This service has no service line to add an add-on to.'));
        }

        $addon = ProductAddon::find($validated['addon_id']);
        $product = $parentItem->product_id !== null
            ? Product::find($parentItem->product_id)
            : $account->product;

        // Server-side defence: an add-on is buyable here only when it is
        // active and scoped to this service's product (or global). Never
        // trust the form.
        $orderableIds = $product !== null
            ? $this->addons->applicableFor($product)->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];

        if ($addon === null || ! in_array((int) $addon->id, $orderableIds, true)) {
            return back()->withInput()->withErrors([
                'addon_id' => $this->failureMessage($account, 'The selected add-on is not available for this service.'),
            ]);
        }

        try {
            $purchase = $this->addons->purchaseForService(
                $order,
                $parentItem,
                $addon,
                (int) $validated['quantity'],
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->withErrors([
                'addon_id' => $this->failureMessage($account, $e->getMessage()),
            ]);
        }

        $invoice = $purchase['invoice'];

        // Post-commit and best-effort: the purchase exists, so a dead SMTP
        // host must never cost the customer the add-on.
        try {
            $invoice->loadMissing(['customer.user', 'order']);

            $this->invoiceEmails->send($invoice);
        } catch (\Throwable $e) {
            AppLog::billing()->error('Add-on invoice email failed', ['exception' => $e, 'invoice_id' => $invoice->id]);
        }

        return redirect()
            ->route('client.hosting.index')
            ->with('success', "Add-on {$addon->name} added to your service. Invoice {$invoice->invoice_no} has been sent.");
    }

    /**
     * Every failure names the service the customer was buying for: the page
     * offers a form per service, so a message without a service id cannot be
     * acted on.
     */
    private function failureMessage(HostingAccount $account, string $message): string
    {
        return "Service #{$account->id} — {$message}";
    }
}
