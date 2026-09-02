<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Domain;
use App\Services\DomainService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin domain management.
 *
 * Route contract: routes/admin/domains.php
 * Permission gates: domains.view / domains.manage
 */
class DomainController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private readonly DomainService $domains) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $domains = Domain::query()
            ->with('customer.user')
            ->when($status !== '' && $status !== null, fn ($q) => $q->where('status', $status))
            ->when($search !== '', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            })
            ->gridSort([
                'domain' => 'name',
                'customer' => fn (Builder $q, string $dir) => $q->orderBy(Customer::select('company')->whereColumn('customers.id', 'domains.customer_id'), $dir),
                'registrar' => 'registrar',
                'expiry' => 'expiry_date',
                'auto_renew' => 'auto_renew',
                'status' => 'status',
                'created_at' => 'created_at',
            ])
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $statuses = DomainService::STATUS_LABELS;
        $stats = Domain::query()->selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count', 'status');
        $expiring = Domain::where('expiry_date', '<=', now()->addDays(30))
            ->where('expiry_date', '>=', now())
            ->where('status', 'active')
            ->count();

        return view('admin.domains.index', compact('domains', 'search', 'status', 'statuses', 'stats', 'expiring'));
    }

    public function create(): View
    {
        $customers = Customer::with('user')->orderBy('id')->get();

        return view('admin.domains.create', compact('customers'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'name' => ['required', 'string', 'max:255', 'unique:domains,name'],
            'registration_period' => ['nullable', 'integer', 'min:1'],
            'recurring_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $validated['status'] = 'active';
        $validated['registration_date'] = now();

        $domain = Domain::create($validated);

        return redirect()
            ->route('admin.domains.show', $domain)
            ->with('success', "Domain {$domain->name} registered.");
    }

    public function show(Domain $domain): View
    {
        $domain->load('customer.user');

        return view('admin.domains.show', compact('domain'));
    }

    public function edit(Domain $domain): View
    {
        $customers = Customer::with('user')->orderBy('id')->get();

        return view('admin.domains.edit', compact('domain', 'customers'));
    }

    public function update(Request $request, Domain $domain): RedirectResponse
    {
        $validated = $request->validate([
            'recurring_amount' => ['sometimes', 'numeric', 'min:0'],
            'auto_renew' => ['sometimes', 'boolean'],
            'nameservers' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', DomainService::STATUSES)],
        ]);

        $domain->update($validated);

        return redirect()
            ->route('admin.domains.show', $domain)
            ->with('success', 'Domain updated.');
    }

    public function destroy(Domain $domain): RedirectResponse
    {
        $domain->delete();

        return redirect()
            ->route('admin.domains.index')
            ->with('success', "Domain {$domain->name} deleted.");
    }

    public function search(Request $request): View
    {
        $query = trim((string) $request->query('q', ''));
        $search = [];

        if ($query !== '') {
            $search = $this->domains->searchAvailability($query);
        }

        $results = $search['results'] ?? [];
        $error = $search['error'] ?? null;

        return view('admin.domains.search', compact('query', 'results', 'error'));
    }
}
