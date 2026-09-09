<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GstSetting;
use App\Support\GstStateCodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin GST settings controller — the single writer of the `gst_settings` table,
 * which is the only source GstTaxService reads.
 *
 * The form itself now lives on the Settings page (Billing tab → "GST & Tax"),
 * so every billing switch is in one place; this controller still owns the
 * validation and the write. The standalone /admin/gst-settings page is kept as a
 * redirect so bookmarks, the old menu entry and any deep link still land
 * somewhere useful.
 */
class GstSettingController extends Controller
{
    /** Where the form lives now. */
    private const FORM_URL_PARAMS = ['tab' => 'billing'];

    public function edit(): RedirectResponse
    {
        // Seed the row on first visit so the settings page always has something
        // to render (and so `enabled` defaults to off rather than to nothing).
        GstSetting::firstOrCreate([], [
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

        return redirect()->route('admin.settings.index', self::FORM_URL_PARAMS);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'gstin' => ['required', 'string', 'max:15'],
            'legal_name' => ['required', 'string', 'max:255'],
            // One of the 38 GST state codes and nothing else. This used to be
            // free text capped at two characters, which is how the company came
            // to hold '27' while customers held 'WB' — two vocabularies that
            // GstTaxService::isIntraState() can never match, so every sale was
            // billed inter-state.
            'state_code' => ['required', 'string', Rule::in(GstStateCodes::codes())],
            'cgst_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'sgst_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'igst_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'hsn_code' => ['nullable', 'string', 'max:20'],
            'sac_code' => ['nullable', 'string', 'max:20'],
            'enabled' => ['sometimes', 'boolean'],
            'tax_mode' => ['sometimes', 'string', 'in:global,per_product,mixed'],
        ]);

        $validated['enabled'] = $validated['enabled'] ?? false;

        // The name is derived, never posted: a code and a name that disagree
        // are a bug waiting to be read off an invoice.
        $validated['state_name'] = GstStateCodes::name($validated['state_code']);

        $gst = GstSetting::first();
        if ($gst) {
            $gst->update($validated);
        } else {
            GstSetting::create($validated);
        }

        return redirect()
            ->route('admin.settings.index', self::FORM_URL_PARAMS)
            ->with('success', 'GST settings updated.');
    }
}
