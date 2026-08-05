<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin transaction listing.
 *
 * Route contract: routes/admin/billing.php
 * Permission gates: invoices.view
 */
class TransactionController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $transactions = Transaction::query()
            ->with(['customer.user', 'invoice'])
            ->when($status !== '' && $status !== null, fn ($q) => $q->where('status', $status))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('transaction_id', 'like', "%{$search}%")
                        ->orWhereHas('customer.user', fn ($u) => $u->where('email', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $statuses = ['completed' => 'Completed', 'pending' => 'Pending', 'failed' => 'Failed', 'refunded' => 'Refunded'];

        return view('admin.transactions.index', compact('transactions', 'search', 'status', 'statuses'));
    }

    public function show(Transaction $transaction): View
    {
        $transaction->load(['customer.user', 'invoice']);
        return view('admin.transactions.show', compact('transaction'));
    }
}
