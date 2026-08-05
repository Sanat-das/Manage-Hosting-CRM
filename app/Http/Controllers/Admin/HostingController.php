<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Product;
use App\Models\Server;
use App\Services\HostingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Admin hosting account management (Session 3A.2).
 *
 * Ported from the reference Modules\Hosting\Presentation\HostingController
 * plus the local pilot (CustomerController) conventions:
 *   - create forces status 'pending' (reference CreateHostingCommand)
 *   - every write is audited (audit_log + customer activity_log)
 *   - status lifecycle actions (suspend/unsuspend/changePackage/destroy)
 *     delegate to HostingService so the guards stay in one place
 *
 * Permission gates: hosting.view (read), hosting.manage (write). The
 * reference granular hosting.create/edit/delete permissions do not exist in
 * the local seeder, so all write actions use hosting.manage.
 */
class HostingController extends Controller
{
    private const PER_PAGE = 20;

    /** Product types that count as hosting packages (products.type enum). */
    private const HOSTING_PRODUCT_TYPES = ['shared_hosting', 'reseller', 'vps', 'dedicated', 'hosting'];

    /** Reference domain-name pattern (Modules\Hosting\Domain\HostingAccount). */
    private const DOMAIN_PATTERN = '/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?(\.[a-zA-Z]{2,})+$/';

    public function __construct(private readonly HostingService $hostingService)
    {
    }

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $accounts = HostingAccount::query()
            ->with(['customer.user:id,email,first_name,last_name', 'product:id,name,type', 'server:id,name,ip_address'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('username', 'like', "%{$search}%")
                        ->orWhere('domain', 'like', "%{$search}%")
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
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.hosting.index', compact('accounts', 'search', 'status'));
    }

    public function create(): View
    {
        return view('admin.hosting.create', $this->formOptions());
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(
            $this->rules(customerId: (int) $request->input('customer_id'))
        );

        try {
            $account = DB::transaction(function () use ($validated, $request) {
                $account = HostingAccount::create([
                    'customer_id' => $validated['customer_id'],
                    'product_id' => $validated['product_id'],
                    'server_id' => $validated['server_id'] ?? null,
                    'username' => $validated['username'],
                    'domain' => $validated['domain'] ?? null,
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
                    "Hosting account {$account->username} created",
                    ['by' => $request->user()->email],
                );

                return $account;
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not create hosting account: '.$e->getMessage()]);
        }

        return redirect()
            ->route('admin.hosting.show', $account)
            ->with('success', "Hosting account {$account->username} created (status: pending).");
    }

    public function show(HostingAccount $hostingAccount): View
    {
        $hostingAccount->load(['customer.user', 'product', 'server', 'order']);

        $audit = AuditLog::query()
            ->where('entity_type', 'hosting_account')
            ->where('entity_id', $hostingAccount->id)
            ->with('user:id,first_name,last_name,email')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $packages = Product::query()
            ->where('status', 'active')
            ->whereIn('type', self::HOSTING_PRODUCT_TYPES)
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'price', 'billing_cycle']);

        return view('admin.hosting.show', compact('hostingAccount', 'audit', 'packages'));
    }

    public function edit(HostingAccount $hostingAccount): View
    {
        $options = $this->formOptions();

        // Ensure the currently assigned server is always selectable, even if it
        // has since been marked inactive.
        if ($hostingAccount->server_id !== null && $options['servers']->doesntContain('id', $hostingAccount->server_id)) {
            $options['servers'] = $options['servers']
                ->push(Server::find($hostingAccount->server_id))
                ->filter()
                ->sortBy('name')
                ->values();
        }

        return view('admin.hosting.edit', $options + ['hostingAccount' => $hostingAccount]);
    }

    public function update(Request $request, HostingAccount $hostingAccount): RedirectResponse
    {
        $validated = $request->validate(
            $this->rules($hostingAccount, (int) $request->input('customer_id', $hostingAccount->customer_id))
            + ['status' => ['sometimes', Rule::in(HostingService::STATUSES)]]
        );

        // Empty password field on the edit form means "leave unchanged".
        if (isset($validated['password']) && trim($validated['password']) === '') {
            unset($validated['password']);
        }

        try {
            DB::transaction(function () use ($hostingAccount, $validated, $request) {
                $hostingAccount->update($validated);

                $this->hostingService->audit(
                    $hostingAccount,
                    'hosting.updated',
                    "Hosting account {$hostingAccount->username} updated",
                    ['by' => $request->user()->email],
                );
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not update hosting account: '.$e->getMessage()]);
        }

        return redirect()
            ->route('admin.hosting.show', $hostingAccount)
            ->with('success', "Hosting account {$hostingAccount->username} updated.");
    }

    /**
     * Delete = reference soft delete: terminate the account (status ->
     * terminated) rather than hard-removing the row, preserving the audit
     * trail and any linked billing history.
     */
    public function destroy(Request $request, HostingAccount $hostingAccount): RedirectResponse
    {
        try {
            $this->hostingService->terminate($hostingAccount, $request->input('reason'));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.hosting.index')
            ->with('success', "Hosting account {$hostingAccount->username} terminated.");
    }

    public function suspend(Request $request, HostingAccount $hostingAccount): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        try {
            $this->hostingService->suspend($hostingAccount, $validated['reason'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', "Hosting account {$hostingAccount->username} suspended.");
    }

    public function unsuspend(HostingAccount $hostingAccount): RedirectResponse
    {
        try {
            $this->hostingService->unsuspend($hostingAccount);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Hosting account {$hostingAccount->username} reactivated.");
    }

    public function changePackage(Request $request, HostingAccount $hostingAccount): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
        ]);

        try {
            $this->hostingService->changePackage($hostingAccount, (int) $validated['product_id']);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', "Hosting account {$hostingAccount->username} package changed.");
    }

    private function formOptions(): array
    {
        return [
            'customers' => Customer::query()
                ->with('user:id,first_name,last_name,email')
                ->orderBy('id')
                ->get(),
            'products' => Product::query()
                ->where('status', 'active')
                ->whereIn('type', self::HOSTING_PRODUCT_TYPES)
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'price', 'billing_cycle']),
            'servers' => Server::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'ip_address', 'panel_type']),
        ];
    }

    /**
     * Validation rules. The domain must be unique per customer (reference
     * findByDomainAndCustomer business rule). $customerId is required for the
     * scoped uniqueness check.
     */
    private function rules(?HostingAccount $account = null, int $customerId = 0): array
    {
        $ignore = $account?->id;

        $domainRules = ['nullable', 'string', 'max:255', Rule::regex(self::DOMAIN_PATTERN)];

        if ($customerId > 0) {
            $domainRules[] = Rule::unique('hosting_accounts', 'domain')
                ->where(fn ($q) => $q->where('customer_id', $customerId))
                ->ignore($ignore);
        }

        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'server_id' => ['nullable', 'integer', 'exists:servers,id'],
            'username' => ['required', 'string', 'max:100', Rule::unique('hosting_accounts', 'username')->ignore($ignore)],
            'domain' => $domainRules,
            'disk_quota' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'bandwidth_quota' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'panel_account_id' => ['nullable', 'string', 'max:255'],
            'username_prefix' => ['nullable', 'string', 'max:50'],
            'password' => ['nullable', 'string', 'max:255'],
        ];
    }
}
