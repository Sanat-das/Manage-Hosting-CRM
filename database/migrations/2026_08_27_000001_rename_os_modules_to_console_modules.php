<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rename the two remote-access modules after what they do rather than the OS
 * they happen to target:
 *
 *   linux-vps      -> ssh-console   (Modules\LinuxVps      -> Modules\SshConsole)
 *   windows-server -> rdp-console   (Modules\WindowsServer -> Modules\RdpConsole)
 *
 * The folders, namespaces, tables and module migration file names all moved on
 * disk, so an already-installed database has to follow:
 *
 *  - `modules` rows are keyed by slug. A new slug would leave the old row
 *    orphaned and reconcile() would insert a second, "installed" row — losing
 *    the active status, the stored config and every product_modules link.
 *  - `module_migrations` rows are keyed by the migration file's basename, so a
 *    renamed file counts as "not yet run" and would re-create a table that
 *    already exists.
 *
 * Every step is guarded, so this is safe on a fresh database (nothing to
 * rename) and on a database where only one of the two modules was installed.
 */
return new class extends Migration
{
    /** @var array<string, string> old table => new table */
    private const TABLES = [
        'linux_vps_ssh_configs' => 'ssh_console_configs',
        'linux_vps_ssh_sessions' => 'ssh_console_sessions',
        'windows_server_rdp_configs' => 'rdp_console_configs',
    ];

    /** @var array<string, string> old module migration basename => new basename */
    private const MODULE_MIGRATIONS = [
        '2026_08_22_000002_create_linux_vps_ssh_configs_table.php' => '2026_08_22_000002_create_ssh_console_configs_table.php',
        '2026_08_22_000003_create_linux_vps_ssh_sessions_table.php' => '2026_08_22_000003_create_ssh_console_sessions_table.php',
        '2026_08_21_000001_create_windows_server_rdp_configs_table.php' => '2026_08_21_000001_create_rdp_console_configs_table.php',
    ];

    /** @var array<string, array{slug: string, name: string, provider: string}> old slug => new registry values */
    private const MODULES = [
        'linux-vps' => [
            'slug' => 'ssh-console',
            'name' => 'SSH Console',
            'provider' => 'Modules\SshConsole\SshConsole',
        ],
        'windows-server' => [
            'slug' => 'rdp-console',
            'name' => 'RDP Console',
            'provider' => 'Modules\RdpConsole\RdpConsole',
        ],
    ];

    public function up(): void
    {
        $this->renameTables(self::TABLES);
        $this->renameModuleMigrations(self::MODULE_MIGRATIONS);

        foreach (self::MODULES as $oldSlug => $new) {
            $this->renameModule($oldSlug, $new['slug'], $new['name'], $new['provider']);
        }
    }

    public function down(): void
    {
        $this->renameTables(array_flip(self::TABLES));
        $this->renameModuleMigrations(array_flip(self::MODULE_MIGRATIONS));

        $this->renameModule('ssh-console', 'linux-vps', 'Linux VPS (SSH)', 'Modules\LinuxVps\LinuxVps');
        $this->renameModule('rdp-console', 'windows-server', 'Windows Server (RDP)', 'Modules\WindowsServer\WindowsServer');
    }

    /**
     * @param  array<string, string>  $map  from => to
     */
    private function renameTables(array $map): void
    {
        foreach ($map as $from => $to) {
            if (Schema::hasTable($from) && ! Schema::hasTable($to)) {
                Schema::rename($from, $to);
            }
        }
    }

    /**
     * @param  array<string, string>  $map  from => to
     */
    private function renameModuleMigrations(array $map): void
    {
        if (! Schema::hasTable('module_migrations')) {
            return;
        }

        foreach ($map as $from => $to) {
            DB::table('module_migrations')->where('migration', $from)->update(['migration' => $to]);
        }
    }

    /**
     * Repoint the registry row in place so status, config and product links
     * survive the rename. The manifest column is refreshed from the module's
     * module.json when the folder is present; otherwise the stored copy is
     * patched key by key so it never disagrees with the row around it.
     */
    private function renameModule(string $oldSlug, string $newSlug, string $name, string $provider): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        $row = DB::table('modules')->where('slug', $oldSlug)->first();

        if ($row === null || DB::table('modules')->where('slug', $newSlug)->exists()) {
            return;
        }

        DB::table('modules')->where('id', $row->id)->update([
            'slug' => $newSlug,
            'name' => $name,
            'provider' => $provider,
            'manifest' => json_encode($this->manifestFor($newSlug, $row->manifest ?? null, $name, $provider)),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestFor(string $slug, ?string $stored, string $name, string $provider): array
    {
        $file = config('modules.path').'/'.$slug.'/module.json';

        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $manifest = is_string($stored) ? json_decode($stored, true) : null;
        $manifest = is_array($manifest) ? $manifest : [];

        return array_merge($manifest, [
            'slug' => $slug,
            'name' => $name,
            'provider' => $provider,
            'namespace' => substr($provider, 0, (int) strrpos($provider, '\\') + 1),
        ]);
    }
};
