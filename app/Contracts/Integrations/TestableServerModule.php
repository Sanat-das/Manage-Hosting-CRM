<?php

declare(strict_types=1);

namespace App\Contracts\Integrations;

use App\Models\Server;

/**
 * Capability for modules that represent a server type.
 *
 * Server creation is type-locked: the available types are exactly the active
 * modules that implement this interface. Each supplies the fields for the
 * credential form, a liveness probe, and a richer info fetch whose payload is
 * stored in servers.connection_meta for the admin detail view.
 */
interface TestableServerModule
{
    /**
     * Field definitions for the server credential / connection form.
     *
     * Same shape as the `fields` array in the integration's configSchema(), e.g.:
     * [['key'=>'api_url','label'=>'API URL','type'=>'text','required'=>false], ...]
     *
     * @return array{fields: array<int, array{key: string, label: string, type: string, required?: bool, encrypted?: bool, options?: array<string, string>, default?: mixed}>}|array<int, array<string, mixed>>
     */
    public function serverConfigSchema(): array;

    public function testConnection(Server $server): ServerConnectionResult;

    public function getServerInfo(Server $server): ServerInfoDTO;
}
