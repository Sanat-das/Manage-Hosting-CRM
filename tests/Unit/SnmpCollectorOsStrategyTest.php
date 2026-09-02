<?php

declare(strict_types=1);

namespace Tests\Unit;

use FreeDSx\Snmp\Exception\EndOfWalkException;
use FreeDSx\Snmp\Oid;
use FreeDSx\Snmp\SnmpClient;
use FreeDSx\Snmp\SnmpWalk;
use Modules\SnmpMonitor\Exceptions\SnmpException;
use Modules\SnmpMonitor\Services\SnmpCollector;
use Tests\TestCase;

/**
 * Plan task 2 — per-OS strategy branches of the shared SNMP collector.
 *
 * The snmp-monitor collector serves both operating systems through a single
 * class driven by $config['target_os'] ('linux' default | 'windows'):
 *
 *   - linux: hrProcessorLoad walk falls back to the UCD-SNMP-MIB 1-minute
 *     load average and records 'cpu_source'; disk-noise filtering drops
 *     /dev/loop*, /snap* and sub-0.5 GB fixed-disk rows;
 *   - windows: ONLY the plain hrProcessorLoad average is used (the UCD OID
 *     must never even be requested) and no 'cpu_source' key is recorded;
 *     every fixed-disk row is kept, including loop-device style labels.
 *
 * Everything runs against a scripted FreeDSx client — no network.
 */
final class SnmpCollectorOsStrategyTest extends TestCase
{
    private const OID_UCD_LA_LOAD_1MIN = '1.3.6.1.4.1.2021.10.1.3.1';

    public function test_linux_default_falls_back_to_ucd_la_load_with_cpu_source(): void
    {
        $client = $this->fakeClient();
        // net-snmp frequently exposes no hrProcessorLoad values at all.
        $client->walks['1.3.6.1.2.1.25.3.3.1.2'] = $this->fakeWalk([]);
        // UCD-SNMP-MIB laLoad.1: 1-minute load average "0.24".
        $client->gets[self::OID_UCD_LA_LOAD_1MIN] = Oid::fromString(self::OID_UCD_LA_LOAD_1MIN, '0.24');

        // No target_os key at all: the linux strategy must be the default.
        $payload = (new SnmpCollector($client))->collect('192.0.2.10', []);

        $this->assertSame(0.24, $payload['cpu_load']);
        $this->assertSame('ucd-laLoad', $payload['cpu_source']);
    }

    public function test_windows_strategy_reports_plain_hr_processor_load_average_without_cpu_source(): void
    {
        $client = $this->fakeClient();
        // Two cores at 10% and 30% -> average 20.0.
        $client->walks['1.3.6.1.2.1.25.3.3.1.2'] = $this->fakeWalk([
            Oid::fromInteger('1.3.6.1.2.1.25.3.3.1.2.1', 10),
            Oid::fromInteger('1.3.6.1.2.1.25.3.3.1.2.2', 30),
        ]);
        // Windows agents expose nothing under UCD laTable — left unscripted.

        $payload = (new SnmpCollector($client))->collect('192.0.2.20', ['target_os' => 'windows']);

        $this->assertSame(20.0, $payload['cpu_load']);
        $this->assertArrayNotHasKey('cpu_source', $payload);
    }

    public function test_windows_strategy_never_attempts_the_ucd_la_load_fallback(): void
    {
        $client = $this->fakeClient();
        // Empty processor walk: a linux agent would fall back to UCD here.
        $client->walks['1.3.6.1.2.1.25.3.3.1.2'] = $this->fakeWalk([]);
        // Deliberately NO scripted UCD answer: if the code ever requests it,
        // getOid() returns null silently, so the request log below is what
        // proves the fallback was never attempted.

        $payload = (new SnmpCollector($client))->collect('192.0.2.20', ['target_os' => 'windows']);

        $this->assertNotContains(self::OID_UCD_LA_LOAD_1MIN, $client->calledGets);
        $this->assertArrayNotHasKey('cpu_load', $payload);
        $this->assertArrayNotHasKey('cpu_source', $payload);
    }

    public function test_linux_strategy_filters_loop_snap_and_tiny_disk_rows(): void
    {
        $payload = (new SnmpCollector($this->fakeClient()))->collect('192.0.2.10', []);

        $this->assertSame([
            ['label' => '/dev/sda1', 'total_gb' => 100.0, 'used_gb' => 50.0],
        ], $payload['disks']);
    }

