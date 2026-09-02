<?php

declare(strict_types=1);

namespace Modules\SnmpMonitor\Exceptions;

/**
 * Raised whenever an SNMP collection attempt fails: unreachable host,
 * authentication/encryption errors, protocol errors, timeouts or an empty
 * response. Consumed by the polling pipeline and future controllers to
 * surface a user-facing error instead of a raw library exception.
 */
final class SnmpException extends \RuntimeException {}
