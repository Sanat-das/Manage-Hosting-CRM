<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ServerGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin product management (CRUD + groups + multi-cycle pricing +
 * configurable options + addons).
 *
 * Ported from the reference CRM:
 * - the `products` row keeps the legacy price/setup_fee/billing_cycle columns
 *   (the default cycle), while the full price ladder lives in
 *   `product_pricing` rows (term months + price + promo).
 * - deletion is guarded: products referenced by active/pending orders cannot
 *   be deleted (decisions.md #10).
 */
class ProductController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');
        $groupId = $request->query('group_id');

        $products = Product::query()
            ->with('group')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->when(in_array($status, ['active', 'inactive'], true), fn ($query) => $query->where('status', $status))
            ->when($groupId !== null && $groupId !== '', fn ($query) => $query->where('product_group_id', $groupId))
            ->withCount('orders')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $groups = ProductGroup::query()->orderBy('sort_order')->orderBy('name')->get();

        return view('admin.products.index', compact('products', 'search', 'status', 'groupId', 'groups'));
    }

    public function create(): View
    {
        return view('admin.products.create', $this->formData());
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $product = DB::transaction(function () use ($validated) {
                $product = Product::create($this->productData($validated));
                $this->savePricing($product, $validated['pricing'] ?? []);

                return $product;
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not create product: '.$e->getMessage()]);
        }

        return redirect()
            ->route('admin.products.show', $product)
            ->with('success', "Product {$product->name} created.");
    }

    public function show(Product $product): View
    {
        $product->load([
            'group',
            'pricing',
            'options' => fn ($query) => $query
                ->with(['values' => fn ($v) => $v->with('pricing')->orderBy('sort_order')])
                ->orderBy('sort_order'),
            'addons' => fn ($query) => $query->orderBy('name'),
        ]);

        return view('admin.products.show', compact('product'));
    }

    public function edit(Product $product): View
    {
        $product->load(['group', 'pricing']);

        return view('admin.products.edit', array_merge(['product' => $product], $this->formData()));
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $validated = $request->validated();

        try {
            DB::transaction(function () use ($validated, $product) {
                $product->update($this->productData($validated));
                $this->savePricing($product, $validated['pricing'] ?? []);
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not update product: '.$e->getMessage()]);
        }

        return redirect()
            ->route('admin.products.show', $product)
            ->with('success', "Product {$product->name} updated.");
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $hasLiveOrders = Order::query()
            ->where('product_id', $product->id)
            ->whereIn('status', ['active', 'pending'])
            ->exists();

        if ($hasLiveOrders) {
            return back()
                ->with('error', "Cannot delete product {$product->name}: it has active or pending orders.");
        }

        $product->delete(); // cascades to pricing, options, addons, meta

        return redirect()
            ->route('admin.products.index')
            ->with('success', "Product {$product->name} deleted.");
    }

    /**
     * Shared select-list data for the create/edit forms.
     *
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'types' => Product::TYPES,
            'cycles' => Product::BILLING_CYCLES,
            'defaultCycles' => Product::DEFAULT_CYCLES,
            'provisioningModules' => Product::PROVISIONING_MODULES,
            'gstTypes' => Product::GST_TYPES,
            'groups' => ProductGroup::query()->orderBy('sort_order')->orderBy('name')->get(),
            'serverGroups' => ServerGroup::query()->where('status', 'active')->orderBy('name')->get(),
            'emailTemplates' => EmailTemplate::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
        ];
    }

    /**
     * Map validated input onto the products row. The legacy price / setup_fee
     * columns mirror the selected default billing cycle.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function productData(array $validated): array
    {
        $pricing = $validated['pricing'] ?? [];
        $cycle = $validated['billing_cycle'];
        $defaultPrice = $pricing[$cycle]['price'] ?? null;
        $defaultSetupFee = $pricing[$cycle]['setup_fee'] ?? null;

        $data = [
            'name' => $validated['name'],
            'type' => $validated['type'],
            'product_group_id' => $validated['product_group_id'] ?? null,
            'description' => $validated['description'] ?? null,
            'billing_cycle' => $cycle,
            'price' => $defaultPrice !== null && $defaultPrice !== '' ? $defaultPrice : 0,
            'setup_fee' => $defaultSetupFee !== null && $defaultSetupFee !== '' ? $defaultSetupFee : 0,
            'provisioning_module' => $validated['provisioning_module'],
            'server_group_id' => $validated['server_group_id'] ?? null,
            'welcome_email_template_id' => $validated['welcome_email_template_id'] ?? null,
            'require_domain' => (bool) ($validated['require_domain'] ?? false),
            'show_in_order' => (bool) ($validated['show_in_order'] ?? false),
            'show_in_affiliate' => (bool) ($validated['show_in_affiliate'] ?? false),
            'only_admin' => (bool) ($validated['only_admin'] ?? false),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'status' => $validated['status'],
            'gst_enabled' => (bool) ($validated['gst_enabled'] ?? false),
            'gst_rate' => $validated['gst_rate'] ?? null,
            'gst_type' => $validated['gst_type'],
            'cgst_rate' => $validated['cgst_rate'] ?? null,
            'sgst_rate' => $validated['sgst_rate'] ?? null,
            'igst_rate' => $validated['igst_rate'] ?? null,
        ];

        foreach (['quota_disk', 'quota_bandwidth', 'quota_email', 'quota_database', 'quota_cpu_cores', 'quota_cpu_speed', 'quota_ram', 'quota_ips', 'quota_ftp_accounts', 'quota_subdomains'] as $quota) {
            $data[$quota] = (int) ($validated[$quota] ?? 0);
        }

        return $data;
    }

    /**
     * Replace the product's pricing ladder (reference: ProductPricingModel::savePricing).
     *
     * @param  array<string, mixed>  $pricing
     */
    private function savePricing(Product $product, array $pricing): void
    {
        $product->pricing()->delete();

        foreach (Product::BILLING_CYCLES as $cycle => $label) {
            $row = $pricing[$cycle] ?? [];
            $price = $row['price'] ?? null;

            // Skip cycles with no price unless the cycle is 'free'.
            if ($cycle !== 'free' && ($price === null || $price === '')) {
                continue;
            }

            $product->pricing()->create([
                'billing_cycle' => $cycle,
                'setup_fee' => $row['setup_fee'] ?? 0,
                'price' => $price !== null && $price !== '' ? $price : 0,
                'promo_price' => ! empty($row['promo_price']) ? $row['promo_price'] : null,
                'promo_start' => ! empty($row['promo_start']) ? $row['promo_start'] : null,
                'promo_end' => ! empty($row['promo_end']) ? $row['promo_end'] : null,
            ]);
        }
    }
}
