<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Models\Server;

final class HypervTemplateCatalog
{
    /**
     * Union of ['name','label'] across ACTIVE hyperv servers, deduped by name (first label wins), sorted by label then name.
     *
     * @return list<array{name: string, label: string}>
     */
    public static function unionOptions(): array
    {
        $servers = Server::query()
            ->where('status', 'active')
            ->where('server_type', 'hyperv')
            ->get();

        $byName = [];
        foreach ($servers as $server) {
            foreach ($server->hypervTemplateOptions() as $opt) {
                $name = (string) ($opt['name'] ?? '');
                if ($name === '' || isset($byName[$name])) {
                    continue;
                }
                $byName[$name] = ['name' => $name, 'label' => (string) ($opt['label'] ?? $name)];
            }
        }

        $out = array_values($byName);
        usort($out, static function (array $a, array $b): int {
            $c = strcmp((string) $a['label'], (string) $b['label']);
            if ($c !== 0) {
                return $c;
            }
            return strcmp((string) $a['name'], (string) $b['name']);
        });

        return $out;
    }

    /**
     * Effective template names for a product on a given server.
     *
     * @param  list<string>  $allowed  sanitized allowed_templates (may be empty)
     * @return list<string>
     */
    public static function effectiveNames(Server $server, array $allowed): array
    {
        $curated = $server->hypervTemplateVms();
        $sanitized = self::sanitizeAllowed($allowed);
        if ($sanitized === []) {
            return $curated;
        }
        $allowedSet = array_flip($sanitized);

        $out = [];
        foreach ($curated as $name) {
            if (isset($allowedSet[$name])) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * Effective options for a product on a given server.
     *
     * @param  list<string>  $allowed
     * @return list<array{name: string, label: string}>
     */
    public static function effectiveOptions(Server $server, array $allowed): array
    {
        $names = self::effectiveNames($server, $allowed);
        if ($names === []) {
            return [];
        }
        $set = array_flip($names);
        $out = [];
        foreach ($server->hypervTemplateOptions() as $opt) {
            if (isset($set[$opt['name']])) {
                $out[] = $opt;
            }
        }

        return $out;
    }

    /**
     * Sanitize allowed_templates list: trim, drop blanks, dedupe case-exact, cap 50, each max 64.
     *
     * @param  mixed  $raw
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
            $name = trim((string) $item);
            if ($name === '') {
                continue;
            }
            $name = mb_substr($name, 0, 64);
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $out[] = $name;
            if (count($out) >= 50) {
                break;
            }
        }

        return $out;
    }

    /**
     * Union names helper.
     *
     * @return list<string>
     */
    public static function unionNames(): array
    {
        return array_column(self::unionOptions(), 'name');
    }
}
