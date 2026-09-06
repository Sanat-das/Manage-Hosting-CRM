<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a service instance exist without a catalog product.
 *
 * `service_instances` came from the enterprise/reference schema, where every
 * service hangs off a `catalog_products` row. The live storefront sells
 * `products` instead — a parallel table with no bridge — so an order could
 * never produce a service instance, which is what ProvisioningModule::provision()
 * takes. Rather than invent a catalog mirror for every product, the link
 * becomes optional: order-born instances carry `order_id` (already present and
 * nullable) and leave `catalog_product_id` null.
 *
 * Every existing reader already null-safes the relation
 * (`$inst->catalogProduct?->name ?? '-'` in the service-instance index/show and
 * the ticket service picker), so nothing needs to change alongside this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_instances', function (Blueprint $table) {
            $table->unsignedBigInteger('catalog_product_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows created from an order have no catalog product to point at, so
        // they must go before the column can be NOT NULL again.
        Schema::table('service_instances', function (Blueprint $table) {
            $table->unsignedBigInteger('catalog_product_id')->nullable(false)->change();
        });
    }
};
