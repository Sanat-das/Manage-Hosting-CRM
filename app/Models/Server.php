<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'ip_address', 'server_type', 'panel_type', 'api_url', 'api_key', 'api_username', 'api_password_encrypted', 'max_accounts', 'status', 'connection_status', 'last_checked_at', 'connection_meta', 'connection_error'])]
class Server extends Model
{
    protected function casts(): array
    {
        return [
            'max_accounts' => 'integer',
            'connection_meta' => 'array',
            'last_checked_at' => 'datetime',
            'api_password_encrypted' => 'encrypted',
        ];
    }

    public function hostingAccounts(): HasMany
    {
        return $this->hasMany(HostingAccount::class);
    }

    public function groupMembers(): HasMany
    {
        return $this->hasMany(ServerGroupMember::class);
    }

    protected function displayType(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $type = (string) ($this->server_type ?? '');

                if ($type !== '') {
                    try {
                        $name = app(\App\Services\Integrations\IntegrationRegistry::class)->nameFor($type);
                        // nameFor returns slug when unknown; fallback to title-cased form only then.
                        if ($name !== $type) {
                            return $name;
                        }
                        // For builtins where name equals slug? still use registry result if it was custom.
                        // If registry returned same slug, check entry existence to decide.
                        $registry = app(\App\Services\Integrations\IntegrationRegistry::class);
                        if ($registry->has($type) && $registry->entry($type) !== null) {
                            return $name;
                        }
                    } catch (\Throwable) {
                        // degrade to fallback
                    }
                }

                return Str::title(str_replace(['_', '-'], ' ', $type));
            },
        );
    }

    /**
     * Backward compatibility: old code and tests still use `panel_type`.
     * Reads and writes proxy to `server_type` so the dropped column never
     * surfaces as an error and legacy factories keep working.
     */
    protected function panelType(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value, array $attributes): ?string => $attributes['server_type'] ?? $value,
            set: fn (?string $value): array => $value !== null ? ['server_type' => $value] : [],
        );
    }

    /**
     * Curated Hyper-V provisioning templates allow-list.
     *
     * - If the `template_vms` key EXISTS (even as empty array) return its sanitized list.
     * - If the key is ABSENT but legacy `template_vm` is set, return [that] (legacy fallback).
     * - Otherwise return [].
     *
     * Sanitization: trimmed, blanks dropped, deduped case-exact preserve-order, capped 50, each truncated to 64.
     *
     * @return list<string>
     */
    public function hypervTemplateVms(): array
    {
        $meta = is_array($this->connection_meta) ? $this->connection_meta : [];

        if (array_key_exists('template_vms', $meta)) {
            return self::sanitizeTemplateVms($meta['template_vms']);
        }

        if (array_key_exists('template_vm', $meta) && is_string($meta['template_vm'])) {
            $legacy = trim($meta['template_vm']);
            if ($legacy !== '') {
                $legacy = mb_substr($legacy, 0, 64);
                return [$legacy];
            }
        }
        if (array_key_exists('template_vm', $meta) && ! is_string($meta['template_vm']) && $meta['template_vm'] !== null && $meta['template_vm'] !== '') {
            $legacy = trim((string) $meta['template_vm']);
            if ($legacy !== '') {
                $legacy = mb_substr($legacy, 0, 64);
                return [$legacy];
            }
        }

        return [];
    }

    /**
     * Default Hyper-V template: trimmed `template_vm` only when it is in hypervTemplateVms(), else null.
     */
    public function hypervDefaultTemplate(): ?string
    {
        $meta = is_array($this->connection_meta) ? $this->connection_meta : [];
        if (! array_key_exists('template_vm', $meta)) {
            return null;
        }
        $raw = $meta['template_vm'];
        if (! is_string($raw) && $raw !== null) {
            $raw = (string) $raw;
        }
        if (! is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        $trimmed = mb_substr($trimmed, 0, 64);
        $list = $this->hypervTemplateVms();
        return in_array($trimmed, $list, true) ? $trimmed : null;
    }

    /**
     * Sanitize a raw template_vms value into the canonical list.
     *
     * @param  mixed  $raw
     * @return list<string>
     */
    public static function sanitizeTemplateVms(mixed $raw): array
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
     * Friendly label map: VM name → display label (trimmed, max 80, non-empty).
     * Only keys that are inside the curated list survive; stale keys are pruned.
     *
     * @return array<string, string>
     */
    public function hypervTemplateLabels(): array
    {
        $meta = is_array($this->connection_meta) ? $this->connection_meta : [];
        $raw = $meta['template_labels'] ?? null;
        $curated = $this->hypervTemplateVms();

        return self::sanitizeTemplateLabels($raw, $curated);
    }

    /**
     * Return the display label for a VM name, or the VM name itself when no label exists.
     */
    public function hypervTemplateLabel(string $vmName): string
    {
        $labels = $this->hypervTemplateLabels();
        $key = trim($vmName);
        if ($key !== '' && array_key_exists($key, $labels)) {
            return $labels[$key];
        }
        if (array_key_exists($vmName, $labels)) {
            return $labels[$vmName];
        }

        return $vmName;
    }

    /**
     * Curated order + label fallback.
     *
     * @return list<array{name: string, label: string}>
     */
    public function hypervTemplateOptions(): array
    {
        $vms = $this->hypervTemplateVms();
        $labels = $this->hypervTemplateLabels();
        $out = [];
        foreach ($vms as $name) {
            $out[] = ['name' => $name, 'label' => $labels[$name] ?? $name];
        }

        return $out;
    }

    /**
     * Sanitize a raw template_labels value.
     *
     * @param  mixed  $raw   associative array VM name => label
     * @param  list<string>  $curatedList  when provided, only keys inside this list are kept
     * @return array<string, string>
     */
    public static function sanitizeTemplateLabels(mixed $raw, array $curatedList = []): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $filterByCurated = func_num_args() >= 2;
        $allowed = [];
        if ($filterByCurated) {
            foreach ($curatedList as $name) {
                $k = trim((string) $name);
                if ($k !== '') {
                    $allowed[$k] = true;
                }
            }
        }
        $out = [];
        foreach ($raw as $k => $v) {
            if (! is_string($k) && ! is_numeric($k)) {
                continue;
            }
            $key = trim((string) $k);
            if ($key === '') {
                continue;
            }
            if ($filterByCurated && ! isset($allowed[$key])) {
                continue;
            }
            if (! is_string($v) && ! is_numeric($v)) {
                continue;
            }
            $label = trim((string) $v);
            if ($label === '') {
                continue;
            }
            $label = mb_substr($label, 0, 80);
            $label = trim($label);
            if ($label === '') {
                continue;
            }
            // Preserve curated order? Keys deduped last-wins for labels (no order needed)
            $out[$key] = $label;
        }

        return $out;
    }
}
