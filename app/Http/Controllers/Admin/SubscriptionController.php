<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPeriod;
use App\Models\ServiceInstance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function index(Request $request): View
    {
        $query = SubscriptionPeriod::with(['service.customer']);
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        $subscriptions = $query->orderByDesc('id')->paginate(20)->withQueryString();
        return view('admin.subscriptions.index', compact('subscriptions'));
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
