<?php

declare(strict_types=1);

namespace Modules\SnmpMonitor\Services;

use FreeDSx\Snmp\Oid;
use FreeDSx\Snmp\SnmpClient;
use FreeDSx\Snmp\Value\AbstractValue;
use Modules\SnmpMonitor\Exceptions\SnmpException;

/**
 * Collects system metrics over SNMP (v2c or v3) using the pure-PHP FreeDSx
 * SNMP client — no php-snmp extension required. One shared collector for
 * every monitored operating system: the per-OS deltas are driven by the
 * module/product config key 'target_os' ('linux' default | 'windows').
 *
 * Fetches RFC 1213 (system MIB), RFC 2790 (HOST-RESOURCES-MIB) and, on
 * Linux, UCD-SNMP-MIB OIDs and normalizes them into a single array consumed
 * by the polling pipeline:
 *
 *   ['hostname' => string, 'os' => string, 'uptime_human' => string,
 *    'cpu_load' => ?float, 'cpu_source' => string (linux only), 'cpu_cores' => ?int,
 *    'memory_total_mb' => ?int, 'memory_used_mb' => ?int,
 *    'disks' => [['label' => string, 'total_gb' => float, 'used_gb' => float], ...]]
 *
 * OS strategies:
 *   - CPU (target_os=linux): hrProcessorLoad is walked first; net-snmp agents
 *     frequently expose no values there, so an empty walk falls back to the
 *     UCD-SNMP-MIB 1-minute load average (laLoad.1). The source of cpu_load
 *     is recorded in 'cpu_source'.
 *   - CPU (target_os=windows): ONLY the plain hrProcessorLoad average is
 *     used (windows agents always populate it); no 'cpu_source' is recorded.
 *   - Disks (target_os=linux): fixed-disk rows whose label starts with
 *     '/dev/loop' or '/snap', or whose total capacity is below 0.5 GB, are
 *     dropped — they are tmpfs / snap loop-mount noise on typical Linux
 *     systems.
 *   - Disks (target_os=windows): every fixed-disk row is kept unfiltered.
 *   - 'os' carries the raw sysDescr line; distro / kernel / arch parsing
 *     happens in the presentation layer.
 *
 * Every failure — unreachable host, timeout, auth/encryption error, protocol
 * error or an empty response — surfaces as a SnmpException with the original
 * library message, never as a raw library exception.
 *
 * The optional constructor argument is a test seam: the container binds a
 * fake/mocked SnmpClient for route and pipeline tests. `new SnmpCollector()`
 * (no arguments) builds a real client per collect() call.
 */
final class SnmpCollector
{
    /** RFC 1213 system MIB. */
    private const OID_SYS_NAME = '1.3.6.1.2.1.1.5.0';

    private const OID_SYS_DESCR = '1.3.6.1.2.1.1.1.0';

    private const OID_SYS_UPTIME = '1.3.6.1.2.1.1.3.0';

    /** RFC 2790 HOST-RESOURCES-MIB. */
    private const OID_HR_PROCESSOR_LOAD = '1.3.6.1.2.1.25.3.3.1.2';

    private const OID_HR_MEMORY_SIZE = '1.3.6.1.2.1.25.2.2.0';

    private const OID_HR_STORAGE_TABLE = '1.3.6.1.2.1.25.2.3.1';

    /** UCD-SNMP-MIB laTable: laLoad.1 = 1-minute load average. */
    private const OID_UCD_LA_LOAD_1MIN = '1.3.6.1.4.1.2021.10.1.3.1';

    /** IF-MIB ifTable (RFC 2863). */
    private const OID_IF_TABLE = '1.3.6.1.2.1.2.2.1';

    /** HOST-RESOURCES-MIB hrSWRunTable (RFC 2790). */
    private const OID_HR_SW_RUN_TABLE = '1.3.6.1.2.1.25.4.2.1';

    /** hrStorageType values (RFC 2790). */
    private const HR_STORAGE_TYPE_PHYSICAL_MEMORY = '1.3.6.1.2.1.25.2.1.2';

    private const HR_STORAGE_TYPE_FIXED_DISK = '1.3.6.1.2.1.25.2.1.4';

    /** Values recorded in the payload's 'cpu_source' key (linux only). */
    private const CPU_SOURCE_HR_PROCESSOR_LOAD = 'hrProcessorLoad';

    private const CPU_SOURCE_UCD_LA_LOAD = 'ucd-laLoad';