    public function test_windows_strategy_keeps_every_fixed_disk_row(): void
    {
        $payload = (new SnmpCollector($this->fakeClient()))->collect('192.0.2.20', ['target_os' => 'windows']);

        // Same table as the linux fixture: no filtering at all — even the
        // loop-device, snap-mount and tiny-tmpfs rows survive on windows.
        $labels = array_column($payload['disks'], 'label');
        $this->assertContains('/dev/sda1', $labels);
        $this->assertContains('/dev/loop0', $labels);
        $this->assertContains('/snap/snapd/12345', $labels);
        $this->assertContains('tmpfs', $labels);
        $this->assertCount(4, $payload['disks']);
    }

    public function test_common_identity_payload_is_identical_across_both_strategies(): void
    {
        $linux = (new SnmpCollector($this->fakeClient()))->collect('192.0.2.10', []);
        $windows = (new SnmpCollector($this->fakeClient()))->collect('192.0.2.20', ['target_os' => 'windows']);

        foreach (['hostname', 'os', 'uptime_human', 'memory_total_mb', 'memory_used_mb'] as $key) {
            $this->assertSame($linux[$key], $windows[$key], "Payload key [{$key}] diverges between strategies.");
        }

        $this->assertSame('LINUX-VPS-01', $linux['hostname']);
        $this->assertSame('32 days, 20:45:30', $linux['uptime_human']);
    }

