<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderRequest;
use App\Jobs\SendEmail;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Billing\BillingService;
use App\Services\InvoiceEmailService;
use App\Services\OrderActivityLogger;
use App\Services\OrderConfigSnapshot;
use App\Services\OrderNumberService;
use App\Services\OrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin order management (Session 3A.2).
 *
 * Ported behavior from the reference CRM orders module:
 * - order number generation ORD-{YEAR}-{seq} (reference format) via the
 *   shared race-safe OrderNumberService (gap-fillup T1.3)
 * - order + order_items row snapshot (product_name/unit_price/total)
 * - a draft invoice per order, generated at creation through the shared
 *   BillingService GST engine (same path as manual invoice creation)
 * - status workflow delegated to the authoritative OrderService state
 *   machine (pending→paid/active/cancelled, ...), which writes the
 *   order_status_history audit row and centralizes the activation
 *   side-effects (next_billing_date seeding + OrderCreated dispatch)
 * - the ActivityLog entry kept as the customer-facing trail (the customer
 *   page surfaces it); the per-order audit lives in order_status_history
 */
class OrderController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly OrderNumberService $orderNumbers,
        private readonly OrderService $orders,
        private readonly BillingService $billing,
        private readonly OrderConfigSnapshot $snapshot,
        private readonly InvoiceEmailService $invoiceEmails,
    ) {}

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

    public function create(Request $request): View
    {
        // Preselect the customer when arriving from the customer profile
        // ("New Order" quick action): flash the query value so old() picks it up.
        if ($customerId = $request->query('customer_id')) {
            $request->flashOnly('customer_id');
        }

        $customers = Customer::query()
            ->with('user:id,email,first_name,last_name')
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        $products = Product::query()
            ->with(['pricing', 'optionLinks.group', 'optionLinks.linkValues.pricing', 'optionLinks.unitPricing'])
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $paymentMethods = [
            'bank_transfer' => 'Bank Transfer',
            'razorpay' => 'Razorpay',
            'stripe' => 'Stripe',
            'paypal' => 'PayPal',
            'wallet' => 'Wallet',
            'manual' => 'Manual',
        ];

        // Normalized GST settings for the client-side tax preview (mirrors
        // the engine's loadSettings() — the draft invoice stays the source
        // of truth, the preview is an estimate).
        $gstSettings = \App\Services\Billing\GstTaxService::loadSettings(\App\Models\GstSetting::find(1));

        // Admin setting "Auto-generate invoices" (yes/no) drives the default
        // of the "Generate Invoice" checkbox on the order form.
        $autoGenerateInvoice = (string) (\App\Models\Setting::where('setting_key', 'auto_generate_invoice')->value('setting_value') ?? 'yes') !== 'no';

        return view('admin.orders.create', compact('customers', 'products', 'paymentMethods', 'gstSettings', 'autoGenerateInvoice'));
    }

    public function store(OrderRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $lines = $validated['lines'];

        // The form's checkbox is authoritative: an unticked "Generate
        // Invoice" (absent key — unchecked HTML checkboxes submit nothing)
        // must never create a draft invoice. The auto_generate_invoice
        // setting only drives the checkbox's initial state on the form.
        $generateInvoice = $request->boolean('generate_invoice');

        try {
            $result = DB::transaction(function () use ($validated, $lines, $generateInvoice) {
                // Resolve each line to its product + line total. The first
                // line is the order's primary product (orders.product_id);
                // every line becomes an order_item. The chargeable unit price
                // is the entered price PLUS the selected option values'
                // per-cycle modifiers (same math the storefront applies), so
                // configuration options affect the price.
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

                $status = $validated['status'] ?? Order::STATUS_PENDING;
                [$primaryProduct, $primaryLine, $primaryUnitPrice] = $prepared[0];

                $order = Order::create([
                    'customer_id' => $validated['customer_id'],
                    'product_id' => $primaryProduct->id,
                    'order_number' => $this->orderNumbers->next(),
                    'billing_cycle' => $primaryLine['billing_cycle'],
                    'quantity' => (int) $primaryLine['quantity'],
                    'total' => round($total, 2),
                    // Always created pending — "create as active" goes through
                    // the guarded state machine below so the activation
                    // side-effects (schedule + provisioning) run exactly once.
                    'status' => Order::STATUS_PENDING,
                    'domain_name' => $primaryLine['domain_name'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'payment_method' => $validated['payment_method'] ?? null,
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

                // "Create as Active" runs the full activation through the
                // guarded state machine inside this transaction: it seeds the
                // recurring schedule (next_billing_date from the cycle) and
                // provisions the hosting account (leasing IPs when available
                // — an exhausted IPAM pool never blocks the order; IPs are
                // assigned later from the hosting page).
                if ($status === Order::STATUS_ACTIVE) {
                    $this->orders->activate($order, 'Created as active on the order form.');
                }

                // Customer-facing trail: the creation itself is audited so the
                // customer page shows where the order came from.
                OrderActivityLogger::created($order);

                // Draft invoice through the shared GST engine (one line per
                // order item) so the order is immediately billable — only when
                // the form's "Generate Invoice" option is ticked (its default
                // comes from the auto_generate_invoice setting). The admin
                // reviews/sends it later. due_date defaults to the same 7-day
                // convention as the scheduled invoice job.
                $invoice = null;
                if ($generateInvoice) {
                    $invoice = $this->billing->createInvoiceForOrder($order);
                }

                return [$order, $invoice];
            });
        } catch (\Throwable $e) {
            Log::error('Order creation failed', ['exception' => $e]);

            return back()->withInput()->withErrors(['error' => 'Could not create the order. Please try again or contact support.']);
        }

        [$order, $invoice] = $result;

        // Post-order emails (outside the transaction, best-effort): the
        // confirmation is sent when "Order Confirmation" is ticked; the
        // generated invoice is emailed when "Send Email" is ticked. Each send
        // is skipped quietly when its admin-managed template is missing.
        $order->load('customer.user');

        if ($request->boolean('send_confirmation')) {
            $this->sendOrderConfirmationEmail($order);
        }

        if ($generateInvoice && $invoice !== null && $request->boolean('send_invoice')) {
            $this->sendInvoiceEmail($order, $invoice);
        }

        return redirect()
            ->route('admin.orders.show', $order)
            ->with('success', "Order {$order->order_number} created (".$order->status.').');
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
            'statusHistory' => fn ($q) => $q->with('user')->orderByDesc('id'),
        ]);

        $statusHistory = $order->statusHistory;
        $allowedTransitions = $this->exposedTransitions($order);

        return view('admin.orders.show', compact('order', 'statusHistory', 'allowedTransitions'));
    }

    /**
     * Guarded status transition (activate / cancel / ...).
     *
     * OrderService is the ONLY place order statuses change; an invalid move
     * (e.g. cancelling an already-cancelled order) is rejected here with a
     * validation error instead of silently corrupting the workflow. The
     * service writes the order_status_history audit row and dispatches the
     * activation events; the ActivityLog row below is the customer-facing
     * trail shown on the customer page.
     */
    public function updateStatus(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(Order::STATUSES)],
        ]);

        $target = $validated['status'];

        if (! $this->orders->canTransition($order, $target)) {
            return back()->withErrors(['status' => "Cannot change order from '{$order->status}' to '{$target}'."]);
        }

        try {
            $from = $order->status;

            $order = $this->orders->transition($order, $target);

            OrderActivityLogger::changed($order, $from, $target, $request->user()?->email);
        } catch (\Throwable $e) {
            Log::error('Order status update failed', ['exception' => $e, 'order_id' => $order->id]);

            return back()->withErrors(['error' => 'Could not update the order status. Please try again or contact support.']);
        }

        return redirect()
            ->route('admin.orders.show', $order)
            ->with('success', "Order {$order->order_number} is now {$order->status}.");
    }

    /**
     * Generate a new draft invoice for an existing order through the shared
     * BillingService GST engine (the same path the order form uses).
     *
     * Guards: a cancelled/terminated order cannot be invoiced, and an order
     * that already has an open draft invoice is not invoiced twice — the
     * existing draft is surfaced instead (the admin can send or void it).
     * Later invoices are only created once the previous one leaves draft
     * (sent/paid/void), mirroring the recurring billing job's one-invoice
     * at-a-time behaviour.
     */
    public function generateInvoice(Order $order): RedirectResponse
    {
        if (in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_TERMINATED], true)) {
            return back()->withErrors(['error' => "Cannot generate an invoice for a {$order->status} order."]);
        }

        $existingDraft = $order->invoices()
            ->where('status', Invoice::STATUS_DRAFT)
            ->latest('id')
            ->first();

        if ($existingDraft !== null) {
            return redirect()
                ->route('admin.invoices.show', $existingDraft)
                ->with('success', "Order {$order->order_number} already has a draft invoice ({$existingDraft->invoice_no}).");
        }

        try {
            $invoice = $this->billing->createInvoiceForOrder($order);
        } catch (\Throwable $e) {
            Log::error('Invoice generation failed', ['exception' => $e, 'order_id' => $order->id]);

            return back()->withErrors(['error' => 'Could not generate the invoice. Please try again or contact support.']);
        }

        return redirect()
            ->route('admin.invoices.show', $invoice)
            ->with('success', "Invoice {$invoice->invoice_no} generated for order {$order->order_number}.");
    }

    /**
     * UI-exposed status targets with button labels, filtered through the
     * state machine (OrderService::canTransition) so a change to the
     * authoritative map can never silently expose an illegal move in the
     * admin UI. `active → suspended` stays hidden here — the hosting module
     * drives that transition.
     *
     * @return array<string, string> target status => button label
     */
    private function exposedTransitions(Order $order): array
    {
        $labels = [
            Order::STATUS_PENDING => [Order::STATUS_ACTIVE => 'Activate', Order::STATUS_CANCELLED => 'Cancel'],
            Order::STATUS_SUSPENDED => [Order::STATUS_ACTIVE => 'Activate', Order::STATUS_CANCELLED => 'Cancel'],
            // Auto-provisioning failed after invoice payment — the admin can
            // retry the activation (re-runs provisioning + billing seeding via
            // OrderService) or cancel the order outright.
            Order::STATUS_FAILED => [Order::STATUS_ACTIVE => 'Retry Provisioning', Order::STATUS_CANCELLED => 'Cancel'],
        ];

        $row = $labels[$order->status] ?? [];

        return array_filter(
            $row,
            fn (string $target) => $this->orders->canTransition($order, $target),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Send the order confirmation email to the customer from the
     * 'order_confirmation' admin-managed template. Skipped quietly when the
     * template is missing/inactive or the customer has no linked user email.
     */
    private function sendOrderConfirmationEmail(Order $order): void
    {
        $email = $order->customer?->user?->email;
        if (! $email) {
            return;
        }

        $template = EmailTemplate::query()
            ->where('name', 'order_confirmation')
            ->where('status', 'active')
            ->first();

        if ($template === null) {
            Log::info('Order confirmation email skipped: template "order_confirmation" not found.', ['order_id' => $order->id]);

            return;
        }

        [$subject, $body] = $this->renderTemplate($template, [
            'name' => $order->customer?->full_name ?? 'there',
            'order_no' => $order->order_number,
            'total' => number_format((float) $order->total, 2),
        ]);

        SendEmail::dispatch($email, $subject, $body);
    }

    /**
     * Send the invoice email to the customer from the 'invoice_created'
     * admin-managed template. Delegated to the shared InvoiceEmailService
     * (same render + dispatch used by the invoice page "Send Invoice").
     * Skipped quietly when the template is missing/inactive or the customer
     * has no linked user email.
     */
    private function sendInvoiceEmail(Order $order, Invoice $invoice): void
    {
        $this->invoiceEmails->send($invoice);
    }

    /**
     * Render a template's {{placeholder}} variables (the same convention the
     * seeded demo templates use).
     *
     * @param  array<string, string>  $vars
     * @return array{0: string, 1: string} [subject, body]
     */
    private function renderTemplate(EmailTemplate $template, array $vars): array
    {
        $placeholders = array_map(fn (string $key) => '{{'.$key.'}}', array_keys($vars));
        $values = array_values($vars);

        return [
            str_replace($placeholders, $values, (string) $template->subject),
            str_replace($placeholders, $values, (string) $template->body),
        ];
    }
}
