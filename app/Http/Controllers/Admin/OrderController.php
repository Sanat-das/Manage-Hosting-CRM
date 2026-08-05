<?php

namespace App\Http\Controllers\Admin;

use App\Events\OrderCreated;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrderRequest;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin order management (Session 3A.2).
 *
 * Ported behavior from the reference CRM orders module:
 * - order number generation ORD-{YEAR}-{seq} (reference format)
 * - order + order_items row snapshot (product_name/unit_price/total)
 * - status workflow with a single guarded transition map (pending→active /
 *   pending→cancelled here; active→suspended is reserved for the hosting
 *   module later — it reuses the same updateStatus() method)
 * - pending→active seeds next_billing_date and dispatches OrderCreated
 *   (the automated provisioning trigger stub, consumed in Session 3B)
 */
class OrderController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * Allowed order status transitions (single source of truth for the whole
     * module). Terminal statuses (cancelled, terminated) have no targets.
     * `active → suspended` is intentionally present but NOT exposed in this
     * module's UI — the hosting module calls updateStatus() to suspend.
     */
    private const TRANSITIONS = [
        Order::STATUS_PENDING => [Order::STATUS_ACTIVE, Order::STATUS_CANCELLED],
        Order::STATUS_ACTIVE => [Order::STATUS_SUSPENDED],
        Order::STATUS_SUSPENDED => [Order::STATUS_ACTIVE, Order::STATUS_CANCELLED],
        Order::STATUS_CANCELLED => [],
        Order::STATUS_TERMINATED => [],
    ];

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $orders = Order::query()
            ->with(['customer.user', 'product'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%")
                        ->orWhereHas('customer.user', function ($u) use ($search) {
                            $u->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->when(in_array($status, Order::STATUSES, true), function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.orders.index', compact('orders', 'search', 'status'));
    }

    public function create(): View
    {
        $customers = Customer::query()
            ->with('user:id,email,first_name,last_name')
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        $products = Product::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        return view('admin.orders.create', compact('customers', 'products'));
    }

    public function store(OrderRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $order = DB::transaction(function () use ($validated) {
                $product = Product::findOrFail($validated['product_id']);
                $total = round((float) $validated['unit_price'] * (int) $validated['quantity'], 2);

                $order = Order::create([
                    'customer_id' => $validated['customer_id'],
                    'product_id' => $validated['product_id'],
                    'order_number' => $this->generateOrderNumber(),
                    'billing_cycle' => $validated['billing_cycle'],
                    'quantity' => $validated['quantity'],
                    'total' => $total,
                    'status' => Order::STATUS_PENDING,
                    'domain_name' => $validated['domain_name'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                ]);

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $validated['quantity'],
                    'unit_price' => $validated['unit_price'],
                    'total' => $total,
                ]);

                return $order;
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not create order: '.$e->getMessage()]);
        }

        return redirect()
            ->route('admin.orders.show', $order)
            ->with('success', "Order {$order->order_number} created (pending).");
    }

    public function show(Order $order): View
    {
        $order->load([
            'customer.user',
            'product',
            'items.product',
            'invoices' => fn ($q) => $q->latest(),
            'hostingAccount',
            'domain',
        ]);

        $activity = ActivityLog::query()
            ->where('customer_id', $order->customer_id)
            ->where('action', 'like', 'order.%')
            ->with('user')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return view('admin.orders.show', compact('order', 'activity'));
    }

    /**
     * Guarded status transition (activate / cancel / ...).
     *
     * The transition map is the ONLY place order statuses change; an invalid
     * move (e.g. cancelling an already-cancelled order) is rejected with a
     * validation error instead of silently corrupting the workflow.
     */
    public function updateStatus(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(Order::STATUSES)],
        ]);

        $target = $validated['status'];
        $allowed = self::TRANSITIONS[$order->status] ?? [];

        if (! in_array($target, $allowed, true)) {
            return back()->withErrors(['status' => "Cannot change order from '{$order->status}' to '{$target}'."]);
        }

        try {
            DB::transaction(function () use ($order, $target, $request) {
                $from = $order->status;
                $firstActivation = $from === Order::STATUS_PENDING && $target === Order::STATUS_ACTIVE;

                $order->update(['status' => $target]);

                if ($firstActivation) {
                    // Seed the recurring-billing schedule (orders WHERE
                    // status='active' AND next_billing_date <= today) and fire
                    // the provisioning trigger stub.
                    $order->update(['next_billing_date' => $this->nextBillingDate($order->billing_cycle)]);
                    OrderCreated::dispatch($order->fresh());
                }

                $this->logActivity($order, 'order_status_changed', "Order status changed from '{$from}' to '{$target}'", [
                    'from' => $from,
                    'to' => $target,
                    'by' => $request->user()?->email,
                ]);
            });
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => 'Could not update order status: '.$e->getMessage()]);
        }

        return redirect()
            ->route('admin.orders.show', $order)
            ->with('success', "Order {$order->order_number} is now {$order->status}.");
    }

    /**
     * Reference order number format: ORD-{YEAR}-{seq padded to 5}.
     */
    private function generateOrderNumber(): string
    {
        $year = date('Y');
        $seq = Order::query()->whereYear('created_at', $year)->count() + 1;
        $number = "ORD-{$year}-".str_pad((string) $seq, 5, '0', STR_PAD_LEFT);

        while (Order::query()->where('order_number', $number)->exists()) {
            $seq++;
            $number = "ORD-{$year}-".str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
        }

        return $number;
    }

    /**
     * Next billing date for a cycle; null for one-time orders (never re-billed).
     */
    private function nextBillingDate(string $cycle): ?string
    {
        $months = Order::CYCLE_MONTHS[$cycle] ?? 1;

        return $months > 0 ? now()->addMonths($months)->toDateString() : null;
    }

    /**
     * Order state changes are written to the customer's activity trail so
     * admins see the full audit history on the customer page.
     */
    private function logActivity(Order $order, string $action, string $description, array $metadata = []): void
    {
        ActivityLog::create([
            'customer_id' => $order->customer_id,
            'user_id' => auth()->id(),
            'action' => $action,
            'description' => $description,
            'metadata' => array_merge(['order_id' => $order->id, 'order_number' => $order->order_number], $metadata),
            'ip_address' => request()->ip(),
        ]);
    }
}
