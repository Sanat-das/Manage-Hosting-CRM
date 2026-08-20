<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RegistrarSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin registrar settings — manage domain registrar API connections.
 */
class RegistrarSettingController extends Controller
{
    public function index(): View
    {
        $registrars = RegistrarSetting::registrars();
        $allSettings = [];

        foreach ($registrars as $registrar) {
            $allSettings[$registrar] = RegistrarSetting::allFor($registrar);
        }

        return view('admin.registrar_settings.index', compact('registrars', 'allSettings'));
    }

    public function edit(string $registrar): View
    {
        $settings = RegistrarSetting::allFor($registrar);

        return view('admin.registrar_settings.edit', compact('registrar', 'settings'));
    }

    public function update(Request $request, string $registrar): RedirectResponse
    {
        $validated = $request->validate([
            'api_endpoint' => ['nullable', 'url', 'max:500'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'test_mode' => ['nullable', 'string', 'in:0,1'],
            'enabled' => ['nullable', 'string', 'in:0,1'],
        ]);

        RegistrarSetting::setMany($registrar, $validated);

        return redirect()
            ->route('admin.registrar-settings.edit', $registrar)
            ->with('success', "Settings for {$registrar} updated.");
    }

    public function destroy(string $registrar): RedirectResponse
    {
        RegistrarSetting::where('registrar', $registrar)->delete();

        return redirect()
            ->route('admin.registrar-settings.index')
            ->with('success', "Registrar {$registrar} removed.");
    }
}
