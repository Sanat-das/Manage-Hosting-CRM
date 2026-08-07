<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\OrderNumberService;
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
    public function __construct(private readonly OrderNumberService $orderNumbers) {}

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
        ]);
        $cart = session()->get('cart', []);
        $cart[] = $validated;
        session()->put('cart', $cart);

        return redirect()->route('admin.cart.index')->with('success', 'Item added to cart.');
    }

    public function removeFromCart(Request $request): RedirectResponse
    {
        $index = $request->input('index');
        $cart = session()->get('cart', []);
        if (isset($cart[$index])) {
            unset($cart[$index]);
            session()->put('cart', array_values($cart));
        }

        return redirect()->route('admin.cart.index')->with('success', 'Item removed from cart.');
    }

    public function checkout(): View
    {
        $cart = session()->get('cart', []);
        $items = $this->resolveCartItems($cart);
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

        $cart = session()->get('cart', []);
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
                        'quantity' => 1,
                        'total' => $item['total'],
                        'status' => Order::STATUS_PENDING,
                        'domain_name' => $item['domain'] ?? null,
                    ]);

                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $item['product']->id,
                        'product_name' => $item['product']->name,
                        'quantity' => 1,
                        'unit_price' => $item['unit_price'],
                        'total' => $item['total'],
                    ]);

                    $created[] = $order;
                }

                return $created;
            });
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => 'Could not place order: '.$e->getMessage()]);
        }

        session()->forget('cart');

        $first = $orders[0];
        $count = count($orders);

        return redirect()
            ->route('admin.orders.show', $first)
            ->with('success', "Order {$first->order_number} placed (pending)".($count > 1 ? ' + '.($count - 1).' more.' : '.'));
    }

    /**
     * Resolve session cart entries into products with cycle/unit-price/total.
     * Only active, orderable (show_in_order) products are kept.
     *
     * @param  array<int, array<string, mixed>>  $cart
     * @return array<int, array{product: Product, cycle: string, unit_price: float, total: float, domain: ?string}>
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

            $pricing = $product->pricing()->where('billing_cycle', $cycle)->first();
            $unitPrice = (float) ($pricing?->price ?? $product->price ?? 0);

            $items[] = [
                'product' => $product,
                'cycle' => $cycle,
                'unit_price' => $unitPrice,
                'total' => round($unitPrice, 2),
                'domain' => $entry['domain'] ?? null,
            ];
        }

        return $items;
    }
}
