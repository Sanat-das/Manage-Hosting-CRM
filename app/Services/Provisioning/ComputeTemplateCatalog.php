<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Models\Server;

/**
 * Per-module template metadata for compute (VM) provisioning drivers.
 *
 * Hyper-V identifies a template by VM name, Proxmox VE by numeric VMID — the
 * two catalogs already implement those semantics; this class is the single
 * place the rest of the application asks "does this module have selectable
 * templates, under which config key, and what is available on this server".
 *
 * Callers deliberately never branch on a slug themselves: adding a compute
 * module means registering it here and in the driver, not editing controllers,
 * presenters and jobs.
 *
 * Allow-list semantics are uniform across drivers: an empty allow-list means
 * "everything this server curates", a non-empty one narrows it.
 */
final class ComputeTemplateCatalog
{
    /**
     * slug => [config key holding the picked template, human label].
     *
     * @var array<string, array{key: string, label: string}>
     */
    private const DRIVERS = [
        'hyperv' => ['key' => 'template_vm', 'label' => 'Template VM'],
        'proxmox' => ['key' => 'template_vmid', 'label' => 'Template VMID'],
        'virtualizor' => ['key' => 'osid', 'label' => 'OS template'],
    ];

    public static function supports(string $slug): bool
    {
        return isset(self::DRIVERS[self::normalize($slug)]);
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(self::DRIVERS);
    }

    /** The module config key that carries the picked template, or null. */
    public static function templateKey(string $slug): ?string
    {
        return self::DRIVERS[self::normalize($slug)]['key'] ?? null;
    }

    public static function templateLabel(string $slug): string
    {
        return self::DRIVERS[self::normalize($slug)]['label'] ?? 'Template';
    }

    /**
     * @return list<string>
     */
    public static function sanitizeAllowed(string $slug, mixed $raw): array
    {
        return match (self::normalize($slug)) {
            'hyperv' => HypervTemplateCatalog::sanitizeAllowed($raw),
            'proxmox' => ProxmoxTemplateCatalog::sanitizeAllowed($raw),
            // Virtualizor OS ids are numeric like PVE VMIDs.
            'virtualizor' => ProxmoxTemplateCatalog::sanitizeAllowed($raw),
            default => [],
        };
    }

    /**
     * Every template the server curates, unfiltered — the denominator the UI
     * uses to distinguish "nothing curated" from "product restricted to none".
     *
     * @return list<string>
     */
    public static function curatedIds(Server $server, string $slug): array
    {
        return match (self::normalize($slug)) {
            'hyperv' => $server->hypervTemplateVms(),
            'proxmox' => array_map(
                static fn (array $template): string => (string) $template['vmid'],
                $server->proxmoxTemplates(),
            ),
            'virtualizor' => array_map(
                static fn (array $template): string => (string) $template['osid'],
                $server->virtualizorOsTemplates(),
            ),
            default => [],
        };
    }

    /**
     * Curated ids a product may use on this server (empty allow-list = all).
     *
     * @param  list<string>  $allowed
     * @return list<string>
     */
    public static function effectiveIds(Server $server, string $slug, array $allowed): array
    {
        return array_column(self::options($server, $slug, $allowed), 'id');
    }

    /**
     * Selectable options, each with the identity the driver expects
     * (`id`) plus a display `label` — and `node` for drivers that need it
     * (Proxmox templates live on a specific node).
     *
     * @param  list<string>  $allowed  sanitized allow-list (may be empty)
     * @return list<array{id: string, label: string, node?: string}>
     */
    public static function options(Server $server, string $slug, array $allowed = []): array
    {
        $slug = self::normalize($slug);

        if ($slug === 'hyperv') {
            $out = [];
            foreach (HypervTemplateCatalog::effectiveOptions($server, $allowed) as $option) {
                $out[] = ['id' => (string) $option['name'], 'label' => (string) $option['label']];
            }

            return $out;
        }

        if ($slug === 'proxmox') {
            $out = [];
            foreach (ProxmoxTemplateCatalog::effectiveTemplates($server, $allowed) as $template) {
                $out[] = [
                    'id' => (string) $template['vmid'],
                    'label' => (string) ($template['label'] !== '' ? $template['label'] : $template['vmid']),
                    'node' => (string) $template['node'],
                ];
            }

            return $out;
        }

        if ($slug === 'virtualizor') {
            $curated = $server->virtualizorOsTemplates();

            if ($allowed === []) {
                $effective = $curated;
            } else {
                $allowedSet = array_flip($allowed);
                $effective = array_values(array_filter(
                    $curated,
                    static fn (array $template): bool => isset($allowedSet[(string) $template['osid']]),
                ));
            }

            return array_map(
                static fn (array $template): array => [
                    'id' => (string) $template['osid'],
                    'label' => (string) ($template['label'] !== '' ? $template['label'] : $template['osid']),
                ],
                $effective,
            );
        }

        return [];
    }

    /**
     * Union of curated templates across ACTIVE servers of this module's type,
     * deduped by id and sorted by label. Powers the product-level default
     * picker, which cannot know which server a service will land on.
     *
     * @return list<array{id: string, label: string}>
     */
    public static function unionOptions(string $slug): array
    {
        $slug = self::normalize($slug);

        if (! self::supports($slug)) {
            return [];
        }

        $byId = [];

        try {
            $servers = Server::query()
                ->where('status', 'active')
                ->where('server_type', $slug)
                ->get();

            foreach ($servers as $server) {
                foreach (self::options($server, $slug) as $option) {
                    $id = (string) $option['id'];

                    if ($id === '' || isset($byId[$id])) {
                        continue;
                    }

                    $byId[$id] = ['id' => $id, 'label' => (string) $option['label']];
                }
            }
        } catch (\Throwable) {
            return [];
        }

        $out = array_values($byId);

        usort($out, static function (array $a, array $b): int {
            $c = strcasecmp($a['label'], $b['label']);

            return $c !== 0 ? $c : strcmp($a['id'], $b['id']);
        });

        return $out;
    }

    /** The server's configured default template, when it is still curated. */
    public static function default(Server $server, string $slug): ?string
    {
        return match (self::normalize($slug)) {
            'hyperv' => $server->hypervDefaultTemplate(),
            'proxmox' => $server->proxmoxDefaultTemplate(),
            'virtualizor' => $server->virtualizorDefaultOs(),
            default => null,
        };
    }

    /**
     * One option by identity, or null when it is not in the product's
     * effective set. Used by callers that need the node for a Proxmox VMID.
     *
     * @param  list<string>  $allowed
     * @return array{id: string, label: string, node?: string}|null
     */
    public static function findOption(Server $server, string $slug, array $allowed, string $id): ?array
    {
        $id = trim($id);

        if ($id === '') {
            return null;
        }

        foreach (self::options($server, $slug, $allowed) as $option) {
            if ($option['id'] === $id) {
                return $option;
            }
        }

        return null;
    }

    private static function normalize(string $slug): string
    {
        return strtolower(trim($slug));
    }
}
