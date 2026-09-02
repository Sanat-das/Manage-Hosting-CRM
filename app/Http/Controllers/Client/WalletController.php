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

        $search = trim((string) $request->query('search'));

        $transactions = $customer->walletTransactions()
            ->when($search !== '', fn ($q) => $q->where('description', 'like', "%{$search}%"))
            ->gridSort([
                'created_at' => 'created_at',
                'type' => 'type',
                'description' => 'description',
                'amount' => 'amount',
            ])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('client.wallet.index', compact('customer', 'transactions', 'search'));
    }
}
