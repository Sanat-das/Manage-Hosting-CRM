<?php

namespace App\Http\Controllers\Admin;

use App\Events\CustomerCreated;
use App\Events\CustomerDeleted;
use App\Events\CustomerUpdated;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerNote;
use App\Models\CustomerWallet;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\ServiceInstance;
use App\Models\SubscriptionChange;
use App\Models\SubscriptionPeriod;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\DomainService;
use App\Services\Exports\CsvStreamService;
use App\Services\HostingService;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\GstStateCodes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin customer management (pilot module).
 *
 * Ported behavior from the reference CRM:
 * - identity lives on the `users` row (email, names, phone, status, role=client)
 * - billing/account state lives on the `customers` row (company, tax_id, balance, credit, status)
 * - customer display id uses the #CLT-xxxxx format
 * - every state change is written to `activity_log`
 */
class CustomerController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View|StreamedResponse
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $query = Customer::query()
            ->with('user')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->whereHas('user', function ($u) use ($search) {
                        $u->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('company', 'like', "%{$search}%");
                    })->orWhere('company', 'like', "%{$search}%");
                });
            })
            ->when(in_array($status, ['active', 'inactive', 'suspended'], true), function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->gridSort([
                'id' => 'id',
                'name' => fn (Builder $q, string $dir) => $q->orderBy(User::select('first_name')->whereColumn('users.id', 'customers.user_id'), $dir)->orderBy(User::select('last_name')->whereColumn('users.id', 'customers.user_id'), $dir),
                'email' => 'user.email',
                'company' => 'company',
                'balance' => 'balance',
                'status' => 'status',
                'created_at' => 'created_at',
            ])
            ->orderByDesc('id');

        if ($request->query('export') === 'csv') {
            $filename = 'customers-'.now()->format('Y-m-d_His').'.csv';
            $csvHeaders = ['#', 'Display ID', 'Name', 'Email', 'Company', 'Balance', 'Credit', 'Status', 'Registered'];

            /** @var CsvStreamService $csv */
            $csv = app(CsvStreamService::class);

            return $csv->stream($filename, $csvHeaders, function ($handle) use ($query): void {
                $query->chunk(500, function ($customers) use ($handle): void {
                    foreach ($customers as $customer) {
                        fputcsv($handle, [
                            $customer->id,
                            $customer->display_id,
                            $customer->full_name,
                            $customer->user?->email ?? '',
                            $customer->company ?? '',
                            number_format((float) $customer->balance, 2, '.', ''),
                            number_format((float) $customer->credit, 2, '.', ''),
                            $customer->status,
                            $customer->created_at?->format('Y-m-d H:i:s') ?? '',
                        ]);
                    }
                });
            });
        }

        $customers = $query->paginate(self::PER_PAGE)->withQueryString();

        return view('admin.customers.index', compact('customers', 'search', 'status'));
    }

    public function create(): View
    {
        return view('admin.customers.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->normalizePhone($request);
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required', 'string', 'min:8', 'confirmed',
                'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/',
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'status' => ['required', Rule::in(['active', 'inactive', 'suspended'])],
        ]);

        try {
            $customer = DB::transaction(function () use ($validated) {
                $legacyAddress = $this->compileLegacyAddress($validated);
                $user = User::create([
                    'email' => $validated['email'],
                    'password_hash' => Hash::make($validated['password']),
                    'role' => 'client',
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'phone' => $validated['phone'] ?? null,
                    'company' => $validated['company'] ?? null,
                    'address' => $legacyAddress,
                    'address_line1' => $validated['address_line1'] ?? null,
                    'address_line2' => $validated['address_line2'] ?? null,
                    'city' => $validated['city'] ?? null,
                    'state' => $validated['state'] ?? null,
                    'postcode' => $validated['postcode'] ?? null,
                    'country' => $validated['country'] ?? 'India',
                    'status' => $validated['status'] === 'active' ? 'active' : 'inactive',
                ]);

                $customer = Customer::create([
                    'user_id' => $user->id,
                    'company' => $validated['company'] ?? null,
                    'tax_id' => $validated['tax_id'] ?? null,
                    'state_code' => $this->resolveStateCode($validated['state'] ?? null),
                    'balance' => 0,
                    'credit' => 0,
                    'status' => $validated['status'],
                ]);

                CustomerCreated::dispatch($customer);

                return $customer;
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not create customer: '.$e->getMessage()]);
        }

        return redirect()
            ->route('admin.customers.show', $customer)
            ->with('success', "Customer {$customer->display_id} created.");
    }

    /**
     * Inline customer creation from the admin order form (and any other page
     * needing a customer without navigating away). Same rules and creation
     * path as store(), minus the password confirmation field; returns the new
     * customer's id + display label as JSON so the calling form can select it.
     */
    public function quickStore(Request $request): JsonResponse
    {
        $this->normalizePhone($request);
        try {
            $validated = $request->validate([
                'first_name' => ['required', 'string', 'max:255'],
                'last_name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => [
                    'required', 'string', 'min:8',
                    'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/',
                ],
                'phone' => ['nullable', 'string', 'max:50'],
                'company' => ['nullable', 'string', 'max:255'],
                'tax_id' => ['nullable', 'string', 'max:100'],
                'address_line1' => ['nullable', 'string', 'max:255'],
                'address_line2' => ['nullable', 'string', 'max:255'],
                'city' => ['nullable', 'string', 'max:100'],
                'state' => ['nullable', 'string', 'max:100'],
                'postcode' => ['nullable', 'string', 'max:20'],
                'country' => ['nullable', 'string', 'max:100'],
                'address' => ['nullable', 'string', 'max:500'],
                'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended'])],
            ]);
        } catch (ValidationException $e) {
            // The app renders JSON exceptions only for api/* routes, so this
            // admin endpoint must hand back its validation errors explicitly
            // for the inline modal (which calls it with fetch()).
            return response()->json(['errors' => $e->errors()], 422);
        }

        $customer = DB::transaction(function () use ($validated) {
            $status = $validated['status'] ?? 'active';
            $legacyAddress = $this->compileLegacyAddress($validated);

            $user = User::create([
                'email' => $validated['email'],
                'password_hash' => Hash::make($validated['password']),
                'role' => 'client',
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'phone' => $validated['phone'] ?? null,
                'company' => $validated['company'] ?? null,
                'address' => $legacyAddress,
                'address_line1' => $validated['address_line1'] ?? null,
                'address_line2' => $validated['address_line2'] ?? null,
                'city' => $validated['city'] ?? null,
                'state' => $validated['state'] ?? null,
                'postcode' => $validated['postcode'] ?? null,
                'country' => $validated['country'] ?? 'India',
                'status' => $status === 'active' ? 'active' : 'inactive',
            ]);

            $customer = Customer::create([
                'user_id' => $user->id,
                'company' => $validated['company'] ?? null,
                'tax_id' => $validated['tax_id'] ?? null,
                'state_code' => $this->resolveStateCode($validated['state'] ?? null),
                'balance' => 0,
                'credit' => 0,
                'status' => $status,
            ]);

            CustomerCreated::dispatch($customer);

            return $customer;
        });

        $customer->load('user:id,email,first_name,last_name');

        return response()->json([
            'id' => $customer->id,
            'label' => $customer->full_name.($customer->user?->email ? ' — '.$customer->user->email : ''),
        ], 201);
    }

    public function show(Request $request, Customer $customer): View|StreamedResponse
    {
        $customer->load([
            'user',
            'contacts' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('created_at'),
            'notes' => fn ($q) => $q->with('user')->orderByDesc('is_important')->orderByDesc('created_at'),
        ]);

        $search = trim((string) $request->query('search', ''));

        $filters = [
            'search' => $search,
            'hosting_status' => trim((string) $request->query('hosting_status', '')),
            'order_status' => trim((string) $request->query('order_status', '')),
            'invoice_status' => trim((string) $request->query('invoice_status', '')),
            'ticket_status' => trim((string) $request->query('ticket_status', '')),
            'domain_status' => trim((string) $request->query('domain_status', '')),
            'wallet_type' => trim((string) $request->query('wallet_type', '')),
        ];

        $activeTab = (string) $request->query('tab', 'profile');

        if ($request->query('export') === 'csv') {
            $tab = (string) $request->query('tab', 'profile');

            return $this->exportTabCsv($request, $customer, $tab, $search, $filters);
        }

        $statusOptions = [
            'hosting' => collect(HostingService::STATUSES)->mapWithKeys(fn (string $value) => [$value => ucfirst(str_replace('_', ' ', $value))])->all(),
            'orders' => collect(Order::STATUSES)->mapWithKeys(fn (string $value) => [$value => ucfirst(str_replace('_', ' ', $value))])->all(),
            'invoices' => Invoice::STATUS_LABELS,
            'tickets' => TicketController::STATUSES,
            'domains' => DomainService::STATUS_LABELS,
            'contacts' => [],
            'billing' => [
                'deposit' => 'Deposit',
                'credit' => 'Credit',
                'debit' => 'Debit',
                'invoice_payment' => 'Invoice payment',
            ],
            'activity' => [],
        ];

        $perPage = 10;

        // Hosting accounts
        $hostingQuery = $customer->hostingAccounts()
            ->with(['product', 'server', 'ipAddresses.subnet', 'order.items', 'order.statusHistory', 'order.domain'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('domain', 'like', "%{$search}%")
                        ->orWhere('host_name', 'like', "%{$search}%")
                        ->orWhere('id', 'like', "%{$search}%")
                        ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('server', fn ($s) => $s->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($filters['hosting_status'] !== '', fn ($query) => $query->where('status', $filters['hosting_status']))
            ->gridFilters([
                'id' => 'id',
                'product' => 'product.name',
                'server' => 'server.name',
                'domain' => fn ($q, $value) => $q->where('domain', 'like', "%{$value}%"),
                'status' => ['status', '='],
            ])
            ->gridSort([
                'id' => 'id',
                'product' => 'product.name',
                'server' => 'server.name',
                'cycle' => 'order.billing_cycle',
                'amount' => 'order.total',
                'next_billing' => 'next_due_date',
                'status' => 'status',
            ])
            ->latest();

        $hostingAccounts = $hostingQuery->paginate($perPage, ['*'], 'hosting_page')->withQueryString();

        // Orders
        $ordersQuery = $customer->orders()
            ->with('product')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%")
                        ->orWhere('domain_name', 'like', "%{$search}%")
                        ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($filters['order_status'] !== '', fn ($query) => $query->where('status', $filters['order_status']))
            ->gridFilters([
                'order_no' => 'order_number',
                'product' => 'product.name',
                'domain_name' => 'domain_name',
                'status' => ['status', '='],
            ])
            ->gridSort([
                'order_no' => 'order_number',
                'product' => 'product.name',
                'cycle' => 'billing_cycle',
                'total' => 'total',
                'status' => 'status',
                'created_at' => 'created_at',
            ])
            ->latest();

        $orders = $ordersQuery->paginate($perPage, ['*'], 'orders_page')->withQueryString();

        // Invoices
        $invoicesQuery = $customer->invoices()
            ->when($search !== '', function ($query) use ($search) {
                $query->where('invoice_no', 'like', "%{$search}%");
            })
            ->when($filters['invoice_status'] !== '', fn ($query) => $query->where('status', $filters['invoice_status']))
            ->gridFilters([
                'invoice_no' => 'invoice_no',
                'status' => ['status', '='],
            ])
            ->gridSort([
                'invoice_no' => 'invoice_no',
                'total' => 'total',
                'status' => 'status',
                'due_date' => 'due_date',
            ])
            ->latest();

        $invoices = $invoicesQuery->paginate($perPage, ['*'], 'invoices_page')->withQueryString();

        // Tickets
        $ticketsQuery = $customer->tickets()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('ticket_no', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%");
                });
            })
            ->when($filters['ticket_status'] !== '', fn ($query) => $query->where('status', $filters['ticket_status']))
            ->gridFilters([
                'ticket_no' => 'ticket_no',
                'subject' => 'subject',
                'status' => ['status', '='],
                'priority' => ['priority', '='],
            ])
            ->gridSort([
                'ticket_no' => 'ticket_no',
                'subject' => 'subject',
                'priority' => 'priority',
                'status' => 'status',
                'created_at' => 'created_at',
            ])
            ->latest();

        $tickets = $ticketsQuery->paginate($perPage, ['*'], 'tickets_page')->withQueryString();

        // Domains
        $domainsQuery = $customer->domains()
            ->when($search !== '', function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%");
            })
            ->when($filters['domain_status'] !== '', fn ($query) => $query->where('status', $filters['domain_status']))
            ->gridFilters([
                'name' => 'name',
                'status' => ['status', '='],
            ])
            ->gridSort([
                'name' => 'name',
                'type' => 'type',
                'expiry_date' => 'expiry_date',
                'status' => 'status',
            ])
            ->latest();

        $domains = $domainsQuery->paginate($perPage, ['*'], 'domains_page')->withQueryString();

        // Contacts
        $contactsQuery = $customer->contacts()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('role', 'like', "%{$search}%");
                });
            })
            ->gridFilters([
                'name' => fn ($q, $value) => $q->where(function ($q2) use ($value) {
                    $q2->where('first_name', 'like', "%{$value}%")
                        ->orWhere('last_name', 'like', "%{$value}%");
                }),
                'email' => 'email',
                'phone' => 'phone',
                'role' => 'role',
            ])
            ->gridSort([
                'name' => fn (Builder $q, string $dir) => $q->orderBy('first_name', $dir)->orderBy('last_name', $dir),
                'email' => 'email',
                'phone' => 'phone',
                'role' => 'role',
                'type' => 'status',
            ]);

        // default: primary-first then created
        $contactsQuery->orderByDesc('is_primary')->orderBy('created_at');

        $contacts = $contactsQuery->paginate($perPage, ['*'], 'contacts_page')->withQueryString();

        // Wallet / billing
        $walletQuery = $customer->walletTransactions()
            ->with('adminUser')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('description', 'like', "%{$search}%")
                        ->orWhere('type', 'like', "%{$search}%");
                });
            })
            ->when($filters['wallet_type'] !== '', fn ($query) => $query->where('type', $filters['wallet_type']))
            ->gridFilters([
                'description' => 'description',
                'type' => ['type', '='],
            ])
            ->gridSort([
                'created_at' => 'created_at',
                'type' => 'type',
                'amount' => 'amount',
                'description' => 'description',
            ])
            ->latest();

        $walletTransactions = $walletQuery->paginate($perPage, ['*'], 'wallet_page')->withQueryString();

        // Activity
        $activityQuery = ActivityLog::query()
            ->where('customer_id', $customer->id)
            ->with('user')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('action', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->gridFilters([
                'action' => 'action',
                'description' => 'description',
            ])
            ->gridSort([
                'created_at' => 'created_at',
                'action' => 'action',
                'description' => 'description',
            ])
            ->latest();

        $activity = $activityQuery->paginate($perPage, ['*'], 'activity_page')->withQueryString();

        $counts = [
            'hosting' => $customer->hostingAccounts()->count(),
            'invoices' => $customer->invoices()->count(),
            'tickets' => $customer->tickets()->count(),
        ];

        return view('admin.customers.show', compact(
            'customer', 'hostingAccounts', 'orders', 'invoices', 'tickets', 'domains',
            'contacts', 'walletTransactions', 'activity', 'filters', 'statusOptions', 'counts', 'search'
        ));
    }

    private function exportTabCsv(Request $request, Customer $customer, string $tab, string $search, array $filters): StreamedResponse
    {
        $filename = 'customer-'.$tab.'-'.now()->format('Y-m-d_His').'.csv';

        switch ($tab) {
            case 'hosting':
                $headers = ['Service #', 'Product', 'Server', 'IP addresses', 'Domain', 'Cycle', 'Amount', 'Next billing', 'Status'];
                $query = $customer->hostingAccounts()
                    ->with(['product', 'server', 'ipAddresses', 'order.items', 'order.domain'])
                    ->when($search !== '', function ($q) use ($search) {
                        $q->where(function ($w) use ($search) {
                            $w->where('domain', 'like', "%{$search}%")
                                ->orWhere('host_name', 'like', "%{$search}%")
                                ->orWhere('id', 'like', "%{$search}%")
                                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%"))
                                ->orWhereHas('server', fn ($s) => $s->where('name', 'like', "%{$search}%"));
                        });
                    })
                    ->when(($filters['hosting_status'] ?? '') !== '', fn ($q) => $q->where('status', $filters['hosting_status']))
                    ->latest();

                /** @var CsvStreamService $csv */
                $csv = app(CsvStreamService::class);

                return $csv->stream($filename, $headers, function ($handle) use ($query): void {
                    $query->chunk(500, function ($accounts) use ($handle): void {
                        foreach ($accounts as $account) {
                            $serviceOrder = $account->order;
                            $serviceItem = $serviceOrder?->items?->first(fn ($item) => $item->product_id === $account->product_id)
                                ?? $serviceOrder?->items?->first();
                            $cycle = $serviceItem?->billing_cycle ?? $serviceOrder?->billing_cycle ?? $account->product?->billing_cycle;
                            $amount = $serviceItem?->total ?? $serviceOrder?->total;
                            $nextBilling = $serviceItem?->next_billing_date ?? $serviceOrder?->next_billing_date ?? $account->next_due_date;
                            $domainName = $account->domain ?? $serviceItem?->domain_name ?? $serviceOrder?->domain_name ?? $serviceOrder?->domain?->name;
                            $ips = $account->ipAddresses->pluck('ip_address')->implode(', ');

                            fputcsv($handle, [
                                $account->id,
                                $account->product?->name ?? '',
                                $account->server?->name ?? '',
                                $ips,
                                $domainName ?? '',
                                $cycle ? ucfirst(str_replace('_', ' ', $cycle)) : '',
                                $amount !== null ? number_format((float) $amount, 2, '.', '') : '',
                                $nextBilling?->format('Y-m-d H:i') ?? '',
                                $account->status,
                            ]);
                        }
                    });
                });

            case 'orders':
                $headers = ['Order #', 'Product', 'Cycle', 'Total', 'Status', 'Created'];
                $query = $customer->orders()
                    ->with('product')
                    ->when($search !== '', function ($q) use ($search) {
                        $q->where(function ($w) use ($search) {
                            $w->where('order_number', 'like', "%{$search}%")
                                ->orWhere('domain_name', 'like', "%{$search}%")
                                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%"));
                        });
                    })
                    ->when(($filters['order_status'] ?? '') !== '', fn ($q) => $q->where('status', $filters['order_status']))
                    ->latest();

                $csv = app(CsvStreamService::class);

                return $csv->stream($filename, $headers, function ($handle) use ($query): void {
                    $query->chunk(500, function ($orders) use ($handle): void {
                        foreach ($orders as $order) {
                            fputcsv($handle, [
                                $order->order_no,
                                $order->product?->name ?? '',
                                ucfirst(str_replace('_', ' ', (string) $order->billing_cycle)),
                                number_format((float) $order->total, 2, '.', ''),
                                $order->status,
                                $order->created_at?->format('Y-m-d H:i') ?? '',
                            ]);
                        }
                    });
                });

            case 'invoices':
                $headers = ['Invoice #', 'Total', 'Status', 'Due date'];
                $query = $customer->invoices()
                    ->when($search !== '', fn ($q) => $q->where('invoice_no', 'like', "%{$search}%"))
                    ->when(($filters['invoice_status'] ?? '') !== '', fn ($q) => $q->where('status', $filters['invoice_status']))
                    ->latest();

                $csv = app(CsvStreamService::class);

                return $csv->stream($filename, $headers, function ($handle) use ($query): void {
                    $query->chunk(500, function ($invoices) use ($handle): void {
                        foreach ($invoices as $invoice) {
                            fputcsv($handle, [
                                $invoice->invoice_no,
                                number_format((float) $invoice->total, 2, '.', ''),
                                $invoice->status,
                                $invoice->due_date?->format('Y-m-d H:i') ?? '',
                            ]);
                        }
                    });
                });

            case 'tickets':
                $headers = ['Ticket #', 'Subject', 'Priority', 'Status'];
                $query = $customer->tickets()
                    ->when($search !== '', function ($q) use ($search) {
                        $q->where(function ($w) use ($search) {
                            $w->where('ticket_no', 'like', "%{$search}%")
                                ->orWhere('subject', 'like', "%{$search}%");
                        });
                    })
                    ->when(($filters['ticket_status'] ?? '') !== '', fn ($q) => $q->where('status', $filters['ticket_status']))
                    ->latest();

                $csv = app(CsvStreamService::class);

                return $csv->stream($filename, $headers, function ($handle) use ($query): void {
                    $query->chunk(500, function ($tickets) use ($handle): void {
                        foreach ($tickets as $ticket) {
                            fputcsv($handle, [
                                $ticket->ticket_no,
                                $ticket->subject,
                                $ticket->priority,
                                $ticket->status,
                            ]);
                        }
                    });
                });

            case 'domains':
                $headers = ['Domain', 'Type', 'Expiry', 'Status'];
                $query = $customer->domains()
                    ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                    ->when(($filters['domain_status'] ?? '') !== '', fn ($q) => $q->where('status', $filters['domain_status']))
                    ->latest();

                $csv = app(CsvStreamService::class);

                return $csv->stream($filename, $headers, function ($handle) use ($query): void {
                    $query->chunk(500, function ($domains) use ($handle): void {
                        foreach ($domains as $domain) {
                            fputcsv($handle, [
                                $domain->name,
                                ucfirst((string) $domain->type),
                                $domain->expiry_date?->format('Y-m-d H:i') ?? '',
                                $domain->status,
                            ]);
                        }
                    });
                });

            case 'contacts':
                $headers = ['Name', 'Email', 'Phone', 'Role', 'Type', 'Primary'];
                $query = $customer->contacts()
                    ->when($search !== '', function ($q) use ($search) {
                        $q->where(function ($w) use ($search) {
                            $w->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%")
                                ->orWhere('role', 'like', "%{$search}%");
                        });
                    })
                    ->orderByDesc('is_primary')
                    ->orderBy('created_at');

                $csv = app(CsvStreamService::class);

                return $csv->stream($filename, $headers, function ($handle) use ($query): void {
                    $query->chunk(500, function ($contacts) use ($handle): void {
                        foreach ($contacts as $contact) {
                            fputcsv($handle, [
                                trim($contact->first_name.' '.$contact->last_name),
                                $contact->email,
                                $contact->phone ?? '',
                                $contact->role ?? '',
                                $contact->status,
                                $contact->is_primary ? 'Yes' : 'No',
                            ]);
                        }
                    });
                });

            case 'billing':
                $headers = ['Date', 'Type', 'Amount', 'Description', 'By'];
                $query = $customer->walletTransactions()
                    ->with('adminUser')
                    ->when($search !== '', function ($q) use ($search) {
                        $q->where(function ($w) use ($search) {
                            $w->where('description', 'like', "%{$search}%")
                                ->orWhere('type', 'like', "%{$search}%");
                        });
                    })
                    ->when(($filters['wallet_type'] ?? '') !== '', fn ($q) => $q->where('type', $filters['wallet_type']))
                    ->latest();

                $csv = app(CsvStreamService::class);

                return $csv->stream($filename, $headers, function ($handle) use ($query): void {
                    $query->chunk(500, function ($txs) use ($handle): void {
                        foreach ($txs as $tx) {
                            fputcsv($handle, [
                                $tx->created_at?->format('Y-m-d H:i') ?? '',
                                ucfirst(str_replace('_', ' ', (string) $tx->type)),
                                number_format((float) $tx->amount, 2, '.', ''),
                                $tx->description ?? '',
                                $tx->adminUser?->full_name ?? '',
                            ]);
                        }
                    });
                });

            case 'activity':
                $headers = ['When', 'Action', 'Description', 'By'];
                $query = ActivityLog::query()
                    ->where('customer_id', $customer->id)
                    ->with('user')
                    ->when($search !== '', function ($q) use ($search) {
                        $q->where(function ($w) use ($search) {
                            $w->where('action', 'like', "%{$search}%")
                                ->orWhere('description', 'like', "%{$search}%");
                        });
                    })
                    ->latest();

                $csv = app(CsvStreamService::class);

                return $csv->stream($filename, $headers, function ($handle) use ($query): void {
                    $query->chunk(500, function ($entries) use ($handle): void {
                        foreach ($entries as $entry) {
                            fputcsv($handle, [
                                $entry->created_at?->format('Y-m-d H:i') ?? '',
                                $entry->action,
                                $entry->description,
                                $entry->user?->full_name ?? 'System',
                            ]);
                        }
                    });
                });

            default:
                abort(404);
        }
    }

    public function edit(Customer $customer): View
    {
        $customer->load('user');

        return view('admin.customers.edit', compact('customer'));
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->normalizePhone($request);
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($customer->user_id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'status' => ['required', Rule::in(['active', 'inactive', 'suspended'])],
        ]);

        try {
            DB::transaction(function () use ($validated, $customer) {
                $legacyAddress = $this->compileLegacyAddress($validated);
                $customer->user->update([
                    'email' => $validated['email'],
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'phone' => $validated['phone'] ?? null,
                    'company' => $validated['company'] ?? null,
                    'address' => $legacyAddress,
                    'address_line1' => $validated['address_line1'] ?? null,
                    'address_line2' => $validated['address_line2'] ?? null,
                    'city' => $validated['city'] ?? null,
                    'state' => $validated['state'] ?? null,
                    'postcode' => $validated['postcode'] ?? null,
                    'country' => $validated['country'] ?? 'India',
                    'status' => $validated['status'] === 'active' ? 'active' : 'inactive',
                ]);

                $customer->update([
                    'company' => $validated['company'] ?? null,
                    'tax_id' => $validated['tax_id'] ?? null,
                    'state_code' => $this->resolveStateCode($validated['state'] ?? null),
                    'status' => $validated['status'],
                ]);

                CustomerUpdated::dispatch($customer->fresh());
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not update customer: '.$e->getMessage()]);
        }

        return redirect()
            ->route('admin.customers.show', $customer)
            ->with('success', "Customer {$customer->display_id} updated.");
    }

    private function compileLegacyAddress(array $validated): ?string
    {
        $parts = array_filter([
            $validated['address_line1'] ?? null,
            $validated['address_line2'] ?? null,
            $validated['city'] ?? null,
            $validated['state'] ?? null,
            $validated['postcode'] ?? null,
            $validated['country'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if ($parts !== []) {
            return implode(', ', $parts);
        }

        return $validated['address'] ?? null;
    }

    /**
     * The customer's GST state code, derived from the address state.
     *
     * Returns a code from GstStateCodes (the numeric GST vocabulary) or null.
     * It used to return two-letter codes, and — worse — fell back to the first
     * two letters of whatever was typed, so "Bengaluru" became "BE". Neither
     * could ever equal the company's numeric code in
     * GstTaxService::isIntraState(), which meant every customer was billed as
     * inter-state. Null for an address we cannot place (a foreign one, most
     * legitimately) is deliberate: a wrong code is worse than no code, and the
     * full name is still stored on `customers.state`.
     */
    private function resolveStateCode(?string $state): ?string
    {
        return GstStateCodes::normalize($state);
    }

    private function normalizePhone(Request $request): void
    {
        if ($request->has('phone_code') || $request->has('phone_number')) {
            $code = trim((string) $request->input('phone_code', ''));
            $number = trim((string) $request->input('phone_number', ''));
            // if hidden phone already correct and code/number empty, keep hidden
            if ($code === '' && $number === '') {
                return;
            }
            // default to +91 if code missing but number present
            if ($code === '' && $number !== '') {
                $code = '+91';
            }
            $combined = $number !== '' ? trim($code.' '.$number) : $code;
            $request->merge(['phone' => $combined]);
        }
    }

    public function destroy(Request $request, Customer $customer): RedirectResponse
    {
        DB::transaction(function () use ($customer) {
            /*
             * `service_instances.customer_id` and its children (`usage_records`,
             * `subscription_periods`, `subscription_changes`) use
             * `restrictOnDelete()` foreign keys, so the user-level cascade below
             * throws a FK violation whenever the customer has a provisioned
             * service. Remove that subtree in dependency order first so the
             * restrict constraints can never block the delete.
             */
            $serviceIds = ServiceInstance::where('customer_id', $customer->id)->pluck('id');

            SubscriptionChange::whereIn('service_id', $serviceIds)->delete();
            SubscriptionPeriod::whereIn('service_id', $serviceIds)->delete();
            UsageRecord::whereIn('service_id', $serviceIds)->delete();

            // forceDelete because ServiceInstance uses SoftDeletes, but the FK
            // restrict operates on the physical row regardless of deleted_at.
            ServiceInstance::where('customer_id', $customer->id)->forceDelete();

            CustomerDeleted::dispatch($customer);

            $customer->user->delete(); // cascades to customers, notes, contacts, etc.
        });

        return redirect()
            ->route('admin.customers.index')
            ->with('success', "Customer {$customer->display_id} deleted.");
    }

    /**
     * Add an embedded note to the customer detail page.
     */
    public function storeNote(Request $request, Customer $customer): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:5000'],
            'is_important' => ['sometimes', 'boolean'],
        ]);

        $note = CustomerNote::create([
            'customer_id' => $customer->id,
            'user_id' => $request->user()->id,
            'note' => $validated['note'],
            'is_important' => $request->boolean('is_important'),
        ]);

        $this->logActivity($customer, AuditEvent::NoteAdded, 'Note added', [
            'note_id' => $note->id,
            'by' => $request->user()->email,
        ]);

        return redirect()
            ->route('admin.customers.show', ['customer' => $customer, 'tab' => 'notes'])
            ->with('success', 'Note added.');
    }

    public function destroyNote(Request $request, Customer $customer, CustomerNote $note): RedirectResponse
    {
        abort_unless($note->customer_id === $customer->id, 404);

        $note->delete();

        $this->logActivity($customer, AuditEvent::NoteDeleted, 'Note deleted', [
            'note_id' => $note->id,
            'by' => $request->user()->email,
        ]);

        return redirect()
            ->route('admin.customers.show', ['customer' => $customer, 'tab' => 'notes'])
            ->with('success', 'Note deleted.');
    }

    public function updateNote(Request $request, Customer $customer, CustomerNote $note): RedirectResponse
    {
        abort_unless($note->customer_id === $customer->id, 404);

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:5000'],
            'is_important' => ['sometimes', 'boolean'],
        ]);

        $note->update([
            'note' => $validated['note'],
            'is_important' => $request->boolean('is_important'),
        ]);

        $this->logActivity($customer, AuditEvent::NoteUpdated, 'Note updated', [
            'note_id' => $note->id,
            'by' => $request->user()->email,
        ]);

        return redirect()
            ->route('admin.customers.show', ['customer' => $customer, 'tab' => 'notes'])
            ->with('success', 'Note updated.');
    }

    /**
     * Add an embedded contact. Exactly one primary contact per customer is
     * enforced (a new primary demotes the previous one; if none exists the
     * first contact becomes primary).
     */
    public function storeContact(Request $request, Customer $customer): RedirectResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['nullable', 'string', 'max:100'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        $contact = DB::transaction(function () use ($validated, $customer, $request) {
            $isPrimary = $request->boolean('is_primary');

            if ($customer->contacts()->count() === 0) {
                $isPrimary = true;
            } elseif ($isPrimary) {
                $customer->contacts()->update(['is_primary' => false]);
            }

            return CustomerContact::create([
                'customer_id' => $customer->id,
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'role' => $validated['role'] ?? null,
                'is_primary' => $isPrimary,
                'status' => 'active',
            ]);
        });

        $this->logActivity($customer, AuditEvent::ContactCreated, 'Contact created', [
            'contact_id' => $contact->id,
            'by' => $request->user()->email,
        ]);

        return redirect()
            ->route('admin.customers.show', ['customer' => $customer, 'tab' => 'contacts'])
            ->with('success', 'Contact added.');
    }

    public function updateContact(Request $request, Customer $customer, CustomerContact $contact): RedirectResponse
    {
        abort_unless($contact->customer_id === $customer->id, 404);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['nullable', 'string', 'max:100'],
            'is_primary' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        DB::transaction(function () use ($validated, $customer, $contact, $request) {
            $status = $validated['status'] ?? $contact->status;

            // Inactive contacts can never be primary.
            if ($status === 'inactive') {
                $isPrimary = false;
            } elseif ($request->boolean('is_primary')) {
                $isPrimary = true;
            } else {
                $isPrimary = $contact->is_primary;
            }

            if ($isPrimary && ! $contact->is_primary) {
                $customer->contacts()->where('id', '!=', $contact->id)->update(['is_primary' => false]);
            }

            $contact->update([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'role' => $validated['role'] ?? null,
                'is_primary' => $isPrimary,
                'status' => $status,
            ]);

            // Keep exactly one primary among active contacts (same invariant as
            // destroyContact). If this update removed the last primary (e.g.
            // inactivating the primary contact), promote the newest active one.
            if (! $isPrimary && $customer->contacts()->where('is_primary', true)->doesntExist()) {
                $customer->contacts()->where('status', 'active')->orderByDesc('id')->first()?->update(['is_primary' => true]);
            }
        });

        $this->logActivity($customer, AuditEvent::ContactUpdated, 'Contact updated', [
            'contact_id' => $contact->id,
            'by' => $request->user()->email,
        ]);

        return redirect()
            ->route('admin.customers.show', ['customer' => $customer, 'tab' => 'contacts'])
            ->with('success', 'Contact updated.');
    }

    public function destroyContact(Request $request, Customer $customer, CustomerContact $contact): RedirectResponse
    {
        abort_unless($contact->customer_id === $customer->id, 404);

        $contact->delete();

        // keep exactly one primary: promote the newest remaining contact
        if ($customer->contacts()->where('is_primary', true)->doesntExist()) {
            $customer->contacts()->orderByDesc('id')->first()?->update(['is_primary' => true]);
        }

        $this->logActivity($customer, AuditEvent::ContactDeleted, 'Contact deleted', [
            'contact_id' => $contact->id,
            'by' => $request->user()->email,
        ]);

        return redirect()
            ->route('admin.customers.show', ['customer' => $customer, 'tab' => 'contacts'])
            ->with('success', 'Contact deleted.');
    }

    /**
     * Wallet adjustment: deposit / credit / debit. Keeps the customer's
     * `balance` (account funds) and `credit` (credit limit) in sync with the
     * wallet ledger, mirroring the reference wallet behavior.
     */
    public function storeWallet(Request $request, Customer $customer): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['deposit', 'credit', 'debit', 'invoice_payment'])],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($validated, $customer, $request) {
            CustomerWallet::create([
                'customer_id' => $customer->id,
                'type' => $validated['type'],
                'amount' => $validated['amount'],
                'balance_type' => in_array($validated['type'], ['credit'], true) ? 'credit' : 'account',
                'description' => $validated['description'] ?? null,
                'admin_user_id' => $request->user()->id,
            ]);

            $delta = match ($validated['type']) {
                'deposit', 'credit' => (float) $validated['amount'],
                default => -1 * (float) $validated['amount'],
            };

            if ($validated['type'] === 'credit') {
                $customer->increment('credit', $delta);
            } else {
                $customer->increment('balance', $delta);
            }
        });

        $this->logActivity($customer, AuditEvent::WalletAdjusted, "Wallet {$validated['type']} of {$validated['amount']}", [
            'amount' => $validated['amount'],
            'type' => $validated['type'],
            'by' => $request->user()->email,
        ]);

        return redirect()
            ->route('admin.customers.show', ['customer' => $customer, 'tab' => 'billing'])
            ->with('success', 'Wallet updated.');
    }

    /**
     * Write an entry to the customer activity log.
     */
    private function logActivity(Customer $customer, AuditEvent $action, string $description, array $metadata = []): void
    {
        app(AuditRecorder::class)->activity($action, $customer, $metadata, $description);
    }
}
