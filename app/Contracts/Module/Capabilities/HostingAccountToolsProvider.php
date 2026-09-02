<?php

declare(strict_types=1);

namespace App\Contracts\Module\Capabilities;

use App\Models\HostingAccount;

/**
 * Optional capability: modules that own interactive tools for a hosting
 * account (launch buttons, settings modals, password-reveal JS) on the admin
 * Product/Service page.
 *
 * The manager type-checks a module against this interface before ever
 * routing a tools call to it; modules that do not implement it are simply
 * skipped when the page collects tool sections. $config is the
 * ALREADY-DECRYPTED per-product module config (see ModuleContext), passed
 * through by the caller.
 */
interface HostingAccountToolsProvider
{
    /**
     * Tools section payload for the given account, or null when the module
     * has nothing to show for it (e.g. the module is not enabled on the
     * account's product).
     *
     * @param  array<string, mixed>  $config  decrypted per-product module config
     * @return array{view: string, data: array<string, mixed>}|null
     */
    public function hostingAccountTools(HostingAccount $account, array $config): ?array;
}
