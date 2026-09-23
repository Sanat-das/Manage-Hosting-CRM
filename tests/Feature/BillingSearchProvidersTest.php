<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Search\GlobalSearchService;
use App\Services\Search\Providers\InvoiceSearchProvider;
use App\Services\Search\Providers\OrderSearchProvider;
use App\Services\Search\Providers\PaymentSearchProvider;
use App\Services\Search\Providers\QuoteSearchProvider;
use App\Services\Search\Providers\TransactionSearchProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * The billing provider group: orders, invoices, payments, transactions, quotes.
 *
 * Every test drives the providers through `GlobalSearchService::groups()` with
 * an explicit registry of exactly these five classes, so a leak assertion
 * ("this viewer gets zero billing groups") cannot be masked by an unrelated
 * provider. The permission strings asserted here are the ones the billing
 * routes gate on (`routes/admin/billing.php`, `routes/admin/orders.php`):
 * transactions and quotes are gated by `invoices.view`, never `payments.view`.
 */
class BillingSearchProvidersTest extends TestCase
{
    use CreatesChatUsers {
        chatUser as panelUserWith;
    }
    use RefreshDatabase;

    /** The five billing providers under test, in `config/search.php` order. */
    private const BILLING_PROVIDERS = [
        OrderSearchProvider::class,
        InvoiceSearchProvider::class,
        PaymentSearchProvider::class,
        TransactionSearchProvider::class,
        QuoteSearchProvider::class,
    ];

    // --- invoices ---------------------------------------------------------

    public function test_invoice_is_found_by_invoice_no_for_a_viewer_with_invoices_view(): void
    {
        $user = $this->panelUserWith('invoices.view');
        $invoice = $this->makeInvoice($this->makeCustomer(), 'INV-2026-00007');

        $groups = $this->groupsFor($user, 'INV-2026-00007');

        $this->assertArrayHasKey('invoices', $groups);
        $this->assertSame('Invoices', $groups['invoices']['label']);
        $this->assertCount(1, $groups['invoices']['results']);

        $result = $groups['invoices']['results'][0];

        $this->assertSame($invoice->id, $result['id']);
        $this->assertSame('INV-2026-00007', $result['label']);
        $this->assertSame('Sent', $result['subtitle']);
        $this->assertSame(route('admin.invoices.show', $invoice), $result['url']);
        $this->assertSame(
            route('admin.invoices.index', ['search' => 'INV-2026-00007']),
            $groups['invoices']['list_url'],
        );
    }

    public function test_invoice_is_absent_for_a_viewer_without_invoices_view(): void
    {
        $user = $this->panelUserWith('orders.view');
        $this->makeInvoice($this->makeCustomer(), 'INV-2026-00007');

        $groups = $this->groupsFor($user, 'INV-2026-00007');

        $this->assertArrayNotHasKey('invoices', $groups);
        $this->assertSame([], $groups);
    }

    // --- orders -----------------------------------------------------------

    public function test_order_is_found_by_order_number_and_links_to_its_show_route(): void
    {
        $user = $this->panelUserWith('orders.view');
        $order = $this->makeOrder($this->makeCustomer(), 'ORD-2026-00042', 'acme-order.com');

        $groups = $this->groupsFor($user, 'ORD-2026-00042');

        $this->assertArrayHasKey('orders', $groups);
        $this->assertSame('Orders', $groups['orders']['label']);
        $this->assertCount(1, $groups['orders']['results']);

        $result = $groups['orders']['results'][0];

        $this->assertSame('ORD-2026-00042', $result['label']);
        $this->assertSame('acme-order.com', $result['subtitle']);
        $this->assertSame(route('admin.orders.show', $order), $result['url']);
        $this->assertSame(
            route('admin.orders.index', ['search' => 'ORD-2026-00042']),
            $groups['orders']['list_url'],
        );
    }

    public function test_order_is_found_by_domain_name(): void
    {
        $user = $this->panelUserWith('orders.view');
        $this->makeOrder($this->makeCustomer(), 'ORD-2026-00042', 'acme-order.com');

        $groups = $this->groupsFor($user, 'acme-order.com');

        $this->assertArrayHasKey('orders', $groups);
        $this->assertSame('ORD-2026-00042', $groups['orders']['results'][0]['label']);
    }

    // --- payments ---------------------------------------------------------

    public function test_payment_is_found_by_transaction_id_and_links_to_its_show_route(): void
    {
        $user = $this->panelUserWith('payments.view');
        $invoice = $this->makeInvoice($this->makeCustomer(), 'INV-2026-00007');
        $payment = $this->makePayment($invoice, 'PAY-TXN-ACME-1');

        $groups = $this->groupsFor($user, 'PAY-TXN-ACME-1');

        $this->assertArrayHasKey('payments', $groups);
        $this->assertSame('Payments', $groups['payments']['label']);

        $result = $groups['payments']['results'][0];

        $this->assertSame($payment->id, $result['id']);
        $this->assertSame('PAY-TXN-ACME-1', $result['label']);
        $this->assertSame('Completed', $result['subtitle']);
        $this->assertSame(route('admin.payments.show', $payment), $result['url']);
    }

