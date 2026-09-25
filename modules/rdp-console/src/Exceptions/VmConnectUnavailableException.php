<?php

declare(strict_types=1);

namespace Modules\RdpConsole\Exceptions;

use RuntimeException;

/**
 * Thrown when the server-side facts a VMConnect session needs are missing
 * (no recorded Hyper-V VM, no host address, no host administrator
 * credentials, no VM GUID). The message is operator-safe and is surfaced
 * verbatim by the token endpoint / console page — it must never contain
 * credential material.
 */
final class VmConnectUnavailableException extends RuntimeException {}
