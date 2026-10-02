<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UpgradeRequest;
use App\Services\Billing\UpgradeRequestService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin upgrade-request console (T4.5).
 *
 * List/search every upgrade request, open its quote snapshot, approve it
 * (payable → upgrade invoice; credit → wallet credit + immediate apply) or
 * cancel it (voids the unpaid invoice). Approval here is explicit: the admin
 * approves a request, it stays pending until its invoice is paid. The manual
 * order flow (OrderController::storeUpgrade) places AND approves in one step.
 */
class UpgradeRequestController extends Controller
{
    public function __construct(
        private readonly UpgradeRequestService $upgrades,
    ) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = trim((string) $request->query('status'));

        $query = UpgradeRequest::query()
            ->with(['customer.user', 'order', 'fromProduct', 'toProduct', 'invoice']);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('upgrade_no', 'like', "%{$search}%")
                    ->orWhereHas('order', fn ($o) => $o->where('order_number', 'like', "%{$search}%"))
                    ->orWhereHas('customer.user', function ($u) use ($search) {
                        $u->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }
        if ($status !== '') {
            $query->where('status', $status);
        }

        $requests = $query
            ->gridSort([
                'upgrade_no' => 'upgrade_no',
                'order' => 'order.order_number',
                'customer' => 'customer.company',
                'from' => 'fromProduct.name',
                'to' => 'toProduct.name',
                'status' => 'status',
                'created_at' => 'created_at',
            ])
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.upgrade_requests.index', compact('requests', 'search', 'status'));
    }

    public function show(UpgradeRequest $upgradeRequest): View
    {
        $upgradeRequest->load(['customer.user', 'order', 'fromProduct', 'toProduct', 'invoice']);

        return view('admin.upgrade_requests.show', ['upgrade' => $upgradeRequest]);
    }

    public function approve(UpgradeRequest $upgradeRequest): RedirectResponse
    {
        try {
            $upgrade = $this->upgrades->approve($upgradeRequest);
        } catch (DomainException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        if ($upgrade->invoice_id !== null) {
            $invoiceNo = $upgrade->invoice?->invoice_no ?? '#'.$upgrade->invoice_id;

            return redirect()
                ->route('admin.upgrade-requests.show', $upgrade)
                ->with('success', "Upgrade {$upgrade->upgrade_no} approved. Invoice {$invoiceNo} generated.");
        }

        if ((float) $upgrade->credit_amount > 0) {
            return redirect()
                ->route('admin.upgrade-requests.show', $upgrade)
                ->with('success', 'Upgrade '.$upgrade->upgrade_no.' approved. Credit of ₹'.number_format((float) $upgrade->credit_amount, 2)." applied to the customer's wallet.");
        }

        return redirect()
            ->route('admin.upgrade-requests.show', $upgrade)
            ->with('success', "Upgrade {$upgrade->upgrade_no} approved and applied.");
    }

    public function cancel(Request $request, UpgradeRequest $upgradeRequest): RedirectResponse
    {
        $reason = trim((string) $request->input('reason'));

        try {
            $this->upgrades->cancel($upgradeRequest, $reason !== '' ? $reason : null);
        } catch (DomainException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.upgrade-requests.show', $upgradeRequest->fresh())
            ->with('success', "Upgrade {$upgradeRequest->upgrade_no} cancelled.");
    }
}
