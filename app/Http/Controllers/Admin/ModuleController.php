<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Services\Modules\ModuleManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin module management (WP-style plugin grid).
 *
 * All lifecycle work (zip validation, provider resolution, migrations,
 * crash isolation) lives in ModuleManager — this controller only surfaces
 * results. A failing module can never take the admin panel down because the
 * manager wraps every resolve/activate call in its own error boundaries.
 */
class ModuleController extends Controller
{
    public function __construct(private readonly ModuleManager $manager)
    {
    }

    /**
     * Plugin grid: one card per module (description + capabilities are
     * computed in the view through the shared manager so a broken module
     * degrades to an empty capability set instead of a 500).
     */
    public function index(): View
    {
        $modules = $this->manager->all();

        return view('admin.modules.index', [
            'modules' => $modules,
            'manager' => $this->manager,
        ]);
    }

    /**
     * Install a module from an uploaded ZIP archive.
     */
    public function install(Request $request): RedirectResponse
    {
        $request->validate([
            'module_zip' => ['required', 'file'],
        ]);

        $path = $request->file('module_zip')->getRealPath();

        try {
            $module = $this->manager->installFromZip($path);
        } catch (\Throwable $e) {
            return back()->with('error', 'Install failed: '.$e->getMessage());
        }

        return redirect()->route('admin.modules.index')
            ->with('success', "Module {$module->name} installed.");
    }

    /**
     * Activate an installed module (runs its migrations/activate hook).
     */
    public function activate(Module $module): RedirectResponse
    {
        try {
            $this->manager->activate($module);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.modules.index')
            ->with('success', "Module {$module->name} activated.");
    }

    /**
     * Deactivate an active module.
     */
    public function deactivate(Module $module): RedirectResponse
    {
        try {
            $this->manager->deactivate($module);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.modules.index')
            ->with('success', "Module {$module->name} deactivated.");
    }

    /**
     * Uninstall a module. The row is deleted, so capture the name first.
     */
    public function uninstall(Module $module): RedirectResponse
    {
        $name = $module->name;

        try {
            $this->manager->uninstall($module);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.modules.index')
            ->with('success', "Module {$name} uninstalled.");
    }

    /**
     * Schema-driven configuration form.
     */
    public function config(Module $module): View
    {
        $schema = $this->manager->resolve($module)?->configSchema() ?? ['fields' => []];
        $config = $this->manager->decryptConfig($module, $module->config ?? []);

        return view('admin.modules.config', compact('module', 'schema', 'config'));
    }

    /**
     * Validate against the module's config schema, encrypt sensitive values
     * and persist the config JSON.
     */
    public function updateConfig(Module $module, Request $request): RedirectResponse
    {
        $schema = $this->manager->resolve($module)?->configSchema() ?? ['fields' => []];

        $rules = [];
        foreach ($schema['fields'] as $field) {
            $key = $field['key'] ?? null;

            if ($key === null || ($field['type'] ?? '') === 'checkbox') {
                continue; // checkboxes are never required and validate as booleans
            }

            $fieldRules = ($field['required'] ?? false) ? ['required'] : ['nullable'];

            if (($field['type'] ?? '') === 'number') {
                $fieldRules[] = 'numeric';
            }

            $rules["config.{$key}"] = $fieldRules;
        }

        $request->validate($rules);

        $raw = $request->input('config', []);

        // Unchecked checkboxes send no payload; persist them explicitly so
        // stored config always reflects the current form state.
        foreach ($schema['fields'] as $field) {
            if (($field['type'] ?? '') === 'checkbox' && !array_key_exists($field['key'], $raw)) {
                $raw[$field['key']] = false;
            }
        }

        $encrypted = $this->manager->encryptConfig($module, $raw);
        $module->update(['config' => $encrypted]);

        return back()->with('success', 'Configuration saved.');
    }
}
