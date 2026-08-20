<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an IP lease cannot be fulfilled — either no free address
 * exists in the requested scope, or a specifically requested address is
 * not available for assignment.
 */
class NoAvailableIpException extends RuntimeException {}
