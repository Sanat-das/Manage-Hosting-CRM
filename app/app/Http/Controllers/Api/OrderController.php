<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Billing\BillingService;
use App\Services\OrderActivityLogger;
use App\Services\OrderConfigSnapshot;
use App\Services\OrderNumberService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;

/**
 * Sanctum-protected orders REST API (Session 3A.2).
 *
 * Mirrors the reference /api/orders endpoints: list (search/status filters),
 * store (creates a pending order + item snapshot), show, and the guarded
 * status update. Status changes delegate to the authoritative OrderService
 * state machine — the same map, audit row (order_status_history), activation
 * side-effects (next_billing_date + provisioning) and OrderCreated dispatch
 * as the admin UI, so every entry point behaves identically.
 */
class OrderController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly OrderService $orders,
        private readonly BillingService $billing,
        private readonly OrderNumberService $orderNumbers,
        private readonly OrderConfigSnapshot $snapshot,
    ) {}

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
        $lines = $validated['lines'];

        $order = DB::transaction(function () use ($validated, $lines) {
            // The first line is the order's primary product; every line
            // becomes an order_item (multi-line payloads come from the admin
            // order form, legacy callers send a single line). The chargeable
            // unit price includes the selected option modifiers.
            $prepared = [];
            $total = 0.0;

            foreach ($lines as $line) {
                $product = Product::findOrFail($line['product_id']);
                // Per-unit price rounded to 2dp (base + option adjustments),
                // then the total — same convention as the storefront.
                $unitPrice = round(OrderConfigSnapshot::formatPrice(
                    (float) $line['unit_price'],
                    OrderConfigSnapshot::adjustmentsFor($product, $line['options'] ?? []),
                    $line['billing_cycle'],
                    $product->pricing->pluck('billing_cycle')->all()
                ), 2);

                $lineTotal = round($unitPrice * (int) $line['quantity'], 2);
                $total += $lineTotal;
                $prepared[] = [$product, $line, $unitPrice, $lineTotal];
            }

            [$primaryProduct, $primaryLine] = $prepared[0];

            $order = Order::create([
                'customer_id' => $validated['customer_id'],
                'product_id' => $primaryProduct->id,
                'order_number' => $this->orderNumbers->next(),
                'billing_cycle' => $primaryLine['billing_cycle'],
                'quantity' => (int) $primaryLine['quantity'],
                'total' => round($total, 2),
                'status' => Order::STATUS_PENDING,
                'domain_name' => $primaryLine['domain_name'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($prepared as [$product, $line, $unitPrice, $lineTotal]) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'billing_cycle' => $line['billing_cycle'],
                    'domain_name' => $line['domain_name'] ?? null,
                    'recurring_cycles_limit' => (int) ($product->recurring_cycles_limit ?? 0),
                    'billing_cycles_count' => (Order::CYCLE_MONTHS[$line['billing_cycle']] ?? 0) > 0 ? 1 : 0,
                    'quantity' => (int) $line['quantity'],
                    'unit_price' => $unitPrice,
                    'total' => $lineTotal,
                    'config_options' => $this->snapshot->capture($product, null, $line['options'] ?? []),
                ]);
            }

            // Customer-facing trail: the API path writes the same order_created
            // row as the admin UI, the storefront and the admin cart.
            OrderActivityLogger::created($order);

            // Draft invoice through the shared GST engine so the order is
            // immediately billable — same convention as the admin paths.
            $this->billing->createInvoiceForOrder($order);

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
     * Guarded status transition through OrderService (the single state
     * machine shared with the admin UI). Returns 422 for illegal transitions
     * (e.g. cancel on an already-cancelled order). Activation provisioning
     * creates the hosting account; IP leasing is best-effort — an exhausted
     * IPAM pool never blocks activation (IPs are assigned from the hosting
     * page), so this path only fails on genuine errors.
     */
    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(Order::STATUSES)],
        ]);

        $target = $validated['status'];

        try {
            $order = $this->orders->transition($order, $target);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (RuntimeException $e) {
            // Genuine provisioning failure — the order is left unchanged.
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $this->present($order->load(['customer.user:id,email,first_name,last_name', 'product', 'items'])),
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
}
