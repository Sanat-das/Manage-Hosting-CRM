<?php

namespace App\Services\Installer;

use App\Models\ProductGroup;
use App\Models\User;
use Database\Seeders\AdminLteRbacSeeder;
use Database\Seeders\InitialDataSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PDO;
use RuntimeException;

/**
 * First-run installer.
 *
 * Orchestrates everything the setup wizard needs: environment pre-flight
 * checks, safe updates to the .env file, database verification, migrations,
 * idempotent seeding and creation of the initial administrator account.
 *
 * Every step is idempotent so a failed or repeated submission never corrupts
 * an already-working installation.
 */
class InstallerService
{
    /**
     * PHP extensions this application requires at runtime.
     */
    private const REQUIRED_EXTENSIONS = [
        'pdo_mysql',
        'mbstring',
        'openssl',
        'curl',
        'fileinfo',
        'gd',
        'intl',
        'zip',
    ];

    /**
     * Storage directories the framework must be able to write to.
     */
    private const WRITABLE_DIRECTORIES = [
        'storage',
        'storage/framework',
        'storage/framework/cache',
        'storage/framework/sessions',
        'storage/framework/views',
        'storage/logs',
        'bootstrap/cache',
    ];

    /**
     * Memoized result of the databaseProvisioned() probe for this request.
     */
    private static ?bool $provisioned = null;

    public function envPath(): string
    {
        return base_path('.env');
    }

    public function envExists(): bool
    {
        return is_file($this->envPath());
    }

    public function envWritable(): bool
    {
        return $this->envExists() && is_writable($this->envPath());
    }

    /**
     * Absolute path to the WordPress-style installation lock file.
     */
    public static function lockPath(): string
    {
        return base_path('install.lock');
    }

    /**
     * Whether the application is considered installed.
     *
     * The presence of install.lock in the project root is the single source
     * of truth. It is checked directly on every request (never through cached
     * config), so deleting the file immediately returns the application to
     * the installer wizard.
     */
    public static function lockExists(): bool
    {
        return is_file(self::lockPath());
    }

    /**
     * Whether the application is installed, for gating purposes.
     *
     * install.lock alone is not a sufficient control. It is gitignored, so any
     * deploy that does not preserve it — a fresh checkout, a restore from
     * backup, a file sync that skips ignored paths — silently reopens the
     * unauthenticated installer on a live application, letting anyone rewrite
     * .env and create an administrator.
     *
     * So the lock is backed by a second, data-derived signal: an already
     * provisioned database. The file is checked first and short-circuits, so
     * the database probe never runs on a normally installed site.
     *
     * Both EnsureAppInstalled and RedirectIfInstalled must consult THIS method
     * rather than lockExists(), or the two disagree when the lock is missing
     * but the database is live and bounce the visitor between / and /install
     * forever.
     */
    public static function isInstalled(): bool
    {
        return self::lockExists() || self::databaseProvisioned();
    }

    /**
     * Whether the configured database already holds an installed application.
     *
     * Deliberately conservative: any failure to prove otherwise returns false
     * so a genuine first run is never blocked. Memoized per request because
     * this sits behind middleware on every web request once the lock is gone.
     */
    public static function databaseProvisioned(): bool
    {
        if (self::$provisioned !== null) {
            return self::$provisioned;
        }

        try {
            $connection = DB::connection();

            // A pre-install .env points at sqlite :memory:, which has no tables
            // and must not be mistaken for an install.
            self::$provisioned = $connection->getSchemaBuilder()->hasTable('users')
                && $connection->table('users')->exists();
        } catch (\Throwable) {
            // No database configured / unreachable / not migrated — treat as a
            // fresh install so the wizard stays reachable.
            self::$provisioned = false;
        }

        return self::$provisioned;
    }

    /**
     * Reset the memoized provisioning probe (tests, and immediately after a
     * successful install so the new state is observed in the same request).
     */
    public static function forgetProvisionedCache(): void
    {
        self::$provisioned = null;
    }

