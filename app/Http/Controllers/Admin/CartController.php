<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogProduct;
use App\Models\ProductGroup;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CartController extends Controller
{
    public function index(Request $request): View
    {
        $groups = ProductGroup::with(['catalogProducts' => fn($q) => $q->where('status', 'active')->where('show_in_order', true)])
            ->orderBy('sort_order')
            ->get();
        $categories = $groups->filter(fn($g) => $g->catalogProducts->isNotEmpty());
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
                $checked = $domain . $ext;
                $results[] = [
                    'domain' => $checked,
                    'available' => rand(0, 1) == 1, // stub
                    'price' => number_format(rand(800, 1500) / 100, 2),
                ];
            }
        }
        return view('admin.cart.domain-search', compact('domain', 'results'));
    }

    public function productDetail(CatalogProduct $product): View
    {
        $product->load('category');
        $configurableOptions = $product->configurableOptions ?? [];
        $pricingTiers = $product->pricingTiers ?? [];
        return view('admin.cart.product-detail', compact('product', 'configurableOptions', 'pricingTiers'));
    }

    public function addToCart(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:catalog_products,id'],
            'configurable_options' => ['nullable', 'array'],
            'domain' => ['nullable', 'string', 'max:255'],
        ]);
        $cart = session()->get('cart', []);
        $cart[] = $validated;
        session()->put('cart', $cart);
        return redirect()->route('admin.cart.index')->with('success', 'Item added to cart.');
    }

    public function removeFromCart(Request $request): \Illuminate\Http\RedirectResponse
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
        $items = [];
        foreach ($cart as $item) {
            $product = CatalogProduct::find($item['product_id']);
            if ($product) {
                $items[] = ['product' => $product, 'options' => $item['configurable_options'] ?? [], 'domain' => $item['domain'] ?? null];
            }
        }
        return view('admin.cart.checkout', compact('items'));
    }
}
