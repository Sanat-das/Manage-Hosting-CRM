<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductBundle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin product bundles (Tier 4.4).
 *
 * A bundle is a Product flagged is_bundle plus its component rows in
 * `product_bundles`. Creating the bundle product itself is ProductController's
 * job; this controller manages which components make up a bundle and how they
 * are discounted. Price is derived at order time by ProductBundlePricingService.
 */
class ProductBundleController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = trim((string) $request->query('status'));

        $query = Product::query()
            ->where('is_bundle', true)
            ->withCount('bundleChildren');

        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }
        if ($status !== '') {
            $query->where('status', $status);
        }

        $bundles = $query
            ->gridSort([
                'name' => 'name',
                'components' => 'bundle_children_count',
                'status' => 'status',
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.product_bundles.index', compact('bundles', 'search', 'status'));
    }

    public function create(): View
    {
        return view('admin.product_bundles.create', $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());
        $bundleProductId = (int) $validated['bundle_product_id'];

        try {
            DB::transaction(function () use ($validated, $bundleProductId) {
                ProductBundle::where('bundle_product_id', $bundleProductId)->delete();

                foreach ($validated['components'] as $row) {
                    ProductBundle::create([
                        'bundle_product_id' => $bundleProductId,
                        'component_product_id' => $row['component_product_id'],
                        'quantity' => $row['quantity'],
                        'discount_type' => $row['discount_type'],
                        'discount_value' => $row['discount_value'],
                        'sort_order' => $row['sort_order'] ?? 0,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not save bundle: '.$e->getMessage()]);
        }

        $bundle = Product::find($bundleProductId);

        return redirect()
            ->route('admin.product-bundles.show', $bundle)
            ->with('success', "Bundle {$bundle->name} saved.");
    }

    public function show(Product $product): View
    {
        $product->load(['bundleChildren' => fn ($q) => $q->with('component')->orderBy('sort_order')->orderBy('id')]);

        return view('admin.product_bundles.show', ['bundle' => $product]);
    }

    public function edit(Product $product): View
    {
        $product->load(['bundleChildren' => fn ($q) => $q->with('component')->orderBy('sort_order')->orderBy('id')]);

        return view('admin.product_bundles.edit', array_merge(['bundle' => $product], $this->formData()));
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        try {
            DB::transaction(function () use ($validated, $product) {
                ProductBundle::where('bundle_product_id', $product->id)->delete();

                foreach ($validated['components'] as $row) {
                    ProductBundle::create([
                        'bundle_product_id' => $product->id,
                        'component_product_id' => $row['component_product_id'],
                        'quantity' => $row['quantity'],
                        'discount_type' => $row['discount_type'],
                        'discount_value' => $row['discount_value'],
                        'sort_order' => $row['sort_order'] ?? 0,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not update bundle: '.$e->getMessage()]);
        }

        return redirect()
            ->route('admin.product-bundles.show', $product)
            ->with('success', "Bundle {$product->name} updated.");
    }

    public function destroy(Product $product): RedirectResponse
    {
        ProductBundle::where('bundle_product_id', $product->id)->delete();

        return redirect()
            ->route('admin.product-bundles.index')
            ->with('success', "Components removed from bundle {$product->name}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'products' => Product::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name']),
            'bundles' => Product::query()
                ->where('is_bundle', true)
                ->orderBy('name')
                ->get(['id', 'name']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'bundle_product_id' => ['required', 'integer', 'exists:products,id'],
            'components' => ['required', 'array', 'min:1'],
            'components.*.component_product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'components.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'components.*.discount_type' => ['required', 'in:percent,fixed'],
            'components.*.discount_value' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'components.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