    /** Supported $config['target_os'] values; anything else means linux. */
    private const OS_LINUX = 'linux';

    private const OS_WINDOWS = 'windows';

    /** Minimum disk size (GB) worth reporting on linux; below this it is mount noise. */
    private const MIN_DISK_TOTAL_GB = 0.5;

    private const KB = 1024;

    private const GB = 1024 ** 3;

    private ?SnmpClient $client = null;

    /**
     * @param  SnmpClient|null  $client  Optional injected client (test seam).
     *                                   When omitted a client is built per call.
     */
    public function __construct(?SnmpClient $client = null)
    {
        $this->client = $client;
    }

    /**
     * Collect system metrics from an SNMP agent.
     *
     * @param  string  $host  IP address or hostname of the SNMP agent.
     * @param  array  $config  Module config: target_os ('linux' default |
     *                         'windows'), snmp_version (v3 default / v2c),
     *                         snmp_community, snmp_username,
     *                         snmp_auth_protocol, snmp_auth_password,
     *                         snmp_priv_protocol, snmp_priv_password,
     *                         snmp_port, snmp_timeout and the collect_* toggles.
     * @return array{hostname: string, os: string, uptime_human: string, cpu_load?: float, cpu_source?: string, cpu_cores?: int, memory_total_mb?: int, memory_used_mb?: int, disks?: array<int, array{label: string, total_gb: float, used_gb: float}>, interfaces?: array<int, array<string, mixed>>, processes?: array<int, array<string, mixed>>}
     *
     * @throws SnmpException on any SNMP failure or an empty response.
     */
    public function collect(string $host, array $config): array
    {
        $client = $this->client ?? $this->buildClient($host, $config);
        $ownsClient = $this->client === null;
        $targetOs = $this->resolveTargetOs($config);

        try {
            $hostname = $this->fetchString($client, self::OID_SYS_NAME);
            if ($hostname === null || $hostname === '') {
                throw new SnmpException('Empty SNMP response');
            }

            $os = $this->sanitizeString($this->fetchString($client, self::OID_SYS_DESCR) ?? '');
            $uptimeHuman = $this->formatUptime(
                $this->numericValue($this->fetchOid($client, self::OID_SYS_UPTIME)) ?? 0.0
            );

            $collectCpu = (bool) ($config['collect_cpu'] ?? true);
            $collectMemory = (bool) ($config['collect_memory'] ?? true);
            $collectDisks = (bool) ($config['collect_disks'] ?? true);
            $collectNetwork = (bool) ($config['collect_network'] ?? false);
            $collectProcesses = (bool) ($config['collect_processes'] ?? false);

            $payload = [
                'hostname' => $this->sanitizeString($hostname),
                'os' => $os,
                'uptime_human' => $uptimeHuman,
            ];

            if ($collectCpu) {
                if ($targetOs === self::OS_WINDOWS) {
                    $cpu = $this->fetchWindowsCpuLoad($client);
                    if ($cpu !== null) {
                        [$payload['cpu_load'], $payload['cpu_cores']] = $cpu;
                    }
                } else {
                    $cpu = $this->fetchLinuxCpuLoad($client);
                    if ($cpu !== null) {
                        [$payload['cpu_load'], $payload['cpu_source'], $payload['cpu_cores']] = $cpu;
                        // When load came from UCD fallback, cpu_cores is null — don't store a meaningless zero
                        if ($payload['cpu_cores'] === null) {
                            unset($payload['cpu_cores']);
                        }
                    }
                }
            }

            if ($collectMemory) {
                $memoryTotalMb = $this->memoryTotalMb(
                    $this->numericValue($this->fetchOid($client, self::OID_HR_MEMORY_SIZE))
                );
                if ($memoryTotalMb !== null) {
                    $payload['memory_total_mb'] = $memoryTotalMb;
                }
            }

            if ($collectMemory || $collectDisks) {
                [$memoryUsedMb, $disks] = $this->fetchStorage($client, $targetOs === self::OS_LINUX);

                if ($collectMemory && $memoryUsedMb !== null) {
                    $payload['memory_used_mb'] = $memoryUsedMb;
                }

                if ($collectDisks && $disks !== []) {
                    $payload['disks'] = $disks;
                }
            }

            if ($collectNetwork) {
                $interfaces = $this->fetchNetwork($client);
                if ($interfaces !== []) {
                    $payload['interfaces'] = $interfaces;
                }
            }

            if ($collectProcesses) {
                $processes = $this->fetchProcesses($client);
                if ($processes !== []) {
                    $payload['processes'] = $processes;
                }
            }

            return $payload;
        } catch (SnmpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new SnmpException($e->getMessage(), 0, $e);
        } finally {
            if ($ownsClient) {
                $this->closeQuietly($client);
            }
        }
    }

