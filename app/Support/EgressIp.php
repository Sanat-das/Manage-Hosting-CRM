<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Source IP a managed server sees when this app connects to it.
 *
 * Why this exists: the Hyper-V WinRM setup guide must tell the operator which
 * single source IP to allow through the host's WinRM IP filter and Windows
 * firewall rule. That address belongs to the panel, not to the operator's
 * browser, and it is not necessarily the inbound SERVER_ADDR — the panel can
 * reach a Hyper-V host over a different interface than the one the admin
 * request arrived on.
 *
 * The only honest source is the local address of the route the WinRM
 * connection will actually take, so we ask the kernel: connect a UDP socket
 * toward the destination and read the local end back. A UDP connect performs
 * no handshake and emits no packet — it only selects a route — so this is
 * side-effect free, cannot be blocked by the remote firewall, and never
 * depends on the remote host being up.
 *
 * Hostnames are never resolved here: a blocking DNS lookup during page render
 * is not worth it, and probing an anchor address yields the same interface in
 * every layout that is not split-tunnel.
 *
 * Everything is best-effort and returns null rather than guessing. A wrong
 * address inside a security control is worse than a visible placeholder, so an
 * unresolvable egress renders as an obvious <HOSTVEXA_IP> token the operator
 * must replace.
 */
final class EgressIp
{
    /**
     * Route probe target used when the destination is unknown — the server
     * create form has no row yet. A route to the public internet and a route
     * to a private Hyper-V host normally share the default-gateway interface.
     */
    private const ANCHOR_IP = '1.1.1.1';

    private const ANCHOR_PORT = 53;

    /** Port is irrelevant to a route probe; kept only to mirror the real call. */
    private const DEFAULT_PORT = 5985;

    /**
     * Resolve the panel's source IP for a connection to $destinationHost.
     *
     * $destinationHost is probed directly when it is an IP literal, which is
     * the accurate answer whenever the management network is not the default
     * route.
     */
    public static function resolve(?string $destinationHost = null, int $destinationPort = self::DEFAULT_PORT): ?string
    {
        $destination = trim((string) $destinationHost);

        if ($destination !== '' && filter_var($destination, FILTER_VALIDATE_IP) !== false) {
            $probed = self::probe($destination, self::validPort($destinationPort));

            if ($probed !== null) {
                return $probed;
            }
        }

        return self::probe(self::ANCHOR_IP, self::ANCHOR_PORT);
    }

    /**
     * The address to print in operator-facing commands, or the literal
     * <HOSTVEXA_IP> placeholder when it cannot be determined — never a guess.
     */
    public static function forDisplay(?string $destinationHost = null, int $destinationPort = self::DEFAULT_PORT): string
    {
        return self::resolve($destinationHost, $destinationPort) ?? '<HOSTVEXA_IP>';
    }

    /** True when $address is an IPv6 literal (such literals need bracketing). */
    private static function isIpv6(?string $address): bool
    {
        return filter_var((string) $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    /**
     * @return string|null the local address chosen for the route, or null
     */
    private static function probe(string $ip, int $port): ?string
    {
        // An IPv6 literal must be bracketed before it forms a socket URI.
        $host = self::isIpv6($ip) ? '['.$ip.']' : $ip;

        $socket = @stream_socket_client(
            sprintf('udp://%s:%d', $host, $port),
            $errno,
            $errstr,
            1,
            STREAM_CLIENT_CONNECT
        );

        if ($socket === false) {
            return null;
        }

        $name = @stream_socket_get_name($socket, false);
        @fclose($socket);

        $local = self::stripPort(is_string($name) ? $name : '');

        return $local !== null && filter_var($local, FILTER_VALIDATE_IP) !== false ? $local : null;
    }

    /**
     * "10.0.0.5:5985" -> "10.0.0.5"; "[fe80::1]:5985" -> "fe80::1".
     *
     * IPv6 names always come back bracketed, so the bracket form is
     * unambiguous and is preferred over splitting on the last colon.
     */
    private static function stripPort(string $name): ?string
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        if ($name[0] === '[') {
            $end = strpos($name, ']');

            return $end === false ? null : substr($name, 1, $end - 1);
        }

        $colon = strrpos($name, ':');

        return $colon === false ? $name : substr($name, 0, $colon);
    }

    private static function validPort(int $port): int
    {
        return $port >= 1 && $port <= 65535 ? $port : self::DEFAULT_PORT;
    }
}
