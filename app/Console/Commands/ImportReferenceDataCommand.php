<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Import the reference project's demo data (seed.sql + seed_devices.sql)
 * into the Laravel application database.
 *
 * The reference MySQL server is no longer running, so the self-contained seed
 * files copied into database/seed-data/ are the canonical data source.
 *
 * What this command does:
 *  1. Parses the seed INSERT statements into structured rows.
 *  2. Remaps any seed primary keys that collide with rows already present in
 *     the target database (the target ships a few seeded users/customers).
 *  3. Rewrites foreign-key columns so they point at the remapped parents.
 *  4. Fills Laravel-added NOT NULL columns with sensible defaults.
 *  5. Maps reference user roles onto the AdminLTE RBAC pivot table.
 */
class ImportReferenceDataCommand extends Command
{
    protected $signature = 'app:import-reference-data {--force : Re-run even if already imported}';

    protected $description = 'Import reference project demo data (seed.sql + devices) with ID remapping';

    /**
     * Tables in dependency order (parents before children so FK constraints hold).
     * Each entry lists the FK columns of that table and the parent table they
     * reference; 'remap' marks tables whose own primary keys may collide with
     * existing target rows and therefore need their IDs re-mapped.
     */
    private const TABLES = [
        'users' => ['fk' => [], 'remap' => true],
        'customers' => ['fk' => ['user_id' => 'users'], 'remap' => true],
        'products' => ['fk' => [], 'remap' => false],
        'servers' => ['fk' => [], 'remap' => false],
        'orders' => ['fk' => ['customer_id' => 'customers', 'product_id' => 'products'], 'remap' => false],
        'invoices' => ['fk' => ['customer_id' => 'customers', 'order_id' => 'orders'], 'remap' => false],
        'invoice_items' => ['fk' => ['invoice_id' => 'invoices'], 'remap' => false],
        'payments' => ['fk' => ['invoice_id' => 'invoices'], 'remap' => false],
        'credits' => ['fk' => ['customer_id' => 'customers'], 'remap' => false],
        'hosting_accounts' => ['fk' => ['customer_id' => 'customers', 'product_id' => 'products', 'server_id' => 'servers', 'order_id' => 'orders'], 'remap' => false],
        'domains' => ['fk' => ['customer_id' => 'customers', 'order_id' => 'orders'], 'remap' => false],
        'tickets' => ['fk' => ['customer_id' => 'customers', 'assigned_to' => 'users'], 'remap' => false],
        'ticket_replies' => ['fk' => ['ticket_id' => 'tickets', 'user_id' => 'users'], 'remap' => false],
        'knowledge_base' => ['fk' => [], 'remap' => false],
        'chat_sessions' => ['fk' => ['customer_id' => 'customers', 'operator_id' => 'users'], 'remap' => false],
        'chat_messages' => ['fk' => ['session_id' => 'chat_sessions', 'user_id' => 'users'], 'remap' => false],
        'audit_log' => ['fk' => ['user_id' => 'users'], 'remap' => false],
        'email_templates' => ['fk' => [], 'remap' => false],
        'automation_log' => ['fk' => [], 'remap' => false],
    ];

    /**
     * Columns that existed in the reference schema but were removed from the
     * target (products.type, the 10 quota_* columns and devices'
     * lifecycle_state). Seed rows still carry them; they are stripped before
     * insert so the query builder never writes a column the migrated schema
     * no longer has.
     *
     * @var array<string, list<string>>
     */
    private const REMOVED_COLUMNS = [
        'products' => ['type', 'quota_disk', 'quota_bandwidth', 'quota_email', 'quota_database', 'quota_cpu_cores', 'quota_cpu_speed', 'quota_ram', 'quota_ips', 'quota_ftp_accounts', 'quota_subdomains'],
        'devices' => ['lifecycle_state'],
    ];

    /**
     * Category for each (manufacturer, model) pair the reference seed ships.
     * Derived from the historical device type values before the legacy type
     * column was dropped (server→server, switch→switch, pdu→pdu); any pair
     * not listed falls back to other_hardware.
     *
     * @var array<string, string>
     */
    private const DEVICE_TYPE_CATEGORIES = [
        'Dell|PowerEdge R750' => 'server',
        'HP|ProLiant DL380 Gen10' => 'server',
        'Arista|7280R3 10GbE Switch' => 'switch',
        'APC|Switched Rack PDU AP8981' => 'pdu',
    ];

