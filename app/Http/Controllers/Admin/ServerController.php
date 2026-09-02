<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin server management (Session 3A.2).
 *
 * Reference note: the task brief lists "name, hostname, ip" — the local
 * servers table has NO hostname column (config_tables migration), so the
 * `name` field is the server's display label. Columns: name, ip_address,
 * panel_type, api_url, api_key, api_username, max_accounts, status.
 *
 * Permission gates: hosting.view (read), hosting.manage (write).
 */
class ServerController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $servers = Server::query()
            ->withCount('hostingAccounts')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhere('api_url', 'like', "%{$search}%");
                });
            })
            ->when(in_array($status, ['active', 'inactive'], true), function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->gridSort([
                'name' => 'name',
                'ip_address' => 'ip_address',
                'panel' => 'panel_type',
                'status' => 'status',
                'created_at' => 'created_at',
            ])
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.servers.index', compact('servers', 'search', 'status'));
    }

    public function show(Server $server): View
    {
        $server->load([
            'hostingAccounts.customer.user:id,email,first_name,last_name',
            'hostingAccounts.product:id,name,type',
        ]);

        $groups = $server->groupMembers()->with('group:id,name,status')->get();

        return view('admin.servers.show', compact('server', 'groups'));
    }

    public function create(): View
    {
        return view('admin.servers.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        $server = Server::create($validated);

        return redirect()
            ->route('admin.servers.show', $server)
            ->with('success', "Server {$server->name} created.");
    }

    public function edit(Server $server): View
    {
        return view('admin.servers.edit', compact('server'));
    }

    public function update(Request $request, Server $server): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        $server->update($validated);

        return redirect()
            ->route('admin.servers.show', $server)
            ->with('success', "Server {$server->name} updated.");
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'ip_address' => ['required', 'ip'],
            'panel_type' => ['required', Rule::in(['cpanel', 'plesk', 'directadmin', 'custom'])],
            'api_url' => ['nullable', 'url', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'api_username' => ['nullable', 'string', 'max:255'],
            'max_accounts' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }
}
