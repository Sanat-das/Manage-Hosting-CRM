<?php

declare(strict_types=1);

namespace Modules\SnmpMonitor\Exceptions;

use RuntimeException;

/**
 * Thrown when an SNMP read is attempted for a hosting account whose product
 * does not have an enabled snmp-monitor module link. Controllers translate
 * this into a 403 so time-series data can never leak across unlinked
 * products.
 */
final class UnlinkedAccountException extends RuntimeException {}
