<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HostingAccount;
use App\Services\HostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Sanctum-protected hosting REST API (Session 3A.2).
 *
 * Mirrors the reference /api/hosting endpoints (index / store / show /
 * update / delete) plus the lifecycle actions suspend / unsuspend /
 * change-package. Create forces status 'pending' like the reference
 * CreateHostingCommand; delete is a soft delete (status -> terminated) and
 * every write is audited through HostingService.
 */
class HostingController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private readonly HostingService $hostingService) {}

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $accounts = HostingAccount::query()
            ->with(['customer.user:id,email,first_name,last_name', 'product:id,name,price', 'server:id,name,ip_address'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('domain', 'like', "%{$search}%")
                        ->orWhere('host_name', 'like', "%{$search}%")
                        ->orWhere('id', 'like', "%{$search}%")
                        ->orWhereHas('customer.user', function ($u) use ($search) {
                            $u->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->when(in_array($status, HostingService::STATUSES, true), function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->integer('product_id')))
            ->when($request->filled('server_id'), fn ($q) => $q->where('server_id', $request->integer('server_id')))
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        return response()->json([
            'data' => $accounts->map(fn (HostingAccount $a) => $this->present($a)),
            'meta' => [
                'current_page' => $accounts->currentPage(),
                'last_page' => $accounts->lastPage(),
                'per_page' => $accounts->perPage(),
                'total' => $accounts->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules(customerId: (int) $request->input('customer_id')));

        $account = HostingAccount::create([
            'customer_id' => $validated['customer_id'],
            'product_id' => $validated['product_id'],
            'server_id' => $validated['server_id'] ?? null,
            'order_id' => $validated['order_id'] ?? null,
            'username' => $validated['username'] ?? null,
            'domain' => $validated['domain'] ?? null,
            'host_name' => $validated['host_name'] ?? null,
            'disk_quota' => $validated['disk_quota'] ?? 0,
            'disk_used' => 0,
            'bandwidth_quota' => $validated['bandwidth_quota'] ?? 0,
            'bandwidth_used' => 0,
            'panel_account_id' => $validated['panel_account_id'] ?? null,
            'username_prefix' => $validated['username_prefix'] ?? null,
            'password' => $validated['password'] ?? null,
            'status' => HostingService::STATUS_PENDING,
        ]);

        $this->hostingService->audit(
            $account,
            'hosting.created',
            "Hosting account #{$account->id} created via API",
            ['by' => $request->user()?->email],
        );

        return response()->json(['data' => $this->present($account->fresh()->load(['customer.user', 'product', 'server']))], 201);
    }

    public function show(HostingAccount $hostingAccount): JsonResponse
    {
        $hostingAccount->load(['customer.user', 'product.group', 'server', 'order']);

        return response()->json(['data' => $this->present($hostingAccount, true)]);
    }

    public function update(Request $request, HostingAccount $hostingAccount): JsonResponse
    {
        $validated = $request->validate(
            $this->rules($hostingAccount, (int) $request->input('customer_id', $hostingAccount->customer_id), true)
            + ['status' => ['sometimes', Rule::in(HostingService::STATUSES)]]
        );

        if (isset($validated['password']) && trim($validated['password']) === '') {
            unset($validated['password']);
        }

        $hostingAccount->update($validated);

        $this->hostingService->audit(
            $hostingAccount,
            'hosting.updated',
            "Hosting account #{$hostingAccount->id} updated via API",
            ['by' => $request->user()?->email],
        );

        return response()->json(['data' => $this->present($hostingAccount->fresh()->load(['customer.user', 'product', 'server']))]);
    }

    /**
     * Soft delete: terminate the account (reference handleDestroy).
     */
    public function destroy(Request $request, HostingAccount $hostingAccount): JsonResponse
    {
        try {
            $this->hostingService->terminate($hostingAccount, $request->input('reason'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Hosting account terminated.'], 200);
    }

    public function suspend(Request $request, HostingAccount $hostingAccount): JsonResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        try {
            $this->hostingService->suspend($hostingAccount, $validated['reason'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($hostingAccount->fresh())]);
    }

    public function unsuspend(HostingAccount $hostingAccount): JsonResponse
    {
        try {
            $this->hostingService->unsuspend($hostingAccount);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($hostingAccount->fresh())]);
    }

    public function changePackage(Request $request, HostingAccount $hostingAccount): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
        ]);

        try {
            $this->hostingService->changePackage($hostingAccount, (int) $validated['product_id']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($hostingAccount->fresh()->load('product'))]);
    }

    /**
     * API resource shape (mirrors the reference hosting account fields).
     */
    private function present(HostingAccount $account, bool $detailed = false): array
    {
        $data = [
            'id' => $account->id,
            'customer_id' => $account->customer_id,
            'product_id' => $account->product_id,
            'server_id' => $account->server_id,
            'order_id' => $account->order_id,
            'username' => $account->username,
            'domain' => $account->domain,
            'host_name' => $account->host_name,
            'disk_quota' => (int) $account->disk_quota,
            'disk_used' => (int) $account->disk_used,
            'disk_usage_percent' => $account->diskUsagePercent(),
            'bandwidth_quota' => (int) $account->bandwidth_quota,
            'bandwidth_used' => (int) $account->bandwidth_used,
            'bandwidth_usage_percent' => $account->bandwidthUsagePercent(),
            'panel_account_id' => $account->panel_account_id,
            'username_prefix' => $account->username_prefix,
            'status' => $account->status,
            'suspended_reason' => $account->suspended_reason,
            'suspended_at' => $account->suspended_at?->toIso8601String(),
            'created_at' => $account->created_at?->toIso8601String(),
            'updated_at' => $account->updated_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['customer'] = $account->customer ? [
                'id' => $account->customer->id,
                'display_id' => $account->customer->display_id,
                'name' => $account->customer->full_name,
                'email' => $account->customer->user?->email,
            ] : null;

            $data['product'] = $account->product ? [
                'id' => $account->product->id,
                'name' => $account->product->name,
                'group_name' => $account->product->group?->name,
                'price' => (float) $account->product->price,
            ] : null;

            $data['server'] = $account->server ? [
                'id' => $account->server->id,
                'name' => $account->server->name,
                'ip_address' => $account->server->ip_address,
                'panel_type' => $account->server->panel_type,
            ] : null;
        }

        return $data;
    }

    private function rules(?HostingAccount $account = null, int $customerId = 0, bool $partial = false): array
    {
        $ignore = $account?->id;

        $domainRules = ['nullable', 'string', 'max:255', 'regex:/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?(\.[a-zA-Z]{2,})+$/'];

        if ($customerId > 0) {
            $domainRules[] = Rule::unique('hosting_accounts', 'domain')
                ->where(fn ($q) => $q->where('customer_id', $customerId))
                ->ignore($ignore);
        }

        $rules = [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'server_id' => ['nullable', 'integer', 'exists:servers,id'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            // username is legacy/module-managed — optional
            'username' => ['nullable', 'string', 'max:100'],
            'domain' => $domainRules,
            'host_name' => ['nullable', 'string', 'max:255', Rule::unique('hosting_accounts', 'host_name')->ignore($ignore)],
            'disk_quota' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'bandwidth_quota' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'panel_account_id' => ['nullable', 'string', 'max:255'],
            'username_prefix' => ['nullable', 'string', 'max:50'],
            'password' => ['nullable', 'string', 'max:255'],
        ];

        if ($partial) {
            $rules = array_map(fn ($rule) => array_merge(['sometimes'], $rule), $rules);
        }

        return $rules;
    }
}
