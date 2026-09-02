<?php

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Services\Installer\InstallerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InstallerController extends Controller
{
    public function __construct(private readonly InstallerService $installer) {}

    /**
     * Show the pre-flight checks and the setup form.
     */
    public function index(): View
    {
        $checks = $this->installer->preflightChecks();

        return view('install.index', [
            'checks' => $checks,
            'canProceed' => $this->installer->hardPrerequisitesPassed($checks),
            'defaults' => $this->defaults(),
        ]);
    }

    /**
     * Run the installation.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'app_name' => ['required', 'string', 'max:60'],
            'db_host' => ['required', 'string', 'max:100'],
            'db_port' => ['required', 'integer', 'between:1,65535'],
            'db_database' => ['required', 'string', 'max:64'],
            'db_username' => ['required', 'string', 'max:64'],
            'db_password' => ['nullable', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            $this->installer->run($validated);
        } catch (\Throwable $e) {
            return back()
                ->withInput()
                ->withErrors(['installer' => $e->getMessage()]);
        }

        $request->session()->put('install.completed', true);

        return redirect()->route('install.complete');
    }

    /**
     * Show the success screen (only reachable right after a completed run).
     */
    public function complete(Request $request): RedirectResponse|View
    {
        if (! $request->session()->get('install.completed')) {
            return redirect('/');
        }

        return view('install.complete');
    }

    /**
     * Values used to pre-fill the setup form.
     *
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'app_name' => config('adminlte.title', config('app.name')),
            'db_host' => config('database.connections.mysql.host'),
            'db_port' => (string) config('database.connections.mysql.port'),
            'db_database' => config('database.connections.mysql.database'),
            'db_username' => config('database.connections.mysql.username'),
            'db_password' => (string) config('database.connections.mysql.password'),
        ];
    }
}
