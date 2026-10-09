<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderRequest;
use App\Models\Customer;
use App\Models\GstSetting;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\ProductAddonPricing;
use App\Models\ProductOptionGroupProduct;
use App\Models\ProductPricing;
use App\Models\ProductUpgradePath;
use App\Models\Setting;
use App\Services\Billing\AddOnService;
use App\Services\Billing\BillingService;
use App\Services\Billing\GstTaxService;
use App\Services\Billing\UpgradeQuoteService;
use App\Services\Billing\UpgradeRequestService;
use App\Services\Exports\CsvStreamService;
use App\Services\InvoiceEmailService;
use App\Services\OptionPricingResolver;
use App\Services\OrderActivityLogger;
use App\Services\OrderConfigSnapshot;
use App\Services\OrderEmailService;
use App\Services\OrderNumberService;
use App\Services\OrderService;
use App\Support\AppSettings;
use App\Support\Logging\AppLog;
use App\Support\OptionSelectionRules;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        private readonly AddOnService $addons,
        private readonly OrderConfigSnapshot $snapshot,
        private readonly InvoiceEmailService $invoiceEmails,
        private readonly OrderEmailService $orderEmails,
    ) {}

    public function index(Request $request): View|StreamedResponse
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $query = Order::query()
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
            ->gridSort([
                'order_number' => 'order_number',
                'customer' => fn (Builder $q, string $dir) => $q->orderBy(Customer::select('company')->whereColumn('customers.id', 'orders.customer_id'), $dir),
                'product' => 'product.name',
                'total' => 'total',
                'status' => 'status',
                'created_at' => 'created_at',
            ])
            ->orderByDesc('id');

        if ($request->query('export') === 'csv') {
            $filename = 'orders-'.now()->format('Y-m-d_His').'.csv';
            $csvHeaders = ['Order #', 'Customer', 'Email', 'Product', 'Total', 'Status', 'Created At'];

            /** @var CsvStreamService $csv */
            $csv = app(CsvStreamService::class);

            return $csv->stream($filename, $csvHeaders, function ($handle) use ($query): void {
                $query->chunk(500, function ($orders) use ($handle): void {
                    foreach ($orders as $order) {
                        fputcsv($handle, [
                            $order->order_no,
                            $order->customer?->full_name ?? '',
                            $order->customer?->user?->email ?? '',
                            $order->product?->name ?? '',
                            number_format((float) $order->total, 2, '.', ''),
                            $order->status,
                            $order->created_at?->format('Y-m-d H:i:s') ?? '',
                        ]);
                    }
                });
            });
        }

        $orders = $query->paginate(self::PER_PAGE)->withQueryString();

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
        $gstSettings = GstTaxService::loadSettings(GstSetting::find(1));

        // Admin setting "Auto-generate invoices" (yes/no) drives the default
        // of the "Generate Invoice" checkbox on the order form.
        $autoGenerateInvoice = (string) (Setting::where('setting_key', 'auto_generate_invoice')->value('setting_value') ?? 'yes') !== 'no';

        // Add-on picker data for the order line editor (frozen shape for the
        // follow-up UI slice): per product, its active product-scoped add-ons
        // plus all active global add-ons. One query, grouped in PHP.
        $activeAddons = ProductAddon::query()
            ->with('pricing')
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $addonsByProduct = [];
        foreach ($products as $product) {
            $addonsByProduct[(string) $product->id] = $activeAddons
                ->filter(fn (ProductAddon $addon) => $addon->product_id === null || (int) $addon->product_id === (int) $product->id)
                ->map(fn (ProductAddon $addon) => [
                    'id' => $addon->id,
                    'name' => $addon->name,
                    'price' => (float) $addon->price,
                    'setup_fee' => (float) $addon->setup_fee,
                    'billing_cycle' => $addon->billing_cycle,
                    // Per-cycle matrix rows: the line editor displays the
                    // price the server will charge for the line's cycle.
                    'pricing' => $addon->pricing
                        ->mapWithKeys(fn (ProductAddonPricing $row) => [
                            (string) $row->billing_cycle => [
                                'price' => (float) $row->price,
                                'setup_fee' => (float) $row->setup_fee,
                            ],
                        ])
                        ->all(),
                ])
                ->values()
                ->all();
        }

        return view('admin.orders.create', compact('customers', 'products', 'paymentMethods', 'gstSettings', 'autoGenerateInvoice', 'addonsByProduct'));
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
                        OrderConfigSnapshot::adjustmentsFor($product, $line['options'] ?? [], $line['billing_cycle']),
                        $line['billing_cycle']
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
                    $parentItem = OrderItem::create([
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
                        'config_options' => $this->snapshot->capture($product, null, $line['options'] ?? [], $line['billing_cycle']),
                    ]);

                    // Order-time add-on selections become their own order
                    // items BEFORE the draft invoice is created below.
                    $this->addons->materialize($order, $parentItem, $line['addons'] ?? []);
                }

                // The add-on rows above are billable lines too: the order
                // total is the sum of ALL its rows (relation query, so it
                // never depends on a loaded relation).
                $order->update(['total' => round((float) $order->items()->sum('total'), 2)]);

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
                    // The add-on rows above were created through fresh model
                    // instances, but refresh the relation so the invoice sees
                    // every line even if this instance touched items earlier.
                    $order->load('items');
                    $invoice = $this->billing->createInvoiceForOrder($order);
                }

                return [$order, $invoice];
            });
        } catch (\Throwable $e) {
            AppLog::billing()->error('Order creation failed', ['exception' => $e]);

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

        // Add-on management data for the "Add-ons" tab: the attach form
        // targets the order's first non-add-on service line, so the picker
        // offers exactly the add-ons applicable to that line's product
        // (active product-scoped + global ones). Empty when the order has no
        // parent line to hang an add-on off.
        $primaryItem = $order->items->first(fn (OrderItem $item) => ! $item->isAddon());
        $applicableAddons = $primaryItem?->product !== null
            ? $this->addons->applicableFor($primaryItem->product)
            : collect();

        return view('admin.orders.show', compact('order', 'statusHistory', 'allowedTransitions', 'applicableAddons'));
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
            AppLog::billing()->error('Order status update failed', ['exception' => $e, 'order_id' => $order->id]);

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
     * Guards: a cancelled/terminated order cannot be invoiced; an existing
     * draft is surfaced instead of duplicated (the admin can send or void it);
     * and an order that already holds a non-draft live invoice (sent, paid,
     * overdue, partial) is refused outright. Every order item — add-on rows
     * included — goes onto the generated invoice, so a second one raised while
     * such an invoice exists would re-bill the whole order. Void and cancelled
     * invoices are dead documents and do not block a fresh one.
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

        // Double-billing guard. The draft branch above already returned, so
        // this only fires for a live non-draft invoice — the add-on attach
        // invoice is `sent`, for example.
        $hasOpenInvoice = $order->invoices()
            ->whereNotIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_CANCELLED, Invoice::STATUS_VOID])
            ->exists();

        if ($hasOpenInvoice) {
            return back()->withErrors(['error' => 'An invoice already exists for this order — send or edit it instead of generating a new one.']);
        }

        try {
            $invoice = $this->billing->createInvoiceForOrder($order);
        } catch (\Throwable $e) {
            AppLog::billing()->error('Invoice generation failed', ['exception' => $e, 'order_id' => $order->id]);

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
     * admin UI.
     *
     * An ACTIVE order used to have no row here at all, so its page offered no
     * actions whatsoever: suspending, cancelling or terminating a live service
     * was impossible from the UI even though the state machine allowed all
     * three. The note that "the hosting module drives that transition" was not
     * true of anything that shipped. Since these hops now reach the control
     * panel (OrderService::applyLifecycleEffects) rather than just moving a
     * status column, leaving them unreachable meant an operator could not
     * actually stop serving a customer.
     *
     * @return array<string, string> target status => button label
     */
    private function exposedTransitions(Order $order): array
    {
        $labels = [
            Order::STATUS_PENDING => [Order::STATUS_ACTIVE => 'Activate', Order::STATUS_CANCELLED => 'Cancel'],
            Order::STATUS_ACTIVE => [
                Order::STATUS_SUSPENDED => 'Suspend',
                Order::STATUS_CANCELLED => 'Cancel',
                Order::STATUS_TERMINATED => 'Terminate',
            ],
            Order::STATUS_SUSPENDED => [
                Order::STATUS_ACTIVE => 'Activate',
                Order::STATUS_CANCELLED => 'Cancel',
                Order::STATUS_TERMINATED => 'Terminate',
            ],
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
     * 'order_confirmation' admin-managed template. Delegated to the shared
     * OrderEmailService so the admin form, the admin cart and the storefront
     * all render the template with the same variable map. Skipped quietly when
     * the template is missing/inactive or the customer has no linked user email.
     */
    private function sendOrderConfirmationEmail(Order $order): void
    {
        $this->orderEmails->send($order);
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
     * STEP 1 of the manual upgrade wizard (Plan). Lists the enabled target
     * products for the order's served product, each with its live quote
     * (payable or credit) for EVERY recurring cycle it is priced for (WHMCS
     * newproductbillingcycle). A pair is offered only when its path's
     * direction admits the quoted change — downgrades need direction
     * downgrade/both AND the product_enable_downgrades setting on; everything
     * else needs upgrade/both (the same rule the client wizard applies).
     * Pairs whose product has no pricing for the offered cycle are omitted —
     * they can neither be quoted nor applied. The option pickers live on step
     * 2 (configure), not here.
     */
    public function upgrade(Order $order): View
    {
        $upgradesEnabled = (bool) AppSettings::get('product_enable_upgrades', '1');
        $downgradesEnabled = (bool) AppSettings::get('product_enable_downgrades', '0');
        $recurring = (Order::CYCLE_MONTHS[$order->billing_cycle] ?? 0) > 0;

        $targets = collect();

        if ($order->status === Order::STATUS_ACTIVE && $recurring && $upgradesEnabled) {
            $quotes = $this->quotes();
            $recurringCycles = array_keys(array_filter(Order::CYCLE_MONTHS, fn (int $months) => $months > 0));

            $served = $order->items->first(
                fn (OrderItem $item) => $item->product_addon_id === null && (int) $item->product_id === (int) $order->product_id,
            ) ?? $order->items->first(fn (OrderItem $item) => $item->product_addon_id === null);

            $fromProductId = $served?->product_id ?? $order->product_id;

            $paths = ProductUpgradePath::query()
                ->where('from_product_id', $fromProductId)
                ->where('enabled', true)
                ->whereHas('toProduct', fn ($q) => $q->where('status', 'active'))
                ->with('toProduct')
                ->get();

            foreach ($paths as $path) {
                $to = $path->toProduct;

                $cycles = ProductPricing::query()
                    ->where('product_id', $to->id)
                    ->whereIn('billing_cycle', $recurringCycles)
                    ->pluck('billing_cycle')
                    ->sortBy(fn (string $cycle) => Order::CYCLE_MONTHS[$cycle] ?? PHP_INT_MAX)
                    ->values()
                    ->all();

                foreach ($cycles as $cycle) {
                    try {
                        $quote = $quotes->quote($order, $to, null, $cycle);
                    } catch (DomainException) {
                        // No pricing for this cycle — such a pair can neither
                        // be quoted nor applied, so it is not offered.
                        continue;
                    }

                    if (! $this->directionAllows($path->direction, $quote['change_type'])) {
                        continue;
                    }

                    if ($quote['change_type'] === 'downgrade' && ! $downgradesEnabled) {
                        continue;
                    }

                    $targets->push(['product' => $to, 'billing_cycle' => $cycle, 'quote' => $quote]);
                }
            }
        }

        // Group the offered (product × cycle) pairs by target product so the
        // step-1 form renders one radio per pair (value "{product_id}:{cycle}",
        // the client wizard's shape). The option pickers are resolved on step 2
        // for the chosen pair only.
        $groups = [];

        foreach ($targets as $target) {
            $groupId = (int) $target['product']->id;
            $groups[$groupId] ??= ['product' => $target['product'], 'pairs' => []];
            $groups[$groupId]['pairs'][] = $target;
        }

        return view('admin.orders.upgrade', compact('order', 'groups', 'upgradesEnabled', 'downgradesEnabled'));
    }

    /**
     * STEP 2 of the manual upgrade wizard (Configure). Stateless — the chosen
     * (product, cycle) pair travels in the step-1 form payload (or as plain
     * fields from the review step's Edit Configuration round-trip), and the
     * option selections post onward to preview(). Re-validates the pair against
     * the same enabled-path / direction / downgrade guards step 1 applies, then
     * renders the target's customer-editable option pickers.
     */
    public function configure(Request $request, Order $order): View|RedirectResponse
    {
        abort_unless($this->upgradeEligible($order), 404);

        // The step-1 form ships one radio per (product × cycle) pair whose
        // value carries both ids; direct payloads (tests, the edit round-trip)
        // already use the plain fields.
        if (! $request->has('to_product_id') && is_string($request->input('pair'))) {
            [$productId, $cycle] = array_pad(explode(':', $request->input('pair'), 2), 2, null);
            $request->merge([
                'to_product_id' => $productId,
                'to_billing_cycle' => $cycle,
            ]);
        }

        $validated = $request->validate([
            'to_product_id' => ['required', 'integer'],
            'to_billing_cycle' => ['nullable', 'string', 'in:'.implode(',', array_keys(array_filter(Order::CYCLE_MONTHS, fn (int $months) => $months > 0)))],
        ]);

        $to = Product::where('status', 'active')->find($validated['to_product_id']);

        if ($to === null) {
            return back()->withErrors(['to_product_id' => 'The target product is not available.']);
        }

        $path = $order->product?->upgradeableTo()->where('to_product_id', $to->id)->first();

        if ($path === null) {
            return back()->withErrors(['to_product_id' => 'The target product is not available.']);
        }

        $cycle = $validated['to_billing_cycle'] ?? null;

        try {
            $quote = $this->quotes()->quote($order, $to, null, $cycle);
        } catch (DomainException $e) {
            return back()->withErrors(['to_product_id' => $e->getMessage()]);
        }

        if (! $this->directionAllows($path->direction, $quote['change_type'])) {
            return back()->withErrors(['to_product_id' => 'This change is not available for the selected product.']);
        }

        if ($quote['change_type'] === 'downgrade' && ! (bool) AppSettings::get('product_enable_downgrades', '0')) {
            return back()->withErrors(['to_product_id' => 'Downgrades are disabled.']);
        }

        $links = OptionPricingResolver::loadLinks($to)->where('customer_editable', true);
        $servedSnapshot = $this->servedSnapshot($order);

        // Repopulate the pickers from a reposted payload when present;
        // otherwise the view preselects the served configuration for an
        // unchanged product.
        $submittedPreselection = $request->has('options')
            ? $this->preselectionFrom($request, $to)
            : null;

        return view('admin.orders.upgrade_configure', compact('order', 'to', 'cycle', 'quote', 'links', 'servedSnapshot', 'submittedPreselection'));
    }

    /**
     * STEP 3 of the manual upgrade wizard (Review & Confirm). Re-runs the live
     * quote with the option selections submitted from configure and renders the
     * exact prorated breakdown, the per-option old→new rows, and a REQUIRED
     * confirmation checkbox. Stateless — nothing is persisted here, the confirm
     * form repeats the payload to storeUpgrade().
     */
    public function preview(Request $request, Order $order): View|RedirectResponse
    {
        abort_unless($this->upgradeEligible($order), 404);

        $validated = $request->validate([
            'to_product_id' => ['required', 'integer'],
            'to_billing_cycle' => ['nullable', 'string', 'in:'.implode(',', array_keys(array_filter(Order::CYCLE_MONTHS, fn (int $months) => $months > 0)))],
        ]);

        $to = Product::where('status', 'active')->find($validated['to_product_id']);

        if ($to === null) {
            return back()->withErrors(['to_product_id' => 'The target product is not available.']);
        }

        $path = $order->product?->upgradeableTo()->where('to_product_id', $to->id)->first();

        if ($path === null) {
            return back()->withErrors(['to_product_id' => 'The target product is not available.']);
        }

        $cycle = $validated['to_billing_cycle'] ?? null;
        $options = $this->optionsFrom($request, $to);

        try {
            $quote = $this->quotes()->quote($order, $to, null, $cycle, $options);
        } catch (DomainException $e) {
            return back()->withErrors(['to_product_id' => $e->getMessage()]);
        }

        if (! $this->directionAllows($path->direction, $quote['change_type'])) {
            return back()->withErrors(['to_product_id' => 'This change is not available for the selected product.']);
        }

        if ($quote['change_type'] === 'downgrade' && ! (bool) AppSettings::get('product_enable_downgrades', '0')) {
            return back()->withErrors(['to_product_id' => 'Downgrades are disabled.']);
        }

        return view('admin.orders.upgrade_confirm', [
            'order' => $order,
            'to' => $to,
            'cycle' => $cycle,
            'quote' => $quote,
            'options' => $options,
            'option_rows' => $this->optionRows($order, $to, $quote, $options),
        ]);
    }

    /**
     * Place AND approve a manual upgrade for an order in one step: the admin
     * acts for the customer, so approval is explicit here (payable → upgrade
     * invoice; credit → wallet credit + immediate apply). All eligibility and
     * pricing guards live in UpgradeRequestService::place() and propagate as
     * DomainExceptions surfaced as form errors.
     */
    public function storeUpgrade(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'to_product_id' => ['required', 'integer', 'exists:products,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'to_billing_cycle' => ['nullable', 'string', Rule::in(array_keys(array_filter(Order::CYCLE_MONTHS, fn (int $months) => $months > 0)))],
            // Target product's option selections keyed by link id (ids or
            // labels); OptionPricingResolver tolerates both and prices the
            // chosen configuration into the quote and the apply.
            'options' => ['nullable', 'array', 'max:100'],
            // Review & Confirm step: the admin must tick the confirmation box
            // before the request is placed and approved.
            'confirm' => ['required', 'accepted'],
        ], [
            'confirm.required' => 'You must confirm the upgrade details to proceed.',
            'confirm.accepted' => 'You must confirm the upgrade details to proceed.',
        ]);

        $upgradeRequests = app(UpgradeRequestService::class);

        try {
            $upgrade = $upgradeRequests->place(
                $order,
                Product::findOrFail((int) $validated['to_product_id']),
                $validated['notes'] ?? null,
                $validated['to_billing_cycle'] ?? null,
                $validated['options'] ?? [],
            );
            $upgrade = $upgradeRequests->approve($upgrade);
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        }

        if ($upgrade->invoice_id !== null) {
            $invoiceNo = $upgrade->invoice?->invoice_no ?? '#'.$upgrade->invoice_id;

            return redirect()
                ->route('admin.orders.show', $order)
                ->with('success', "Upgrade {$upgrade->upgrade_no} approved. Invoice {$invoiceNo} generated.");
        }

        if ((float) $upgrade->credit_amount > 0) {
            return redirect()
                ->route('admin.orders.show', $order)
                ->with('success', 'Upgrade '.$upgrade->upgrade_no.' approved. Credit of ₹'.number_format((float) $upgrade->credit_amount, 2)." applied to the customer's wallet.");
        }

        return redirect()
            ->route('admin.orders.show', $order)
            ->with('success', "Upgrade {$upgrade->upgrade_no} approved and applied.");
    }

    /**
     * Whether this order may run the manual upgrade wizard: an active
     * recurring service, upgrades enabled, and at least one enabled path to
     * an active target product.
     */
    private function upgradeEligible(Order $order): bool
    {
        $served = $order->items->first(
            fn (OrderItem $item) => $item->product_addon_id === null && (int) $item->product_id === (int) $order->product_id,
        ) ?? $order->items->first(fn (OrderItem $item) => $item->product_addon_id === null);

        return $order->status === Order::STATUS_ACTIVE
            && (Order::CYCLE_MONTHS[(string) $order->billing_cycle] ?? 0) > 0
            && (bool) AppSettings::get('product_enable_upgrades', '1')
            && ($served?->product ?? $order->product) !== null
            && ($served?->product ?? $order->product)->upgradeableTo()
                ->whereHas('toProduct', fn ($q) => $q->where('status', 'active'))
                ->exists();
    }

    /**
     * Whether a path's direction admits the quoted change: a downgrade needs
     * direction downgrade/both (the global product_enable_downgrades toggle is
     * checked separately); every other change needs direction upgrade/both. A
     * path with no direction (pre-migration rows) is treated as 'both'.
     */
    private function directionAllows(?string $direction, string $changeType): bool
    {
        $direction = $direction ?: 'both';

        if ($changeType === 'downgrade') {
            return in_array($direction, ['downgrade', 'both'], true);
        }

        return in_array($direction, ['upgrade', 'both'], true);
    }

    private function quotes(): UpgradeQuoteService
    {
        return app(UpgradeQuoteService::class);
    }

    /**
     * The submitted option selections for a target product, validated per
     * option-link input type. A request without an `options` key keeps the
     * pre-options engine behavior exactly (null → "no selections"); the admin
     * store path passes [] so the target's declared FIXED options are priced
     * and its snapshot rewritten.
     */
    private function optionsFrom(Request $request, Product $to): ?array
    {
        $links = OptionPricingResolver::loadLinks($to);

        if (! $request->has('options')) {
            return $links->isEmpty() ? null : [];
        }

        $editable = $links->where('customer_editable', true);

        return $request->validate($this->optionRules($editable))['options'] ?? [];
    }

    /**
     * Snapshot-shaped preselection rows rebuilt from a reposted option payload
     * (the review step's "Edit Configuration" form), so the step-2 pickers
     * re-render with the admin's selections applied. Tolerant on purpose:
     * unknown ids simply preselect nothing, exactly like the served snapshot.
     */
    private function preselectionFrom(Request $request, Product $to): Collection
    {
        $links = OptionPricingResolver::loadLinks($to)->where('customer_editable', true);
        $rows = [];

        foreach ($request->input('options', []) as $linkId => $value) {
            $link = $links->firstWhere('id', (int) $linkId);
            $type = $link?->group?->type ?? 'dropdown';

            $rows[(int) $linkId] = match ($type) {
                'checkbox' => ['value_ids' => array_map('intval', (array) $value)],
                'dropdown', 'radio' => ['value_id' => (int) $value],
                default => ['selected' => $value],
            };
        }

        return collect($rows);
    }

    /**
     * Per-link validation rules for the upgrade option payload — the
     * storefront's rules minus the membership check, so an unknown value id
     * still previews (OptionPricingResolver ignores it) instead of erroring.
     *
     * @param  Collection<int, ProductOptionGroupProduct>  $editableLinks
     * @return array<string, list<mixed>>
     */
    private function optionRules(Collection $editableLinks): array
    {
        $rules = [];

        foreach ($editableLinks as $link) {
            $key = 'options.'.$link->id;
            $type = $link->group?->type ?? 'dropdown';
            $presence = ($link->required ?? true) ? 'required' : 'nullable';

            switch ($type) {
                case 'checkbox':
                    $maxCheckboxes = (int) ($link->input_max ?? $link->group?->input_max ?? $link->linkValues->count());
                    $rules[$key] = [$presence, 'array', 'max:'.max(1, $maxCheckboxes)];
                    $rules[$key.'.*'] = ['integer'];
                    break;

                case 'quantity':
                    $rules[$key] = [$presence, 'integer', 'min:'.OptionSelectionRules::inputMin($link)];
                    if (OptionSelectionRules::inputMax($link) !== null) {
                        $rules[$key][] = 'max:'.OptionSelectionRules::inputMax($link);
                    }
                    break;

                case 'number':
                    $rules[$key] = [$presence, 'numeric', 'min:'.OptionSelectionRules::inputMin($link), 'max:'.(OptionSelectionRules::inputMax($link) ?? PHP_FLOAT_MAX), OptionSelectionRules::stepRule($link)];
                    break;

                case 'slider':
                    $rules[$key] = [$presence, 'numeric', 'min:'.OptionSelectionRules::inputMin($link), 'max:'.(OptionSelectionRules::inputMax($link) ?? 100), OptionSelectionRules::stepRule($link)];
                    break;

                case 'text':
                    $rules[$key] = [$presence, 'string', 'max:255'];
                    break;

                default: // dropdown / radio — link-value ids
                    $rules[$key] = [$presence, 'integer'];
                    break;
            }
        }

        return $rules;
    }

    /**
     * Per-option change rows for the confirm view: group name, the served
     * selection vs the submitted selection, and the new per-cycle unit rate,
     * one row per editable link that actually changes (selection, rate or
     * applied price).
     *
     * @return array<int, array{name: string, old: string|null, new: string|null, unit: string, price_unit: float|null}>
     */
    private function optionRows(Order $order, Product $to, array $quote, ?array $selections): array
    {
        if ($selections === null) {
            return [];
        }

        $cycle = (string) ($quote['to']['billing_cycle'] ?? $order->billing_cycle);
        $links = OptionPricingResolver::loadLinks($to);
        $resolved = (new OptionPricingResolver)->resolve($to, $selections, $cycle, $links);
        $snapshot = $this->servedSnapshot($order);

        $rows = [];

        foreach ($links as $link) {
            $line = $resolved['lines'][$link->id] ?? null;

            if ($line === null) {
                continue;
            }

            $old = $snapshot !== null ? ($snapshot[$link->id] ?? null) : null;
            $oldSelected = $old !== null
                ? $this->displaySelected($old['selected'] ?? null, $old['value_id'] ?? null, $old['value_ids'] ?? [], $link)
                : null;
            $newSelected = $this->displaySelected($line['selected'] ?? null, $line['value_id'] ?? null, $line['value_ids'] ?? [], $link);

            $oldUnit = $old !== null && ($old['price_unit'] ?? null) !== null ? (float) $old['price_unit'] : null;
            $oldApplied = $old !== null ? (float) ($old['price_applied'] ?? 0) : 0.0;
            $newUnit = $line['price_unit'] !== null ? (float) $line['price_unit'] : null;

            if ($oldSelected === $newSelected
                && $oldUnit === $newUnit
                && $oldApplied === (float) $line['price_applied']) {
                continue;
            }

            $rows[] = [
                'name' => (string) ($link->group?->name ?? ''),
                'old' => $oldSelected,
                'new' => $newSelected,
                'unit' => (string) ($link->group?->unit ?? ''),
                'price_unit' => $line['price_unit'],
            ];
        }

        return $rows;
    }

    /**
     * Render a selection for display: label(s) for discrete values, the raw
     * amount for continuous ones, falling back to the value id's label when
     * the resolver left the label empty.
     */
    private function displaySelected(mixed $selected, mixed $valueId, array $valueIds, ProductOptionGroupProduct $link): ?string
    {
        if (is_array($selected)) {
            $labels = array_values(array_filter(array_map('strval', $selected), static fn (string $s) => $s !== ''));

            return $labels === [] ? null : implode(', ', $labels);
        }

        if ($selected !== null && $selected !== '') {
            return (string) $selected;
        }

        if ($valueIds !== []) {
            $label = $link->linkValues->firstWhere('id', (int) $valueIds[0])?->label;

            if ($label !== null) {
                return (string) $label;
            }
        }

        if ($valueId !== null) {
            return (string) ($link->linkValues->firstWhere('id', (int) $valueId)?->label ?? '');
        }

        return null;
    }

    /**
     * The served order item's option snapshot keyed by option-link id, or null
     * when the item carries none. Preselects on the configure step and feeds
     * the "current" side of the confirm view's per-option rows.
     */
    private function servedSnapshot(Order $order): ?Collection
    {
        $served = $order->items->first(
            fn (OrderItem $item) => $item->product_addon_id === null && (int) $item->product_id === (int) $order->product_id,
        ) ?? $order->items->first(fn (OrderItem $item) => $item->product_addon_id === null);

        if ($served === null || ! is_array($served->config_options)) {
            return null;
        }

        return collect($served->config_options['options'] ?? [])->keyBy('id');
    }
}
