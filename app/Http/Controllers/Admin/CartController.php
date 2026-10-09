<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\SanitizesSessionCart;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Billing\AddOnService;
use App\Services\Billing\BillingService;
use App\Services\OptionPricingResolver;
use App\Services\OrderActivityLogger;
use App\Services\OrderConfigSnapshot;
use App\Services\OrderNumberService;
use App\Services\ProductBundlePricingService;
use App\Support\Logging\AppLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin shopping cart + order placement (Session 3B.1, gap-fillup T1.1).
 *
 * The cart was originally a stub browsing the empty `catalog_products` table
 * with a dead "Place Order" button. Per the gap-fillup plan (and the client
 * storefront plan it mirrors), the cart now browses the real `Product` catalog
 * — the model `Order`/`OrderItem` actually reference — and `placeOrder()`
 * converts the session cart into Order + OrderItem rows with race-safe
 * numbers from OrderNumberService (T1.3).
 */
class CartController extends Controller
{
    use SanitizesSessionCart;

    public function __construct(
        private readonly OrderNumberService $orderNumbers,
        private readonly ProductBundlePricingService $bundlePricing,
        private readonly BillingService $billing,
        private readonly AddOnService $addons,
        private readonly OrderConfigSnapshot $snapshot,
        private readonly OptionPricingResolver $optionPricing,
    ) {}

    public function index(Request $request): View
    {
        $groups = ProductGroup::with(['products' => fn ($q) => $q->where('status', 'active')->where('show_in_order', true)])
            ->orderBy('sort_order')
            ->get();
        $categories = $groups->filter(fn ($g) => $g->products->isNotEmpty());

        return view('admin.cart.index', compact('categories'));
    }

    public function domainSearch(Request $request): View
    {
        $domain = $request->query('domain', '');
        $results = [];
        if ($domain) {
            // Stub: check domain availability via registrar service
            $extensions = ['.com', '.net', '.org', '.in', '.co'];
            foreach ($extensions as $ext) {
                $checked = $domain.$ext;
                $results[] = [
                    'domain' => $checked,
                    'available' => rand(0, 1) == 1, // stub
                    'price' => number_format(rand(800, 1500) / 100, 2),
                ];
            }
        }

        return view('admin.cart.domain-search', compact('domain', 'results'));
    }

    public function productDetail(Product $product): View
    {
        $product->load('group', 'pricing');

        return view('admin.cart.product-detail', compact('product'));
    }