    /**
     * Close the SNMP session if one was opened. Never throws.
     */
    public function __destruct()
    {
        if ($this->client !== null) {
            $this->closeQuietly($this->client);
        }
    }

    /**
     * Normalize the configured OS flag; unknown/missing values mean linux.
     *
     * @param  array<string, mixed>  $config
     */
    private function resolveTargetOs(array $config): string
    {
        return strtolower((string) ($config['target_os'] ?? self::OS_LINUX)) === self::OS_WINDOWS
            ? self::OS_WINDOWS
            : self::OS_LINUX;
    }

    /**
     * Build the FreeDSx client from the module config.
     *
     * @param  array<string, mixed>  $config
     */
    private function buildClient(string $host, array $config): SnmpClient
    {
        $timeout = max(1, (int) ($config['snmp_timeout'] ?? 2));

        $options = [
            'host' => $host,
            'port' => (int) ($config['snmp_port'] ?? 161),
            'timeout_connect' => $timeout,
            'timeout_read' => $timeout,
        ];

        if (($config['snmp_version'] ?? 'v3') === 'v2c') {
            $options['version'] = 2;
            $options['community'] = (string) ($config['snmp_community'] ?? 'public');
        } else {
            $options['version'] = 3;
            $options['user'] = (string) ($config['snmp_username'] ?? '');
            $options['use_auth'] = true;
            $options['auth_mech'] = $this->normalizeAuthMech((string) ($config['snmp_auth_protocol'] ?? 'SHA'));
            $options['auth_pwd'] = (string) ($config['snmp_auth_password'] ?? '');

            $privPassword = (string) ($config['snmp_priv_password'] ?? '');
            if ($privPassword !== '') {
                $options['use_priv'] = true;
                $options['priv_mech'] = $this->normalizePrivMech((string) ($config['snmp_priv_protocol'] ?? 'AES'));
                $options['priv_pwd'] = $privPassword;
            }
        }

        return new SnmpClient($options);
    }

    /**
      * Linux CPU strategy: average of all hrProcessorLoad values (one per
      * core). net-snmp agents often expose nothing under this subtree, in
      * which case the UCD-SNMP-MIB 1-minute load average is used instead.
      * The core count (number of hrProcessorLoad entries) is returned as the
      * third element so the panel can display "23.5% · 4 cores".
      *
      * @return array{0: float, 1: string, 2: ?int}|null Array of [load, source, cores]; null when both sources are unavailable.
      */
    private function fetchLinuxCpuLoad(SnmpClient $client): ?array
    {
        $loads = [];

        foreach ($this->walkOids($client, self::OID_HR_PROCESSOR_LOAD) as $oid) {
            $value = $this->scalarValue($oid);
            if (is_numeric($value)) {
                $loads[] = (float) $value;
            }
        }

        if ($loads !== []) {
            return [array_sum($loads) / count($loads), self::CPU_SOURCE_HR_PROCESSOR_LOAD, count($loads)];
        }

        // Fallback for net-snmp: 1-minute load average (a float, not a percent).
        $laLoad = $this->numericValue($this->fetchOid($client, self::OID_UCD_LA_LOAD_1MIN));

        if ($laLoad !== null) {
            return [$laLoad, self::CPU_SOURCE_UCD_LA_LOAD, null];
        }

        return null;
    }

    /**
      * Windows CPU strategy: plain average of all hrProcessorLoad values
      * (one per core); null when empty. Windows agents always populate this
      * table, so there is no fallback and no 'cpu_source'. Returns
      * [averageLoad, coreCount] so the panel can display core count.
      *
      * @return array{0: float, 1: int}|null
      */
    private function fetchWindowsCpuLoad(SnmpClient $client): ?array
    {
        $loads = [];

        foreach ($this->walkOids($client, self::OID_HR_PROCESSOR_LOAD) as $oid) {
            $value = $this->scalarValue($oid);
            if (is_numeric($value)) {
                $loads[] = (float) $value;
            }
        }

        return $loads === [] ? null : [array_sum($loads) / count($loads), count($loads)];
    }

