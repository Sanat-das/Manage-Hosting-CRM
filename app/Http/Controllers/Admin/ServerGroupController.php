<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin server group management (Session 3A.2).
 *
 * Server groups collect servers for load-balanced assignment (products point
 * at a server_group_id). Members are stored in server_group_members and
 * managed through a multi-select on the create/edit forms.
 *
 * Permission gates: hosting.view (read), hosting.manage (write). The task
 * brief mentions a `hosting.server_groups` permission, but the local seeder
 * only defines hosting.view / hosting.manage — so server group writes are
 * gated by hosting.manage.
 */
class ServerGroupController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $groups = ServerGroup::query()
            ->withCount('servers')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when(in_array($status, ['active', 'inactive'], true), function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.server-groups.index', compact('groups', 'search', 'status'));
    }

    public function create(): View
    {
        return view('admin.server-groups.create', ['servers' => $this->allServers()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        DB::transaction(function () use ($validated) {
            $group = ServerGroup::create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'load_balancing' => $validated['load_balancing'],
                'status' => $validated['status'],
            ]);

            $group->servers()->sync($validated['server_ids'] ?? []);
        });

        return redirect()
            ->route('admin.server-groups.index')
            ->with('success', "Server group {$validated['name']} created.");
    }

    public function edit(ServerGroup $serverGroup): View
    {
        $serverGroup->load('servers:id');

        return view('admin.server-groups.edit', [
            'serverGroup' => $serverGroup,
            'servers' => $this->allServers(),
            'selectedServerIds' => $serverGroup->servers->pluck('id')->all(),
        ]);
    }

    public function update(Request $request, ServerGroup $serverGroup): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        DB::transaction(function () use ($serverGroup, $validated) {
            $serverGroup->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'load_balancing' => $validated['load_balancing'],
                'status' => $validated['status'],
            ]);

            $serverGroup->servers()->sync($validated['server_ids'] ?? []);
        });

        return redirect()
            ->route('admin.server-groups.index')
            ->with('success', "Server group {$validated['name']} updated.");
    }

    private function allServers()
    {
        return Server::query()
            ->orderBy('name')
            ->get(['id', 'name', 'ip_address', 'status']);
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'load_balancing' => ['required', Rule::in(['round_robin', 'least_loaded', 'failover'])],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'server_ids' => ['nullable', 'array'],
            'server_ids.*' => ['integer', 'exists:servers,id'],
        ];
    }
}
