<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\SanitizesSessionCart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionGroupProduct;
use App\Services\Billing\BillingService;
use App\Services\OrderActivityLogger;
use App\Services\OrderConfigSnapshot;
use App\Services\OrderNumberService;
use App\Services\ProductBundlePricingService;
use App\Support\OptionSelectionRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Client-facing storefront (gap-fillup T1.4; spec: .omo/plans/client-store.md).
 *
 * Lets a client browse the orderable catalog, build a session cart, and place
 * an Order. Mirrors the admin cart ordering path (T1.1): one Order per product
 * line, race-safe numbers from OrderNumberService, pricing resolved through
 * ProductPricing. Orders land in `pending` — payment is a separate flow.
 */
class StoreController extends Controller
{
    use SanitizesSessionCart;

    public function __construct(
        private readonly OrderNumberService $orderNumbers,
        private readonly ProductBundlePricingService $bundlePricing,
        private readonly BillingService $billing,
        private readonly OrderConfigSnapshot $snapshot,
    ) {}

    public function index(): View
    {
        $groups = ProductGroup::with(['products' => fn ($q) => $q
            ->where('status', 'active')
            ->where('show_in_order', true)
            ->where('only_admin', false)
            ->whereHas('group', fn ($q) => $q->whereNotIn('slug', ['domain-registration', 'addons-extras']))
            ->with('pricing')])
            ->orderBy('sort_order')
            ->get();

        $categories = $groups->filter(fn ($g) => $g->products->isNotEmpty());

        return view('client.store.index', compact('categories'));
    }

    public function show(Product $product): View
    {
        abort_unless($product->status === 'active' && $product->show_in_order && ! $product->only_admin, 404);

        $product->load('group', 'pricing');

        return view('client.store.product', compact('product'));
    }

