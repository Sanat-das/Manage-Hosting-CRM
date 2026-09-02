<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Billing\BillingService;
use App\Services\DomainService;
use App\Services\OrderActivityLogger;
use App\Services\OrderNumberService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Client portal — domain listing, detail, and self-service registration.
 */
class DomainController extends Controller
{
    public function __construct(
        private readonly DomainService $domains,
        private readonly OrderNumberService $orderNumbers,
        private readonly BillingService $billing,
    ) {}

    public function index(Request $request): View
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $search = trim((string) $request->query('search'));

        $domains = $customer->domains()
            ->when($search !== '', fn ($q) => $q->where(function ($inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('registrar', 'like', "%{$search}%");
            }))
            ->gridSort([
                'name' => 'name',
                'registrar' => 'registrar',
                'expiry_date' => 'expiry_date',
                'auto_renew' => 'auto_renew',
                'status' => 'status',
            ])
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('client.domains.index', compact('domains', 'search'));
    }

    public function show(Request $request, int $id): View
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $domain = $customer->domains()->findOrFail($id);

        return view('client.domains.show', compact('domain'));
    }

    /**
     * Availability search + registration form (one stateless page).
     *
     * `?q=example.com` runs the availability search; appending
     * `&domain=example.com` also renders the registration/checkout card when
     * that result is available.
     */
    public function register(Request $request): View
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $query = trim((string) $request->query('q'));
        $results = [];
        $error = null;
        $selected = null;
        $terms = [];

        if ($query !== '') {
            $search = $this->domains->searchAvailability($query);
            $results = $search['results'] ?? [];
            $error = $search['error'] ?? null;
            $this->domains->logSearch($query, $results, $customer->id);
        }

        $selectedName = strtolower(trim((string) $request->query('domain')));
        if ($selectedName !== '') {
            $selected = collect($results)->first(
                fn ($result) => strtolower($result['domain']) === $selectedName && $result['available'] === true
            );

            if ($selected !== null) {
                $terms = $this->domains->termsFor($selected['domain']);
            } else {
                $error = 'That domain is no longer available.';
            }
        }

        return view('client.domains.register', compact('query', 'results', 'error', 'selected', 'terms'));
    }

    /**
     * Place the domain registration order.
     *
     * Mirrors the storefront order placement: one Order (pending) + one
     * OrderItem + an immediately-billable draft invoice, and a Domain record
     * linked to the order. Availability is re-verified server-side and the
     * price is recomputed from the pricing tables — never trusted from the
     * client. The customer is sent straight to the invoice payment page to
     * complete the purchase.
     */
    public function registerStore(Request $request): RedirectResponse
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'registration_period' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        $name = strtolower(trim($validated['domain']));
        $years = (int) $validated['registration_period'];

        // A domain may have been registered by someone else between the search
        // and this submit — always re-verify at order time.
        $search = $this->domains->searchAvailability($name);
        $match = collect($search['results'] ?? [])->first(
            fn ($result) => strtolower($result['domain']) === $name && $result['available'] === true
        );

        if ($match === null) {
            return back()
                ->withErrors(['domain' => "{$name} is no longer available. Please search again."])
                ->withInput();
        }

        $price = $this->domains->priceForTerm($name, $years);
        if ($price === null || $price <= 0) {
            return back()
                ->withErrors(['domain' => "We could not price {$name}. Please contact support."])
                ->withInput();
        }

        $product = $this->domainRegistrationProduct();
        if ($product === null) {
            return back()
                ->withErrors(['domain' => 'Domain registration is temporarily unavailable. Please contact support.'])
                ->withInput();
        }

        $expiry = now()->addYears($years)->toDateString();

        try {
            [$order, $invoice] = DB::transaction(function () use ($customer, $product, $name, $years, $price, $expiry) {
                $order = Order::create([
                    'customer_id' => $customer->id,
                    'product_id' => $product->id,
                    'order_number' => $this->orderNumbers->next(),
                    'billing_cycle' => 'annual',
                    'quantity' => 1,
                    'total' => $price,
                    'status' => Order::STATUS_PENDING,
                    'domain_name' => $name,
                    'next_billing_date' => $expiry,
                ]);

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_name' => "Domain Registration — {$name}",
                    'billing_cycle' => 'annual',
                    'domain_name' => $name,
                    'recurring_cycles_limit' => (int) ($product->recurring_cycles_limit ?? 0),
                    'billing_cycles_count' => 1,
                    'quantity' => 1,
                    'unit_price' => $price,
                    'total' => $price,
                    'config_options' => [],
                ]);

                Domain::create([
                    'customer_id' => $customer->id,
                    'order_id' => $order->id,
                    'name' => $name,
                    'type' => 'register',
                    'registration_date' => now()->toDateString(),
                    'registration_period' => $years,
                    'expiry_date' => $expiry,
                    'next_due_date' => $expiry,
                    'next_invoice_date' => $expiry,
                    'recurring_amount' => $this->domains->annualRenewPrice($name),
                    'auto_renew' => true,
                    'status' => 'pending',
                ]);

                // Immediately billable — same draft-invoice convention as the
                // storefront and the admin order form.
                $invoice = $this->billing->createInvoiceForOrder($order);
                OrderActivityLogger::created($order);

                return [$order, $invoice];
            });
        } catch (\Throwable $e) {
            Log::error('Domain registration order failed', [
                'exception' => $e,
                'customer_id' => $customer->id,
                'domain' => $name,
            ]);

            return back()
                ->withErrors(['domain' => 'Could not place your domain order. Please try again or contact support.'])
                ->withInput();
        }

        return redirect()
            ->route('client.invoices.pay', $invoice)
            ->with('success', "Domain {$name} added. Complete your payment to activate it.");
    }

    /**
     * The product row domain orders link to (created by migration).
     */
    private function domainRegistrationProduct(): ?Product
    {
        return Product::query()
            ->where('name', 'Domain Registration')
            ->whereHas('group', fn ($q) => $q->where('slug', 'domain-registration'))
            ->first();
    }
}
