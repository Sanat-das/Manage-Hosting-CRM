<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The remote control-panel account backing a service instance.
 *
 * One table for every panel (cPanel, Plesk, DirectAdmin, Virtualizor) with a
 * `panel` discriminator, rather than a table per module. The row records
 * something that exists on someone else's server: it must outlive deactivating
 * or uninstalling the module, which a module-owned table would not. It also
 * keeps the four modules to a driver each instead of four copies of the same
 * schema, model and lifecycle bookkeeping.
 *
 * Supersedes the short-lived module-owned `cpanel_accounts` table, dropped
 * here — it shipped in the same development cycle and never held real rows.
 *
 * `password_encrypted` uses Laravel's `encrypted` cast. It is deliberately not
 * a hash: the panel password has to be recoverable to be delivered or shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_instance_id');
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->string('panel', 32)->index();
            $table->string('username', 64);
            $table->string('domain')->nullable();
            $table->text('password_encrypted')->nullable();
            $table->string('plan')->nullable();
            $table->string('external_id')->nullable();
            $table->json('meta')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->timestamps();

            // One remote account per service. Re-provisioning after a failure
            // updates the existing row rather than adding a second.
            $table->unique('service_instance_id');
        });

        Schema::dropIfExists('cpanel_accounts');
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_accounts');
    }
};
