<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sanctum-protected products REST API (Session 3A.2).
 *
 * Mirrors the reference /api/products endpoints (index/show/store/update/
 * destroy). Reuses the same ProductRequest validation and the same save
 * semantics as the admin ProductController: the `products` row keeps the
 * legacy price/setup_fee/billing_cycle columns (the default cycle) while the
 * full price ladder lives in `product_pricing` rows. Deletion is guarded the
 * same way — products referenced by active/pending orders are rejected.
 */
class ProductController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');
        $groupId = $request->query('group_id');

        $products = Product::query()
            ->with('group:id,name')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->when(in_array($status, ['active', 'inactive'], true), fn ($query) => $query->where('status', $status))
            ->when($groupId !== null && $groupId !== '', fn ($query) => $query->where('product_group_id', $groupId))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => $products->map(fn (Product $product) => $this->present($product)),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $product = DB::transaction(function () use ($validated) {
            $product = Product::create($this->productData($validated));
            $this->savePricing($product, $validated['pricing'] ?? []);

            return $product;
        });

        return response()->json([
            'data' => $this->present($product->fresh()->load(['group', 'pricing']), true),
        ], 201);
    }

    public function show(Product $product): JsonResponse
    {
        $product->load([
            'group',
            'pricing',
            'options' => fn ($query) => $query
                ->with(['values' => fn ($v) => $v->with('pricing')->orderBy('sort_order')])
                ->orderBy('sort_order'),
            'addons' => fn ($query) => $query->orderBy('name'),
        ]);

        return response()->json(['data' => $this->present($product, true)]);
    }

    public function update(ProductRequest $request, Product $product): JsonResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $product) {
            $product->update($this->productData($validated));
            $this->savePricing($product, $validated['pricing'] ?? []);
        });

        return response()->json([
            'data' => $this->present($product->fresh()->load(['group', 'pricing']), true),
        ]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $hasLiveOrders = Order::query()
            ->where('product_id', $product->id)
            ->whereIn('status', ['active', 'pending'])
            ->exists();

        if ($hasLiveOrders) {
            return response()->json([
                'message' => "Cannot delete product {$product->name}: it has active or pending orders.",
            ], 422);
        }

        $product->delete(); // cascades to pricing, options, addons, meta

        return response()->json(['message' => "Product {$product->name} deleted."]);
    }

    /**
     * API resource shape (mirrors the reference product fields).
     */
    private function present(Product $product, bool $detailed = false): array
    {
        $data = [
            'id' => $product->id,
            'name' => $product->name,
            'type' => $product->type,
            'type_label' => Product::TYPES[$product->type] ?? $product->type,
            'product_group_id' => $product->product_group_id,
            'group_name' => $product->group?->name,
            'description' => $product->description,
            'price' => (float) $product->price,
            'setup_fee' => (float) $product->setup_fee,
            'billing_cycle' => $product->billing_cycle,
            'provisioning_module' => $product->provisioning_module,
            'server_group_id' => $product->server_group_id,
            'welcome_email_template_id' => $product->welcome_email_template_id,
            'require_domain' => (bool) $product->require_domain,
            'show_in_order' => (bool) $product->show_in_order,
            'show_in_affiliate' => (bool) $product->show_in_affiliate,
            'only_admin' => (bool) $product->only_admin,
            'sort_order' => $product->sort_order,
            'status' => $product->status,
            'gst_enabled' => (bool) $product->gst_enabled,
            'gst_type' => $product->gst_type,
            'gst_rate' => $product->gst_rate !== null ? (float) $product->gst_rate : null,
            'cgst_rate' => $product->cgst_rate !== null ? (float) $product->cgst_rate : null,
            'sgst_rate' => $product->sgst_rate !== null ? (float) $product->sgst_rate : null,
            'igst_rate' => $product->igst_rate !== null ? (float) $product->igst_rate : null,
            'quota_disk' => (int) $product->quota_disk,
            'quota_bandwidth' => (int) $product->quota_bandwidth,
            'quota_email' => (int) $product->quota_email,
            'quota_database' => (int) $product->quota_database,
            'quota_cpu_cores' => (int) $product->quota_cpu_cores,
            'quota_cpu_speed' => (int) $product->quota_cpu_speed,
            'quota_ram' => (int) $product->quota_ram,
            'quota_ips' => (int) $product->quota_ips,
            'quota_ftp_accounts' => (int) $product->quota_ftp_accounts,
            'quota_subdomains' => (int) $product->quota_subdomains,
            'created_at' => $product->created_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['pricing'] = $product->pricing->map(fn ($row) => [
                'billing_cycle' => $row->billing_cycle,
                'setup_fee' => (float) $row->setup_fee,
                'price' => (float) $row->price,
                'promo_price' => $row->promo_price !== null ? (float) $row->promo_price : null,
                'promo_start' => $row->promo_start?->toDateString(),
                'promo_end' => $row->promo_end?->toDateString(),
            ]);

            $data['options'] = $product->options->map(fn ($option) => [
                'id' => $option->id,
                'name' => $option->name,
                'type' => $option->type,
                'sort_order' => $option->sort_order,
                'values' => $option->values->map(fn ($value) => [
                    'id' => $value->id,
                    'label' => $value->label,
                    'sort_order' => $value->sort_order,
                    'pricing' => $value->pricing->map(fn ($pricing) => [
                        'billing_cycle' => $pricing->billing_cycle,
                        'price_modifier' => (float) $pricing->price_modifier,
                    ]),
                ]),
            ]);

            $data['addons'] = $product->addons->map(fn ($addon) => [
                'id' => $addon->id,
                'name' => $addon->name,
                'description' => $addon->description,
                'billing_cycle' => $addon->billing_cycle,
                'setup_fee' => (float) $addon->setup_fee,
                'price' => (float) $addon->price,
                'status' => $addon->status,
            ]);
        }

        return $data;
    }

    /**
     * Map validated input onto the products row (same mapping as the admin
     * ProductController::productData — the legacy price/setup_fee columns
     * mirror the selected default billing cycle).
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
     * Replace the product's pricing ladder (same as admin ProductController
     * savePricing — reference ProductPricingModel::savePricing).
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
