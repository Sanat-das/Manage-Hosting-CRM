<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\MarketingConsentLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Client portal profile (view + edit own details).
 *
 * Clients edit only their own identity fields; the linked Customer row
 * (balance, credit, tax_id) is managed by admins.
 */
class ProfileController extends Controller
{
    private const CONSENT_CONTACT_TYPE = 'marketing_email';

    public function edit(Request $request): View
    {
        $user = $request->user();
        $user->load('customer');

        $marketingConsent = $user->customer
            ? MarketingConsentLog::where('customer_id', $user->customer->id)
                ->where('contact_type', self::CONSENT_CONTACT_TYPE)
                ->where('consent_status', 'opt_in')
                ->exists()
            : false;

        return view('client.profile', compact('user', 'marketingConsent'));
    }

    public function toggleConsent(Request $request): RedirectResponse
    {
        $user = $request->user();
        $customer = $user->customer;

        abort_if($customer === null, 404);

        $contactType = $request->input('contact_type', self::CONSENT_CONTACT_TYPE);
        $consentStatus = $request->boolean('consent') ? 'opt_in' : 'opt_out';

        MarketingConsentLog::updateOrCreate(
            ['customer_id' => $customer->id, 'contact_type' => $contactType],
            [
                'consent_status' => $consentStatus,
                'source' => 'profile',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
        );

        return redirect()
            ->route('client.profile')
            ->with('success', 'Marketing preferences updated.');
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($request->has('phone_code') || $request->has('phone_number')) {
            $code = trim((string) $request->input('phone_code', ''));
            $number = trim((string) $request->input('phone_number', ''));
            if ($code === '' && $number !== '') $code = '+91';
            $request->merge(['phone' => $number !== '' ? trim($code.' '.$number) : $code]);
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed', 'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/'],
        ]);

        $legacy = collect([$validated['address_line1'] ?? null, $validated['address_line2'] ?? null, $validated['city'] ?? null, $validated['state'] ?? null, $validated['postcode'] ?? null, $validated['country'] ?? null])->filter()->implode(', ');
        if ($legacy === '') {
            $legacy = $validated['address'] ?? null;
        }

        $user->update([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'company' => $validated['company'] ?? null,
            'address' => $legacy,
            'address_line1' => $validated['address_line1'] ?? null,
            'address_line2' => $validated['address_line2'] ?? null,
            'city' => $validated['city'] ?? null,
            'state' => $validated['state'] ?? null,
            'postcode' => $validated['postcode'] ?? null,
            'country' => $validated['country'] ?? null,
        ]);

        if (! empty($validated['password'])) {
            $user->update(['password_hash' => Hash::make($validated['password'])]);
        }

        // Keep the linked customer row's company in sync.
        $user->customer?->update(['company' => $validated['company'] ?? null]);

        return redirect()
            ->route('client.profile')
            ->with('success', 'Profile updated.');
    }
}
