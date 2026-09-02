<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-product order-form rules (the Domain-style option set):
     * - sell_single: quantity locked to 1 (e.g. domains, dedicated servers)
     * - require_public_ip / require_private_ip: capture an IP at order time,
     *   like require_domain captures a domain name — optional unless flagged.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('sell_single')->default(false)->after('require_domain');
            $table->boolean('require_public_ip')->default(false)->after('sell_single');
            $table->boolean('require_private_ip')->default(false)->after('require_public_ip');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('public_ip', 45)->nullable()->after('domain_name');
            $table->string('private_ip', 45)->nullable()->after('public_ip');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['sell_single', 'require_public_ip', 'require_private_ip']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['public_ip', 'private_ip']);
        });
    }
};