<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PanelAccount;
use App\Models\ProvisioningEvent;
use App\Models\ServiceInstance;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Modules\ModuleManager;
use App\Services\Provisioning\ProvisioningDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

        return view('admin.service-instances.show', compact('serviceInstance', 'provisioningEvents'));
    }

    public function update(Request $request, ServiceInstance $serviceInstance): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:active,suspended,cancelled,terminated,pending'],
            'provision_status' => ['sometimes', 'string', 'in:pending,provisioning,provisioned,failed,suspended'],
        ]);
        $serviceInstance->update($validated);

        return redirect()->route('admin.service-instances.show', $serviceInstance)->with('success', 'Service instance updated.');
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
     * + event rows); order-less mirrors call the owning module directly,
     * resolved from the recorded PanelAccount panel (or the server's driver).
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

            $method = $verb === 'provision' ? 'provision' : $verb;
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
                        if ($module !== null && $module->status === \App\Models\Module::STATUS_ACTIVE) {
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
