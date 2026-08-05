<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Client portal — hosting account listing and detail.
 */
class HostingController extends Controller
{
    public function index(Request $request): View
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $accounts = $customer->hostingAccounts()
            ->with(['product', 'server'])
            ->orderByDesc('id')
            ->get();

        return view('client.hosting.index', compact('accounts'));
    }

    public function show(Request $request, int $id): View
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $account = $customer->hostingAccounts()
            ->with(['product', 'server', 'order'])
            ->findOrFail($id);

        return view('client.hosting.show', compact('account'));
    }
}