    public function addToCart(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('status', 'active')],
            'billing_cycle' => ['nullable', 'string', Rule::in(Order::BILLING_CYCLES)],
            'domain' => ['nullable', 'string', 'max:255'],
            'addons' => ['sometimes', 'array', 'max:20'],
            'addons.*.addon_id' => ['required', 'integer', 'exists:product_addons,id'],
            'addons.*.quantity' => ['required', 'integer', 'min:1', 'max:'.Order::MAX_QUANTITY],
        ]);
        $cart = $this->sanitizeCart();

        $product = Product::find($validated['product_id']);
        $cycle = $validated['billing_cycle'] ?? $product->billing_cycle ?? 'monthly';
        if (! in_array($cycle, Order::BILLING_CYCLES, true)) {
            $cycle = 'monthly';
        }

        // Server-side defence: an add-on is orderable here only when it is
        // active and scoped to this product (or global). Never trust the form.
        // Bundle-expanded lines carry no add-on UI, so selections only apply
        // to the single-product path below.
        $addonSelections = $this->normalizeAddonSelections($validated['addons'] ?? []);
        if ($addonSelections !== [] && $product !== null) {
            $orderableIds = $this->addons->applicableFor($product)->pluck('id')->map(fn ($id) => (int) $id)->all();
            foreach ($addonSelections as $selection) {
                if (! in_array((int) $selection['addon_id'], $orderableIds, true)) {
                    return back()->withErrors(['addons' => 'The selected add-on is not available for this product.'])->withInput();
                }
            }
        }

        if ($product !== null && $product->isBundle()) {
            $quote = $this->bundlePricing->priceFor($product, $cycle);
            if ($quote === null) {
                return back()
                    ->with('error', "Bundle {$product->name} has no pricing for the ".ucfirst(str_replace('_', ' ', $cycle)).' cycle.');
            }

            foreach ($quote['line_items'] as $line) {
                $quantity = max(1, (int) $line['quantity']);
                $cart[] = [
                    'product_id' => $line['product_id'],
                    'billing_cycle' => $cycle,
                    'domain' => $validated['domain'] ?? null,
                    'quantity' => $quantity,
                    'unit_price' => round($line['total'] / $quantity, 2),
                    'total' => round($line['total'], 2),
                    'bundle_id' => $product->id,
                    'bundle_name' => $product->name,
                ];
            }
        } else {
            // Single-unit products are sold one unit per order — force qty 1
            // into the cart entry too. The add-on set rides on the entry
            // (default []) so the resolver can materialize it at order time.
            // This cart is append-only (no merge identity), so each entry
            // keeps its own add-on set.
            $cart[] = array_merge($validated, $product !== null && $product->isSingleUnit()
                ? ['quantity' => 1]
                : [], ['addons' => $addonSelections]);
        }

        session()->put('cart', $cart);

        return redirect()->route('admin.cart.index')->with('success', 'Item added to cart.');
    }

    public function removeFromCart(Request $request): RedirectResponse
    {
        $index = $request->input('index');
        $cart = $this->sanitizeCart();
        if (isset($cart[$index])) {
            unset($cart[$index]);
            session()->put('cart', array_values($cart));
        }

        return redirect()->route('admin.cart.index')->with('success', 'Item removed from cart.');
    }

    public function checkout(): View
    {
        $cart = $this->sanitizeCart();
        $items = $this->resolveCartItems($cart);

        // The add-on selections riding on a cart entry are charged at order
        // time by AddOnService::materialize(). Expand them into preview rows
        // here too — recurring plus setup — or the operator approves a total
        // below what the customer is billed.
        $previewRows = [];
        foreach ($items as $item) {
            if (($item['addons'] ?? []) === []) {
                continue;
            }

            $previewRows[] = $this->addons->previewLinesFor($item['product'], $item['cycle'], $item['addons']);
        }

        $items = array_merge($items, ...$previewRows);

        $customers = Customer::query()
            ->with('user:id,email,first_name,last_name')
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        return view('admin.cart.checkout', compact('items', 'customers'));
    }

    /**
     * Convert the session cart into Order + OrderItem rows (gap-fillup T1.1).
     *
     * One Order per cart line — the orders schema has a NOT NULL product_id
     * (single primary product per order), matching OrderController::store and
     * the client storefront's placeOrder. Totals resolve through ProductPricing
     * for the selected billing cycle (falling back to the product's default
     * price), never hardcoded.
     */
    public function placeOrder(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
        ]);

        $cart = $this->sanitizeCart();
        if ($cart === []) {
            return back()->withErrors(['cart' => 'Your cart is empty — nothing to order.']);
        }

        $items = $this->resolveCartItems($cart);
        if ($items === []) {
            return back()->withErrors(['cart' => 'Your cart contains no orderable products.']);
        }

        try {
            $orders = DB::transaction(function () use ($items, $validated) {
                $created = [];
                foreach ($items as $item) {
                    $order = Order::create([
                        'customer_id' => $validated['customer_id'],
                        'product_id' => $item['product']->id,
                        'order_number' => $this->orderNumbers->next(),
                        'billing_cycle' => $item['cycle'],
                        'quantity' => $item['quantity'],
                        'total' => $item['total'],
                        'status' => Order::STATUS_PENDING,
                        'domain_name' => $item['domain'] ?? null,
                    ]);

                    $parentItem = OrderItem::create([
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
                        'config_options' => $this->snapshot->capture($item['product'], null, [], $item['cycle']),
                    ]);

                    // Order-time add-on selections become their own order
                    // items BEFORE the draft invoice is created below.
                    $this->addons->materialize($order, $parentItem, $item['addons'] ?? []);

                    // The add-on rows above are billable lines too: the order
                    // total is the sum of ALL its rows (relation query, so it
                    // never depends on a loaded relation).
                    $order->update(['total' => round((float) $order->items()->sum('total'), 2)]);

                    // Same draft-invoice convention as the admin order form and
                    // the client storefront: each order is immediately billable.
                    $order->load('items');
                    $this->billing->createInvoiceForOrder($order);

                    // Customer-facing trail: every cart order writes the same
                    // order_created row as the other entry points.
                    OrderActivityLogger::created($order);

                    $created[] = $order;
                }

                return $created;
            });
        } catch (\Throwable $e) {
            AppLog::billing()->error('Cart order placement failed', ['exception' => $e]);

            return back()->withErrors(['error' => 'Could not place the order. Please try again or contact support.']);
        }

        session()->forget('cart');

        $first = $orders[0];
        $count = count($orders);

        return redirect()
            ->route('admin.orders.show', $first)
            ->with('success', "Order {$first->order_number} placed (pending)".($count > 1 ? ' + '.($count - 1).' more.' : '.'));
    }

    /**
     * Resolve session cart entries into products with cycle/quantity/price/
     * total. Only active, orderable (show_in_order) products are kept.
     *
     * @param  array<int, array<string, mixed>>  $cart
     * @return array<int, array{product: Product, cycle: string, quantity: int, unit_price: float, total: float, domain: ?string, addons: array}>
     */
    private function resolveCartItems(array $cart): array
    {
        $items = [];
        foreach ($cart as $entry) {
            $product = Product::query()
                ->where('status', 'active')
                ->where('show_in_order', true)
                ->find($entry['product_id'] ?? null);
            if ($product === null) {
                continue;
            }

            $cycle = $entry['billing_cycle'] ?? $product->billing_cycle ?? 'monthly';
            if (! in_array($cycle, Order::BILLING_CYCLES, true)) {
                $cycle = 'monthly';
            }

            $quantity = max(1, (int) ($entry['quantity'] ?? 1));

            // Single-unit products are always one unit per order — clamp at the
            // resolution point so placeOrder() can never persist qty > 1.
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
                    'addons' => $entry['addons'] ?? [],
                ];

                continue;
            }

            $pricing = $product->pricing()->where('billing_cycle', $cycle)->first();
            $unitPrice = (float) ($pricing?->price ?? $product->price ?? 0);

            // The admin cart offers no option UI, but a product's FIXED
            // options are features it ships with — charged here exactly as the
            // storefront, the admin order form and the API charge them, so the
            // same product cannot cost less depending on who ordered it. Free
            // products never charge.
            if (($product->payment_type ?? 'recurring') !== 'free') {
                $unitPrice += $this->optionPricing->adjustment($product, [], $cycle);
            }

            $items[] = [
                'product' => $product,
                'cycle' => $cycle,
                'quantity' => $quantity,
                'unit_price' => round($unitPrice, 2),
                'total' => round($unitPrice * $quantity, 2),
                'domain' => $entry['domain'] ?? null,
                'addons' => $entry['addons'] ?? [],
            ];
        }

        return $items;
    }

    /**
     * Normalize an add-on selection set for storage: integer-cast, keyed
     * shape, sorted by addon_id (order-insensitive).
     *
     * @param  array<int, array<string, mixed>>  $selections
     * @return array<int, array{addon_id: int, quantity: int}>
     */
    private function normalizeAddonSelections(array $selections): array
    {
        $normalized = array_map(fn (array $selection) => [
            'addon_id' => (int) ($selection['addon_id'] ?? 0),
            'quantity' => max(1, (int) ($selection['quantity'] ?? 1)),
        ], array_values($selections));

        usort($normalized, fn (array $a, array $b) => $a['addon_id'] <=> $b['addon_id']);

        return $normalized;
    }
}
