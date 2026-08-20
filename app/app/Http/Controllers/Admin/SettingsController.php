<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin settings controller — manages application configuration.
 *
 * Typed settings keys (see AppSettings::TYPED_KEYS) are saved through their
 * spatie typed class, which applies per-key validation. Untyped keys keep
 * writing to the legacy `settings` table.
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
        $payload = $request->input('settings', []);

        $rules = ['settings' => ['required', 'array']];

        foreach ($payload as $key => $value) {
            if (isset(AppSettings::TYPED_KEYS[$key])) {
                $class = AppSettings::TYPED_KEYS[$key];
                $keyRules = $class::rules()[$key] ?? ['nullable', 'string', 'max:1000'];
            } else {
                $keyRules = ['nullable', 'string', 'max:1000'];
            }

            $rules["settings.{$key}"] = $keyRules;
        }

        $validated = $request->validate($rules);
        $values = $validated['settings'];

        $this->saveTyped($values);
        $this->saveUntyped($values);

        return redirect()->route('admin.settings.index')
            ->with('success', 'Settings updated successfully.');
    }

    /**
     * Save typed keys grouped by their settings class.
     */
    private function saveTyped(array $values): void
    {
        $byClass = [];

        foreach ($values as $key => $value) {
            if (isset(AppSettings::TYPED_KEYS[$key])) {
                $byClass[AppSettings::TYPED_KEYS[$key]][$key] = $value;
            }
        }

        foreach ($byClass as $class => $classValues) {
            $settings = app($class);

            // Empty form fields arrive as null (ConvertEmptyStringsToNull) and
            // must never be assigned to non-nullable typed properties; skip
            // them so untouched values keep their stored/default value.
            $settings->fill(array_filter($classValues, fn ($value) => $value !== null));
            $settings->save();
        }
    }

    /**
     * Save untyped keys to the legacy settings table.
     */
    private function saveUntyped(array $values): void
    {
        foreach ($values as $key => $value) {
            if (isset(AppSettings::TYPED_KEYS[$key])) {
                continue;
            }

            \DB::table('settings')->updateOrInsert(
                ['setting_key' => $key],
                ['setting_value' => $value, 'updated_at' => now()]
            );
        }
    }

    private function loadAll(): array
    {
        $rows = \DB::table('settings')->pluck('setting_value', 'setting_key')->toArray();

        foreach (AppSettings::TYPED_KEYS as $key => $class) {
            $value = app($class)->{$key};
            $rows[$key] = $value === null ? '' : (string) $value;
        }

        return $rows;
    }
}
