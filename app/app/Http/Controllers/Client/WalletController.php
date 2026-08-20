<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Client portal — wallet / credit balance and transaction history.
 */
class WalletController extends Controller
{
    public function index(Request $request): View
    {
        $customer = $request->user()->customer;
        abort_unless($customer, 404);

        $transactions = $customer->walletTransactions()
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('client.wallet.index', compact('customer', 'transactions'));
    }
}
