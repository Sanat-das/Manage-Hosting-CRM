<?php

namespace App\Http\Controllers\AdminLte;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeManage();

        $search = trim((string) $request->query('search'));

        $query = Role::withCount('permissions');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('label', 'like', "%{$search}%");
            });
        }

        $roles = $query
            ->gridSort([
                'name' => 'name',
                'label' => 'label',
                'permissions' => 'permissions_count',
            ])
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('adminlte.roles.index', compact('roles', 'search'));
    }

    public function create(): View
    {
        $this->authorizeManage();

        $permissions = Permission::orderBy('name')->get();
        $grouped = $this->groupedPermissions($permissions);

        return view('adminlte.roles.create', compact('permissions', 'grouped'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:adminlte_roles,name'],
            'label' => ['nullable', 'string', 'max:255'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['integer', 'exists:adminlte_permissions,id'],
        ]);

        $role = Role::create([
            'name' => $data['name'],
            'label' => $data['label'] ?? null,
        ]);

        $role->permissions()->sync($data['permissions'] ?? []);

        return redirect()->route('adminlte.roles.index')
            ->with('status', __('adminlte.role_created'));
    }

    public function edit(Role $role): View
    {
        $this->authorizeManage();
        $this->denyIfProtected($role);

        $permissions = Permission::orderBy('name')->get();
        $grouped = $this->groupedPermissions($permissions);
        $role->load('permissions');

        return view('adminlte.roles.edit', compact('role', 'permissions', 'grouped'));
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->authorizeManage();
        $this->denyIfProtected($role);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:adminlte_roles,name,'.$role->id],
            'label' => ['nullable', 'string', 'max:255'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['integer', 'exists:adminlte_permissions,id'],
        ]);

        $role->update([
            'name' => $data['name'],
            'label' => $data['label'] ?? null,
        ]);

        $role->permissions()->sync($data['permissions'] ?? []);

        return redirect()->route('adminlte.roles.index')
            ->with('status', __('adminlte.role_updated'));
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->authorizeManage();
        $this->denyIfProtected($role);

        $role->delete();

        return redirect()->route('adminlte.roles.index')
            ->with('status', __('adminlte.role_deleted'));
    }

    private function denyIfProtected(Role $role): void
    {
        abort_if($role->name === 'admin', 403, 'The admin role cannot be modified.');
    }

    private function authorizeManage(): void
    {
        $user = auth()->user();

        abort_unless($user !== null && ($user->isAdmin() || $user->hasPermission('manage-roles')), 403);
    }

    /**
     * Group permissions by domain for the grouped checklist UI.
     *
     * Mirrors the granular inventory in config/permissions.php — every
     * seeded permission is assigned to exactly one visual group, ordered
     * for scanning (Dashboard first, System last). Unmatched names fall
     * into "Other" so new seeders never silently disappear.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\Permission>  $permissions
     * @return array<string, \Illuminate\Support\Collection<int, \App\Models\Permission>>
     */
    private function groupedPermissions(\Illuminate\Support\Collection $permissions): array
    {
        $definitions = [
            'Dashboard & Reporting' => ['dashboard.', 'analytics.', 'reports.', 'activity.', 'search'],
            'Customer Management' => ['customers.'],
            'Products & Catalog' => ['products.', 'catalog-products.', 'product-bundles.', 'product-upgrades.'],
            'Sales & Billing' => ['orders.', 'invoices.', 'payments.', 'subscriptions.', 'usage-records.', 'tax-rates.'],
            'Hosting' => ['hosting.'],
            'Infrastructure' => ['datacenters.', 'racks.', 'licenses.', 'inventory.', 'asset-relationships.', 'resource-types.', 'resource-pools.', 'service-instances.', 'provisioning-events.'],
            'Network & DNS' => ['ip-subnets.', 'ip-addresses.', 'vlans.', 'dns-zones.', 'dns-records.'],
            'Domains' => ['domains.'],
            'Support' => ['tickets.', 'kb.'],
            'System & Administration' => ['settings.', 'cron.', 'modules.', 'manage-roles', 'manage-users', 'notifications.', 'users.', 'email.'],
        ];

        $grouped = [];
        foreach (array_keys($definitions) as $key) {
            $grouped[$key] = collect();
        }
        $grouped['Other'] = collect();

        foreach ($permissions as $permission) {
            $name = $permission->name;
            $placed = false;

            foreach ($definitions as $group => $prefixes) {
                foreach ($prefixes as $prefix) {
                    if ($name === $prefix || str_starts_with($name, $prefix)) {
                        $grouped[$group]->push($permission);
                        $placed = true;
                        break 2;
                    }
                }
            }

            if (! $placed) {
                $grouped['Other']->push($permission);
            }
        }

        // Drop empty groups (including Other if empty) and keep definition order
        $grouped = array_filter($grouped, fn ($c) => $c->isNotEmpty());

        // Sort within each group by label/name for stable scan
        foreach ($grouped as $group => $items) {
            $grouped[$group] = $items->sortBy(fn ($p) => $p->label ?? $p->name)->values();
        }

        return $grouped;
    }
}
