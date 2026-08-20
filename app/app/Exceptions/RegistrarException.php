<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a registrar driver cannot fulfil an operation — either the
 * registrar is not configured (missing credentials) or the remote API
 * returned an error. Availability is never fabricated on failure.
 */
class RegistrarException extends RuntimeException {}
