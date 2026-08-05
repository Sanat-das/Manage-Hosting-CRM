<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\License;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LicenseController extends Controller
{
    public function index(Request $request): View
    {
        $licenses = License::orderByDesc('id')->paginate(20);
        return view('admin.licenses.index', compact('licenses'));
    }

    public function create(): View
    {
        return view('admin.licenses.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'license_type' => ['required', 'string', 'max:100'],
            'license_key' => ['required', 'string', 'max:500'],
            'seats' => ['nullable', 'integer', 'min:1'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'purchase_order' => ['nullable', 'string', 'max:255'],
            'expiry_date' => ['nullable', 'date'],
            'renewal_date' => ['nullable', 'date'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'string', 'in:active,expired,revoked'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $validated['status'] = $validated['status'] ?? 'active';
        $validated['seats_available'] = $validated['seats'] ?? 1;
        License::create($validated);
        return redirect()->route('admin.licenses.index')->with('success', 'License created.');
    }

    public function show(License $license): View
    {
        $license->load('assignments');
        return view('admin.licenses.show', compact('license'));
    }

    public function edit(License $license): View
    {
        return view('admin.licenses.edit', compact('license'));
    }

    public function update(Request $request, License $license): RedirectResponse
    {
        $validated = $request->validate([
            'license_type' => ['sometimes', 'string', 'max:100'],
            'license_key' => ['sometimes', 'string', 'max:500'],
            'seats' => ['nullable', 'integer', 'min:1'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'expiry_date' => ['nullable', 'date'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'string', 'in:active,expired,revoked'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $license->update($validated);
        return redirect()->route('admin.licenses.show', $license)->with('success', 'License updated.');
    }

    public function destroy(License $license): RedirectResponse
    {
        $license->delete();
        return redirect()->route('admin.licenses.index')->with('success', 'License deleted.');
    }
}
