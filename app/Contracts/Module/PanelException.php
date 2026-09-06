<?php

declare(strict_types=1);

namespace App\Contracts\Module;

use RuntimeException;

/**
 * A control-panel API call did not succeed.
 *
 * Carries the panel's own reason where there is one ("Username already
 * exists"), so the provisioning event records what the panel actually said
 * rather than a generic transport error. AbstractPanelModule converts it into
 * a ProvisioningResult::fail() — it never escapes a module.
 */
class PanelException extends RuntimeException {}
