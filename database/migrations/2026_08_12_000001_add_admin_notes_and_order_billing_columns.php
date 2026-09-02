<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WHMCS-parity columns for the Product/Service page:
     *  - hosting_accounts.notes     admin-only free-text notes (WHMCS "Admin Notes")
     *  - orders.payment_method      per-service payment method captured at order time
     *  - orders.subscription_id     gateway subscription id (WHMCS Service.subscriptionId)
     */
    public function up(): void
    {
        Schema::table('hosting_accounts', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('password');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method', 50)->nullable()->after('notes');
            $table->string('subscription_id', 255)->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('hosting_accounts', function (Blueprint $table) {
            $table->dropColumn('notes');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'subscription_id']);
        });
    }
};