    // --- transactions -----------------------------------------------------

    public function test_transaction_is_found_by_transaction_id_and_links_to_its_show_route(): void
    {
        $user = $this->panelUserWith('invoices.view');
        $transaction = $this->makeTransaction($this->makeCustomer(), 'TXN-ACME-9001');

        $groups = $this->groupsFor($user, 'TXN-ACME-9001');

        $this->assertArrayHasKey('transactions', $groups);
        $this->assertSame('Transactions', $groups['transactions']['label']);

        $result = $groups['transactions']['results'][0];

        $this->assertSame($transaction->id, $result['id']);
        $this->assertSame('TXN-ACME-9001', $result['label']);
        $this->assertSame('Completed', $result['subtitle']);
        $this->assertSame(route('admin.transactions.show', $transaction), $result['url']);
    }

    // --- quotes -----------------------------------------------------------

    public function test_quote_is_found_by_quote_no_and_by_subject(): void
    {
        $user = $this->panelUserWith('invoices.view');
        $quote = $this->makeQuote($this->makeCustomer(), 'QT-2026-0009', 'Acme migration plan');

        $byNumber = $this->groupsFor($user, 'QT-2026-0009');

        $this->assertArrayHasKey('quotes', $byNumber);
        $this->assertSame('Quotes', $byNumber['quotes']['label']);

        $result = $byNumber['quotes']['results'][0];

        $this->assertSame($quote->id, $result['id']);
        $this->assertSame('QT-2026-0009', $result['label']);
        $this->assertSame('Acme migration plan', $result['subtitle']);
        $this->assertSame(route('admin.quotes.show', $quote), $result['url']);
        $this->assertSame(
            route('admin.quotes.index', ['search' => 'QT-2026-0009']),
            $byNumber['quotes']['list_url'],
        );

        $bySubject = $this->groupsFor($user, 'migration plan');

        $this->assertArrayHasKey('quotes', $bySubject);
        $this->assertSame('QT-2026-0009', $bySubject['quotes']['results'][0]['label']);
    }

    // --- gating -----------------------------------------------------------

    public function test_transactions_and_quotes_are_gated_by_invoices_view_never_payments_view(): void
    {
        // Route-file contract: transactions.index/show and quotes.index/show
        // carry `permission:invoices.view`; payments carry `payments.view`.
        $this->assertSame('orders.view', (new OrderSearchProvider)->permission());
        $this->assertSame('invoices.view', (new InvoiceSearchProvider)->permission());
        $this->assertSame('payments.view', (new PaymentSearchProvider)->permission());
        $this->assertSame('invoices.view', (new TransactionSearchProvider)->permission());
        $this->assertSame('invoices.view', (new QuoteSearchProvider)->permission());

        $user = $this->panelUserWith('payments.view');
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, 'INV-ACME-1');
        $this->makePayment($invoice, 'PAY-ACME-1');
        $this->makeTransaction($customer, 'TXN-ACME-1');
        $this->makeQuote($customer, 'QT-ACME-1', 'Acme migration plan');

        $groups = $this->groupsFor($user, 'ACME');

