<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Allow unknown sender tickets (no customer yet)
            $table->string('guest_email')->nullable()->after('customer_id');
            $table->string('guest_name')->nullable()->after('guest_email');
            // Make customer_id nullable for guest tickets
            $table->unsignedBigInteger('customer_id')->nullable()->change();
        });

        Schema::table('ticket_replies', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        // Drop FK if exists and re-add as nullable FK (Laravel's change() doesn't handle FK automatically on MySQL)
        // Use raw statement to handle existing FK constraint name varies
        try {
            $table = 'tickets';
            // MySQL: need to drop foreign key first if exists
            $fkName = collect(\Illuminate\Support\Facades\DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_NAME='tickets' AND COLUMN_NAME='customer_id' AND CONSTRAINT_NAME != 'PRIMARY' AND TABLE_SCHEMA=DATABASE()"))->pluck('CONSTRAINT_NAME')->first();
            if ($fkName) {
                \Illuminate\Support\Facades\DB::statement("ALTER TABLE `tickets` DROP FOREIGN KEY `{$fkName}`");
                \Illuminate\Support\Facades\DB::statement("ALTER TABLE `tickets` ADD CONSTRAINT `tickets_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE");
            }
        } catch (\Throwable $e) {
            // SQLite in tests doesn't need FK handling
        }
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['guest_email', 'guest_name']);
            // Note: making customer_id non-nullable again is not safe if guest rows exist
        });
    }
};
