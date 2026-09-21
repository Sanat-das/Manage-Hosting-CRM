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
}