        $this->assertArrayHasKey('payments', $groups);
        $this->assertArrayNotHasKey('transactions', $groups);
        $this->assertArrayNotHasKey('quotes', $groups);
        $this->assertArrayNotHasKey('invoices', $groups);
        $this->assertArrayNotHasKey('orders', $groups);
    }

    public function test_a_viewer_with_only_customers_view_gets_zero_billing_groups(): void
    {
        $user = $this->panelUserWith('customers.view');
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, 'INV-ACME-1');
        $this->makeOrder($customer, 'ORD-ACME-1', 'acme-order.com');
        $this->makePayment($invoice, 'PAY-ACME-1');
        $this->makeTransaction($customer, 'TXN-ACME-1');
        $this->makeQuote($customer, 'QT-ACME-1', 'Acme migration plan');

        // Every row above matches `ACME`; the explicit five-provider registry
        // means an empty result proves all five providers skipped.
        $this->assertSame([], $this->groupsFor($user, 'ACME'));
    }

    public function test_config_registry_lists_all_five_billing_providers_in_order(): void
    {
        $registered = config('search.providers');

        foreach (self::BILLING_PROVIDERS as $class) {
            $this->assertContains($class, $registered);
        }

        $billingSlice = array_values(array_filter(
            $registered,
            static fn (string $class): bool => in_array($class, self::BILLING_PROVIDERS, true),
        ));

        $this->assertSame(self::BILLING_PROVIDERS, $billingSlice);
    }

    // --- adversarial: escaping + payload shape ----------------------------

    public function test_a_literal_percent_in_a_billing_identifier_is_matched_literally(): void
    {
        $user = $this->panelUserWith('invoices.view');
        $customer = $this->makeCustomer();
        $this->makeInvoice($customer, 'INV-50%');
        $this->makeInvoice($customer, 'INV-200');

        DB::enableQueryLog();
        $groups = $this->groupsFor($user, '%');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Two rows exist; `q=%` must match only the one holding a literal `%`.
        $this->assertSame(2, Invoice::count());
        $this->assertArrayHasKey('invoices', $groups);
        $this->assertCount(1, $groups['invoices']['results']);
        $this->assertSame('INV-50%', $groups['invoices']['results'][0]['label']);

        $like = collect($queries)->first(
            static fn (array $query): bool => str_contains($query['query'], 'ESCAPE'),
        );

        $this->assertNotNull($like, 'SQL run: '.implode(' | ', array_column($queries, 'query')));
        $this->assertStringContainsString("ESCAPE '!'", $like['query']);
        $this->assertContains('%!%%', $like['bindings']);
    }

    public function test_billing_typeahead_payload_never_contains_monetary_fields(): void
    {
        $user = $this->panelUserWith('orders.view', 'invoices.view', 'payments.view');
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, 'INV-ACME-1', total: 9876.54);
        $this->makeOrder($customer, 'ORD-ACME-1', 'acme-order.com', total: 1234.56);
        $this->makePayment($invoice, 'PAY-ACME-1', amount: 543.21);
        $this->makeTransaction($customer, 'TXN-ACME-1', amount: 321.45);
        $this->makeQuote($customer, 'QT-ACME-1', 'Acme migration plan', total: 654.32);

        $groups = $this->groupsFor($user, 'ACME');

        $this->assertCount(5, $groups, 'Every billing group should have matched ACME.');

        $payload = json_encode($groups, JSON_THROW_ON_ERROR);

        foreach (['9876.54', '1234.56', '543.21', '321.45', '654.32'] as $monetary) {
            $this->assertStringNotContainsString($monetary, $payload);
        }

        foreach ($groups as $group) {
            foreach ($group['results'] as $result) {
                $this->assertSame(['id', 'label', 'subtitle', 'url'], array_keys($result));
            }
        }
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @return array<string, array{key: string, label: string, results: list<array<string, mixed>>, has_more: bool, list_url: string|null}>
     */
    private function groupsFor(User $user, string $term, int $limit = 5): array
    {
        $service = new GlobalSearchService(self::BILLING_PROVIDERS);

        return collect($service->groups($service->permissionNames($user), $term, $limit))
            ->keyBy('key')
            ->all();
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create(['role' => 'client']);

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Acme Billing',
            'status' => 'active',
        ]);
    }

    private function makeInvoice(Customer $customer, string $invoiceNo, float $total = 250.00): Invoice
    {
        return Invoice::create([
            'customer_id' => $customer->id,
            'invoice_no' => $invoiceNo,
            'amount' => $total,
            'tax' => 0,
            'total' => $total,
            'status' => Invoice::STATUS_SENT,
            'due_date' => now()->addDays(7),
        ]);
    }

    private function makeOrder(Customer $customer, string $orderNumber, string $domain, float $total = 100.00): Order
    {
        $product = Product::create([
            'name' => 'Shared Hosting Basic',
            'price' => $total,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'status' => 'active',
        ]);

        return Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => $orderNumber,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => $total,
            'status' => Order::STATUS_PENDING,
            'domain_name' => $domain,
        ]);
    }

    private function makePayment(Invoice $invoice, string $transactionId, float $amount = 100.00): Payment
    {
        return Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'method' => 'razorpay',
            'transaction_id' => $transactionId,
            'status' => 'completed',
        ]);
    }

    private function makeTransaction(Customer $customer, string $transactionId, float $amount = 100.00): Transaction
    {
        // The transactions table (migration 2026_07_30_120040) has only a
        // created_at column, and the model does not disable updated_at, so a
        // plain create() writes a non-existent column. The provider only reads
        // this table; the fixture inserts with model timestamps disabled.
        $transaction = new Transaction([
            'customer_id' => $customer->id,
            'amount' => $amount,
            'fee' => 0,
            'net_amount' => $amount,
            'currency' => 'INR',
            'payment_method' => 'razorpay',
            'transaction_id' => $transactionId,
            'status' => 'completed',
        ]);

        $transaction->timestamps = false;
        $transaction->save();

        return $transaction->refresh();
    }

    private function makeQuote(Customer $customer, string $quoteNo, string $subject, float $total = 200.00): Quote
    {
        return Quote::create([
            'customer_id' => $customer->id,
            'quote_no' => $quoteNo,
            'subject' => $subject,
            'stage' => 'draft',
            'subtotal' => $total,
            'total' => $total,
        ]);
    }
}
