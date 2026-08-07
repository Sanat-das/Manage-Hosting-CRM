<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\OrderNumberService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    public function __construct(private readonly OrderNumberService $orderNumbers) {}

    public function index(): View
    {
        $groups = ProductGroup::with(['products' => fn ($q) => $q
            ->where('status', 'active')
            ->where('show_in_order', true)
            ->where('only_admin', false)
            ->whereNotIn('type', ['domain', 'addon'])])
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
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'billing_cycle' => ['required', 'string', 'in:'.implode(',', Order::BILLING_CYCLES)],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'domain' => ['nullable', 'string', 'max:255'],
        ]);

        $cart = session()->get('cart', []);
        $product = Product::find($validated['product_id']);

        $last = null;
        foreach ($cart as $k => $item) {
            if ((int) ($item['product_id'] ?? 0) === $product->id
                && ($item['billing_cycle'] ?? null) === $validated['billing_cycle']
                && ($item['domain'] ?? null) === ($validated['domain'] ?? null)) {
                $cart[$k]['quantity'] += $validated['quantity'];
                $last = $k;
                break;
            }
            $last = $k;
        }

        if ($last === null) {
            $cart[] = $validated;
        }

        session()->put('cart', $cart);

        return redirect()->route('client.store.index')->with('success', "{$product->name} added to cart.");
    }

    public function updateCart(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'index' => ['required', 'integer', 'min:0'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        $cart = session()->get('cart', []);
        if (isset($cart[$validated['index']])) {
            $cart[$validated['index']]['quantity'] = $validated['quantity'];
            session()->put('cart', $cart);
        }

        return redirect()->route('client.store.cart')->with('success', 'Cart updated.');
    }

    public function removeFromCart(Request $request): RedirectResponse
    {
        $index = $request->input('index');
        $cart = session()->get('cart', []);
        if (isset($cart[$index])) {
            unset($cart[$index]);
            session()->put('cart', array_values($cart));
        }

        return redirect()->route('client.store.cart')->with('success', 'Item removed from cart.');
    }

    public function cart(): View
    {
        $items = $this->resolveCartItems(session()->get('cart', []));

        return view('client.store.cart', compact('items'));
    }

    public function checkout(): View
    {
        $items = $this->resolveCartItems(session()->get('cart', []));
        if ($items === []) {
            return redirect()->route('client.store.cart')->with('error', 'Your cart is empty.');
        }

        return view('client.store.checkout', compact('items'));
    }

    public function placeOrder(Request $request): RedirectResponse
    {
        $items = $this->resolveCartItems(session()->get('cart', []));
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
                        'quantity' => $item['quantity'],
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

        $confirm = count($orders) === 1 ? $orders[0] : $orders[0];

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
     * @param  array<int, array<string, mixed>>  $cart
     * @return array<int, array{product: Product, cycle: string, quantity: int, unit_price: float, total: float, domain: ?string}>
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

            $pricing = $product->pricing()->where('billing_cycle', $cycle)->first();
            $unitPrice = (float) ($pricing?->price ?? $product->price ?? 0);

            $items[] = [
                'product' => $product,
                'cycle' => $cycle,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total' => round($unitPrice * $quantity, 2),
                'domain' => $entry['domain'] ?? null,
            ];
        }

        return $items;
    }
}
