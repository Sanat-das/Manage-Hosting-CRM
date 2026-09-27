<?php

declare(strict_types=1);

namespace App\Jobs;

/**
 * Back-compat shim for the original Hyper-V build job.
 *
 * The implementation moved to {@see ProvisionComputeVm}. This subclass keeps
 * serialized payloads dispatched before the generalization hydratable (they
 * reference this class name) and keeps older call sites resolving; the parent
 * defaults `$moduleSlug` to `hyperv`.
 */
class ProvisionHypervVm extends ProvisionComputeVm {}
