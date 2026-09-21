<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Contracts\Integrations\TestableServerModule;
use App\Models\Server;
use App\Services\Modules\ModuleManager;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class IntegrationRegistry
{
    public function __construct(private readonly ModuleManager $modules)
    {
    }

    /**
     * @return list<string>
     */
    public function slugs(): array
    {
        return array_keys((array) config('integrations.builtins', []));
    }

    public function has(string $slug): bool
    {
        $builtins = (array) config('integrations.builtins', []);

        return array_key_exists($slug, $builtins);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function entry(string $slug): ?array
    {
        $builtins = (array) config('integrations.builtins', []);

        return $builtins[$slug] ?? null;
    }

    public function instanceFor(string $slug): ?object
    {
        $entry = $this->entry($slug);

        if ($entry === null) {
            return null;
        }

        $class = $entry['class'] ?? null;

        if (! is_string($class) || $class === '') {
            return null;
        }

        try {
            return app($class);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{fields: array<int, array<string, mixed>>}
     */
    public function configSchemaFor(string $slug): array
    {
        // Builtin first
        if ($this->has($slug)) {
            try {
                $instance = $this->instanceFor($slug);

                if ($instance !== null && method_exists($instance, 'configSchema')) {
                    $schema = $instance->configSchema();

                    if (is_array($schema) && isset($schema['fields']) && is_array($schema['fields'])) {
                        return $schema;
                    }
                }
            } catch (Throwable) {
                // degrade
            }

            return ['fields' => []];
        }

        // Plugin slug — delegate to ModuleManager
        try {
            $module = $this->modules->find($slug);

            if ($module === null) {
                return ['fields' => []];
            }

            $instance = $this->modules->resolve($module);

            if ($instance === null) {
                return ['fields' => []];
            }

            $schema = $instance->configSchema();

            if (is_array($schema) && isset($schema['fields']) && is_array($schema['fields'])) {
                return $schema;
            }
        } catch (Throwable) {
            return ['fields' => []];
        }

        return ['fields' => []];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function encryptConfigFor(string $slug, array $config): array
    {
        if ($this->has($slug)) {
            $fields = $this->configSchemaFor($slug)['fields'] ?? [];

            return $this->encryptWithFields($fields, $config);
        }

        // Plugin delegation: use ModuleManager encryptConfig if Module exists
        try {
            $module = $this->modules->find($slug);

            if ($module !== null) {
                return $this->modules->encryptConfig($module, $config);
            }
        } catch (Throwable) {
            // degrade
        }

        // Unknown slug: best-effort walk builtin-like? No schema -> passthrough
        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function decryptConfigFor(string $slug, array $config): array
    {
        if ($this->has($slug)) {
            $fields = $this->configSchemaFor($slug)['fields'] ?? [];

            return $this->decryptWithFields($fields, $config);
        }

        try {
            $module = $this->modules->find($slug);

            if ($module !== null) {
                return $this->modules->decryptConfig($module, $config);
            }
        } catch (Throwable) {
            // degrade
        }

        return $config;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function encryptWithFields(array $fields, array $config): array
    {
        foreach ($fields as $field) {
            $key = $field['key'] ?? null;

            if (! is_string($key) || ! ($field['encrypted'] ?? false)) {
                continue;
            }

            $value = $config[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if (is_string($value) && str_starts_with($value, 'eyJ')) {
                continue;
            }

            $config[$key] = Crypt::encryptString((string) $value);
        }

        return $config;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function decryptWithFields(array $fields, array $config): array
    {
        foreach ($fields as $field) {
            $key = $field['key'] ?? null;

            if (! is_string($key) || ! ($field['encrypted'] ?? false)) {
                continue;
            }

            $value = $config[$key] ?? null;

            if ($value === null || $value === '' || ! is_string($value) || ! str_starts_with($value, 'eyJ')) {
                continue;
            }

            try {
                $config[$key] = Crypt::decryptString($value);
            } catch (Throwable) {
                // keep raw
            }
        }

        return $config;
    }

    /**
     * @return array<int, array{value: string, label: string, group: string, description: string, slug: string}>
     */
    public function serverTypeOptions(): array
    {
        $options = [];

        // Builtins implementing TestableServerModule
        foreach ($this->slugs() as $slug) {
            try {
                $instance = $this->instanceFor($slug);

                if (! $instance instanceof TestableServerModule) {
                    continue;
                }

                $entry = $this->entry($slug);
                $group = (string) ($entry['group'] ?? 'panel');

                if (! in_array($group, ['panel', 'virtualization', 'compute'], true)) {
                    $group = 'panel';
                }

                $options[] = [
                    'value' => $slug,
                    'label' => (string) ($entry['name'] ?? $slug),
                    'group' => $group,
                    'description' => (string) ($entry['description'] ?? ''),
                    'slug' => $slug,
                ];
            } catch (Throwable) {
                continue;
            }
        }

        // Merge plugin server modules, dedupe by slug
        try {
            $pluginOptions = $this->modules->serverTypeOptions();

            $seen = array_flip(array_column($options, 'slug'));

            foreach ($pluginOptions as $opt) {
                $slug = (string) ($opt['slug'] ?? $opt['value'] ?? '');

                if ($slug === '' || isset($seen[$slug])) {
                    continue;
                }

                $group = (string) ($opt['group'] ?? 'panel');

                if (! in_array($group, ['panel', 'virtualization', 'compute'], true)) {
                    $group = 'panel';
                }

                $opt['group'] = $group;
                $options[] = $opt;
                $seen[$slug] = true;
            }
        } catch (Throwable) {
            // degrade
        }

        return $options;
    }

    public function resolveForServer(Server $server): ?TestableServerModule
    {
        // Try builtin by server_type first
        try {
            $slug = trim((string) ($server->server_type ?? ''));

            if ($slug !== '' && $this->has($slug)) {
                $instance = $this->instanceFor($slug);

                if ($instance instanceof TestableServerModule) {
                    return $instance;
                }
            }
        } catch (Throwable) {
            // degrade
        }

        // Fallback to ModuleManager
        try {
            return $this->modules->resolveForServer($server);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, array{slug: string, name: string, group: string, builtin: bool}>
     */
    public function linkableModules(): array
    {
        $result = [];

        foreach ($this->slugs() as $slug) {
            $entry = $this->entry($slug);

            $result[] = [
                'slug' => $slug,
                'name' => (string) ($entry['name'] ?? $slug),
                'group' => (string) ($entry['group'] ?? 'panel'),
                'builtin' => true,
            ];
        }

        try {
            $plugins = $this->modules->active();

            foreach ($plugins as $module) {
                $slug = (string) $module->slug;

                // Dedupe against builtins
                if ($this->has($slug)) {
                    continue;
                }

                $manifest = is_array($module->manifest) ? $module->manifest : [];
                $group = (string) ($manifest['group'] ?? 'panel');

                if (! in_array($group, ['panel', 'virtualization', 'compute'], true)) {
                    $group = 'panel';
                }

                $result[] = [
                    'slug' => $slug,
                    'name' => (string) $module->name,
                    'group' => $group,
                    'builtin' => false,
                ];
            }
        } catch (Throwable) {
            // degrade
        }

        return $result;
    }

    public function nameFor(string $slug): string
    {
        $entry = $this->entry($slug);

        if ($entry !== null) {
            return (string) ($entry['name'] ?? $slug);
        }

        try {
            $module = $this->modules->find($slug);

            if ($module !== null && is_string($module->name) && $module->name !== '') {
                return $module->name;
            }
        } catch (Throwable) {
            // degrade
        }

        return $slug;
    }
}
