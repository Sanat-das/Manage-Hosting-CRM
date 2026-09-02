<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Quote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin quote management.
 *
 * Route contract: routes/admin/billing.php
 * Permission gates: invoices.view (quotes share billing permissions)
 */
class QuoteController extends Controller
{
    private const PER_PAGE = 20;

    /** Must mirror the quotes.stage enum (migration 2026_07_30_120040_create_financial_tables.php). */
    public const STAGES = [
        'draft' => 'Draft',
        'delivered' => 'Delivered',
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
        'dead' => 'Dead',
    ];

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $stage = $request->query('stage');

        $quotes = Quote::query()
            ->with('customer.user')
            ->when($stage !== '' && $stage !== null, fn ($q) => $q->where('stage', $stage))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('quote_no', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%");
                });
            })
            ->gridSort([
                'quote_no' => 'quote_no',
                'customer' => 'customer.company',
                'subject' => 'subject',
                'total' => 'total',
                'stage' => 'stage',
                'valid_until' => 'valid_until',
                'status' => 'stage',
                'created_at' => 'created_at',
            ])
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $stages = self::STAGES;
        $stats = Quote::query()->selectRaw('stage, COUNT(*) as count')->groupBy('stage')->pluck('count', 'stage');

        return view('admin.quotes.index', compact('quotes', 'search', 'stage', 'stages', 'stats'));
    }

    public function create(): View
    {
        $customers = Customer::with('user')->orderBy('id')->get();

        return view('admin.quotes.create', compact('customers'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'subject' => ['required', 'string', 'max:255'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'valid_until' => ['nullable', 'date'],
        ]);

        $validated['quote_no'] = 'QT-'.str_pad(Quote::count() + 1, 5, '0', STR_PAD_LEFT);
        $validated['stage'] = 'draft';
        $validated['total'] = ($validated['subtotal'] ?? 0) - ($validated['discount'] ?? 0) + ($validated['tax'] ?? 0);

        $quote = Quote::create($validated);

        return redirect()
            ->route('admin.quotes.show', $quote)
            ->with('success', "Quote {$quote->quote_no} created.");
    }

    public function show(Quote $quote): View
    {
        $quote->load('customer.user');
        $stages = self::STAGES;

        return view('admin.quotes.show', compact('quote', 'stages'));
    }

    public function edit(Quote $quote): View
    {
        $customers = Customer::with('user')->orderBy('id')->get();

        return view('admin.quotes.edit', compact('quote', 'customers'));
    }

    public function update(Request $request, Quote $quote): RedirectResponse
    {
        $validated = $request->validate([
            'subject' => ['sometimes', 'string', 'max:255'],
            'subtotal' => ['sometimes', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'valid_until' => ['nullable', 'date'],
            'stage' => ['sometimes', 'string', 'in:'.implode(',', array_keys(self::STAGES))],
        ]);

        if (isset($validated['subtotal'])) {
            $validated['total'] = ($validated['subtotal'] ?? 0) - ($validated['discount'] ?? 0) + ($validated['tax'] ?? 0);
        }

        $quote->update($validated);

        return redirect()
            ->route('admin.quotes.show', $quote)
            ->with('success', 'Quote updated.');
    }

    public function destroy(Quote $quote): RedirectResponse
    {
        $quote->delete();

        return redirect()
            ->route('admin.quotes.index')
            ->with('success', "Quote {$quote->quote_no} deleted.");
    }
}