    public function test_client_failure_is_wrapped_into_snmp_exception(): void
    {
        $client = $this->fakeClient();
        $client->getException = new \RuntimeException('agent unreachable');

        try {
            (new SnmpCollector($client))->collect('192.0.2.10', []);

            $this->fail('Expected SnmpException was not thrown.');
        } catch (SnmpException $e) {
            $this->assertSame('agent unreachable', $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    /**
     * A faked FreeDSx SNMP client with scripted responses and no network I/O.
     *
     * Hand-rolled as an anonymous subclass instead of a Mockery mock: mocking
     * the concrete SnmpClient/SnmpWalk classes generates eval'd mock classes
     * large enough to exhaust phpunit's default 128M memory limit.
     */
    private function fakeClient(): SnmpClient
    {
        $client = new class extends SnmpClient
        {
            /** @var array<string, Oid> scripted getOid() answers, keyed by OID string. */
            public array $gets = [];

            /** @var array<string, SnmpWalk> scripted walk() answers, keyed by start OID. */
            public array $walks = [];

            /** Every OID ever passed to getOid(), in call order. */
            public array $calledGets = [];

            /** When set, getOid() throws this (simulates an unreachable agent). */
            public ?\Throwable $getException = null;

            public function getOid($oid): ?Oid
            {
                $this->calledGets[] = (string) $oid;

                if ($this->getException !== null) {
                    throw $this->getException;
                }

                return $this->gets[(string) $oid] ?? null;
            }

            public function walk(?string $startAt = null, ?string $endAt = null): SnmpWalk
            {
                $key = (string) $startAt;

                if (! isset($this->walks[$key])) {
                    throw new \RuntimeException("Unexpected SNMP walk [{$key}].");
                }

                return $this->walks[$key];
            }

            public function close(): void {}
        };

        // Base system identity shared by every scenario below.
        $client->gets['1.3.6.1.2.1.1.5.0'] = Oid::fromString('1.3.6.1.2.1.1.5.0', 'LINUX-VPS-01');
        $client->gets['1.3.6.1.2.1.1.1.0'] = Oid::fromString(
            '1.3.6.1.2.1.1.1.0',
            'Linux linux-vps-01 5.15.0-91-generic #101-Ubuntu SMP Tue Nov 22 14:00:00 UTC 2022 x86_64'
        );
        // 283953000 hundredths of a second = 32 days, 20:45:30.
        $client->gets['1.3.6.1.2.1.1.3.0'] = Oid::fromTimeticks('1.3.6.1.2.1.1.3.0', 283953000);
        // hrMemorySize is reported in KB -> 4096 MB.
        $client->gets['1.3.6.1.2.1.25.2.2.0'] = Oid::fromInteger('1.3.6.1.2.1.25.2.2.0', 4194304);

        // net-snmp-style empty processor walk by default; CPU-specific
        // scenarios override this key with their own values.
        $client->walks['1.3.6.1.2.1.25.3.3.1.2'] = $this->fakeWalk([]);

        // hrStorageTable: row 1 = /dev/sda1 fixed disk (4096-byte units,
        // 26214400*4096 B = 100 GB total, 13107200*4096 B = 50 GB used),
        // row 2 = physical memory (1024-byte units, used = 2048 MB), rows
        // 3-5 = loop / snap / tiny-tmpfs rows the linux strategy must drop
        // and the windows strategy must keep.
        $client->walks['1.3.6.1.2.1.25.2.3.1'] = $this->fakeWalk([
            Oid::fromOid('1.3.6.1.2.1.25.2.3.1.2.1', '1.3.6.1.2.1.25.2.1.4'),
            Oid::fromString('1.3.6.1.2.1.25.2.3.1.3.1', '/dev/sda1'),
            Oid::fromInteger('1.3.6.1.2.1.25.2.3.1.4.1', 4096),
            Oid::fromInteger('1.3.6.1.2.1.25.2.3.1.5.1', 26214400),
            Oid::fromInteger('1.3.6.1.2.1.25.2.3.1.6.1', 13107200),
            Oid::fromOid('1.3.6.1.2.1.25.2.3.1.2.2', '1.3.6.1.2.1.25.2.1.2'),
            Oid::fromString('1.3.6.1.2.1.25.2.3.1.3.2', 'Physical memory'),
            Oid::fromInteger('1.3.6.1.2.1.25.2.3.1.4.2', 1024),
            Oid::fromInteger('1.3.6.1.2.1.25.2.3.1.5.2', 4194304),
            Oid::fromInteger('1.3.6.1.2.1.25.2.3.1.6.2', 2097152),
            Oid::fromOid('1.3.6.1.2.1.25.2.3.1.2.3', '1.3.6.1.2.1.25.2.1.4'),
            Oid::fromString('1.3.6.1.2.1.25.2.3.1.3.3', '/dev/loop0'),
            Oid::fromInteger('1.3.6.1.2.1.25.2.3.1.4.3', 1024),
            Oid::fromInteger('1.3.6.1.2.1.25.2.3.1.5.3', 62592),
            Oid::fromInteger('1.3.6.1.2.1.25.2.3.1.6.3', 62592),
            Oid::fromOid('1.3.6.1.2.1.25.2.3.1.2.4', '1.3.6.1.2.1.25.2.1.4'),
            Oid::fromString('1.3.6.1.2.1.25.2.3.1.3.4', '/snap/snapd/12345'),
            Oid::fromInteger('1.3.6.1.2.1.25.2.3.1.4.4', 1024),
            Oid::fromInteger('1.3.6.1.2.1.25.2.3.1.5.4', 200000),
            Oid::fromInteger('1.3.6.1.2.1.25.2.3.1.6.4', 100000),
            Oid::fromOid('1.3.6.1.2.1.25.2.3.1.2.5', '1.3.6.1.2.1.25.2.1.4'),
            Oid::fromString('1.3.6.1.2.1.25.2.3.1.3.5', 'tmpfs'),
            Oid::fromInteger('1.3.6.1.2.1.25.2.3.1.4.5', 1024),
            Oid::fromInteger('1.3.6.1.2.1.25.2.3.1.5.5', 400000),
            Oid::fromInteger('1.3.6.1.2.1.25.2.3.1.6.5', 100000),
        ]);

        return $client;
    }

    /**
     * A walk iterator over a fixed list of OIDs (no network).
     *
     * @param  list<Oid>  $oids
     */
    private function fakeWalk(array $oids): SnmpWalk
    {
        return new class($oids) extends SnmpWalk
        {
            private array $queue;

            public function __construct(array $oids)
            {
                // Parent constructor intentionally skipped: no client needed.
                $this->queue = $oids;
            }

            public function hasOids(): bool
            {
                return $this->queue !== [];
            }

            public function next(): Oid
            {
                if ($this->queue === []) {
                    throw new EndOfWalkException('There are no more OIDs left in the walk.');
                }

                return array_shift($this->queue);
            }
        };
    }
}
