<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Support\EgressIp;
use Tests\TestCase;

/**
 * The Hyper-V WinRM guide tells the operator which single source IP to allow
 * through the host firewall, so the address it prints has to be the panel's
 * own egress address — not the admin's browser IP, and never a guess.
 *
 * EgressIp resolves that address by routing a UDP socket (no packet, no
 * handshake) and reading the local end back, so the loopback cases below are
 * deterministic on any host and exercise the real probe + port-stripping path.
 * The partial render case covers the blade plumbing end to end.
 */
class EgressIpTest extends TestCase
{
    public function test_resolves_the_local_end_of_a_reachable_route(): void
    {
        // Loopback routes unconditionally, so this is stable regardless of the
        // estate's routing table.
        $this->assertSame('127.0.0.1', EgressIp::resolve('127.0.0.1'));
    }

    public function test_never_returns_a_bogus_address(): void
    {
        // A hostname is deliberately NOT resolved (blocking DNS during render
        // is not worth it), and an unroutable literal cannot be probed. Every
        // input must still yield a valid IP or the visible placeholder.
        $destinations = [
            null,
            '',
            '   ',
            'not an ip',
            '999.999.999.999',
            'hyperv.example.internal',
            '10.255.255.1',
        ];

        foreach ($destinations as $destination) {
            $value = EgressIp::forDisplay($destination);

            $label = var_export($destination, true);

            $this->assertNotSame('', $value, "blank result for {$label}");

            $this->assertTrue(
                filter_var($value, FILTER_VALIDATE_IP) !== false || $value === '<HOSTVEXA_IP>',
                "for {$label} expected a valid IP or the placeholder, got {$value}"
            );
        }
    }

    public function test_out_of_range_ports_fall_back_instead_of_failing(): void
    {
        foreach ([0, -1, 70000] as $port) {
            $this->assertSame('127.0.0.1', EgressIp::forDisplay('127.0.0.1', $port), "port {$port}");
        }
    }

    public function test_winrm_guide_scopes_the_host_firewall_to_the_panel_ip(): void
    {
        $html = view('admin.servers.partials._winrm-guide', [
            'serverType' => 'hyperv',
            'typeSlug' => 'hyperv',
            'server' => new Server(['ip_address' => '127.0.0.1']),
        ])->render();

        // The panel IP is interpolated into the commands the operator copies.
        $this->assertStringContainsString('$panelIp = "127.0.0.1"', $html);

        // The enforcement mechanism is the firewall scope, applied by name from
        // an on-host enumeration because the WINRM* rule names vary by version.
        $this->assertStringContainsString('Set-NetFirewallRule -Name $_.Name -RemoteAddress $panelIp', $html);
        $this->assertStringContainsString('Get-NetFirewallRule -Name "WINRM*"', $html);

        // The guide must not send operators to the WSMan IP filters: those
        // select local bind addresses, they are not a client allow-list.
        $this->assertStringNotContainsString('Set-Item WSMan:\localhost\Service\IPv4Filter', $html);
    }
}
