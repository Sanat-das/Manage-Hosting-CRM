<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Order;
use App\Models\PanelAccount;
use App\Models\ProvisioningEvent;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Modules\ModuleManager;
use App\Services\Provisioning\ProvisioningDispatcher;
use App\Services\Provisioning\VmOperationConflictException;
use App\Services\Provisioning\VmOperationDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ServiceInstanceController extends Controller
{
    public function index(Request $request): View
    {
        $query = ServiceInstance::with(['customer', 'catalogProduct', 'server', 'serverGroup']);
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('provision_status')) {
            $query->where('provision_status', $request->query('provision_status'));
        }
        $search = trim((string) $request->query('search'));
        if ($search !== '') {
            $query->where(function ($q2) use ($search) {
                $q2->where('username', 'like', "%{$search}%")
                    ->orWhere('domain', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($cq) => $cq->where('email', 'like', "%{$search}%"));
            });
        }
        $instances = $query
            ->gridSort([
                'id' => 'id',
                'domain' => 'domain',
                'product' => 'catalogProduct.name',
                'server' => 'server.name',
                'status' => 'status',
            ])
            ->orderByDesc('id')->paginate(25)->withQueryString();
        $status = trim((string) $request->query('status'));

        return view('admin.service-instances.index', compact('instances', 'search', 'status'));
    }

    public function show(ServiceInstance $serviceInstance): View
    {
        $serviceInstance->load(['customer', 'catalogProduct', 'server', 'serverGroup', 'subscriptionPeriods', 'usageRecords']);
        $provisioningEvents = ProvisioningEvent::where('service_instance_id', $serviceInstance->id)
            ->orderByDesc('created_at')->limit(20)->get();

        // Eligible move targets: active servers of the same kind. Resolved here
        // so the view never queries, and so an impossible move is not offered.
        $moveTargets = Server::query()
            ->where('status', 'active')
            ->where('id', '!=', (int) $serviceInstance->server_id)
            ->when(
                in_array(strtolower(trim((string) $serviceInstance->provisioning_method)), ['proxmox', 'hyperv', 'virtualizor', 'cpanel', 'plesk', 'directadmin'], true),
                fn ($query) => $query->where('server_type', strtolower(trim((string) $serviceInstance->provisioning_method))),
            )
            ->orderBy('name')
            ->get(['id', 'name', 'server_type']);

        // true = verified present, false = verified gone, null = unverifiable.
        // Unknown counts as present for the warning, so an unprobeable driver
        // keeps the safe behaviour.
        $machineState = $this->machineState($serviceInstance);

        return view('admin.service-instances.show', compact('serviceInstance', 'provisioningEvents', 'moveTargets', 'machineState'));
    }

    public function update(Request $request, ServiceInstance $serviceInstance): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:active,suspended,cancelled,terminated,pending'],
            'provision_status' => ['sometimes', 'string', Rule::in(ServiceInstance::PROVISION_STATUSES)],
        ]);
        $serviceInstance->update($validated);

        return redirect()->route('admin.service-instances.show', $serviceInstance)->with('success', 'Service instance updated.');
    }

    /**
     * Re-point a service at a different server.
     *
     * `service_instances.server_id` is otherwise written exactly once, by the
     * provisioning dispatcher at creation. That left no way to move a service
     * off a server being decommissioned — and because the server delete is
     * correctly blocked while services point at it, the server could not be
     * removed either. This closes that dead end.
     *
     * Two guards, because a wrong move is silent and damaging:
     *
     *  - the target must be an active server of the same kind, so a Proxmox
     *    service cannot be pointed at a Hyper-V host;
     *  - a service whose machine was actually built needs an explicit
     *    acknowledgement. Every later lifecycle call resolves the driver from
     *    `server_id`, so pointing it at a server that does not hold the machine
     *    would make suspend/terminate target the wrong place.
     */
    public function move(Request $request, ServiceInstance $serviceInstance): RedirectResponse
    {
        $validated = $request->validate([
            'server_id' => ['required', 'integer', 'exists:servers,id'],
            'confirm_machine_handled' => ['sometimes', 'boolean'],
        ]);

        $target = Server::findOrFail($validated['server_id']);

        if ((int) $serviceInstance->server_id === (int) $target->id) {
            return back()->with('error', sprintf('This service is already on "%s".', $target->name));
        }

        $blocker = $this->moveBlocker($serviceInstance, $target);

        if ($blocker !== null) {
            return back()->with('error', $blocker);
        }

        $from = $serviceInstance->server?->name ?? 'unassigned';
        $tag = $serviceInstance->service_tag ?: '#'.$serviceInstance->id;
        $machine = $this->machineState($serviceInstance);

        // `false` is the only outcome that clears the guard: a machine that could
        // NOT be verified (`null`) must be treated as still there, otherwise an
        // unreachable host would let a live service be re-pointed silently.
        $confirmed = $request->boolean('confirm_machine_handled');

        if ($machine !== false && ! $confirmed) {
            return back()->with('error', sprintf(
                '%s Moving it would point suspend/terminate at a server that does not hold that machine. '
                .'Migrate or destroy it in Proxmox/Hyper-V first, then tick the confirmation to move.',
                $machine === true
                    ? sprintf('Service %s already has a machine on "%s".', $tag, $from)
                    : sprintf('Whether service %s still has a machine on "%s" could not be verified.', $tag, $from),
            ));
        }

        $fromServerId = $serviceInstance->server_id;

        $serviceInstance->update(['server_id' => $target->id]);

        // `created_at` is not fillable on AuditLog, so it is set explicitly —
        // passing it to create() would silently store NULL.
        $audit = new AuditLog([
            'user_id' => $request->user()?->id,
            'action' => 'service.moved',
            'entity_type' => 'service_instance',
            'entity_id' => $serviceInstance->id,
            'details' => json_encode([
                'service' => $tag,
                'from' => $from,
                'from_server_id' => $fromServerId,
                'to' => $target->name,
                'to_server_id' => $target->id,
                'machine_state' => $machine === null ? 'unknown' : ($machine ? 'present' : 'absent'),
                'machine_acknowledged' => $machine === true && $confirmed,
            ]),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
        $audit->created_at = now();
        $audit->save();

        $note = match ($machine) {
            true => ' Its existing machine must be migrated separately.',
            false => ' The machine previously recorded for it no longer exists on the host.',
            default => '',
        };

        return redirect()
            ->route('admin.service-instances.show', $serviceInstance)
            ->with('success', sprintf('Service %s moved from "%s" to "%s".%s', $tag, $from, $target->name, $note));
    }

    /**
     * Why this service cannot be moved to this server, or null when it can.
     */
    private function moveBlocker(ServiceInstance $serviceInstance, Server $target): ?string
    {
        $tag = $serviceInstance->service_tag ?: '#'.$serviceInstance->id;

        if (trim((string) $target->status) !== 'active') {
            return sprintf('Server "%s" is not active, so services cannot be moved onto it.', $target->name);
        }

        if (in_array(strtolower(trim((string) $serviceInstance->status)), ['terminated', 'cancelled'], true)) {
            return sprintf('Service %s is %s — there is nothing left to move.', $tag, $serviceInstance->status);
        }

        // Only constrain when both sides name a known server type; 'manual' or
        // a plugin slug has no type to match against.
        $method = strtolower(trim((string) $serviceInstance->provisioning_method));
        $type = strtolower(trim((string) ($target->server_type ?? '')));
        $typed = ['proxmox', 'hyperv', 'virtualizor', 'cpanel', 'plesk', 'directadmin'];

        if (in_array($method, $typed, true) && $type !== '' && $method !== $type) {
            return sprintf(
                'Service %s is a %s service and cannot live on a %s server. Pick a %s server.',
                $tag,
                $method,
                $type,
                $method,
            );
        }

        return null;
    }

    /**
     * Does this service really still have a machine?
     *
     * `true` / `false` are verified against the owning driver. `null` means it
     * could not be determined — no panel account, no probing hook, or the host
     * did not answer — and callers must treat that as "assume it is still
     * there", never as "gone".
     *
     * Without the probe a stale record (the VM was deleted out-of-band) would
     * demand the confirmation tick forever, which trains operators to tick it
     * blindly.
     */
    private function machineState(ServiceInstance $serviceInstance): ?bool
    {
        $account = PanelAccount::where('service_instance_id', $serviceInstance->id)
            ->orderByDesc('id')
            ->first();

        if ($account === null) {
            // No panel record at all: the only signal left is external_id, which
            // a module writes on success. It cannot be verified, so trust it.
            return trim((string) ($serviceInstance->external_id ?? '')) !== '' ? null : false;
        }

        $server = $account->server_id !== null ? Server::find($account->server_id) : null;

        if ($server === null) {
            return null;
        }

        try {
            $driver = app(IntegrationRegistry::class)->resolveForServer($server);
        } catch (\Throwable) {
            return null;
        }

        if ($driver === null || ! method_exists($driver, 'recordedVmState')) {
            // No hook for this driver — cannot verify, so assume present.
            return null;
        }

        try {
            $probe = $driver->recordedVmState($account);

            return is_bool($probe['exists'] ?? null) ? $probe['exists'] : null;
        } catch (\Throwable $e) {
            Log::warning('Could not probe service machine state', [
                'service_instance_id' => $serviceInstance->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Retry provisioning through the product's module (idempotent: an
     * already-active PanelAccount short-circuits in the module, no duplicate
     * remote resource).
     */
    public function provision(ServiceInstance $serviceInstance): RedirectResponse
    {
        $attempt = $this->runModule($serviceInstance, 'provision', 'provisioned');

        if ($attempt === true) {
            $serviceInstance->update(['status' => 'active', 'provision_status' => 'provisioned']);

            return redirect()->route('admin.service-instances.show', $serviceInstance)->with('success', 'Service provisioned (module synced).');
        }

        $serviceInstance->update(['provision_status' => 'failed']);

        return redirect()->route('admin.service-instances.show', $serviceInstance)->with('error', $attempt);
    }

    public function suspend(ServiceInstance $serviceInstance): RedirectResponse
    {
        $attempt = $this->runModule($serviceInstance, 'suspend', 'suspended');

        if ($attempt === true) {
            $serviceInstance->update(['status' => 'suspended', 'provision_status' => 'suspended']);

            return redirect()->route('admin.service-instances.show', $serviceInstance)->with('success', 'Service suspended (module synced).');
        }

        return redirect()->route('admin.service-instances.show', $serviceInstance)->with('error', $attempt);
    }

    public function unsuspend(ServiceInstance $serviceInstance): RedirectResponse
    {
        $attempt = $this->runModule($serviceInstance, 'unsuspend', 'active');

        if ($attempt === true) {
            $serviceInstance->update(['status' => 'active', 'provision_status' => 'provisioned']);

            return redirect()->route('admin.service-instances.show', $serviceInstance)->with('success', 'Service reactivated (module synced).');
        }

        return redirect()->route('admin.service-instances.show', $serviceInstance)->with('error', $attempt);
    }

    public function terminate(ServiceInstance $serviceInstance): RedirectResponse
    {
        $attempt = $this->runModule($serviceInstance, 'terminate', 'terminated');

        if ($attempt === true) {
            $serviceInstance->update(['status' => 'terminated', 'provision_status' => 'terminated']);

            return redirect()->route('admin.service-instances.index')->with('success', 'Service terminated (module synced).');
        }

        return redirect()->route('admin.service-instances.show', $serviceInstance)->with('error', $attempt);
    }

    /**
     * Run a lifecycle verb against the module that owns this service.
     *
     * Order-born services go through the ProvisioningDispatcher (order audit
     * + event rows); order-less lifecycle verbs queue through
     * VmOperationDispatcher (the dispatcher owns the durable event, so the
     * one-shot writes below only serve the order-less provision path, which
     * stays inline because a build is not a VM operation).
     *
     * The local status flip happens only in the action methods above — never
     * here — so a module refusal leaves local and remote in agreement.
     *
     * @return bool|string true on success, error message otherwise
     */
    private function runModule(ServiceInstance $serviceInstance, string $verb, string $eventType): bool|string
    {
        try {
            if ($serviceInstance->order_id !== null) {
                $order = Order::find($serviceInstance->order_id);

                if ($order !== null) {
                    $dispatcher = app(ProvisioningDispatcher::class);

                    // provision maps to a full dispatcher run (config merge +
                    // snapshot + welcome mail); the lifecycle verbs map 1:1.
                    $attempt = $verb === 'provision'
                        ? $dispatcher->run($order)
                        : $dispatcher->{$verb}($order, 'Admin action on service #'.$serviceInstance->id);

                    if ($attempt->succeeded()) {
                        return true;
                    }

                    return $attempt->message ?? ucfirst($verb).' failed.';
                }
            }

            [$slug, $provisioner, $config] = $this->provisionerForService($serviceInstance);

            if ($provisioner === null || $slug === null) {
                return 'No provisioning module owns this service (no order, panel record, or server module).';
            }

            if (! method_exists($provisioner, $verb === 'provision' ? 'provision' : $verb)) {
                return "Module {$slug} cannot provision.";
            }

            // Order-less power verbs run queued: the durable event goes
            // running/queued now and lands completed/failed on the worker. A
            // queue-time refusal (conflict, unresolvable module) returns here
            // for the error flash.
            if ($verb !== 'provision') {
                try {
                    app(VmOperationDispatcher::class)->dispatchForService(
                        $serviceInstance,
                        $verb,
                        [],
                        auth()->id(),
                        $slug,
                    );

                    return true;
                } catch (VmOperationConflictException $e) {
                    return $e->getMessage();
                } catch (\Throwable $e) {
                    Log::error('Service instance queued module action failed', [
                        'service_instance_id' => $serviceInstance->id,
                        'action' => $verb,
                        'error' => $e->getMessage(),
                    ]);

                    return "Module action failed: {$e->getMessage()}";
                }
            }

            $method = 'provision';
            $result = $provisioner->{$method}($serviceInstance, $config);

            if ($result->success) {
                ProvisioningEvent::create([
                    'service_instance_id' => $serviceInstance->id,
                    'event_type' => $eventType,
                    'event_status' => 'completed',
                    'triggered_by' => auth()->id(),
                    'payload' => ['reason' => 'Admin action', 'module' => $slug],
                    'result' => ['status' => $eventType, 'message' => $result->message],
                ]);

                return true;
            }

            ProvisioningEvent::create([
                'service_instance_id' => $serviceInstance->id,
                'event_type' => $eventType,
                'event_status' => 'failed',
                'triggered_by' => auth()->id(),
                'payload' => ['reason' => 'Admin action', 'module' => $slug],
                'result' => ['error' => $result->message],
            ]);

            return $result->message ?? ucfirst($verb).' failed.';
        } catch (\Throwable $e) {
            Log::error('Service instance module action failed', [
                'service_instance_id' => $serviceInstance->id,
                'action' => $verb,
                'error' => $e->getMessage(),
            ]);

            return "Module action failed: {$e->getMessage()}";
        }
    }

    /**
     * Owning provisioner for an order-less service: the recorded PanelAccount's
     * panel slug first, falling back to the server's driver.
     *
     * @return array{0: ?string, 1: ?object, 2: array<string, mixed>} [slug, driver, config]
     */
    private function provisionerForService(ServiceInstance $serviceInstance): array
    {
        $registry = app(IntegrationRegistry::class);

        $panel = PanelAccount::where('service_instance_id', $serviceInstance->id)->orderByDesc('id')->first();

        if ($panel !== null) {
            $slug = trim((string) $panel->panel);
            if ($slug !== '') {
                $driver = $registry->instanceFor($slug);
                // Plugin fallback
                if ($driver === null) {
                    try {
                        $module = app(ModuleManager::class)->find($slug);
                        if ($module !== null && $module->status === Module::STATUS_ACTIVE) {
                            $driver = app(ModuleManager::class)->capabilityInstance($module, 'provisioning');
                            if ($driver !== null) {
                                $config = app(ModuleManager::class)->decryptConfig($module, is_array($module->config ?? null) ? $module->config : []);

                                return [$slug, $driver, $config];
                            }
                        }
                    } catch (\Throwable) {
                        // degrade
                    }
                }
                if ($driver !== null) {
                    // Builtin: decrypt via registry (config may be link-level but for order-less we use empty or panel config)
                    $config = $registry->decryptConfigFor($slug, []);

                    return [$slug, $driver, $config];
                }
            }
        }

        $server = $serviceInstance->server;

        if ($server !== null) {
            $driver = $registry->resolveForServer($server);
            if ($driver !== null) {
                $slug = trim((string) ($server->server_type ?? ''));
                if ($slug === '') {
                    $slug = 'unknown';
                }
                // For server-resolved driver, config is server's type config (no link). Decrypt empty for now.
                $config = $registry->decryptConfigFor($slug, []);
                // If plugin driver resolved via ModuleManager fallback inside resolveForServer, try to get its stored config
                if (! $registry->has($slug)) {
                    try {
                        $module = app(ModuleManager::class)->find($slug);
                        if ($module !== null) {
                            $config = app(ModuleManager::class)->decryptConfig($module, is_array($module->config ?? null) ? $module->config : []);
                        }
                    } catch (\Throwable) {
                        // keep registry decrypt
                    }
                }

                return [$slug, $driver, $config];
            }
        }

        return [null, null, []];
    }
}
