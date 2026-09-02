<?php

declare(strict_types=1);

namespace App\Contracts\Module;

use App\Models\Module;
use App\Models\ModuleLog;

/**
 * Immutable context handed to a module for the current request.
 *
 * Holds the backing Module row plus the ALREADY-DECRYPTED config — this
 * class never decrypts; the module manager decrypts once and passes the
 * plaintext array in. Modules must treat the config as read-only.
 */
final class ModuleContext
{
    public function __construct(
        public readonly Module $module,
        private readonly array $config,
    ) {
    }

    /**
     * Read config by key, or the whole config array when $key is null.
     *
     * @return mixed
     */
    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        return $this->config[$key] ?? $default;
    }

    /**
     * Append an audit entry to the module's log.
     *
     * @param  string  $event   short event name, e.g. 'provision'
     * @param  string  $status  log level/outcome, e.g. 'info', 'success', 'error'
     * @param  string|null  $error  error message when the event failed
     * @param  int|null  $serviceInstanceId  related service instance, when applicable
     */
    public function log(string $event, string $status = 'info', ?string $error = null, ?int $serviceInstanceId = null): void
    {
        ModuleLog::create([
            'module_id' => $this->module->id,
            'event' => $event,
            'status' => $status,
            'error' => $error,
            'service_instance_id' => $serviceInstanceId,
        ]);
    }
}
