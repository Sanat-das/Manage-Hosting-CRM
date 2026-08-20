<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin payment gateway settings — manage gateway mode, enablement, and credentials.
 */
class GatewayController extends Controller
{
    public function index(PaymentGatewayManager $gateways): View
    {
        $gateways = $gateways->all();

        return view('admin.gateway_settings.index', compact('gateways'));
    }

    public function edit(PaymentGateway $gateway): View
    {
        return view('admin.gateway_settings.edit', compact('gateway'));
    }

    public function update(Request $request, PaymentGateway $gateway): RedirectResponse
    {
        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:test,live'],
            'enabled' => ['required', 'string', 'in:0,1'],
            ...$this->credentialRules($gateway->code),
        ]);

        $gateway->update([
            'mode' => $validated['mode'],
            'enabled' => $validated['enabled'] === '1',
            'credentials' => $this->mergeCredentials($gateway, $validated['credentials'] ?? []),
        ]);

        return redirect()
            ->route('admin.gateway-settings.edit', $gateway)
            ->with('success', "{$gateway->name} settings updated.");
    }

    /**
     * Validation rules for the gateway's credential fields.
     *
     * @return array<string, string[]>
     */
    private function credentialRules(string $code): array
    {
        $rules = [];

        foreach ($this->credentialFields($code) as $field) {
            $rules['credentials.'.$field] = ['nullable', 'string', 'max:500'];
        }

        return $rules;
    }

    /**
     * The credential fields a gateway exposes in its settings form.
     *
     * @return list<string>
     */
    private function credentialFields(string $code): array
    {
        return match ($code) {
            'stripe' => ['secret_key', 'publishable_key'],
            'paypal' => ['client_id', 'client_secret'],
            'razorpay' => ['key_id', 'key_secret'],
            'bank_transfer' => ['account_name', 'account_number', 'bank_name', 'ifsc', 'instructions'],
            default => [],
        };
    }

    /**
     * Merge submitted credentials over the existing set, keeping current
     * values for any field left blank so existing keys are never wiped.
     *
     * @param  array<string, mixed>  $submitted
     * @return array<string, string>
     */
    private function mergeCredentials(PaymentGateway $gateway, array $submitted): array
    {
        $credentials = $gateway->credentials ?? [];

        foreach ($this->credentialFields($gateway->code) as $field) {
            $value = $submitted[$field] ?? null;

            if (is_string($value) && $value !== '') {
                $credentials[$field] = $value;
            }
        }

        return $credentials;
    }
}
