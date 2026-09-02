<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TaxRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TaxRateController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = trim((string) $request->query('status'));

        $query = TaxRate::query();

        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }
        if ($status !== '') {
            $query->where('is_active', $status === 'active');
        }

        $rates = $query
            ->gridSort([
                'name' => 'name',
                'rate' => 'rate',
                'status' => 'is_active',
            ])
            ->orderBy('name')->paginate(20)->withQueryString();

        return view('admin.tax_rates.index', compact('rates', 'search', 'status'));
    }

    public function create(): View
    {
        return view('admin.tax_rates.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);
        if ($request->has('is_active')) {
            $validated['is_active'] = $request->boolean('is_active');
        }
        TaxRate::create($validated);

        return redirect()->route('admin.tax-rates.index')->with('success', 'Tax rate created.');
    }

    public function show(TaxRate $taxRate): View
    {
        return view('admin.tax_rates.show', ['rate' => $taxRate]);
    }

    public function edit(TaxRate $taxRate): View
    {
        return view('admin.tax_rates.edit', ['rate' => $taxRate]);
    }

    public function update(Request $request, TaxRate $taxRate): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);
        if ($request->has('is_active')) {
            $validated['is_active'] = $request->boolean('is_active');
        }
        $taxRate->update($validated);

        return redirect()->route('admin.tax-rates.show', $taxRate)->with('success', 'Tax rate updated.');
    }

    public function destroy(TaxRate $taxRate): RedirectResponse
    {
        $taxRate->delete();

        return redirect()->route('admin.tax-rates.index')->with('success', 'Tax rate deleted.');
    }
}