    /** @var array<string, array<int, int>> old-id => new-id maps per remapped table */
    private array $idMaps = [];

    /** @var array<int, string> seed product id => billing cycle (for order defaults) */
    private array $productCycles = [];

    /** @var array<string, list<int>> table => primary keys actually inserted (for idempotent --force re-runs) */
    private array $insertedIds = [];

    public function handle(): int
    {
        if (DB::table('settings')->where('setting_key', 'reference_data_imported')->exists() && ! $this->option('force')) {
            $this->warn('Reference data already imported. Re-run with --force to import again.');

            return self::FAILURE;
        }

        $seedPath = database_path('seed-data/reference-seed.sql');
        $invPath = database_path('seed-data/reference-devices.sql');

        if (! is_file($seedPath) || ! is_file($invPath)) {
            $this->error('Seed files missing from database/seed-data/.');

            return self::FAILURE;
        }

        $data = array_merge($this->parseSeedFile($seedPath), $this->parseSeedFile($invPath));

        try {
            DB::transaction(function () use ($data): void {
                DB::statement('SET FOREIGN_KEY_CHECKS=0');

                try {
                    if ($this->option('force')) {
                        $this->deletePreviouslyImported();
                    }

                    foreach (self::TABLES as $table => $config) {
                        if (! isset($data[$table])) {
                            $this->warn("  - {$table}: not present in seed files, skipped");

                            continue;
                        }

                        $count = $this->importTable($table, $config, $data[$table]);
                        $this->line("  ✓ {$table}: {$count} rows");
                    }

                    $invCount = $this->importDevices($data['devices'] ?? ['columns' => [], 'rows' => []]);
                    $this->line("  ✓ devices: {$invCount} rows");

                    $this->assignStaffRoles($data['users'] ?? ['columns' => [], 'rows' => []]);

                    DB::table('settings')->updateOrInsert(
                        ['setting_key' => 'reference_data_imported'],
                        [
                            'setting_value' => now()->toDateTimeString(),
                            'group' => 'system',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    );

                    DB::table('settings')->updateOrInsert(
                        ['setting_key' => 'reference_import_ids'],
                        [
                            'setting_value' => json_encode($this->insertedIds),
                            'group' => 'system',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    );
                } finally {
                    DB::statement('SET FOREIGN_KEY_CHECKS=1');
                }
            });
        } catch (\Throwable $e) {
            $this->error('Import failed, transaction rolled back: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Reference data import complete.');

        return self::SUCCESS;
    }

    /**
     * Import one table: cast values, remap its own PK (if needed), translate
     * FK columns through the parent id-maps, and apply column defaults.
     */
    private function importTable(string $table, array $config, array $source): int
    {
        [$columns, $rows] = [$source['columns'], $source['rows']];

        if (($config['remap'] ?? false) && in_array('id', $columns, true)) {
            $this->idMaps[$table] = $this->buildIdMap($table, $rows);
        }

        $prepared = [];

        foreach ($rows as $rawRow) {
            $row = array_combine($columns, array_map([$this, 'castValue'], $rawRow));

            foreach (self::REMOVED_COLUMNS[$table] ?? [] as $column) {
                unset($row[$column]);
            }

            foreach ($config['fk'] as $column => $parent) {
                if (array_key_exists($column, $row) && $row[$column] !== null) {
                    $row[$column] = $this->remapId($parent, (int) $row[$column]);
                }
            }

            if (($config['remap'] ?? false) && isset($row['id'])) {
                $row['id'] = $this->remapId($table, (int) $row['id']);
            }

            if ($table === 'products' && isset($row['id'], $row['billing_cycle'])) {
                $this->productCycles[(int) $row['id']] = (string) $row['billing_cycle'];
            }

            $prepared[] = array_merge($row, $this->defaultsFor($table, $row));
        }

        if ($prepared === []) {
            return 0;
        }

        DB::table($table)->insert($prepared);

        if (in_array('id', $columns, true)) {
            $this->insertedIds[$table] = array_map(fn (array $p) => (int) $p['id'], $prepared);
        }

        return count($prepared);
    }

    /**
     * Devices use auto-increment ids (the seed file has no id column),
     * so they are inserted into the empty table and the new ids are remembered
     * for the import record.
     *
     * Every row is linked to a device type: (manufacturer, model) pairs are
     * resolved against existing catalog rows or created on the fly (with the
     * category from DEVICE_TYPE_CATEGORIES), upserting the manufacturer by
     * name. Device types and manufacturers created here are deliberately NOT
     * deleted by deletePreviouslyImported() — a --force re-run reuses them.
     */
    private function importDevices(array $source): int
    {
        if ($source['rows'] === []) {
            return 0;
        }

        $rows = array_map(fn (array $raw) => array_combine(
            $source['columns'],
            array_map([$this, 'castValue'], $raw),
        ), $source['rows']);

        $rows = array_map(function (array $row): array {
            foreach (self::REMOVED_COLUMNS['devices'] ?? [] as $column) {
                unset($row[$column]);
            }

            return $row;
        }, $rows);

        foreach ($rows as &$row) {
            $row['device_type_id'] = $this->resolveDeviceTypeId($row);
        }
        unset($row);

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) DB::table('devices')->insertGetId($row);
        }
        $this->insertedIds['devices'] = $ids;

        return count($rows);
    }

    /**
     * Resolve (creating when absent) the device type for a seed row's
     * (manufacturer, model) pair. Fails closed: a device row without a
     * manufacturer or model cannot be classified and aborts the import.
     *
     * @param  array<string, mixed>  $row
     */
    private function resolveDeviceTypeId(array $row): int
    {
        $manufacturerName = trim((string) ($row['manufacturer'] ?? ''));
        $model = trim((string) ($row['model'] ?? ''));

        if ($manufacturerName === '' || $model === '') {
            throw new \RuntimeException(
                'Device '.($row['asset_tag'] ?? '(unknown tag)').' has no manufacturer/model; cannot resolve a device type.'
            );
        }

        $manufacturer = DB::table('manufacturers')->where('name', $manufacturerName)->first(['id']);

        if ($manufacturer === null) {
            $manufacturerId = (int) DB::table('manufacturers')->insertGetId([
                'name' => $manufacturerName,
                'slug' => $this->uniqueSlug('manufacturers', $manufacturerName),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $manufacturerId = (int) $manufacturer->id;
        }

        $existing = DB::table('device_types')
            ->where('manufacturer_id', $manufacturerId)
            ->where('model', $model)
            ->first(['id']);

        if ($existing !== null) {
            return (int) $existing->id;
        }

        return (int) DB::table('device_types')->insertGetId([
            'manufacturer_id' => $manufacturerId,
            'model' => $model,
            'slug' => $this->uniqueSlug('device_types', $manufacturerName.' '.$model),
            'category' => self::DEVICE_TYPE_CATEGORIES[$manufacturerName.'|'.$model] ?? 'other_hardware',
            'u_height' => 1,
            'is_full_depth' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * A slug derived from $nameBase that is free in $table; suffixed
     * -2, -3, ... when the base is taken.
     */
    private function uniqueSlug(string $table, string $nameBase): string
    {
        $base = Str::slug($nameBase);
        $base = $base === '' ? 'entry' : $base;

        $candidate = $base;
        $suffix = 2;

        while (DB::table($table)->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Reference users carry a `role` string column, mirrored into the pivot.
     *
     * This used to map staff onto "support" because the RBAC set had no "staff"
     * role. It does now, so the mapping is name-for-name and the pivot agrees
     * with users.role — `hasRole()` reads both, and a user matching through one
     * but not the other reads as privileged on one code path only. "support" is
     * kept as a fallback for a target seeded before the staff role existed.
     * Client users keep their role column, which hasRole() checks directly.
     */
    private function assignStaffRoles(array $source): void
    {
        if ($source['rows'] === [] || ! isset($this->idMaps['users'])) {
            return;
        }

        $roleName = 'staff';
        $staffRoleId = DB::table('adminlte_roles')->where('name', $roleName)->value('id');

        if ($staffRoleId === null) {
            $roleName = 'support';
            $staffRoleId = DB::table('adminlte_roles')->where('name', $roleName)->value('id');
        }

        if ($staffRoleId === null) {
            $this->warn('  ! neither "staff" nor "support" found in adminlte_roles; staff RBAC mapping skipped');

            return;
        }

        $columns = $source['columns'];
        $assigned = 0;

        foreach ($source['rows'] as $rawRow) {
            $row = array_combine($columns, array_map([$this, 'castValue'], $rawRow));

            if (($row['role'] ?? '') !== 'staff') {
                continue;
            }

            $userId = $this->remapId('users', (int) $row['id']);
            DB::table('adminlte_role_user')->insertOrIgnore([
                'role_id' => $staffRoleId,
                'user_id' => $userId,
            ]);
            $assigned++;
        }

        $this->line("  ✓ staff → {$roleName} RBAC: {$assigned} users mapped");
    }

    /**
     * Determine, for the seed rows of a table, which ids already exist in the
     * target and assign those rows fresh ids (continuing above the highest id
     * in the union of seed and target ids, so no future collision is possible).
     */
    private function buildIdMap(string $table, array $rows): array
    {
        $seedIds = array_map(fn (array $row) => (int) $row[0], $rows);
        $existing = DB::table($table)->whereIn('id', $seedIds)->pluck('id')->map(fn ($v) => (int) $v)->all();

        $maxSeed = max($seedIds);
        $maxExisting = (int) DB::table($table)->max('id');
        $next = max($maxSeed, $maxExisting) + 1;

        $map = [];

        foreach ($seedIds as $id) {
            $map[$id] = in_array($id, $existing, true) ? $next++ : $id;
        }

        return $map;
    }

    private function remapId(string $table, int $oldId): int
    {
        return $this->idMaps[$table][$oldId] ?? $oldId;
    }

    /**
     * On a --force re-run, delete the rows the previous import inserted (their
     * primary keys were recorded in settings.reference_import_ids) before
     * inserting again. Children are removed before parents so FK constraints
     * stay satisfiable even though FOREIGN_KEY_CHECKS is already off.
     */
    private function deletePreviouslyImported(): void
    {
        $stored = DB::table('settings')->where('setting_key', 'reference_import_ids')->value('setting_value');
        $ids = $stored ? json_decode((string) $stored, true) : [];

        if (! is_array($ids) || $ids === []) {
            $this->warn('  ! no previously imported ids recorded; skipping cleanup');

            return;
        }

        // Remove pivot rows first (they reference users, not the other way).
        if (! empty($ids['users'])) {
            DB::table('adminlte_role_user')->whereIn('user_id', $ids['users'])->delete();
        }

        // Reverse dependency order: children before parents.
        $reverse = array_reverse(array_keys(self::TABLES));
        if (! empty($ids['devices'])) {
            $reverse[] = 'devices';
        }

        foreach ($reverse as $table) {
            if (empty($ids[$table])) {
                continue;
            }

            DB::table($table)->whereIn('id', $ids[$table])->delete();
        }
    }

    /**
     * Defaults for Laravel-added NOT NULL columns the reference seed does not
     * provide. Values are chosen to keep the imported data coherent with the
     * target schema (e.g. GST fields reflect the reference's 18% tax rate).
     */
    private function defaultsFor(string $table, array $row): array
    {
        return match ($table) {
            'products' => [
                'provisioning_module' => 'manual',
                'require_domain' => 1,
                'show_in_order' => 1,
                'show_in_affiliate' => 1,
                'only_admin' => 0,
                'sort_order' => 0,
                'is_bundle' => 0,
                'gst_enabled' => 1,
                'gst_type' => 'standard',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            'orders' => [
                'order_number' => null,
                'billing_cycle' => $this->productCycles[(int) ($row['product_id'] ?? 0)] ?? 'monthly',
                'next_billing_date' => null,
                'next_invoice_date' => null,
                'last_billing_date' => null,
                'updated_at' => now(),
            ],
            'invoices' => [
                'paid_amount' => ($row['status'] ?? '') === 'paid' ? $row['total'] : 0,
                'gst_enabled' => (float) ($row['tax_rate'] ?? 0) > 0 ? 1 : 0,
                'igst_rate' => (float) ($row['tax_rate'] ?? 0) > 0 ? $row['tax_rate'] : null,
                'igst_amount' => (float) ($row['tax'] ?? 0) > 0 ? $row['tax'] : null,
                'reminder_count' => 0,
                'updated_at' => now(),
            ],
            'invoice_items' => [
                'product_id' => null,
                'gst_enabled' => 1,
                'gst_rate' => 18.00,
                'gst_type' => 'standard',
            ],
            'hosting_accounts' => [
                'suspended_reason' => null,
                'suspended_at' => null,
                'updated_at' => now(),
            ],
            'domains' => [
                'type' => 'register',
                'registration_period' => 1,
                'lock_status' => 0,
                'dns_management' => 0,
                'email_forwarding' => 0,
                'id_protection' => 0,
                'updated_at' => now(),
            ],
            'tickets' => [
                'last_reply_at' => null,
                'updated_at' => now(),
            ],
            'knowledge_base' => [
                'updated_at' => now(),
            ],
            'email_templates' => [
                'created_at' => now(),
                'updated_at' => now(),
            ],
            default => [],
        };
    }

    /* ------------------------------------------------------------------ *
     *  Seed-file parsing (INSERT IGNORE INTO `table` (cols) VALUES ...;)
     * ------------------------------------------------------------------ */

    /**
     * Parse a seed file into ['table' => ['columns' => [...], 'rows' => [[...]]]].
     * Handles multi-line INSERT statements, comma/quote-rich string values and
     * MySQL '' escaped quotes.
     */
    private function parseSeedFile(string $path): array
    {
        $sql = file_get_contents($path);
        $result = [];
        $offset = 0;

        while (preg_match('/INSERT\s+IGNORE\s+INTO\s+`(\w+)`\s*\(([^)]*)\)\s*VALUES/si', $sql, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $table = $match[1][0];
            $columns = array_map(fn (string $c) => trim($c, " \t`"), explode(',', $match[2][0]));

            $bodyStart = $match[0][1] + strlen($match[0][0]);
            $bodyEnd = $this->findStatementEnd($sql, $bodyStart);

            $rows = $this->parseTuples(substr($sql, $bodyStart, $bodyEnd - $bodyStart));

            if (isset($result[$table])) {
                // Seed files may split one logical table across multiple
                // INSERT statements (e.g. devices) — accumulate rows.
                if ($result[$table]['columns'] !== $columns) {
                    throw new \RuntimeException("Column mismatch across INSERT statements for `{$table}`.");
                }
                $result[$table]['rows'] = array_merge($result[$table]['rows'], $rows);
            } else {
                $result[$table] = ['columns' => $columns, 'rows' => $rows];
            }

            $offset = $bodyEnd + 1;
        }

        return $result;
    }

    /** Locate the terminating ';' that is not inside a single-quoted string. */
    private function findStatementEnd(string $sql, int $start): int
    {
        $length = strlen($sql);
        $inString = false;

        for ($i = $start; $i < $length; $i++) {
            $char = $sql[$i];

            if ($inString) {
                if ($char === "'") {
                    // MySQL escapes a quote inside a string by doubling it.
                    if ($i + 1 < $length && $sql[$i + 1] === "'") {
                        $i++;

                        continue;
                    }
                    $inString = false;
                }

                continue;
            }

            if ($char === "'") {
                $inString = true;
            } elseif ($char === ';') {
                return $i;
            }
        }

        return $length;
    }

    /**
     * Split the VALUES body into row tuples, then each tuple into fields,
     * respecting single-quoted strings and '' escapes.
     */
    private function parseTuples(string $body): array
    {
        $tuples = [];
        $length = strlen($body);
        $i = 0;

        while ($i < $length) {
            while ($i < $length && $body[$i] !== '(') {
                $i++;
            }
            if ($i >= $length) {
                break;
            }
            $i++; // consume '('

            $fields = [];
            $field = '';
            $inString = false;
            $done = false;

            while ($i < $length && ! $done) {
                $char = $body[$i];

                if ($inString) {
                    if ($char === "'") {
                        if ($i + 1 < $length && $body[$i + 1] === "'") {
                            $field .= "'";
                            $i += 2;

                            continue;
                        }
                        $inString = false;
                    } else {
                        $field .= $char;
                    }
                    $i++;

                    continue;
                }

                if ($char === "'") {
                    $inString = true;
                } elseif ($char === ',') {
                    $fields[] = trim($field);
                    $field = '';
                } elseif ($char === ')') {
                    $fields[] = trim($field);
                    $done = true;
                } else {
                    $field .= $char;
                }
                $i++;
            }

            $tuples[] = $fields;
        }

        return $tuples;
    }

    /** Convert a raw SQL literal to a PHP value (NULL / unquoted string). */
    private function castValue(string $value): mixed
    {
        $value = trim($value);

        if (strcasecmp($value, 'NULL') === 0) {
            return null;
        }

        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            return str_replace("''", "'", substr($value, 1, -1));
        }

        return $value;
    }
}
