<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Services\Integrations\IntegrationRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            ->gridSort([
                'name' => 'name',
                'description' => 'description',
                'load_balancing' => 'load_balancing',
                'status' => 'status',
                'created_at' => 'created_at',
            ])
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.server-groups.index', compact('groups', 'search', 'status'));
    }

    public function create(IntegrationRegistry $registry): View
    {
        return view('admin.server-groups.create', [
            'servers' => $this->allServers(),
            'serverTypeOptions' => $registry->serverTypeOptions(),
        ]);
    }

    public function store(Request $request, IntegrationRegistry $registry): RedirectResponse
    {
        $validated = $request->validate($this->rules($registry));

        $this->assertMembersMatchType($validated['allowed_server_type'] ?? null, $validated['server_ids'] ?? []);

        DB::transaction(function () use ($validated) {
            $group = ServerGroup::create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'load_balancing' => $validated['load_balancing'],
                'status' => $validated['status'],
                'allowed_server_type' => $validated['allowed_server_type'] ?? null,
            ]);

            $group->servers()->sync($validated['server_ids'] ?? []);
        });

        return redirect()
            ->route('admin.server-groups.index')
            ->with('success', "Server group {$validated['name']} created.");
    }

    public function edit(ServerGroup $serverGroup, IntegrationRegistry $registry): View
    {
        $serverGroup->load('servers:id');

        return view('admin.server-groups.edit', [
            'serverGroup' => $serverGroup,
            'servers' => $this->allServers(),
            'selectedServerIds' => $serverGroup->servers->pluck('id')->all(),
            'serverTypeOptions' => $registry->serverTypeOptions(),
        ]);
    }

    public function update(Request $request, ServerGroup $serverGroup, IntegrationRegistry $registry): RedirectResponse
    {
        $validated = $request->validate($this->rules($registry));

        $newType = $validated['allowed_server_type'] ?? null;
        $oldType = $serverGroup->allowed_server_type;

        // If changing allowed_server_type (non-empty), reject if existing members mismatch
        if ($newType !== $oldType && $newType !== null && $newType !== '') {
            $mismatched = $serverGroup->servers()
                ->where('server_type', '!=', $newType)
                ->exists();

            // Also check pending sync list if provided
            if (! $mismatched && ! empty($validated['server_ids'] ?? [])) {
                $count = Server::whereIn('id', $validated['server_ids'])->where('server_type', '!=', $newType)->count();
                $mismatched = $count > 0;
            } elseif (! isset($validated['server_ids'])) {
                // No server_ids submitted means keeping current members — check them
                $mismatched = $serverGroup->servers()->where('server_type', '!=', $newType)->exists();
            }

            if ($mismatched) {
                return back()->withInput()->withErrors([
                    'allowed_server_type' => "Cannot change group type to '{$newType}': existing members have a different server_type.",
                ]);
            }
        }

        $effectiveType = $newType ?? $oldType;
        // When type is set, validate requested members against it
        $memberType = $newType !== null ? $newType : $oldType;
        // If newType is empty string, treat as null (Any)
        if ($memberType === '') {
            $memberType = null;
        }
        $this->assertMembersMatchType($memberType, $validated['server_ids'] ?? null);

        DB::transaction(function () use ($serverGroup, $validated) {
            $serverGroup->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'load_balancing' => $validated['load_balancing'],
                'status' => $validated['status'],
                'allowed_server_type' => $validated['allowed_server_type'] ?? null,
            ]);

            if (array_key_exists('server_ids', $validated)) {
                $serverGroup->servers()->sync($validated['server_ids'] ?? []);
            }
        });

        return redirect()
            ->route('admin.server-groups.index')
            ->with('success', "Server group {$validated['name']} updated.");
    }

    /**
     * AJAX member sync: attach/detach servers with type enforcement.
     * Detach is always allowed; attach is rejected when server.server_type != group.allowed_server_type.
     */
    public function syncMembers(Request $request, ServerGroup $serverGroup): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'attach_ids' => ['nullable', 'array'],
            'attach_ids.*' => ['integer', 'exists:servers,id'],
            'detach_ids' => ['nullable', 'array'],
            'detach_ids.*' => ['integer', 'exists:servers,id'],
            'server_ids' => ['nullable', 'array'],
            'server_ids.*' => ['integer', 'exists:servers,id'],
        ]);

        $allowedType = $serverGroup->allowed_server_type;

        // Full sync mode (form submit)
        if (array_key_exists('server_ids', $validated) && ! array_key_exists('attach_ids', $validated) && ! array_key_exists('detach_ids', $validated)) {
            $ids = $validated['server_ids'] ?? [];
            if ($allowedType !== null && $allowedType !== '') {
                $mismatched = Server::whereIn('id', $ids)->where('server_type', '!=', $allowedType)->pluck('name')->all();
                if ($mismatched !== []) {
                    $msg = "Cannot attach server(s) with mismatched type to group '{$serverGroup->name}' (allowed: {$allowedType}): ".implode(', ', $mismatched);
                    if ($request->expectsJson()) {
                        return response()->json(['message' => $msg], 422);
                    }
                    return back()->withErrors(['server_ids' => $msg]);
                }
            }
            $serverGroup->servers()->sync($ids);
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Members synced.', 'count' => count($ids)]);
            }
            return back()->with('success', 'Members synced.');
        }

        $attachIds = $validated['attach_ids'] ?? [];
        $detachIds = $validated['detach_ids'] ?? [];

        if ($attachIds !== [] && $allowedType !== null && $allowedType !== '') {
            $mismatched = Server::whereIn('id', $attachIds)->where('server_type', '!=', $allowedType)->get();
            if ($mismatched->isNotEmpty()) {
                $names = $mismatched->pluck('name')->implode(', ');
                $msg = "Cannot attach server(s) [{$names}] to group '{$serverGroup->name}' — group is locked to '{$allowedType}' and those servers are of different type(s).";
                if ($request->expectsJson()) {
                    return response()->json(['message' => $msg, 'mismatched_ids' => $mismatched->pluck('id')->all()], 422);
                }
                return back()->withErrors(['attach_ids' => $msg]);
            }
        }

        if ($attachIds !== []) {
            $serverGroup->servers()->syncWithoutDetaching($attachIds);
        }
        if ($detachIds !== []) {
            $serverGroup->servers()->detach($detachIds);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Members updated.', 'attached' => $attachIds, 'detached' => $detachIds]);
        }

        return back()->with('success', 'Members updated.');
    }

    private function allServers()
    {
        return Server::query()
            ->orderBy('name')
            ->get(['id', 'name', 'ip_address', 'status', 'server_type']);
    }

    private function rules(?IntegrationRegistry $registry = null): array
    {
        $activeSlugs = [];
        if ($registry !== null) {
            try {
                $activeSlugs = array_column($registry->serverTypeOptions(), 'value');
            } catch (\Throwable) {
                $activeSlugs = [];
            }
        }

        $typeRule = ['nullable', 'string', 'max:50'];
        if ($activeSlugs !== []) {
            $typeRule[] = Rule::in($activeSlugs);
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'load_balancing' => ['required', Rule::in(['round_robin', 'least_loaded', 'failover'])],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'allowed_server_type' => $typeRule,
            'server_ids' => ['nullable', 'array'],
            'server_ids.*' => ['integer', 'exists:servers,id'],
        ];
    }

    private function assertMembersMatchType(?string $allowedType, ?array $serverIds): void
    {
        if ($allowedType === null || $allowedType === '' || $serverIds === null || $serverIds === []) {
            return;
        }

        $mismatched = Server::whereIn('id', $serverIds)->where('server_type', '!=', $allowedType)->pluck('name')->all();

        if ($mismatched !== []) {
            $msg = "Cannot attach server(s) with mismatched type (allowed: {$allowedType}): ".implode(', ', $mismatched);
            if (request()->expectsJson()) {
                abort(response()->json(['message' => $msg], 422));
            }
            throw ValidationException::withMessages(['server_ids' => $msg]);
        }
    }
}
