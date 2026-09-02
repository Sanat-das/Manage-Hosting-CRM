<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Module;
use App\Models\ModuleLog;
use App\Models\ServiceInstance;
use App\Services\Modules\ModuleManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Runs a single capability call (e.g. provision/suspend/unsuspend/terminate)
 * on a module's provider inside a queued job, so module work never runs
 * inline in request paths. The job is fully isolated: every failure is logged
 * to module_log and never rethrown.
 */
class RunModuleCapability implements ShouldQueue
{
    use Dispatchable;

    public function __construct(
        public readonly int $moduleId,
        public readonly string $capability,
        public readonly string $method,
        public readonly int $serviceInstanceId,
        public readonly array $config,
    ) {
    }

    public function handle(ModuleManager $manager): void
    {
        $module = Module::find($this->moduleId);

        if ($module === null) {
            return;
        }

        $instance = $manager->capabilityInstance($module, $this->capability);

        if ($instance === null) {
            $this->log($module, 'capability', 'failed', 'capability not supported', $this->serviceInstanceId);

            return;
        }

        $service = ServiceInstance::find($this->serviceInstanceId);

        if ($service === null) {
            $this->log($module, 'capability', 'failed', 'service instance not found', $this->serviceInstanceId);

            return;
        }

        try {
            $result = $instance->{$this->method}($service, $manager->decryptConfig($module, $this->config));

            $this->log(
                $module,
                $this->method,
                $result->success ? 'success' : 'failed',
                $result->success ? null : $result->message,
                $this->serviceInstanceId,
            );
        } catch (Throwable $e) {
            $this->log($module, $this->method, 'failed', $e->getMessage(), $this->serviceInstanceId);
        }
    }

    private function log(Module $module, string $event, string $status, ?string $error = null, ?int $serviceInstanceId = null): void
    {
        try {
            ModuleLog::create([
                'module_id' => $module->id,
                'event' => $event,
                'status' => $status,
                'error' => $error,
                'service_instance_id' => $serviceInstanceId,
            ]);
        } catch (Throwable) {
            // Logging must never break job isolation.
        }
    }
}