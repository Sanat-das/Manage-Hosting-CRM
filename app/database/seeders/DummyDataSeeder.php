<?php

namespace Database\Seeders;

use Database\Seeders\Demo\AuditSeeder;
use Database\Seeders\Demo\CustomerSeeder;
use Database\Seeders\Demo\InfrastructureSeeder;
use Database\Seeders\Demo\InventorySeeder;
use Database\Seeders\Demo\InvoiceSeeder;
use Database\Seeders\Demo\IpamDnsSeeder;
use Database\Seeders\Demo\NotificationEmailSeeder;
use Database\Seeders\Demo\OrderSeeder;
use Database\Seeders\Demo\PaymentSeeder;
use Database\Seeders\Demo\ProductExtrasSeeder;
use Database\Seeders\Demo\ProductSeeder;
use Database\Seeders\Demo\QuoteSeeder;
use Database\Seeders\Demo\ResourceSeeder;
use Database\Seeders\Demo\ServiceSeeder;
use Database\Seeders\Demo\SupportSeeder;
use Database\Seeders\Demo\UserSeeder;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Single entry point for ALL demo data (Task 20).
 *
 * The 16 `Database\Seeders\Demo` modules are invoked strictly in foreign-key
 * order so that every module's FK references resolve to rows already written
 * by the modules before it:
 *
 *   1. UserSeeder           users + RBAC + passkeys            (no deps)
 *   2. CustomerSeeder       customers + contacts/notes/...     (needs users)
 *   3. ProductSeeder        product catalog                    (no deps)
 *   4. ProductExtrasSeeder  addons/upgrades/bundles/...        (needs products)
 *   5. InfrastructureSeeder servers + groups/members/...       (no deps)
 *   6. ResourceSeeder       resource types/pools/allocations   (needs infra)
 *   7. InventorySeeder      inventory_assets + relationships   (needs products)
 *   8. ServiceSeeder        hosting_accounts + service_...     (needs cust+prod+infra)
 *   9. OrderSeeder          orders + order_items + history     (needs cust+service)
 *  10. InvoiceSeeder        invoices + invoice_items + quotes  (needs orders)
 *  11. PaymentSeeder        payments + transactions + credits  (needs invoices)
 *  12. QuoteSeeder          quotes + quote_items               (needs customers/products)
 *  13. IpamDnsSeeder        subnets/addresses/vlans/dns        (needs servers/customers)
 *  14. SupportSeeder        tickets + replies + chat + KB      (needs cust+service)
 *  15. AuditSeeder          audit/activity/automation/cron     (needs everything)
 *  16. NotificationEmailSeeder  e-mail + notifications + config (needs customers/gateways)
 *
 * The admin user is created by UserSeeder (module 1) - this orchestrator never
 * creates users itself, so re-running the chain cannot duplicate the admin.
 *
 * Every module is idempotent (updateOrInsert on natural keys / seedRowOnce),
 * which makes the whole chain safe to re-run any number of times.
 *
 * FAILURE REPORTING
 * -----------------
 * Each module is run through runTagged() so that if ANY module throws, the
 * error message names the module that failed and the original exception is
 * preserved as the previous chain. Failures are never swallowed - they always
 * bubble up and abort the run.
 */
class DummyDataSeeder extends Seeder
{
    /**
     * The 16 demo modules in strict FK order - do not reorder.
     *
     * @var list<class-string<Seeder>>
     */
    private const MODULES = [
        UserSeeder::class,
        CustomerSeeder::class,
        ProductSeeder::class,
        ProductExtrasSeeder::class,
        InfrastructureSeeder::class,
        ResourceSeeder::class,
        InventorySeeder::class,
        ServiceSeeder::class,
        OrderSeeder::class,
        InvoiceSeeder::class,
        PaymentSeeder::class,
        QuoteSeeder::class,
        IpamDnsSeeder::class,
        SupportSeeder::class,
        AuditSeeder::class,
        NotificationEmailSeeder::class,
    ];

    public function run(): void
    {
        foreach (self::MODULES as $module) {
            $this->runTagged($module);
        }

        $this->command?->info('DummyDataSeeder: all 16 modules seeded.');
    }

    /**
     * Run one module, tagging any failure with the module class name so the
     * failing seeder is identifiable from the error message. Always rethrows.
     *
     * @param  class-string<Seeder>  $module
     */
    private function runTagged(string $module): void
    {
        try {
            $this->call($module);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                sprintf('Demo seeder \'%s\' failed: %s', $module, $e->getMessage()),
                0,
                $e
            );
        }
    }
}