    /**
     * Parse the hrStorageTable: memory used (physical-memory row) and disk
     * rows (fixed-disk rows). On linux targets, loop-device (/dev/loop*),
     * snap-mount (/snap*) and sub-0.5 GB rows are filtered out as mount
     * noise; windows targets keep every fixed-disk row.
     *
     * @return array{0: ?int, 1: array<int, array{label: string, total_gb: float, used_gb: float}>}
     */
    private function fetchStorage(SnmpClient $client, bool $filterDiskNoise): array
    {
        $rows = $this->parseStorageTable($this->walkOids($client, self::OID_HR_STORAGE_TABLE));

        $memoryUsedMb = null;
        $disks = [];

        foreach ($rows as $row) {
            $type = isset($row[2]) ? $this->normalizeOid((string) $row[2]) : null;
            $units = $this->floatAt($row, 4);
            $size = $this->floatAt($row, 5);
            $used = $this->floatAt($row, 6);

            if ($type === self::HR_STORAGE_TYPE_PHYSICAL_MEMORY) {
                $memoryUsedMb = (int) round($used * $units / self::KB / self::KB);
            } elseif ($type === self::HR_STORAGE_TYPE_FIXED_DISK) {
                $totalGb = round($size * $units / self::GB, 1);

                if ($filterDiskNoise && $this->isDiskNoise(isset($row[3]) ? (string) $row[3] : '', $totalGb)) {
                    continue;
                }

                $disks[] = [
                    'label' => $this->sanitizeString(isset($row[3]) ? (string) $row[3] : ''),
                    'total_gb' => $totalGb,
                    'used_gb' => round($used * $units / self::GB, 1),
                ];
            }
        }

        return [$memoryUsedMb, $disks];
    }

    /**
     * True when a fixed-disk row is Linux mount noise rather than real
     * storage: snap loop devices (/dev/loopN), snap mounts (/snap/...) or
     * tiny tmpfs-style entries below the minimum reportable size.
     */
    private function isDiskNoise(string $label, float $totalGb): bool
    {
        if (str_starts_with($label, '/dev/loop') || str_starts_with($label, '/snap')) {
            return true;
        }

        return $totalGb < self::MIN_DISK_TOTAL_GB;
    }

    /**
     * Collect network interfaces via IF-MIB ifTable.
     * Returns an array of interfaces with name, operStatus, speed, in/out octets.
     * Empty array when the walk is empty (never throws).
     *
     * @return array<int, array{index: int, name: string, descr: string, operStatus: mixed, oper_status: mixed, adminStatus: mixed, speed: ?int, inOctets: ?int, in_octets: ?int, outOctets: ?int, out_octets: ?int, type: mixed, mtu: mixed, physAddress: mixed}>
     */
    private function fetchNetwork(SnmpClient $client): array
    {
        $oids = $this->walkOids($client, self::OID_IF_TABLE);

        if ($oids === []) {
            return [];
        }

        $rows = $this->parseGenericTable($oids, self::OID_IF_TABLE);
        $interfaces = [];

        foreach ($rows as $index => $row) {
            $name = isset($row[2]) ? $this->sanitizeString(trim((string) $row[2], " \t\n\r\0\x00\x0B")) : '';

            if (($name === '' || $name === null) && ! isset($row[1])) {
                continue;
            }

            $fallback = isset($row[1]) ? $this->sanitizeString(trim((string) $row[1], " \t\n\r\0\x00\x0B")) : '';
            $rawPhys = $row[6] ?? null;
            $phys = null;
            if (is_string($rawPhys)) {
                $phys = $this->formatPhysAddress($rawPhys);
            } elseif ($rawPhys !== null) {
                $phys = $this->sanitizeString((string) $rawPhys);
            }

            $interfaces[] = [
                'index' => (int) $index,
                'name' => ($name !== '' && $name !== null) ? $name : ($fallback ?? ''),
                'descr' => $name ?? '',
                'operStatus' => $row[8] ?? null,
                'oper_status' => $row[8] ?? null,
                'adminStatus' => $row[7] ?? null,
                'speed' => isset($row[5]) && is_numeric($row[5]) ? (int) $row[5] : null,
                'inOctets' => isset($row[10]) && is_numeric($row[10]) ? (int) $row[10] : null,
                'in_octets' => isset($row[10]) && is_numeric($row[10]) ? (int) $row[10] : null,
                'outOctets' => isset($row[16]) && is_numeric($row[16]) ? (int) $row[16] : null,
                'out_octets' => isset($row[16]) && is_numeric($row[16]) ? (int) $row[16] : null,
                'type' => $row[3] ?? null,
                'mtu' => $row[4] ?? null,
                'physAddress' => $phys,
            ];
        }

        return $interfaces;
    }

