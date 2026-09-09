<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Client portal — order listing and detail.
 *
 * The customer had no way to see their own orders. The storefront gave them a
 * one-shot confirmation page and then nothing: a pending order awaiting payment,
 * or one that failed provisioning, was invisible to the person who placed it.
 * The seeded order_confirmation email even links to /client/orders, which until
 * now was a 404.
 *
 * Read-only by design. Everything that changes an order — activating,
 * cancelling, terminating — goes through the guarded state machine from the
 * admin side (OrderService); nothing here writes.
 */
class OrderController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): View
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $orders = $customer->orders()
            ->with(['product:id,name', 'items:id,order_id,product_name,quantity,total'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    // Item product names are searched as well as the order's own
                    // product: an order placed from the storefront cart carries
                    // its products on the items, and older ones only on the
                    // order header.
                    $q->where('order_number', 'like', "%{$search}%")
                        ->orWhere('domain_name', 'like', "%{$search}%")
                        ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('items', fn ($i) => $i->where('product_name', 'like', "%{$search}%"));
                });
            })
            ->when(in_array($status, Order::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->gridSort([
                'order_number' => 'order_number',
                'product' => 'product.name',
                'total' => 'total',
                'status' => 'status',
                'created_at' => 'created_at',
            ])
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('client.orders.index', compact('orders', 'search', 'status'));
    }

    public function show(Request $request, int $id): View
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        // Scoped to the customer's own orders, so another customer's order is a
        // 404 rather than a 403 that confirms it exists.
        $order = $customer->orders()
            ->with([
                'product:id,name',
                'items.product:id,name',
                'invoices' => fn ($q) => $q->orderByDesc('id'),
                'hostingAccount:id,order_id,domain,status',
            ])
            ->findOrFail($id);

        return view('client.orders.show', [
            'order' => $order,
            'timeline' => $this->timelineFor($order),
        ]);
    }

    /**
     * The order's status history as the customer may see it — what it changed
     * to and when, oldest first.
     *
     * `order_status_history.notes` and the acting user are deliberately
     * dropped: those rows are an internal audit trail written by admins and by
     * background jobs, and the notes are free text nobody writes expecting a
     * customer to read it.
     *
     * @return array<int, array{status: string, at: ?Carbon}>
     */
    private function timelineFor(Order $order): array
    {
        return $order->statusHistory()
            ->orderBy('id')
            ->get(['id', 'to_status', 'created_at'])
            ->map(fn ($row) => ['status' => (string) $row->to_status, 'at' => $row->created_at])
            ->all();
    }
}
