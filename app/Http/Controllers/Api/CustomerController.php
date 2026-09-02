<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ServiceInstance;
use App\Models\SubscriptionChange;
use App\Models\SubscriptionPeriod;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Sanctum-protected customer REST API (full CRUD).
 *
 * Mirrors the reference /api/customers endpoints: index (search/status
 * filters), store (creates the linked user with role=client), show,
 * update, destroy.
 */
class CustomerController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $customers = Customer::query()
            ->with('user:id,email,first_name,last_name,phone,status')
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
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => $customers->map(fn (Customer $c) => $this->present($c)),
            'meta' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/'],
            'phone' => ['nullable', 'string', 'max:30'],
            'company' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended'])],
        ]);

        $status = $validated['status'] ?? 'active';

        $customer = DB::transaction(function () use ($validated, $status) {
            $user = User::create([
                'email' => $validated['email'],
                'password_hash' => Hash::make($validated['password']),
                'role' => 'client',
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'phone' => $validated['phone'] ?? null,
                'company' => $validated['company'] ?? null,
                'address' => $validated['address'] ?? null,
                'status' => $status === 'active' ? 'active' : 'inactive',
            ]);

            return Customer::create([
                'user_id' => $user->id,
                'company' => $validated['company'] ?? null,
                'tax_id' => $validated['tax_id'] ?? null,
                'balance' => 0,
                'credit' => 0,
                'status' => $status,
            ]);
        });

        return response()->json(['data' => $this->present($customer->load('user:id,email,first_name,last_name,phone,status'))], 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        $customer->load([
            'user:id,email,first_name,last_name,phone,status',
            'hostingAccounts',
            'domains',
            'invoices',
            'tickets',
            'notes',
            'contacts',
        ]);

        return response()->json(['data' => $this->present($customer, true)]);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($customer->user_id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'company' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended'])],
        ]);

        DB::transaction(function () use ($validated, $customer) {
            $userData = array_intersect_key($validated, array_flip(['first_name', 'last_name', 'email', 'phone', 'company', 'address']));
            if (isset($validated['status'])) {
                $userData['status'] = $validated['status'] === 'active' ? 'active' : 'inactive';
            }
            if ($userData !== []) {
                $customer->user->update($userData);
            }

            $customerData = array_intersect_key($validated, array_flip(['company', 'tax_id', 'status']));
            if ($customerData !== []) {
                $customer->update($customerData);
            }
        });

        return response()->json(['data' => $this->present($customer->fresh()->load('user:id,email,first_name,last_name,phone,status'))]);
    }

    public function destroy(Customer $customer): JsonResponse
    {
        /*
         * `service_instances.customer_id` and its children (`usage_records`,
         * `subscription_periods`, `subscription_changes`) use restrictOnDelete()
         * FKs, so the user-level cascade throws whenever the customer has a
         * provisioned service. Remove that subtree in dependency order first.
         */
        DB::transaction(function () use ($customer) {
            $serviceIds = ServiceInstance::where('customer_id', $customer->id)->pluck('id');

            SubscriptionChange::whereIn('service_id', $serviceIds)->delete();
            SubscriptionPeriod::whereIn('service_id', $serviceIds)->delete();
            UsageRecord::whereIn('service_id', $serviceIds)->delete();

            // forceDelete because ServiceInstance uses SoftDeletes.
            ServiceInstance::where('customer_id', $customer->id)->forceDelete();

            $customer->user->delete(); // cascades to customers, notes, contacts, etc.
        });

        return response()->json(['message' => 'Customer deleted.'], 200);
    }

    /**
     * API resource shape (matches the reference customer fields).
     */
    private function present(Customer $customer, bool $detailed = false): array
    {
        $data = [
            'id' => $customer->id,
            'display_id' => $customer->display_id,
            'user_id' => $customer->user_id,
            'first_name' => $customer->user?->first_name,
            'last_name' => $customer->user?->last_name,
            'email' => $customer->user?->email,
            'phone' => $customer->user?->phone,
            'company' => $customer->company,
            'tax_id' => $customer->tax_id,
            'balance' => (float) $customer->balance,
            'credit' => (float) $customer->credit,
            'status' => $customer->status,
            'created_at' => $customer->created_at?->toIso8601String(),
            'updated_at' => $customer->updated_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['hosting_accounts'] = $customer->hostingAccounts->map(fn ($a) => [
                'id' => $a->id, 'username' => $a->username, 'domain' => $a->domain, 'status' => $a->status,
            ]);
            $data['domains'] = $customer->domains->map(fn ($d) => [
                'id' => $d->id, 'name' => $d->name, 'expiry_date' => $d->expiry_date?->toDateString(), 'status' => $d->status,
            ]);
            $data['invoices'] = $customer->invoices->map(fn ($i) => [
                'id' => $i->id, 'invoice_no' => $i->invoice_no, 'total' => (float) $i->total, 'status' => $i->status,
            ]);
            $data['tickets'] = $customer->tickets->map(fn ($t) => [
                'id' => $t->id, 'ticket_no' => $t->ticket_no, 'subject' => $t->subject, 'status' => $t->status,
            ]);
            $data['notes'] = $customer->notes->map(fn ($n) => [
                'id' => $n->id, 'note' => $n->note, 'is_important' => $n->is_important, 'created_at' => $n->created_at?->toIso8601String(),
            ]);
            $data['contacts'] = $customer->contacts->map(fn ($c) => [
                'id' => $c->id, 'first_name' => $c->first_name, 'last_name' => $c->last_name,
                'email' => $c->email, 'phone' => $c->phone, 'role' => $c->role, 'is_primary' => $c->is_primary,
            ]);
        }

        return $data;
    }
}
