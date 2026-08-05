<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Admin settings controller — manages application configuration.
 */
class SettingsController extends Controller
{
    public function index(): View
    {
        $settings = $this->loadAll();
        return view('admin.settings.index', compact('settings'));
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*' => ['nullable', 'string', 'max:1000'],
        ]);

        foreach ($validated['settings'] as $key => $value) {
            \DB::table('settings')->updateOrInsert(
                ['setting_key' => $key],
                ['setting_value' => $value, 'updated_at' => now()]
            );
        }

        return redirect()->route('admin.settings.index')
            ->with('success', 'Settings updated successfully.');
    }

    private function loadAll(): array
    {
        $rows = \DB::table('settings')->pluck('setting_value', 'setting_key')->toArray();
        return $rows;
    }
}
