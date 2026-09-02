<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddonRequest;
use App\Models\EmailTemplate;
use App\Models\Product;
use App\Models\ProductAddon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin product add-ons.
 *
 * Ported from the reference ProductAddonModel: an add-on is either attached
 * to a single product (product_id set) or global (product_id NULL), with its
 * own billing cycle, setup fee and price.
 */
class AddonController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');
        $productId = $request->query('product_id');

        $addons = ProductAddon::query()
            ->with('product:id,name')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->when(in_array($status, ['active', 'inactive'], true), fn ($query) => $query->where('status', $status))
            ->when($productId !== null && $productId !== '', fn ($query) => $query->where('product_id', $productId))
            ->gridSort([
                'name' => 'name',
                'product' => 'product.name',
                'cycle' => 'billing_cycle',
                'setup_fee' => 'setup_fee',
                'price' => 'price',
                'status' => 'status',
                'created_at' => 'created_at',
            ])
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $products = Product::query()->orderBy('name')->get(['id', 'name']);

        return view('admin.addons.index', compact('addons', 'search', 'status', 'products', 'productId'));
    }

    public function create(): View
    {
        return view('admin.addons.create', $this->formData());
    }

    public function store(AddonRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $addon = ProductAddon::create([
                'product_id' => $validated['product_id'] ?? null,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'billing_cycle' => $validated['billing_cycle'],
                'setup_fee' => $validated['setup_fee'] ?? 0,
                'price' => $validated['price'] ?? 0,
                'welcome_email_template_id' => $validated['welcome_email_template_id'] ?? null,
                'status' => $validated['status'],
            ]);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not create add-on: '.$e->getMessage()]);
        }

        return redirect()
            ->route('admin.addons.index')
            ->with('success', "Add-on {$addon->name} created.");
    }

    public function edit(ProductAddon $addon): View
    {
        return view('admin.addons.edit', array_merge(['addon' => $addon], $this->formData()));
    }

    public function update(AddonRequest $request, ProductAddon $addon): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $addon->update([
                'product_id' => $validated['product_id'] ?? null,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'billing_cycle' => $validated['billing_cycle'],
                'setup_fee' => $validated['setup_fee'] ?? 0,
                'price' => $validated['price'] ?? 0,
                'welcome_email_template_id' => $validated['welcome_email_template_id'] ?? null,
                'status' => $validated['status'],
            ]);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not update add-on: '.$e->getMessage()]);
        }

        return redirect()
            ->route('admin.addons.index')
            ->with('success', "Add-on {$addon->name} updated.");
    }

    public function destroy(Request $request, ProductAddon $addon): RedirectResponse
    {
        $addon->delete();

        return redirect()
            ->route('admin.addons.index')
            ->with('success', "Add-on {$addon->name} deleted.");
    }

    /**
     * Shared select-list data for the create/edit forms.
     *
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'products' => Product::query()->orderBy('name')->get(['id', 'name']),
            'cycles' => [
                'one_time' => 'One Time',
                'monthly' => 'Monthly',
                'quarterly' => 'Quarterly',
                'semi_annual' => 'Semi-Annual',
                'annual' => 'Annual',
            ],
            'emailTemplates' => EmailTemplate::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name']),
        ];
    }
}