    public function addToCart(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('status', 'active')->where('show_in_order', true)->where('only_admin', 0)],
            'billing_cycle' => ['required', 'string', 'in:'.implode(',', Order::BILLING_CYCLES)],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.Order::MAX_QUANTITY],
            'domain' => ['nullable', 'string', 'max:255'],
        ]);

        $cart = $this->sanitizeCart();
        $product = Product::find($validated['product_id']);

        // Single-unit products are sold one unit per order (qty locked to 1);
        // a customer may still buy the product again in later orders.
        $quantity = $product !== null && $product->isSingleUnit() ? 1 : (int) $validated['quantity'];

        // Customer-editable option selections only apply to the product being
        // added itself; bundle products expand into component lines priced by
        // ProductBundlePricingService and expose no customer options here.
        $editableLinks = collect();
        $selections = [];

        if ($product !== null && ! $product->isBundle()) {
            $optionLinks = $product->optionLinks()
                ->with(['group', 'linkValues.pricing', 'unitPricing'])
                ->orderBy('id')
                ->get();

            $editableLinks = $optionLinks->where('customer_editable', true);

            if ($editableLinks->isNotEmpty()) {
                $selections = $this->validateOptionSelections($request, $editableLinks);
            }
        }

        // Bundles expand at add time into component lines priced by
        // ProductBundlePricingService; non-bundle products keep the legacy
        // merge-by-identity behaviour exactly as before.
        if ($product !== null && $product->isBundle()) {
            $quote = $this->bundlePricing->priceFor($product, $validated['billing_cycle']);
            if ($quote === null) {
                return back()
                    ->with('error', "Bundle {$product->name} has no pricing for the ".ucfirst(str_replace('_', ' ', $validated['billing_cycle'])).' cycle.');
            }

            foreach ($quote['line_items'] as $line) {
                $lineQty = max(1, (int) $line['quantity']);
                $cart[] = [
                    'product_id' => $line['product_id'],
                    'billing_cycle' => $validated['billing_cycle'],
                    'quantity' => $lineQty,
                    'domain' => $validated['domain'] ?? null,
                    'unit_price' => round($line['total'] / $lineQty, 2),
                    'total' => round($line['total'], 2),
                    'bundle_id' => $product->id,
                    'bundle_name' => $product->name,
                ];
            }

            session()->put('cart', $cart);

            return redirect()->route('client.store.index')->with('success', "{$product->name} added to cart.");
        }

        $matched = false;
        foreach ($cart as $k => $item) {
            // Two configurations of the same product must never merge: the
            // option selection joins the merge identity.
            if ((int) ($item['product_id'] ?? 0) === $product->id
                && ($item['billing_cycle'] ?? null) === $validated['billing_cycle']
                && ($item['domain'] ?? null) === ($validated['domain'] ?? null)
                && ($item['options'] ?? []) === $selections) {
                $cart[$k]['quantity'] += $quantity;
                if (array_key_exists('unit_price', $cart[$k]) && array_key_exists('total', $cart[$k])) {
                    $cart[$k]['total'] = round((float) $cart[$k]['unit_price'] * $cart[$k]['quantity'], 2);
                }
                $matched = true;
                break;
            }
        }

        if (! $matched) {
            $entry = array_merge($validated, ['quantity' => $quantity]);

            // Option-carrying lines pin their own unit price (base price +
            // selected-value modifiers) and total, mirroring the
            // bundle-expanded lines, so the cart resolver never re-derives the
            // unmodified base price for the cycle. Free products keep the
            // selection (it may matter for provisioning) but never charge.
            if ($selections !== []) {
                $entry = array_merge($entry, ['options' => $selections]);

                if (($product->payment_type ?? 'recurring') !== 'free') {
                    $unitPrice = OrderConfigSnapshot::formatPrice(
                        $this->baseUnitPrice($product, $validated['billing_cycle']),
                        OrderConfigSnapshot::adjustmentsFor($product, $selections),
                        $validated['billing_cycle'],
                        $product->pricing->pluck('billing_cycle')->all()
                    );

                    $entry = array_merge($entry, [
                        'unit_price' => round($unitPrice, 2),
                        'total' => round($unitPrice * $quantity, 2),
                    ]);
                }
            }

            $cart[] = $entry;
        }

        session()->put('cart', $cart);

        return redirect()->route('client.store.index')->with('success', "{$product->name} added to cart.");
    }

    public function updateCart(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'index' => ['required', 'integer', 'min:0'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.Order::MAX_QUANTITY],
        ]);

        $cart = $this->sanitizeCart();
        if (isset($cart[$validated['index']])) {
            // Single-unit products stay locked to qty 1 regardless of the
            // submitted quantity.
            $entry = $cart[$validated['index']];
            $product = Product::find($entry['product_id'] ?? null);
            $newQty = $product !== null && $product->isSingleUnit() ? 1 : (int) $validated['quantity'];

            $cart[$validated['index']]['quantity'] = $newQty;

            if (array_key_exists('unit_price', $cart[$validated['index']])) {
                $cart[$validated['index']]['total'] = round((float) $cart[$validated['index']]['unit_price'] * $newQty, 2);
            }

            session()->put('cart', $cart);
        }

        return redirect()->route('client.store.cart')->with('success', 'Cart updated.');
    }

    public function removeFromCart(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'index' => ['required', 'integer', 'min:0'],
        ]);

        $cart = $this->sanitizeCart();
        if (isset($cart[$validated['index']])) {
            unset($cart[$validated['index']]);
            session()->put('cart', array_values($cart));
        }

        return redirect()->route('client.store.cart')->with('success', 'Item removed from cart.');
    }

    public function cart(): View
    {
        $items = $this->resolveCartItems($this->sanitizeCart());

        return view('client.store.cart', compact('items'));
    }

    public function checkout(): View|RedirectResponse
    {
        $items = $this->resolveCartItems($this->sanitizeCart());
        if ($items === []) {
            return redirect()->route('client.store.cart')->with('error', 'Your cart is empty.');
        }

        return view('client.store.checkout', compact('items'));
    }

    public function placeOrder(Request $request): RedirectResponse
    {
        $items = $this->resolveCartItems($this->sanitizeCart());
        if ($items === []) {
            return back()->withErrors(['cart' => 'Your cart is empty — nothing to order.']);
        }

        $customer = $request->user()->customer;
        if ($customer === null) {
            return back()->withErrors(['cart' => 'No customer account linked. Contact support.']);
        }

        try {
            $orders = DB::transaction(function () use ($items, $customer) {
                $created = [];
                foreach ($items as $item) {
                    $order = Order::create([
                        'customer_id' => $customer->id,
                        'product_id' => $item['product']->id,
                        'order_number' => $this->orderNumbers->next(),
                        'billing_cycle' => $item['cycle'],
                        'quantity' => $item['quantity'],
                        'total' => $item['total'],
                        'status' => Order::STATUS_PENDING,
                        'domain_name' => $item['domain'] ?? null,
                    ]);

                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $item['product']->id,
                        'product_name' => $item['product']->name,
                        'billing_cycle' => $item['cycle'],
                        'domain_name' => $item['domain'] ?? null,
                        'recurring_cycles_limit' => (int) ($item['product']->recurring_cycles_limit ?? 0),
                        'billing_cycles_count' => (Order::CYCLE_MONTHS[$item['cycle']] ?? 0) > 0 ? 1 : 0,
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'total' => $item['total'],
                        'config_options' => $this->snapshot->capture($item['product'], null, $item['options'] ?? []),
                    ]);

                    // Draft invoice via the shared GST engine, same convention
                    // as the admin order form / admin cart — the customer's
                    // order is immediately billable.
                    $this->billing->createInvoiceForOrder($order);

                    // Customer-facing trail: the storefront writes the same
                    // order_created row as the other entry points.
                    OrderActivityLogger::created($order);

                    $created[] = $order;
                }

                return $created;
            });
        } catch (\Throwable $e) {
            Log::error('Order placement failed', ['exception' => $e, 'customer_id' => $customer->id]);

            return back()->withErrors(['error' => 'Could not place your order. Please try again or contact support.']);
        }

        session()->forget('cart');

        $confirm = $orders[0];

        return redirect()->route('client.store.confirmation', $confirm)->with('success', 'Order placed.');
    }

    public function confirmation(Order $order): View
    {
        $customerId = auth()->user()?->customer?->id;
        abort_unless($customerId !== null && (int) $order->customer_id === (int) $customerId, 403);

        $order->load('items.product');

        return view('client.store.confirmation', compact('order'));
    }

    /**
     * Resolve session cart entries into product lines with price/total.
     *
     * Each line carries a `config_options` preview of the product's option
     * configuration (via OrderConfigSnapshot) for display on the cart page —
     * no schema change, purely view-facing.
     *
     * @param  array<int, array<string, mixed>>  $cart
     * @return array<int, array{product: Product, cycle: string, quantity: int, unit_price: float, total: float, domain: ?string, options: array, config_options: array}>
     */
    private function resolveCartItems(array $cart): array
    {
        $items = [];
        foreach ($cart as $entry) {
            $product = Product::query()
                ->where('status', 'active')
                ->where('show_in_order', true)
                ->where('only_admin', false)
                ->find($entry['product_id'] ?? null);
            if ($product === null) {
                continue;
            }

            $cycle = $entry['billing_cycle'] ?? 'monthly';
            if (! in_array($cycle, Order::BILLING_CYCLES, true)) {
                $cycle = 'monthly';
            }

            $quantity = max(1, (int) ($entry['quantity'] ?? 1));

            // Single-unit products are always one unit per order — clamp at the
            // resolution point so placeOrder() can never persist qty > 1 even if
            // the session cart was tampered with or seeded directly.
            if ($product->isSingleUnit()) {
                $quantity = 1;
            }

            // Bundle-expanded lines carry a precomputed price (T4.4) — use it
            // verbatim so component lines sum exactly to the bundle price.
            if (array_key_exists('unit_price', $entry)) {
                $unitPrice = (float) $entry['unit_price'];
                $resolvedTotal = $product->isSingleUnit()
                    ? round($unitPrice, 2)
                    : (float) ($entry['total'] ?? $unitPrice);

                $items[] = [
                    'product' => $product,
                    'cycle' => $cycle,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total' => $resolvedTotal,
                    'domain' => $entry['domain'] ?? null,
                    'options' => $entry['options'] ?? [],
                    'config_options' => $this->snapshot->capture($product, null, $entry['options'] ?? []),
                ];

                continue;
            }

            $pricing = $product->pricing()->where('billing_cycle', $cycle)->first();
            $unitPrice = (float) ($pricing?->price ?? $product->price ?? 0);

            $items[] = [
                'product' => $product,
                'cycle' => $cycle,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total' => round($unitPrice * $quantity, 2),
                'domain' => $entry['domain'] ?? null,
                'options' => $entry['options'] ?? [],
                'config_options' => $this->snapshot->capture($product, null, $entry['options'] ?? []),
            ];
        }

        return $items;
    }

    /**
     * Validate the submitted option selections for a product's editable links.
     *
     * Rules follow each option group's input type; an invalid payload aborts
     * with the standard validation redirect (back + errors + old input). Only
     * editable links are validated — informational links are ignored, and any
     * hidden payload for them is dropped from the returned selections.
     *
     * @param  Collection<int, ProductOptionGroupProduct>  $editableLinks
     * @return array<string, mixed> validated selections keyed by link id
     */
    private function validateOptionSelections(Request $request, Collection $editableLinks): array
    {
        // Shared per-type rules (also used by the admin order form).
        $rules = OptionSelectionRules::forLinks($editableLinks, 'options');

        return $request->validate($rules)['options'] ?? [];
    }

    /**
     * The product's base unit price for a billing cycle — the same source the
     * cart resolver uses (product_pricing row, falling back to products.price).
     */
    private function baseUnitPrice(Product $product, string $cycle): float
    {
        $pricing = $product->pricing()->where('billing_cycle', $cycle)->first();

        return (float) ($pricing?->price ?? $product->price ?? 0);
    }
}