    /**
     * Whether a submitted database host is safe to use.
     *
     * verifyConnection() interpolates the host straight into a PDO DSN, which
     * is a semicolon-delimited key=value list — so an unfiltered host can
     * append its own DSN parameters (unix_socket=... being the interesting
     * one). This endpoint is unauthenticated pre-install, so the host is also
     * an outbound-connection primitive; restricting it to a bare hostname or
     * IP removes both.
     */
    public static function isValidDatabaseHost(string $host): bool
    {
        $host = trim($host);

        if ($host === '' || strlen($host) > 100) {
            return false;
        }

        // Anything that could terminate or extend a DSN parameter.
        if (preg_match('/[;=\s\/\\\\\'"]/', $host) === 1) {
            return false;
        }

        // Bracketed IPv6, e.g. [::1].
        $ip = (str_starts_with($host, '[') && str_ends_with($host, ']'))
            ? substr($host, 1, -1)
            : $host;

        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            return ! self::isLinkLocalIp($ip);
        }

        // Otherwise it must be a plain hostname / FQDN.
        return preg_match(
            '/^(?=.{1,253}$)[A-Za-z0-9]([A-Za-z0-9\-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9\-]{0,61}[A-Za-z0-9])?)*$/',
            $host
        ) === 1;
    }

    /**
     * Link-local addresses: 169.254.0.0/16 and fe80::/10.
     *
     * This is the cloud instance-metadata range. Private LAN ranges
     * (10/8, 172.16/12, 192.168/16) are deliberately NOT blocked — a database
     * server on the local network is the normal deployment for this app, and
     * blocking it would break legitimate installs to stop a weak primitive.
     */
    private static function isLinkLocalIp(string $ip): bool
    {
        return str_starts_with($ip, '169.254.')
            || str_starts_with(strtolower($ip), 'fe80:');
    }

    /**
     * Create the install.lock file, marking the application as installed.
     */
    public static function markInstalled(): void
    {
        file_put_contents(self::lockPath(), 'Installed on '.date(DATE_ATOM).PHP_EOL, LOCK_EX);
    }

    /**
     * Run the non-mutating environment checks shown on the wizard's first page.
     *
     * @return array<int, array{name: string, passed: bool, detail: string}>
     */
    public function preflightChecks(): array
    {
        $checks = [];

        // PHP version (composer.json requires ^8.3).
        $phpOk = PHP_VERSION_ID >= 80300;
        $checks[] = [
            'name' => 'PHP version',
            'passed' => $phpOk,
            'detail' => $phpOk
                ? PHP_VERSION.' (8.3 or newer required)'
                : PHP_VERSION.' (8.3 or newer required)',
        ];

        // Required PHP extensions.
        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $loaded = extension_loaded($extension);
            $checks[] = [
                'name' => 'PHP extension: '.$extension,
                'passed' => $loaded,
                'detail' => $loaded ? 'loaded' : 'not loaded',
            ];
        }

        // .env configuration file.
        if ($this->envWritable()) {
            $envDetail = 'exists and is writable';
        } elseif ($this->envExists()) {
            $envDetail = 'exists but is not writable';
        } else {
            $envDetail = 'missing';
        }
        $checks[] = [
            'name' => 'Configuration file (.env)',
            'passed' => $this->envWritable(),
            'detail' => $envDetail,
        ];

        // Writable storage directories.
        foreach (self::WRITABLE_DIRECTORIES as $directory) {
            $path = base_path($directory);
            $writable = is_dir($path) && is_writable($path);
            $checks[] = [
                'name' => 'Writable directory: '.$directory,
                'passed' => $writable,
                'detail' => $writable ? 'writable' : 'not writable',
            ];
        }

        // Database reachable with the credentials currently in .env.
        $databaseDetail = 'not configured';
        $databaseOk = false;
        try {
            DB::connection()->getPdo();
            $databaseOk = true;
            $databaseDetail = sprintf(
                '%s @ %s:%s',
                config('database.connections.mysql.database'),
                config('database.connections.mysql.host'),
                config('database.connections.mysql.port')
            );
        } catch (\Throwable $e) {
            $databaseDetail = $e->getMessage();
        }
        $checks[] = [
            'name' => 'Database connection',
            'passed' => $databaseOk,
            'detail' => $databaseDetail,
        ];

        return $checks;
    }

    /**
     * Determine whether the hard prerequisites (everything except the
     * database, which is re-tested when the form is submitted) passed.
     *
     * @param  array<int, array{name: string, passed: bool, detail: string}>  $checks
     */
    public function hardPrerequisitesPassed(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['name'] === 'Database connection') {
                continue;
            }
            if (! $check['passed']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Update (or append) a single key in the .env file.
     */
    public function setEnvValue(string $key, string $value): void
    {
        $path = $this->envPath();
        $line = $key.'='.$this->quoteEnvValue($value);
        $content = is_file($path) ? (string) file_get_contents($path) : '';
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $content)) {
            $content = (string) preg_replace($pattern, $line, $content);
        } else {
            $content = rtrim($content, "\r\n").PHP_EOL.$line.PHP_EOL;
        }

        file_put_contents($path, $content);
    }

    /**
     * Run the installation using validated form input.
     *
     * @param  array<string, mixed>  $input
     */
    public function run(array $input): void
    {
        // 0. Refuse to re-install over a live application. The middleware
        //    already gates the route, but this is the destructive step — it
        //    rewrites .env (including DB_*) and creates an administrator — so
        //    it carries its own check rather than trusting the caller.
        if (self::isInstalled()) {
            throw new RuntimeException(
                'This application is already installed. Refusing to run the installer again. '
                .'If you are intentionally reinstalling, drop the application database first.'
            );
        }

        // 1. Verify the submitted credentials without touching the live
        //    connection (a failed attempt must not break the session).
        $this->verifyConnection(
            (string) $input['db_host'],
            (int) $input['db_port'],
            (string) $input['db_database'],
            (string) $input['db_username'],
            (string) $input['db_password']
        );

        // 2. Persist the chosen configuration to .env and apply it to this
        //    request's connection manager.
        $this->setEnvValue('APP_NAME', (string) $input['app_name']);

        // Harden the environment the moment the app becomes a real install.
        // bootstrap/app.php seeds .env from .env.example, which ships
        // APP_ENV=local + APP_DEBUG=true for developer convenience; left in
        // place those turn every unhandled exception into a stack trace
        // exposing DB_PASSWORD/APP_KEY, and they relax framework and package
        // guards that key off the environment name.
        $this->setEnvValue('APP_ENV', 'production');
        $this->setEnvValue('APP_DEBUG', 'false');

        // .env.example ships SESSION_SECURE_COOKIE=false so the wizard itself
        // is usable over plain http — a Secure cookie is discarded by the
        // browser there, and without a session the installer's own CSRF token
        // can never round-trip (every submit 419s). Now that the scheme the
        // admin actually reached this form over is known, put the flag back to
        // true for an https install. A TLS-terminating proxy that this app is
        // not configured to trust looks like http from here, so that case
        // still needs the flag set by hand afterwards.
        $this->setEnvValue(
            'SESSION_SECURE_COOKIE',
            request()->isSecure() ? 'true' : 'false'
        );

        $this->setEnvValue('DB_CONNECTION', 'mysql');
        $this->setEnvValue('DB_HOST', (string) $input['db_host']);
        $this->setEnvValue('DB_PORT', (string) $input['db_port']);
        $this->setEnvValue('DB_DATABASE', (string) $input['db_database']);
        $this->setEnvValue('DB_USERNAME', (string) $input['db_username']);
        $this->setEnvValue('DB_PASSWORD', (string) $input['db_password']);
        Artisan::call('config:clear');

        $this->applyDatabaseConfig($input);
        config(['database.default' => 'mysql']);
        DB::setDefaultConnection('mysql');
        DB::purge('mysql');
        DB::reconnect('mysql');

        // 3. Migrations (idempotent: only pending migrations run).
        Artisan::call('migrate', ['--force' => true]);

        // Switch session and cache to the database driver now that the tables exist.
        $this->setEnvValue('SESSION_DRIVER', 'database');
        $this->setEnvValue('CACHE_STORE', 'database');

        // 4. RBAC roles and permissions (idempotent, firstOrCreate based).
        Artisan::call('db:seed', [
            '--class' => AdminLteRbacSeeder::class,
            '--force' => true,
        ]);

        // 5. Starter data only on a genuinely fresh database. product_groups
        //    is the first table InitialDataSeeder populates, so an empty one
        //    means no data has ever been seeded.
        $fresh = ProductGroup::count() === 0;
        if ($fresh) {
            Artisan::call('db:seed', [
                '--class' => InitialDataSeeder::class,
                '--force' => true,
            ]);
        }

        // 6. Create (or update) the administrator account the user chose.
        $admin = User::firstOrCreate(
            ['email' => $input['email']],
            [
                'password_hash' => Hash::make($input['password']),
                'role' => 'admin',
                'status' => 'active',
                'first_name' => $input['first_name'],
                'last_name' => $input['last_name'],
            ]
        );

        if (! $admin->wasRecentlyCreated) {
            $admin->update([
                'password_hash' => Hash::make($input['password']),
                'first_name' => $input['first_name'],
                'last_name' => $input['last_name'],
            ]);
        }

        $admin->forceFill(['role' => 'admin', 'status' => 'active'])->save();
        $admin->assignRole('admin');

        // On a fresh install InitialDataSeeder creates the well-known default
        // administrator; keep only the account the user configured.
        if ($fresh && strtolower((string) $admin->email) !== 'admin@localhost.com') {
            User::where('email', 'admin@localhost.com')
                ->where('id', '!=', $admin->id)
                ->delete();
        }

        // 7. Mark the application as installed by writing install.lock.
        //    Its presence is what gates the installer (like WordPress) —
        //    deleting the file returns the app to the setup wizard and
        //    allows a clean reinstall. Refresh config caches so the next
        //    request boots as a fully installed application.
        self::markInstalled();
        Artisan::call('config:clear');
    }

    /**
     * Test database credentials with a raw PDO connection so the framework's
     * connection manager and the session are never left in a broken state.
     *
     * If the database does not exist it is created automatically (utf8mb4 /
     * utf8mb4_unicode_ci), so a fresh server needs zero manual SQL.
     *
     * @throws RuntimeException When the server is unreachable, the credentials
     *                          are rejected, or the database does not exist
     *                          and could not be created (e.g. no CREATE
     *                          privilege).
     */
    private function verifyConnection(
        string $host,
        int $port,
        string $database,
        string $username,
        string $password
    ): void {
        // Defence in depth: run() is public and takes raw input, so the host is
        // re-checked here rather than trusting the controller's validation to
        // be the only caller. Without this the interpolation below would accept
        // DSN parameter injection.
        if (! self::isValidDatabaseHost($host)) {
            throw new RuntimeException('The database host must be a plain hostname or IP address.');
        }

        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";

        try {
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (\PDOException $e) {
            throw new RuntimeException('Could not connect to the database server: '.$e->getMessage());
        }

        $statement = $pdo->query(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '.$pdo->quote($database)
        );
        $exists = $statement !== false ? $statement->fetchColumn() : false;

        if ($exists === false) {
            // WordPress-style: create the missing database automatically so a
            // blank server installs without manual SQL. Backticks are stripped
            // from the identifier so it can never break out of the statement.
            $identifier = str_replace('`', '', $database);

            try {
                $pdo->exec(
                    'CREATE DATABASE `'.$identifier.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
                );
            } catch (\PDOException $e) {
                throw new RuntimeException(
                    'The database "'.$database.'" does not exist and could not be created automatically ('
                    .$e->getMessage().'). Create it manually first, e.g.: CREATE DATABASE `'.$database
                    .'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
                );
            }
        }
    }

    /**
     * Point the current request's connection manager at the submitted
     * credentials (only called after verifyConnection succeeded).
     *
     * @param  array<string, mixed>  $input
     */
    private function applyDatabaseConfig(array $input): void
    {
        config([
            'database.connections.mysql.host' => $input['db_host'],
            'database.connections.mysql.port' => (int) $input['db_port'],
            'database.connections.mysql.database' => $input['db_database'],
            'database.connections.mysql.username' => $input['db_username'],
            'database.connections.mysql.password' => (string) $input['db_password'],
        ]);
    }

    private function quoteEnvValue(string $value): string
    {
        $value = str_replace('#', '\#', $value);

        if (preg_match('/[\s"\'\\\\]/', $value)) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\"'], $value).'"';
        }

        return $value;
    }
}
