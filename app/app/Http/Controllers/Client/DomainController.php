<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Client portal — domain listing and detail.
 */
class DomainController extends Controller
{
    public function index(Request $request): View
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $domains = $customer->domains()
            ->orderByDesc('id')
            ->get();

        return view('client.domains.index', compact('domains'));
    }

    public function show(Request $request, int $id): View
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $domain = $customer->domains()->findOrFail($id);

        return view('client.domains.show', compact('domain'));
    }
}
