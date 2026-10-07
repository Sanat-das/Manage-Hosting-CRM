<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a port connection cannot be written — either a port is being
 * connected to itself, or one of the two ports already carries a cable.
 */
class PortConnectionConflictException extends RuntimeException {}
