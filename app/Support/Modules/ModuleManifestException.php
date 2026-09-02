<?php

declare(strict_types=1);

namespace App\Support\Modules;

/**
 * Thrown when a module.json is missing, unreadable, or fails validation.
 */
class ModuleManifestException extends \RuntimeException
{
}