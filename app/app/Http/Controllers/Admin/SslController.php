<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SslRequest;
use App\Models\Customer;
use App\Models\SslCertificate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin SSL certificate management (list + manage).
 *
 * Fresh module: the reference CRM has NO SSL module at all, so this controller
 * is designed from scratch following the Session 2 customer pilot pattern
 * (UI kit partials, per-action permission middleware).
 *
 * Permission gates (wired in routes/admin/ssl.php):
 * - view (index/show): hosting.view
 * - manage (create/store/edit/update/destroy): settings.edit
 */
class SslController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * Number of days used by the "expiring soon" quick filter.
     */
    private const EXPIRING_SOON_DAYS = 30;

    private const STATUSES = ['active', 'pending', 'expired', 'revoked', 'failed'];

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');
        $expiring = $request->boolean('expiring');

        $certificates = SslCertificate::query()
            ->with('customer.user:id,first_name,last_name,company')
            ->when($search !== '', function ($query) use ($search) {
                $query->where('domain_name', 'like', "%{$search}%");
            })
            ->when(in_array($status, self::STATUSES, true), function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->when($expiring, function ($query) {
                $query->where('status', 'active')
                    ->whereDate('expiry_date', '>=', now()->startOfDay())
                    ->whereDate('expiry_date', '<=', now()->addDays(self::EXPIRING_SOON_DAYS)->endOfDay());
            })
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.ssl.index', compact('certificates', 'search', 'status', 'expiring'));
    }

    public function create(): View
    {
        return view('admin.ssl.create', [
            'customers' => $this->customerOptions(),
        ]);
    }

    public function store(SslRequest $request): RedirectResponse
    {
        $certificate = SslCertificate::create($request->validated());

        return redirect()
            ->route('admin.ssl.show', $certificate)
            ->with('success', "SSL certificate for {$certificate->domain_name} created.");
    }

    public function show(SslCertificate $ssl): View
    {
        $ssl->load(['customer.user', 'order']);

        return view('admin.ssl.show', compact('ssl'));
    }

    public function edit(SslCertificate $ssl): View
    {
        return view('admin.ssl.edit', [
            'ssl' => $ssl,
            'customers' => $this->customerOptions(),
        ]);
    }

    public function update(SslRequest $request, SslCertificate $ssl): RedirectResponse
    {
        $ssl->update($request->validated());

        return redirect()
            ->route('admin.ssl.show', $ssl)
            ->with('success', "SSL certificate for {$ssl->domain_name} updated.");
    }

    public function destroy(SslCertificate $ssl): RedirectResponse
    {
        $domain = $ssl->domain_name;
        $ssl->delete();

        return redirect()
            ->route('admin.ssl.index')
            ->with('success', "SSL certificate for {$domain} deleted.");
    }

    /**
     * Customers keyed for the create/edit dropdown (id => display name).
     *
     * @return array<int, string>
     */
    private function customerOptions(): array
    {
        return Customer::query()
            ->with('user:id,first_name,last_name,company')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (Customer $customer) => [
                $customer->id => $customer->full_name.' ('.$customer->display_id.')',
            ])
            ->all();
    }
}
