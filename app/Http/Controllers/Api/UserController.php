<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StaffUserRequest;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Sanctum-protected staff API (full CRUD + status toggle).
 *
 * Mirrors the reference /api/users endpoints (index/search/status filters,
 * store with a min-8 password, show, update, destroy) plus the activate /
 * deactivate / lock / unlock semantics collapsed into a single
 * toggle-status action using the local status vocabulary.
 *
 * Client accounts are excluded (role != client), matching the reference
 * repository and the admin.web module.
 */
class UserController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $users = User::query()
            ->with('roles')
            ->where('role', '!=', 'client')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when(in_array($status, ['active', 'inactive', 'suspended'], true), function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => $users->map(fn (User $user) => $this->present($user)),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function store(StaffUserRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = DB::transaction(function () use ($validated) {
            $user = User::create([
                'email' => $validated['email'],
                'password_hash' => Hash::make($validated['password']),
                'role' => $validated['role'],
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                // `nullable` in StaffUserRequest means these keys can be absent
                // entirely, not just empty — `?:` alone raises an undefined-key
                // ErrorException and 500s the request.
                'phone' => ($validated['phone'] ?? null) ?: null,
                'company' => ($validated['company'] ?? null) ?: null,
                'address' => ($validated['address'] ?? null) ?: null,
                'status' => $validated['status'],
            ]);

            $roleModel = Role::where('name', $validated['role'])->first();
            $user->roles()->sync($roleModel !== null ? [$roleModel->id] : []);

            return $user;
        });

        $this->logActivity($user, 'user_created', "Staff account created via API ({$user->full_name})", [
            'user_id' => $user->id,
            'role' => $user->role,
        ]);

        return response()->json(['data' => $this->present($user->fresh()->load('roles'))], 201);
    }

    public function show(User $user): JsonResponse
    {
        $this->assertStaffAccount($user);

        $user->load('roles.permissions');

        return response()->json(['data' => $this->present($user, true)]);
    }

    public function update(StaffUserRequest $request, User $user): JsonResponse
    {
        $this->assertStaffAccount($user);

        $validated = $request->validated();

        // Self-protection mirror of the web module.
        $isSelf = $user->id === $request->user()?->id;
        $roleChanged = isset($validated['role']) && $validated['role'] !== $user->role;
        $deactivatingSelf = $isSelf && isset($validated['status']) && $validated['status'] !== 'active';

        if (($isSelf && $roleChanged) || $deactivatingSelf) {
            abort(403, 'You cannot change your own role or deactivate your own account.');
        }

        DB::transaction(function () use ($validated, $user, $roleChanged) {
            $data = [];
            foreach (['first_name', 'last_name', 'email', 'phone', 'company', 'address', 'role', 'status'] as $key) {
                if (array_key_exists($key, $validated)) {
                    $data[$key] = $validated[$key] ?? null;
                }
            }

            foreach (['phone', 'company', 'address'] as $key) {
                if (array_key_exists($key, $data) && $data[$key] === '') {
                    $data[$key] = null;
                }
            }

            $user->update($data);

            if ($roleChanged) {
                $roleModel = Role::where('name', $validated['role'])->first();
                $user->roles()->sync($roleModel !== null ? [$roleModel->id] : []);
            }
        });

        $this->logActivity($user, 'user_updated', "Staff account updated via API ({$user->full_name})", [
            'user_id' => $user->id,
        ]);

        return response()->json(['data' => $this->present($user->fresh()->load('roles'))]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->assertStaffAccount($user);

        if ($user->id === $request->user()?->id) {
            abort(403, 'You cannot delete your own account.');
        }

        if ($user->role === 'admin') {
            abort(403, 'Administrator accounts cannot be deleted.');
        }

        $this->logActivity($user, 'user_deleted', "Staff account deleted via API ({$user->full_name})", [
            'user_id' => $user->id,
        ]);

        $user->delete();

        return response()->json(['message' => 'User deleted.']);
    }

    /**
     * Activate / deactivate / suspend / unsuspend (reference L271/297/323/349).
     */
    public function toggleStatus(Request $request, User $user): JsonResponse
    {
        $this->assertStaffAccount($user);

        if ($user->id === $request->user()?->id) {
            abort(403, 'You cannot change your own account status.');
        }

        $validated = $request->validate([
            'action' => ['required', Rule::in(['activate', 'deactivate', 'suspend', 'unsuspend'])],
        ]);

        $target = match ($validated['action']) {
            'deactivate' => 'inactive',
            'suspend' => 'suspended',
            default => 'active', // activate / unsuspend
        };

        if ($target === $user->status) {
            return response()->json(['message' => "User is already {$target}."]);
        }

        $user->update(['status' => $target]);

        $this->logActivity($user, 'status_changed', "Status changed to {$target} via API ({$validated['action']})", [
            'user_id' => $user->id,
            'action' => $validated['action'],
        ]);

        return response()->json(['message' => "User is now {$target}.", 'status' => $target]);
    }

    private function assertStaffAccount(User $user): void
    {
        abort_unless($user->role !== 'client', 404, 'Client accounts are managed through the customer module.');
    }

    private function present(User $user, bool $detailed = false): array
    {
        $data = [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'company' => $user->company,
            'address' => $user->address,
            'role' => $user->role,
            'status' => $user->status,
            'last_login_at' => $user->last_login_at ? Carbon::parse($user->last_login_at)->toIso8601String() : null,
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['adminlte_roles'] = $user->roles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label,
            ])->values();
            $data['permissions'] = $user->roles
                ->flatMap(fn ($role) => $role->permissions->pluck('name'))
                ->unique()
                ->values();
        }

        return $data;
    }

    private function logActivity(User $user, string $action, string $description, array $metadata = []): void
    {
        $log = new ActivityLog;
        $log->user_id = auth()->id();
        $log->action = $action;
        $log->description = $description;
        $log->metadata = $metadata !== [] ? $metadata : null;
        $log->ip_address = request()->ip();
        $log->created_at = now();
        $log->save();
    }
}
