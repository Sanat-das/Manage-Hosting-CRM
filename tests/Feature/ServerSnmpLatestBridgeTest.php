<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\ServerController;
use App\Models\Server;
use App\Services\Modules\ModuleManager;
use App\ViewModels\Admin\ServerDetailViewModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithSnmpMonitorModule;
use Tests\TestCase;

require_once __DIR__.'/../Support/InteractsWithSnmpMonitorModule.php';

/**
 * Todo 9 evidence: SNMP-latest read-only bridge on ServerController::show().
 *
 * Exercises applySnmpLatestBridge() directly (cached DB reads only — never a
 * live SNMP dial) and pins: latest-collected_at wins, tie-break lowest
 * snmp_targets.id, derived gauges beat raw payload fields, malformed rows
 * degrade, zero targets render empty slots with the bridge itself <2s.
 */
final class ServerSnmpLatestBridgeTest extends TestCase
{
    use InteractsWithSnmpMonitorModule;
    use RefreshDatabase;

    private ModuleManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerSnmpMonitorAutoloader();
        $this->ensureSnmpMonitoringTables($this->manager = app(ModuleManager::class));
    }

    private function bridge(Server $server): ServerDetailViewModel
    {
        $controller = app(ServerController::class);
        $method = new \ReflectionMethod($controller, 'applySnmpLatestBridge');
        $method->setAccessible(true);

        return $method->invoke($controller, $server->refresh(), ServerDetailViewModel::fromServer($server->refresh()));
    }

    private function makeServer(string $name = 'SNMP Bridge Srv'): Server
    {
        return Server::create([
            'name' => $name,
            'ip_address' => '192.0.2.10',
            'server_type' => 'cpanel',
            'status' => 'active',
        ]);
    }

    private function seedLatest(int $targetId, string $collectedAt, array|string $payload): void
    {
        $this->monitoring()->table('snmp_latest')->insert([
            'host_id' => $targetId,
            'collected_at' => $collectedAt,
            'status' => 'up',
            'payload' => is_string($payload) ? $payload : json_encode($payload),
        ]);
    }

    private function seedSample(int $targetId, string $collectedAt, array $row): void
    {
        $this->monitoring()->table('snmp_host_samples')->insert(array_merge([
            'host_id' => $targetId,
            'collected_at' => $collectedAt,
        ], $row));
    }

    public function test_bridge_fills_snmp_props_from_latest_target_with_gauges_and_source_tag(): void
    {
        $server = $this->makeServer();
        $module = $this->activateSnmpMonitorModule($this->manager);
        $product = $this->makeMonitoredProduct($this->manager, $module);

        $older = $this->makeAccount($product);
        $older->update(['server_id' => $server->id]);
        $newer = $this->makeAccount($product);
        $newer->update(['server_id' => $server->id]);

        $t1 = $this->makeTarget($older);
        $t2 = $this->makeTarget($newer);

        // Older target: payload says cpu 12.5, sample gauge says 33.3 → gauge must win.
        $this->seedLatest($t1->id, '2026-08-25 11:58:00', [
            'uptime_human' => '2.05:00:00',
            'cpu_load' => 12.5,
            'cpu_source' => 'hrProcessorLoad',
            'memory_total_mb' => 4096,
            'memory_used_mb' => 2048,
            'disks' => [['total_gb' => 100, 'used_gb' => 50]],
        ]);
        $this->seedSample($t1->id, '2026-08-25 11:58:00', [
            'cpu_pct' => 33.3, 'mem_total_mb' => 4096, 'mem_used_mb' => 2048,
            'storage_pct' => 99.9, 'response_ms' => 11,
        ]);

        // Newer target wins on collected_at.
        $this->seedLatest($t2->id, '2026-08-25 11:59:00', [
            'uptime_human' => '3.01:00:00',
            'cpu_load' => 44.4,
            'cpu_source' => 'hrProcessorLoad',
            'memory_total_mb' => 8192,
            'memory_used_mb' => 4096,
            'disks' => [['total_gb' => 200, 'used_gb' => 100]],
        ]);
        $this->seedSample($t2->id, '2026-08-25 11:59:00', [
            'cpu_pct' => 44.4, 'mem_total_mb' => 8192, 'mem_used_mb' => 4096,
            'storage_pct' => 11.1, 'response_ms' => 22,
        ]);

        $vm = $this->bridge($server);

        $this->assertSame('3.01:00:00', $vm->snmpUptime);
        $this->assertSame(44.4, $vm->snmpCpu);
        $this->assertSame(50.0, $vm->snmpMem);
        $this->assertSame(11.1, $vm->snmpDisks);
        $this->assertSame('snmp:account-'.$newer->id, $vm->snmpSource);
        $this->assertSame('2026-08-25 11:59:00', (string) $vm->snmpCollectedAt);

        fwrite(STDERR, sprintf(
            "\n[EVIDENCE gauge-wins] cpu=%s mem=%s disk=%s (payload disks implied 50.0, gauge 11.1 won) source=%s collected_at=%s\n",
            var_export($vm->snmpCpu, true), var_export($vm->snmpMem, true),
            var_export($vm->snmpDisks, true), $vm->snmpSource, $vm->snmpCollectedAt
        ));
    }

    public function test_bridge_tie_on_collected_at_breaks_to_lowest_target_id(): void
    {
        $server = $this->makeServer('SNMP Tie Srv');
        $module = $this->activateSnmpMonitorModule($this->manager);
        $product = $this->makeMonitoredProduct($this->manager, $module);

        $a = $this->makeAccount($product);
        $a->update(['server_id' => $server->id]);
        $b = $this->makeAccount($product);
        $b->update(['server_id' => $server->id]);

        $t1 = $this->makeTarget($a);
        $t2 = $this->makeTarget($b);

        $this->assertLessThan($t2->id, $t1->id);

        foreach ([$t1->id => 'AAA', $t2->id => 'BBB'] as $tid => $tag) {
            $this->seedLatest($tid, '2026-08-25 11:59:00', ['uptime_human' => $tag]);
        }

        $vm = $this->bridge($server);

        $this->assertSame('AAA', $vm->snmpUptime);
        $this->assertSame('snmp:account-'.$a->id, $vm->snmpSource);

        fwrite(STDERR, sprintf(
            "\n[EVIDENCE tie-break] winner uptime=%s source=%s (lowest target id %d won over %d)\n",
            var_export($vm->snmpUptime, true), $vm->snmpSource, $t1->id, $t2->id
        ));
    }

    public function test_bridge_malformed_payload_and_missing_rows_degrade(): void
    {
        $server = $this->makeServer('SNMP Malformed Srv');
        $module = $this->activateSnmpMonitorModule($this->manager);
        $product = $this->makeMonitoredProduct($this->manager, $module);

        // Target with NO snmp_latest row at all must be skipped.
        $ghost = $this->makeAccount($product);
        $ghost->update(['server_id' => $server->id]);
        $this->makeTarget($ghost);

        // Winner has garbage payload but a real sample row: gauges still fill.
        $real = $this->makeAccount($product);
        $real->update(['server_id' => $server->id]);
        $t = $this->makeTarget($real);
        $this->seedLatest($t->id, '2026-08-25 11:59:00', 'not-json{{{');
        $this->seedSample($t->id, '2026-08-25 11:59:00', [
            'cpu_pct' => 7.5, 'mem_total_mb' => 1024, 'mem_used_mb' => 512,
            'storage_pct' => 25.0, 'response_ms' => 5,
        ]);

        $vm = $this->bridge($server);

        $this->assertNull($vm->snmpUptime);
        $this->assertSame(7.5, $vm->snmpCpu);
        $this->assertSame(50.0, $vm->snmpMem);
        $this->assertSame(25.0, $vm->snmpDisks);
        $this->assertSame('snmp:account-'.$real->id, $vm->snmpSource);

        fwrite(STDERR, sprintf(
            "\n[EVIDENCE malformed] garbage payload -> uptime=%s gauges cpu=%s mem=%s disk=%s\n",
            var_export($vm->snmpUptime, true), var_export($vm->snmpCpu, true),
            var_export($vm->snmpMem, true), var_export($vm->snmpDisks, true)
        ));
    }

    public function test_bridge_absent_zero_targets_renders_empty_slots_fast(): void
    {
        $server = $this->makeServer('SNMP Absent Srv');

        $start = microtime(true);
        $vm = $this->bridge($server);
        $elapsed = microtime(true) - $start;

        $this->assertNull($vm->snmpSource);
        $this->assertNull($vm->snmpCollectedAt);
        $this->assertNull($vm->snmpUptime);
        $this->assertNull($vm->snmpCpu);
        $this->assertNull($vm->snmpMem);
        $this->assertNull($vm->snmpDisks);
        $this->assertLessThan(2.0, $elapsed, 'SNMP-absent bridge must complete <2s.');

        fwrite(STDERR, sprintf(
            "\n[EVIDENCE absent-timing] zero targets -> all snmp* null, bridge elapsed=%.4fs (bound <2s)\n",
            $elapsed
        ));
    }
}
