<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carry the configurable-option snapshot onto invoice lines.
 *
 * An invoice line said only "Cloud VPS - Monthly": the RAM, storage and
 * support the customer is being billed for appeared nowhere, so a line's
 * amount could not be explained from the invoice itself. `order_items` has
 * held that snapshot since the option work began; this copies it onto the
 * invoice at the moment the invoice is raised, which keeps the invoice
 * self-contained and immune to later catalog edits.
 *
 * Nullable: invoices raised by hand from the admin form carry no order, and
 * every line predating this column keeps rendering without one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->json('config_options')->nullable()->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('config_options');
        });
    }
};
