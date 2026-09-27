<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Models\Server;

/**
 * Proxmox VE curated-template catalog — the PVE twin of HypervTemplateCatalog.
 *
 * Identity is the **VMID**, not a display name: PVE VMIDs are unique across a
 * cluster and stable, whereas a VM name can be renamed or duplicated. The name
 * is carried alongside as the label.
 *
 * Every entry also carries the node the template lives on. A clone addresses
 * the template on ITS node and passes `target` for where the new VM should end
 * up, so a product whose template lives on one node can still provision onto
 * another.
 */
final class ProxmoxTemplateCatalog
{
    /**
     * Union of curated templates across ACTIVE proxmox servers, deduped by
     * VMID (first occurrence wins), sorted by label then VMID.
     *
     * @return list<array{vmid: string, node: string, label: string}>
     */
    public static function unionOptions(): array
    {
        $servers = Server::query()
            ->where('status', 'active')
            ->where('server_type', 'proxmox')
            ->get();

        $byVmid = [];

        foreach ($servers as $server) {
            foreach ($server->proxmoxTemplates() as $template) {
                $vmid = (string) $template['vmid'];

                if ($vmid === '' || isset($byVmid[$vmid])) {
                    continue;
                }

                $byVmid[$vmid] = $template;
            }
        }

        $out = array_values($byVmid);

        usort($out, static function (array $a, array $b): int {
            $c = strcasecmp((string) $a['label'], (string) $b['label']);

            return $c !== 0 ? $c : strcmp((string) $a['vmid'], (string) $b['vmid']);
        });

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function unionVmIds(): array
    {
        return array_map(static fn (array $t): string => (string) $t['vmid'], self::unionOptions());
    }

    /**
     * Templates a product may use on a given server.
     *
     * An empty allow-list means "everything this server curates" — the same
     * semantics as Hyper-V's restriction, so an unrestricted product keeps
     * working while a restricted tier only sees its approved images.
     *
     * @param  list<string>  $allowed  sanitized allowed_templates (may be empty)
     * @return list<array{vmid: string, node: string, label: string}>
     */
    public static function effectiveTemplates(Server $server, array $allowed): array
    {
        $curated = $server->proxmoxTemplates();
        $sanitized = self::sanitizeAllowed($allowed);

        if ($sanitized === []) {
            return $curated;
        }

        $allowedSet = array_flip($sanitized);

        return array_values(array_filter(
            $curated,
            static fn (array $template): bool => isset($allowedSet[(string) $template['vmid']]),
        ));
    }

    /**
     * Sanitize an allowed_templates list: trim, digits only, dedupe, cap 50.
     *
     * @return list<string>
     */
    public static function sanitizeAllowed(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        $seen = [];

        foreach ($raw as $item) {
            if (! is_string($item) && ! is_numeric($item)) {
                continue;
            }

            $value = trim((string) $item);

            // VMIDs are positive integers; anything else is not a template id.
            if ($value === '' || ! ctype_digit($value) || (int) $value <= 0) {
                continue;
            }

            if (isset($seen[$value])) {
                continue;
            }

            $seen[$value] = true;
            $out[] = $value;

            if (count($out) >= 50) {
                break;
            }
        }

        return $out;
    }

    /**
     * Find one curated entry by VMID across every active proxmox server.
     *
     * @return array{vmid: string, node: string, label: string}|null
     */
    public static function find(string $vmid): ?array
    {
        foreach (self::unionOptions() as $template) {
            if ((string) $template['vmid'] === $vmid) {
                return $template;
            }
        }

        return null;
    }
}
