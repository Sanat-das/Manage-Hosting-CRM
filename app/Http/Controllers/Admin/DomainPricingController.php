<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DomainPricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DomainPricingController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = trim((string) $request->query('status'));

        $query = DomainPricing::query();

        if ($search !== '') {
            $query->where('tld', 'like', "%{$search}%");
        }
        if ($status !== '') {
            $query->where('enabled', $status === 'enabled');
        }

        $pricings = $query
            ->gridSort([
                'tld' => 'tld',
                'register_price' => 'register_price',
                'renew_price' => 'renew_price',
                'transfer_price' => 'transfer_price',
                'premium' => 'premium',
                'enabled' => 'enabled',
            ])
            ->orderBy('tld')->paginate(20)->withQueryString();

        return view('admin.domain_pricing.index', compact('pricings', 'search', 'status'));
    }

    public function create(): View
    {
        return view('admin.domain_pricing.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tld' => ['required', 'string', 'max:255', 'unique:domain_pricing,tld'],
            'register_price' => ['required', 'numeric', 'min:0'],
            'renew_price' => ['required', 'numeric', 'min:0'],
            'transfer_price' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'premium' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
            'terms' => ['nullable', 'array', 'max:10'],
            'terms.*.term_years' => ['required_with:terms.*', 'integer', 'min:1', 'max:10'],
            'terms.*.register_price' => ['required_with:terms.*', 'numeric', 'min:0'],
            'terms.*.renew_price' => ['required_with:terms.*', 'numeric', 'min:0'],
        ]);

        $validated['premium'] = $request->boolean('premium');
        $validated['enabled'] = $request->boolean('enabled', true);

        $terms = collect($validated['terms'] ?? [])
            ->filter(fn ($t) => ! empty($t['term_years']))
            ->values()
            ->toArray();

        DB::transaction(function () use ($validated, $terms) {
            $pricing = DomainPricing::create(collect($validated)->except('terms')->toArray());

            foreach ($terms as $term) {
                $pricing->terms()->create($term);
            }
        });

        return redirect()->route('admin.domain-pricing.index')->with('success', 'Domain pricing created.');
    }

    public function show(DomainPricing $domainPricing): View
    {
        $domainPricing->load('terms');

        return view('admin.domain_pricing.show', ['pricing' => $domainPricing]);
    }

    public function edit(DomainPricing $domainPricing): View
    {
        $domainPricing->load('terms');

        return view('admin.domain_pricing.edit', ['pricing' => $domainPricing]);
    }

    public function update(Request $request, DomainPricing $domainPricing): RedirectResponse
    {
        $validated = $request->validate([
            'tld' => ['sometimes', 'string', 'max:255', 'unique:domain_pricing,tld,'.$domainPricing->id],
            'register_price' => ['sometimes', 'numeric', 'min:0'],
            'renew_price' => ['sometimes', 'numeric', 'min:0'],
            'transfer_price' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'premium' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
            'terms' => ['nullable', 'array', 'max:10'],
            'terms.*.term_years' => ['required_with:terms.*', 'integer', 'min:1', 'max:10'],
            'terms.*.register_price' => ['required_with:terms.*', 'numeric', 'min:0'],
            'terms.*.renew_price' => ['required_with:terms.*', 'numeric', 'min:0'],
        ]);

        $validated['premium'] = $request->boolean('premium');
        $validated['enabled'] = $request->boolean('enabled', true);

        $terms = collect($validated['terms'] ?? [])
            ->filter(fn ($t) => ! empty($t['term_years']))
            ->values()
            ->toArray();

        DB::transaction(function () use ($domainPricing, $validated, $terms) {
            $domainPricing->update(collect($validated)->except('terms')->toArray());

            // Delete existing terms and recreate from the form payload.
            $domainPricing->terms()->delete();
            foreach ($terms as $term) {
                $domainPricing->terms()->create($term);
            }
        });

        return redirect()->route('admin.domain-pricing.show', $domainPricing)->with('success', 'Domain pricing updated.');
    }

    public function destroy(DomainPricing $domainPricing): RedirectResponse
    {
        $domainPricing->delete();

        return redirect()->route('admin.domain-pricing.index')->with('success', 'Domain pricing deleted.');
    }
}
