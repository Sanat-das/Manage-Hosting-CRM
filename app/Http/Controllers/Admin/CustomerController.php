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
use App\Models\ServiceInstance;
use App\Models\SubscriptionChange;
use App\Models\SubscriptionPeriod;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\Exports\CsvStreamService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
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

    public function show(Customer $customer): View
    {
        $customer->load([
            'user',
            'hostingAccounts' => fn ($q) => $q->with([
                'product',
                'server',
                'ipAddresses.subnet',
                'order.items',
                'order.statusHistory',
                'order.domain',
            ])->latest(),
            'orders' => fn ($q) => $q->with('product')->latest(),
            'domains' => fn ($q) => $q->latest(),
            'tickets' => fn ($q) => $q->latest(),
            'notes' => fn ($q) => $q->with('user')->orderByDesc('is_important')->orderByDesc('created_at'),
            'contacts' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('created_at'),
        ]);

        $perPage = 10;

        $invoices = $customer->invoices()->latest()->paginate($perPage, ['*'], 'invoices_page');
        $orders = $customer->orders()->with('product')->latest()->paginate($perPage, ['*'], 'orders_page');
        $walletTransactions = $customer->walletTransactions()->with('adminUser')->latest()->paginate($perPage, ['*'], 'wallet_page');

        $activity = ActivityLog::query()
            ->where('customer_id', $customer->id)
            ->with('user')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'activity_page');

        return view('admin.customers.show', compact('customer', 'invoices', 'orders', 'walletTransactions', 'activity'));
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

    private function resolveStateCode(?string $state): ?string
    {
        if ($state === null || trim($state) === '') {
            return null;
        }

        $map = [
            'andhra pradesh' => 'AP', 'arunachal pradesh' => 'AR', 'assam' => 'AS', 'bihar' => 'BR',
            'chhattisgarh' => 'CG', 'goa' => 'GA', 'gujarat' => 'GJ', 'haryana' => 'HR',
            'himachal pradesh' => 'HP', 'jharkhand' => 'JH', 'karnataka' => 'KA', 'kerala' => 'KL',
            'madhya pradesh' => 'MP', 'maharashtra' => 'MH', 'manipur' => 'MN', 'meghalaya' => 'ML',
            'mizoram' => 'MZ', 'nagaland' => 'NL', 'odisha' => 'OR', 'punjab' => 'PB',
            'rajasthan' => 'RJ', 'sikkim' => 'SK', 'tamil nadu' => 'TN', 'telangana' => 'TG',
            'tripura' => 'TR', 'uttar pradesh' => 'UP', 'uttarakhand' => 'UK', 'west bengal' => 'WB',
            'delhi' => 'DL', 'jammu and kashmir' => 'JK', 'ladakh' => 'LA',
        ];

        $key = strtolower(trim($state));
        if (isset($map[$key])) {
            return $map[$key];
        }

        return strtoupper(substr(trim($state), 0, 2));
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

        $this->logActivity($customer, 'note_added', 'Note added', [
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

        $this->logActivity($customer, 'note_deleted', 'Note deleted', [
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

        $this->logActivity($customer, 'note_updated', 'Note updated', [
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

        $this->logActivity($customer, 'contact_created', 'Contact created', [
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

        $this->logActivity($customer, 'contact_updated', 'Contact updated', [
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

        $this->logActivity($customer, 'contact_deleted', 'Contact deleted', [
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

        $this->logActivity($customer, 'wallet_adjusted', "Wallet {$validated['type']} of {$validated['amount']}", [
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
    private function logActivity(Customer $customer, string $action, string $description, array $metadata = []): void
    {
        ActivityLog::create([
            'customer_id' => $customer->id,
            'user_id' => auth()->id(),
            'action' => $action,
            'description' => $description,
            'metadata' => $metadata ?: null,
            'ip_address' => request()->ip(),
        ]);
    }
}
