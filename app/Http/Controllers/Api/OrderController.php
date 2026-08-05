<?php

namespace App\Http\Controllers\Api;

use App\Events\OrderCreated;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrderRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Sanctum-protected orders REST API (Session 3A.2).
 *
 * Mirrors the reference /api/orders endpoints: list (search/status filters),
 * store (creates a pending order + item snapshot), show, and the guarded
 * status update that dispatches OrderCreated on activation.
 */
class OrderController extends Controller
{
    private const PER_PAGE = 20;

    private const TRANSITIONS = [
        Order::STATUS_PENDING => [Order::STATUS_ACTIVE, Order::STATUS_CANCELLED],
        Order::STATUS_ACTIVE => [Order::STATUS_SUSPENDED],
        Order::STATUS_SUSPENDED => [Order::STATUS_ACTIVE, Order::STATUS_CANCELLED],
        Order::STATUS_CANCELLED => [],
        Order::STATUS_TERMINATED => [],
    ];

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $orders = Order::query()
            ->with(['customer.user:id,email,first_name,last_name', 'product'])
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
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => $orders->map(fn (Order $o) => $this->present($o)),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function store(OrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

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

        return response()->json([
            'data' => $this->present($order->load(['customer.user:id,email,first_name,last_name', 'product', 'items'])),
        ], 201);
    }

    public function show(Order $order): JsonResponse
    {
        $order->load([
            'customer.user:id,email,first_name,last_name',
            'product',
            'items',
            'invoices',
            'hostingAccount',
            'domain',
        ]);

        return response()->json(['data' => $this->present($order, true)]);
    }

    /**
     * Guarded status transition; same map as the admin controller. Returns
     * 422 for invalid transitions (e.g. cancel on an already-cancelled order).
     */
    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(Order::STATUSES)],
        ]);

        $target = $validated['status'];
        $allowed = self::TRANSITIONS[$order->status] ?? [];

        if (! in_array($target, $allowed, true)) {
            return response()->json([
                'message' => "Cannot change order from '{$order->status}' to '{$target}'.",
            ], 422);
        }

        $from = $order->status;
        $firstActivation = $from === Order::STATUS_PENDING && $target === Order::STATUS_ACTIVE;

        $order->update(['status' => $target]);

        if ($firstActivation) {
            $order->update(['next_billing_date' => $this->nextBillingDate($order->billing_cycle)]);
            OrderCreated::dispatch($order->fresh());
        }

        return response()->json([
            'data' => $this->present($order->fresh()->load(['customer.user:id,email,first_name,last_name', 'product', 'items'])),
        ]);
    }

    /**
     * API resource shape (mirrors the reference order fields).
     */
    private function present(Order $order, bool $detailed = false): array
    {
        $data = [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'customer_id' => $order->customer_id,
            'customer_name' => $order->customer?->full_name,
            'customer_email' => $order->customer?->user?->email,
            'product_id' => $order->product_id,
            'product_name' => $order->product?->name,
            'billing_cycle' => $order->billing_cycle,
            'quantity' => $order->quantity,
            'total' => (float) $order->total,
            'status' => $order->status,
            'domain_name' => $order->domain_name,
            'notes' => $order->notes,
            'next_billing_date' => $order->next_billing_date?->toDateString(),
            'last_billing_date' => $order->last_billing_date?->toDateString(),
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['items'] = $order->items->map(fn (OrderItem $item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total' => (float) $item->total,
            ]);
            $data['invoices'] = $order->invoices->map(fn ($i) => [
                'id' => $i->id, 'invoice_no' => $i->invoice_no, 'total' => (float) $i->total, 'status' => $i->status,
            ]);
        }

        return $data;
    }

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

    private function nextBillingDate(string $cycle): ?string
    {
        $months = Order::CYCLE_MONTHS[$cycle] ?? 1;

        return $months > 0 ? now()->addMonths($months)->toDateString() : null;
    }
}
