<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StaffUserRequest;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin staff management module (admin.users.*).
 *
 * Ported from the reference Users module:
 * - password is bcrypt-hashed into the `password_hash` column (the User model
 *   wires it into the auth stack via getAuthPassword())
 * - activate/deactivate (reference UserController L271/L297) map to the
 *   active/inactive toggle; lock/unlock (L323/L349) map to the `suspended`
 *   status per decisions.md (local schema has no `locked` value)
 * - AdminLTE RBAC roles mirror the users.role enum (1:1 sync on create/role
 *   change) through App\Models\Concerns\HasRoles
 * - self-protection: an admin can never deactivate, suspend, demote or delete
 *   their own account; reference semantics also block deleting admin accounts
 */
class UserController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        // The reference repository excludes clients from the staff module
        // (client accounts are managed through admin.customers.*).
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
            ->gridSort([
                'id' => 'id',
                'name' => fn (Builder $q, string $dir) => $q->orderBy('first_name', $dir)->orderBy('last_name', $dir),
                'email' => 'email',
                'role' => 'role',
                'status' => 'status',
                'last_login_at' => 'last_login_at',
                'created_at' => 'created_at',
            ])
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.users.index', compact('users', 'search', 'status'));
    }

    public function create(): View
    {
        return view('admin.users.create');
    }

    public function store(StaffUserRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $user = DB::transaction(function () use ($validated) {
                $legacy = collect([$validated['address_line1'] ?? null, $validated['address_line2'] ?? null, $validated['city'] ?? null, $validated['state'] ?? null, $validated['postcode'] ?? null, $validated['country'] ?? null])->filter()->implode(', ');
                if ($legacy === '') {
                    $legacy = $this->blankToNull($validated['address'] ?? null);
                }
                $user = User::create([
                    'email' => $validated['email'],
                    'password_hash' => Hash::make($validated['password']),
                    'role' => $validated['role'],
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'phone' => $this->blankToNull($validated['phone'] ?? null),
                    'company' => $this->blankToNull($validated['company'] ?? null),
                    'address' => $legacy,
                    'address_line1' => $this->blankToNull($validated['address_line1'] ?? null),
                    'address_line2' => $this->blankToNull($validated['address_line2'] ?? null),
                    'city' => $this->blankToNull($validated['city'] ?? null),
                    'state' => $this->blankToNull($validated['state'] ?? null),
                    'postcode' => $this->blankToNull($validated['postcode'] ?? null),
                    'country' => $this->blankToNull($validated['country'] ?? null),
                    'status' => $validated['status'],
                ]);

                $this->syncAdminlteRoles($user, $validated['role']);

                return $user;
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not create user: '.$e->getMessage()]);
        }

        $this->logActivity($user, 'user_created', "Staff account created ({$user->full_name})", [
            'user_id' => $user->id,
            'role' => $user->role,
            'by' => auth()->user()?->email,
        ]);

        return redirect()
            ->route('admin.users.show', $user)
            ->with('success', "User {$user->full_name} created.");
    }

    public function show(User $user): View
    {
        $this->assertStaffAccount($user);

        $user->load('roles.permissions');

        $activity = ActivityLog::query()
            ->where('metadata->user_id', $user->id)
            ->with('user')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return view('admin.users.show', compact('user', 'activity'));
    }

    public function edit(User $user): View
    {
        $this->assertStaffAccount($user);

        $user->load('roles');

        return view('admin.users.edit', compact('user'));
    }

    public function update(StaffUserRequest $request, User $user): RedirectResponse
    {
        $this->assertStaffAccount($user);

        $validated = $request->validated();

        // Self-protection: an admin may update their own profile details but
        // never demote their role or take their own account out of `active`.
        $isSelf = $user->id === $request->user()?->id;
        $roleChanged = isset($validated['role']) && $validated['role'] !== $user->role;
        $deactivatingSelf = $isSelf && isset($validated['status']) && $validated['status'] !== 'active';

        if (($isSelf && $roleChanged) || $deactivatingSelf) {
            return back()->withInput()->withErrors(['error' => 'You cannot change your own role or deactivate your own account.']);
        }

        try {
            DB::transaction(function () use ($validated, $user, $roleChanged) {
                $data = [];
                foreach (['first_name', 'last_name', 'email', 'phone', 'company', 'address', 'address_line1', 'address_line2', 'city', 'state', 'postcode', 'country', 'role', 'status'] as $key) {
                    if (array_key_exists($key, $validated)) {
                        $data[$key] = $validated[$key] ?? null;
                    }
                }

                // compile legacy address when structured fields are present
                if (array_intersect_key($validated, array_flip(['address_line1','address_line2','city','state','postcode','country'])) !== []) {
                    $legacy = collect([$validated['address_line1'] ?? $user->address_line1, $validated['address_line2'] ?? $user->address_line2, $validated['city'] ?? $user->city, $validated['state'] ?? $user->state, $validated['postcode'] ?? $user->postcode, $validated['country'] ?? $user->country])->filter()->implode(', ');
                    if ($legacy !== '') {
                        $data['address'] = $legacy;
                    }
                }

                foreach (['phone', 'company', 'address', 'address_line1', 'address_line2', 'city', 'state', 'postcode', 'country'] as $key) {
                    if (array_key_exists($key, $data) && $data[$key] === '') {
                        $data[$key] = null;
                    }
                }

                $user->update($data);

                if ($roleChanged) {
                    $this->syncAdminlteRoles($user, $validated['role']);
                }
            });
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not update user: '.$e->getMessage()]);
        }

        $this->logActivity($user, 'user_updated', "Staff account updated ({$user->full_name})", [
            'user_id' => $user->id,
            'by' => auth()->user()?->email,
        ]);

        return redirect()
            ->route('admin.users.show', $user)
            ->with('success', "User {$user->full_name} updated.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->assertStaffAccount($user);

        if ($user->id === $request->user()?->id) {
            abort(403, 'You cannot delete your own account.');
        }

        // Reference semantics: administrator accounts cannot be deleted.
        if ($user->role === 'admin') {
            return back()->withErrors(['error' => 'Administrator accounts cannot be deleted.']);
        }

        $this->logActivity($user, 'user_deleted', "Staff account deleted ({$user->full_name})", [
            'user_id' => $user->id,
            'by' => auth()->user()?->email,
        ]);

        $user->delete();

        return redirect()
            ->route('admin.users.index')
            ->with('success', "User {$user->full_name} deleted.");
    }

    /**
     * Activate / deactivate / suspend / unsuspend a staff account.
     *
     * Ports the reference activate (L271) / deactivate (L297) / lock (L323) /
     * unlock (L349) handlers into the local status vocabulary:
     *   activate, unsuspend -> active
     *   deactivate         -> inactive
     *   suspend            -> suspended   (reference "lock")
     */
    public function toggleStatus(Request $request, User $user): RedirectResponse
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
            return back()->with('info', "{$user->full_name} is already {$target}.");
        }

        $user->update(['status' => $target]);

        $this->logActivity($user, 'status_changed', "Status changed to {$target} ({$validated['action']})", [
            'user_id' => $user->id,
            'action' => $validated['action'],
            'by' => auth()->user()?->email,
        ]);

        return back()->with('success', "{$user->full_name} is now {$target}.");
    }

    /**
     * Send a Fortify password-reset email to the staff account owner.
     */
    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $this->assertStaffAccount($user);

        $status = Password::sendResetLink(['email' => $user->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            return back()->withErrors(['error' => __($status)]);
        }

        $this->logActivity($user, 'password_reset_email', "Password reset email sent to {$user->email}", [
            'user_id' => $user->id,
            'by' => auth()->user()?->email,
        ]);

        return back()->with('success', "Password reset email sent to {$user->email}.");
    }

    /**
     * Admin directly sets a new password for a staff account (no current password required).
     */
    public function setPassword(Request $request, User $user): RedirectResponse
    {
        $this->assertStaffAccount($user);

        $validated = $request->validate([
            'new_password' => ['required', 'string', 'min:8', 'confirmed', 'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/'],
        ]);

        $user->update(['password_hash' => Hash::make($validated['new_password'])]);

        $this->logActivity($user, 'password_set', "Password directly set for {$user->full_name}", [
            'user_id' => $user->id,
            'by' => auth()->user()?->email,
        ]);

        return back()->with('success', "Password for {$user->full_name} has been updated.");
    }

    /**
     * Keep the AdminLTE RBAC roles in sync with the users.role enum (1:1).
     * Roles that have no adminlte_roles row (e.g. `staff`) simply get no
     * pivot rows; panel access for those still works through users.role via
     * AdminMiddleware's hasRole() check.
     */
    private function syncAdminlteRoles(User $user, string $role): void
    {
        $roleModel = Role::where('name', $role)->first();

        $user->roles()->sync($roleModel !== null ? [$roleModel->id] : []);
    }

    private function assertStaffAccount(User $user): void
    {
        abort_unless($user->role !== 'client', 404, 'Client accounts are managed through the customer module.');
    }

    private function blankToNull(mixed $value): ?string
    {
        return ($value === null || $value === '') ? null : $value;
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