    /**
     * Collect running processes via HOST-RESOURCES-MIB hrSWRunTable.
     * Returns an array of processes with name, path, type, status.
     * Capped at 200 entries to bound payload size. Empty array when walk empty.
     *
     * @return array<int, array{index: int, name: string, id: mixed, path: ?string, parameters: ?string, type: mixed, status: mixed}>
     */
    private function fetchProcesses(SnmpClient $client): array
    {
        $oids = $this->walkOids($client, self::OID_HR_SW_RUN_TABLE);

        if ($oids === []) {
            return [];
        }

        $rows = $this->parseGenericTable($oids, self::OID_HR_SW_RUN_TABLE);
        $processes = [];

        foreach ($rows as $index => $row) {
            $name = isset($row[2]) ? $this->sanitizeString(trim((string) $row[2], " \t\n\r\0\x00\x0B")) : null;

            if ($name === null || $name === '') {
                continue;
            }

            $processes[] = [
                'index' => (int) $index,
                'name' => $name,
                'id' => $row[3] ?? null,
                'path' => isset($row[4]) ? $this->sanitizeString((string) $row[4]) : null,
                'parameters' => isset($row[5]) ? $this->sanitizeString((string) $row[5]) : null,
                'type' => $row[6] ?? null,
                'status' => $row[7] ?? null,
            ];
        }

        if (count($processes) > 200) {
            $processes = array_slice($processes, 0, 200);
        }

        return $processes;
    }

    /**
     * Group walked hrStorageTable OIDs into rows keyed by table index, each
     * row keyed by column number (2=type, 3=descr, 4=allocation units,
     * 5=size, 6=used).
     *
     * @param  Oid[]  $oids
     * @return array<int, array<int, int|float|string|bool|null>>
     */
    private function parseStorageTable(array $oids): array
    {
        $rows = [];

        foreach ($oids as $oid) {
            $suffix = $this->storageSuffix($oid->getOid());
            if ($suffix === null) {
                continue;
            }

            [$column, $index] = $suffix;
            $rows[$index][$column] = $this->scalarValue($oid);
        }

        return $rows;
    }

