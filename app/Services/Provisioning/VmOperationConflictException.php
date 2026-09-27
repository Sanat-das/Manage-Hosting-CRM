<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use RuntimeException;

/**
 * A VM operation was requested while another action event is still running
 * for the same hosting account. The controller maps this to HTTP 409.
 */
class VmOperationConflictException extends RuntimeException {}
