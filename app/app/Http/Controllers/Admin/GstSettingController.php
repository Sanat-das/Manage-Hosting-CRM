<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GstSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin GST settings controller — manage GST configuration.
 */
class GstSettingController extends Controller
{
    public function edit(): View
    {
        $gst = GstSetting::firstOrCreate([], [
            'gstin' => '',
            'legal_name' => '',
            'state_code' => '27',
            'state_name' => 'Maharashtra',
            'cgst_rate' => 9.00,
            'sgst_rate' => 9.00,
            'igst_rate' => 18.00,
            'hsn_code' => '',
            'sac_code' => '',
            'enabled' => false,
            'tax_mode' => 'global',
        ]);

        return view('admin.gst_settings.edit', compact('gst'));
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'gstin' => ['required', 'string', 'max:15'],
            'legal_name' => ['required', 'string', 'max:255'],
            'state_code' => ['required', 'string', 'max:2'],
            'state_name' => ['required', 'string', 'max:100'],
            'cgst_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'sgst_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'igst_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'hsn_code' => ['nullable', 'string', 'max:20'],
            'sac_code' => ['nullable', 'string', 'max:20'],
            'enabled' => ['sometimes', 'boolean'],
            'tax_mode' => ['sometimes', 'string', 'in:global,per_product,mixed'],
        ]);

        $validated['enabled'] = $validated['enabled'] ?? false;

        $gst = GstSetting::first();
        if ($gst) {
            $gst->update($validated);
        } else {
            GstSetting::create($validated);
        }

        return redirect()
            ->route('admin.gst-settings.edit')
            ->with('success', 'GST settings updated.');
    }
}
