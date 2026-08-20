<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Move IP capture off the order form and onto the activation flow.
 *
 * The order form no longer asks for public/private IPs — the requirement is
 * declared per-product on the product edit page (require_public_ip /
 * require_private_ip) and the actual lease happens when the order is
 * activated (provisioning), pulling from the IPAM pool.
 *
 * - orders.public_ip / orders.private_ip are dropped: the lease now lives
 *   on the polymorphic ip_addresses pair (assigned_to_type=HostingAccount),
 *   read through order -> hostingAccount -> ip lease.
 *
 * NOTE: the legacy type-based backfill (products WHERE type IN
 * ('vps','dedicated') -> require_public_ip=true) was REMOVED because
 * products.type no longer exists (product-type-to-groups refactor).
 * IP requirements are now declared per-product via require_public_ip /
 * require_private_ip flags; seeders set those flags explicitly on the
 * product rows that need them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['public_ip', 'private_ip']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('public_ip', 45)->nullable()->after('domain_name');
            $table->string('private_ip', 45)->nullable()->after('public_ip');
        });
    }
};
