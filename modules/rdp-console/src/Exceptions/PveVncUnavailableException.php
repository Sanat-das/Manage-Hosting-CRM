<?php

declare(strict_types=1);

namespace Modules\RdpConsole\Exceptions;

use RuntimeException;

/**
 * Thrown when the server-side facts a Proxmox VNC console needs are missing
 * (no recorded Proxmox VM, no VMID, no resolvable node, no configured
 * server, or a failed vncproxy call). The message is operator-safe and is
 * surfaced verbatim by the token endpoint / console page — it must never
 * contain credential material.
 */
final class PveVncUnavailableException extends RuntimeException {}
