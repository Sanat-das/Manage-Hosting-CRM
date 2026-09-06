<?php

declare(strict_types=1);

namespace App\Contracts\Module;

use App\Models\Server;
use App\Models\ServiceInstance;

/**
 * Everything a panel driver needs to create one account, resolved once by
 * AbstractPanelModule so each driver only writes its own API call.
 *
 * Immutable: a driver reads it and returns provider data, it never mutates
 * the request.
 */
final class PanelProvisionRequest
{
    /**
     * @param  array<string, mixed>  $config  the module's already-decrypted per-product config
     */
    public function __construct(
        public readonly ServiceInstance $service,
        public readonly Server $server,
        public readonly array $config,
        public readonly string $username,
        public readonly string $password,
        public readonly string $domain,
        public readonly string $contactEmail,
        public readonly string $plan,
    ) {}

    /**
     * A config value, trimmed, with a default when blank or absent.
     */
    public function config(string $key, string $default = ''): string
    {
        $value = trim((string) ($this->config[$key] ?? ''));

        return $value !== '' ? $value : $default;
    }
}
