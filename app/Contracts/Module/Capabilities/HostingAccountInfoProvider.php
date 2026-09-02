<?php

declare(strict_types=1);

namespace App\Contracts\Module\Capabilities;

use App\Models\HostingAccount;

/**
 * Optional capability: modules that can display read-only information about
 * a hosting account on the Product/Service pages.
 *
 * The manager type-checks a module against this interface before ever
 * routing a display call to it; modules that do not implement it are simply
 * skipped when the pages collect panels. $config is the ALREADY-DECRYPTED
 * per-product module config (see ModuleContext), passed through by the
 * caller.
 */
interface HostingAccountInfoProvider
{
    /**
     * Panel payload for the given account, or null when the module has
     * nothing to show for it (e.g. the module is not enabled on the
     * account's product).
     *
     * @param  array<string, mixed>  $config  decrypted per-product module config
     * @return array{title: string, icon: ?string, view: string, data: array<string, mixed>}|null
     */
    public function hostingAccountInfo(HostingAccount $account, array $config): ?array;
}