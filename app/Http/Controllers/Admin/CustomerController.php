<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Events\CustomerCreated;
use App\Events\CustomerDeleted;
use App\Events\CustomerUpdated;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerNote;
use App\Models\CustomerWallet;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

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

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $customers = Customer::query()
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
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.customers.index', compact('customers', 'search', 'status'));
    }

    public function create(): View
    {
        return view('admin.customers.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required', 'string', 'min:8', 'confirmed',
                'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/',
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'company' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'status' => ['required', Rule::in(['active', 'inactive', 'suspended'])],
        ]);

        try {
            $customer = DB::transaction(function () use ($validated, $request) {
                $user = User::create([
                    'email' => $validated['email'],
                    'password_hash' => Hash::make($validated['password']),
                    'role' => 'client',
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'phone' => $validated['phone'] ?? null,
                    'company' => $validated['company'] ?? null,
                    'address' => $validated['address'] ?? null,
                    'status' => $validated['status'] === 'active' ? 'active' : 'inactive',
                ]);

                $customer = Customer::create([
                    'user_id' => $user->id,
                    'company' => $validated['company'] ?? null,
                    'tax_id' => $validated['tax_id'] ?? null,
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

    public function show(Customer $customer): View
    {
        $customer->load([
            'user',
            'hostingAccounts' => fn ($q) => $q->latest(),
            'domains' => fn ($q) => $q->latest(),
            'invoices' => fn ($q) => $q->latest(),
            'tickets' => fn ($q) => $q->latest(),
            'notes' => fn ($q) => $q->with('user')->orderByDesc('is_important')->orderByDesc('created_at'),
            'contacts' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('created_at'),
            'walletTransactions' => fn ($q) => $q->with('adminUser')->latest(),
            'transactions' => fn ($q) => $q->latest(),
        ]);

        $activity = ActivityLog::query()
            ->where('customer_id', $customer->id)
            ->with('user')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('admin.customers.show', compact('customer', 'activity'));
    }

    public function edit(Customer $customer): View
    {
        $customer->load('user');

        return view('admin.customers.edit', compact('customer'));
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($customer->user_id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'company' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'status' => ['required', Rule::in(['active', 'inactive', 'suspended'])],
        ]);

        try {
            DB::transaction(function () use ($validated, $customer, $request) {
                $customer->user->update([
                    'email' => $validated['email'],
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'phone' => $validated['phone'] ?? null,
                    'company' => $validated['company'] ?? null,
                    'address' => $validated['address'] ?? null,
                    'status' => $validated['status'] === 'active' ? 'active' : 'inactive',
                ]);

                $customer->update([
                    'company' => $validated['company'] ?? null,
                    'tax_id' => $validated['tax_id'] ?? null,
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

    public function destroy(Request $request, Customer $customer): RedirectResponse
    {
        CustomerDeleted::dispatch($customer);

        $customer->user->delete(); // cascades to customers, notes, contacts, etc.

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
            'phone' => ['nullable', 'string', 'max:30'],
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
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['nullable', 'string', 'max:100'],
            'is_primary' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        DB::transaction(function () use ($validated, $customer, $contact, $request) {
            $isPrimary = $request->boolean('is_primary') || $validated['status'] === 'inactive' ? false : $contact->is_primary;

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
                'status' => $validated['status'] ?? $contact->status,
            ]);
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
