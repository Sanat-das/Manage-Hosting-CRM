<?php

declare(strict_types=1);

namespace App\Support\Modules;

/**
 * Value object wrapping a parsed module.json.
 *
 * Immutable after construction: the constructor validates every required key
 * and throws ModuleManifestException on any missing or invalid value, so a
 * broken manifest can never reach the rest of the module system.
 */
class ModuleManifest
{
    /**
     * @param  array<string, mixed>  $data  decoded module.json contents
     */
    public function __construct(private readonly array $data)
    {
        $this->validate();
    }

    /**
     * Parse and validate the module.json inside the given directory.
     *
     * @throws ModuleManifestException when the file is missing, unreadable,
     *                                 invalid JSON, or fails validation.
     */
    public static function fromDirectory(string $dir): self
    {
        $file = $dir.'/module.json';

        if (! is_file($file)) {
            throw new ModuleManifestException("Module manifest not found at [{$file}].");
        }

        $json = file_get_contents($file);

        if ($json === false) {
            throw new ModuleManifestException("Unable to read module manifest at [{$file}].");
        }

        try {
            // Strip a UTF-8 BOM — Windows editors commonly add one, and
            // json_decode rejects it as a syntax error.
            $data = json_decode(ltrim($json, "\xEF\xBB\xBF"), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ModuleManifestException(
                "Module manifest at [{$file}] is not valid JSON: {$e->getMessage()}",
                0,
                $e,
            );
        }

        if (! is_array($data)) {
            throw new ModuleManifestException("Module manifest at [{$file}] must decode to an object.");
        }

        return new self($data);
    }

    /**
     * The capability names modules may declare.
     *
     * @return list<string>
     */
    public static function capabilitiesWhitelist(): array
    {
        return ['provisioning', 'hosting-account-info'];
    }

    public function slug(): string
    {
        return $this->data['slug'];
    }

    public function name(): string
    {
        return $this->data['name'];
    }

    public function version(): string
    {
        return $this->data['version'];
    }

    public function namespace(): string
    {
        return $this->data['namespace'];
    }

    public function provider(): string
    {
        return $this->data['provider'];
    }

    public function description(): ?string
    {
        return $this->data['description'] ?? null;
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return $this->data['capabilities'];
    }

    /**
     * @return array{app?: string, php?: string}
     */
    public function requires(): array
    {
        return $this->data['requires'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Whether the module's declared app/php constraints are satisfied by the
     * host application. Uses Composer\Semver when installed, otherwise falls
     * back to a best-effort version_compare with the operator parsed from
     * each constraint string.
     */
    public function compatibleWithApp(): bool
    {
        $requires = $this->requires();

        $appConstraint = $requires['app'] ?? '>=1.0.0';
        $phpConstraint = $requires['php'] ?? '>=8.0';

        if (class_exists(\Composer\Semver\Semver::class)) {
            return \Composer\Semver\Semver::satisfies(app()->version(), $appConstraint)
                && \Composer\Semver\Semver::satisfies(PHP_VERSION, $phpConstraint);
        }

        return $this->satisfiesFallback(app()->version(), $appConstraint)
            && $this->satisfiesFallback(PHP_VERSION, $phpConstraint);
    }

    /**
     * @throws ModuleManifestException on any missing or invalid required key.
     */
    private function validate(): void
    {
        foreach (['slug', 'name', 'version', 'namespace', 'provider', 'capabilities', 'requires'] as $key) {
            if (! array_key_exists($key, $this->data)) {
                throw new ModuleManifestException("Module manifest is missing the required '{$key}' key.");
            }
        }

        if (! is_string($this->data['slug']) || preg_match('/^[a-z0-9\-]+$/', $this->data['slug']) !== 1) {
            throw new ModuleManifestException('Module manifest slug must match ^[a-z0-9-]+$.');
        }

        foreach (['name', 'version', 'namespace', 'provider'] as $key) {
            if (! is_string($this->data[$key]) || $this->data[$key] === '') {
                throw new ModuleManifestException("Module manifest '{$key}' must be a non-empty string.");
            }
        }

        if (! is_array($this->data['capabilities'])) {
            throw new ModuleManifestException("Module manifest 'capabilities' must be an array.");
        }

        foreach ($this->data['capabilities'] as $capability) {
            if (! in_array($capability, self::capabilitiesWhitelist(), true)) {
                throw new ModuleManifestException("Module manifest declares unsupported capability '{$capability}'.");
            }
        }

        if (! is_array($this->data['requires'])) {
            throw new ModuleManifestException("Module manifest 'requires' must be an array.");
        }

        foreach (['app', 'php'] as $key) {
            if (array_key_exists($key, $this->data['requires']) && ! is_string($this->data['requires'][$key])) {
                throw new ModuleManifestException("Module manifest 'requires.{$key}' must be a string.");
            }
        }

        if (array_key_exists('description', $this->data) && ! is_string($this->data['description'])) {
            throw new ModuleManifestException("Module manifest 'description' must be a string.");
        }

        if (array_key_exists('author', $this->data) && ! is_string($this->data['author'])) {
            throw new ModuleManifestException("Module manifest 'author' must be a string.");
        }

        if (array_key_exists('permissions', $this->data) && ! is_array($this->data['permissions'])) {
            throw new ModuleManifestException("Module manifest 'permissions' must be an array.");
        }
    }

    /**
     * Best-effort semver check without composer/semver: split the constraint
     * on whitespace and require every part to hold. '~' and '^' are treated
     * as '>='; a bare version without an operator means exact match.
     */
    private function satisfiesFallback(string $version, string $constraint): bool
    {
        foreach (preg_split('/\s+/', trim($constraint)) ?: [] as $part) {
            if ($part === '' || $part === '*') {
                continue;
            }

            if (preg_match('/^(>=|<=|>|<|==|=|!=|~|\^)?\s*(.+)$/', $part, $matches) !== 1) {
                return false;
            }

            $operator = $matches[1] !== '' ? $matches[1] : '==';

            if ($operator === '~' || $operator === '^') {
                $operator = '>=';
            }

            if (! version_compare($version, $matches[2], $operator)) {
                return false;
            }
        }

        return true;
    }
}