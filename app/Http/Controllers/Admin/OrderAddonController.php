<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductAddon;
use App\Services\Billing\AddOnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Admin management of live add-ons on an existing order (T2.3).
 *
 * Thin by design: validate the request, resolve the parent service line,
 * delegate the attach/cancel to AddOnService — the single writer for the
 * add-on rows, the immediate invoice and the activity_log audit row — then
 * redirect to the order page. Guard violations raised by the service come
 * back as a flash error instead of a 500; the service stays the authority
 * on what may be attached and cancelled.
 */
class OrderAddonController extends Controller
{
    public function __construct(private readonly AddOnService $addons) {}

    public function store(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'addon_id' => ['required', 'integer', 'exists:product_addons,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.Order::MAX_QUANTITY],
            'parent_item_id' => ['sometimes', 'integer'],
        ]);

        $addon = ProductAddon::findOrFail($validated['addon_id']);

        if ($addon->status !== 'active') {
            return back()->withInput()->withErrors(['addon_id' => 'The selected add-on is not active.']);
        }

        if (array_key_exists('parent_item_id', $validated)) {
            $parent = $order->items()->whereKey($validated['parent_item_id'])->first();

            if ($parent === null || $parent->isAddon()) {
                return back()->withInput()->withErrors(['parent_item_id' => 'The selected parent item is not a service line on this order.']);
            }
        } else {
            // Default target: the order's first non-add-on service line.
            $parent = $order->items()
                ->whereNull('product_addon_id')
                ->orderBy('id')
                ->first();

            if ($parent === null) {
                return back()->withInput()->withErrors(['addon_id' => 'This order has no service line to attach the add-on to.']);
            }
        }

        try {
            $this->addons->attach($order, $parent, $addon, (int) $validated['quantity'], $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['addon_id' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.orders.show', $order)
            ->with('success', "Add-on {$addon->name} attached to order {$order->order_number}.");
    }

    public function destroy(Request $request, Order $order, OrderItem $orderItem): RedirectResponse
    {
        abort_unless((int) $orderItem->order_id === (int) $order->id, 404);
        abort_unless($orderItem->isAddon(), 404);

        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $this->addons->cancel($orderItem, $validated['reason'] ?? null, $request->user());

        return back()->with('success', "Add-on {$orderItem->product_name} cancelled.");
    }
}
