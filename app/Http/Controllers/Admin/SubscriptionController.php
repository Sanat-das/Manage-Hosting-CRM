<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = trim((string) $request->query('status'));

        $query = SubscriptionPeriod::with(['service.customer']);

        if ($search !== '') {
            $query->whereHas('service', function ($q) use ($search) {
                $q->where('domain', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }
        if ($status !== '') {
            $query->where('status', $status);
        }

        $subscriptions = $query
            ->gridSort([
                'id' => 'id',
                'service' => 'service.domain',
                'amount' => 'amount',
                'billing_cycle' => 'billing_cycle',
                'status' => 'status',
            ])
            ->orderByDesc('id')->paginate(20)->withQueryString();

        return view('admin.subscriptions.index', compact('subscriptions', 'search', 'status'));
    }

    public function show(SubscriptionPeriod $subscription): View
    {
        $subscription->load(['service.customer', 'service.catalogProduct', 'children']);

        return view('admin.subscriptions.show', compact('subscription'));
    }

    public function update(Request $request, SubscriptionPeriod $subscription): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:active,suspended,cancelled,expired'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
        ]);
        $subscription->update($validated);

        return redirect()->route('admin.subscriptions.show', $subscription)->with('success', 'Subscription updated.');
    }
}