    /**
     * Extract the column and row index from a walked storage OID, e.g.
     * "1.3.6.1.2.1.25.2.3.1.4.3" -> [4, 3]. Null when not a table row.
     *
     * @return array{0: int, 1: int}|null
     */
    private function storageSuffix(string $oid): ?array
    {
        $oid = $this->normalizeOid($oid);

        if (! str_starts_with($oid, self::OID_HR_STORAGE_TABLE)) {
            return null;
        }

        $suffix = substr($oid, strlen(self::OID_HR_STORAGE_TABLE));

        if (! preg_match('/^\.(\d+)\.(\d+)$/', $suffix, $matches)) {
            return null;
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    /**
     * Group walked OIDs of a generic table (ifTable, hrSWRunTable) into rows
     * keyed by table index, each row keyed by column number.
     *
     * @param  Oid[]  $oids
     * @return array<int, array<int, int|float|string|bool|null>>
     */
    private function parseGenericTable(array $oids, string $baseOid): array
    {
        $rows = [];

        foreach ($oids as $oid) {
            $suffix = $this->tableSuffix($oid->getOid(), $baseOid);
            if ($suffix === null) {
                continue;
            }

            [$column, $index] = $suffix;
            $rows[$index][$column] = $this->scalarValue($oid);
        }

        return $rows;
    }

    /**
     * Extract column and row index from a walked table OID, e.g.
     * "1.3.6.1.2.1.2.2.1.10.3" -> [10, 3]. Null when not a table row.
     *
     * @return array{0: int, 1: int}|null
     */
    private function tableSuffix(string $oid, string $baseOid): ?array
    {
        $oid = $this->normalizeOid($oid);
        $baseOid = $this->normalizeOid($baseOid);

        if (! str_starts_with($oid, $baseOid)) {
            return null;
        }

        $suffix = substr($oid, strlen($baseOid));

        if (! preg_match('/^\.(\d+)\.(\d+)$/', $suffix, $matches)) {
            return null;
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    /**
     * Walk a subtree and return every OID carrying a real value (exception
     * varbinds such as endOfMibView / noSuchInstance are skipped).
     *
     * @return Oid[]
     */
    private function walkOids(SnmpClient $client, string $startAt): array
    {
        $walk = $client->walk($startAt);
        $oids = [];

        while ($walk->hasOids()) {
            $oid = $walk->next();
            if ($oid->getStatus() === null) {
                $oids[] = $oid;
            }
        }

        return $oids;
    }

    private function fetchOid(SnmpClient $client, string $oid): ?Oid
    {
        return $client->getOid($oid);
    }

    private function fetchString(SnmpClient $client, string $oid): ?string
    {
        $value = $this->scalarValue($this->fetchOid($client, $oid));

        return $value === null ? null : (string) $value;
    }

    /**
     * Extract the scalar value of an OID, unwrapping the FreeDSx value object.
     */
    private function scalarValue(?Oid $oid): int|float|string|bool|null
    {
        $value = $oid?->getValue();

        if ($value === null) {
            return null;
        }

        if ($value instanceof AbstractValue) {
            return $value->getValue();
        }

        return $value;
    }

    private function numericValue(?Oid $oid): ?float
    {
        $value = $this->scalarValue($oid);

        return is_numeric($value) ? (float) $value : null;
    }

    private function floatAt(array $row, int $column): float
    {
        $value = $row[$column] ?? null;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function memoryTotalMb(?float $kb): ?int
    {
        return $kb === null ? null : (int) round($kb / self::KB);
    }

    /**
     * Format sysUpTime (hundredths of seconds) as a human duration, e.g.
     * "3 days, 4:12:33" or "0:00:00".
     */
    private function formatUptime(float $hundredths): string
    {
        $seconds = (int) round($hundredths / 100);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        $time = sprintf('%d:%02d:%02d', $hours, $minutes, $secs);

        return $days > 0 ? sprintf('%d days, %s', $days, $time) : $time;
    }

    /**
     * Map the config auth protocol (SHA/MD5) to a FreeDSx auth mechanism.
     */
    private function normalizeAuthMech(string $protocol): string
    {
        return match (strtoupper($protocol)) {
            'MD5' => 'md5',
            'SHA' => 'sha1',
            default => strtolower($protocol),
        };
    }

    /**
     * Map the config privacy protocol (AES/DES) to a FreeDSx privacy mechanism.
     */
    private function normalizePrivMech(string $protocol): string
    {
        return match (strtoupper($protocol)) {
            'DES' => 'des',
            'AES' => 'aes128',
            default => strtolower($protocol),
        };
    }

    /**
     * Ensure a string is valid UTF-8 for json_encode. Drops invalid byte
     * sequences (e.g. Windows-1252 fragments from hrSWRunTable/ifDescr) and
     * preserves the rest.
     */
    private function sanitizeString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // //IGNORE drops malformed sequences; also strip lone surrogates.
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

        if ($clean === false) {
            $clean = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        // Final safety: json_encode must not fail — replace any remaining
        // invalid code points with the Unicode replacement character.
        if (! mb_check_encoding($clean, 'UTF-8')) {
            $clean = mb_convert_encoding($clean, 'UTF-8', 'UTF-8');
        }

        return $clean;
    }

    /**
     * Convert a raw SNMP physAddress (binary MAC) to a readable hex string.
     * Empty string stays empty; printable ASCII is returned sanitized; binary
     * bytes become colon-separated hex (e.g. 00:15:5D:03:01:A4).
     */
    private function formatPhysAddress(string $raw): ?string
    {
        if ($raw === '') {
            return '';
        }

        // If it contains non-printable bytes, treat as binary MAC.
        if (preg_match('/[^\x20-\x7E]/', $raw)) {
            $hex = strtoupper(bin2hex($raw));
            // Heuristic: MAC is 6 bytes (12 hex chars); if longer, just hex.
            if (strlen($hex) === 12) {
                return implode(':', str_split($hex, 2));
            }
            if (strlen($hex) > 0) {
                // Chunk into bytes for readability even if not 6.
                return implode(':', str_split($hex, 2));
            }
        }

        return $this->sanitizeString($raw);
    }

    /**
     * FreeDSx returns OIDs without a leading dot; config values may carry one.
     */
    private function normalizeOid(string $oid): string
    {
        return str_starts_with($oid, '.') ? substr($oid, 1) : $oid;
    }

    private function closeQuietly(SnmpClient $client): void
    {
        try {
            $client->close();
        } catch (\Throwable) {
            // Cleanup must never throw.
        }
    }
}
