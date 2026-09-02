<?php

declare(strict_types=1);

namespace Modules\SshConsole\Exceptions;

/**
 * Raised whenever an SSH terminal operation fails: unreachable host,
 * authentication errors, invalid private keys or dropped connections.
 * Surfaces a user-facing error instead of raw phpseclib exceptions.
 */
final class SshException extends \RuntimeException {}
