<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_replies', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        // Recreate FK as nullable if needed (MySQL)
        try {
            $fk = collect(DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_NAME='ticket_replies' AND COLUMN_NAME='user_id' AND CONSTRAINT_NAME != 'PRIMARY' AND TABLE_SCHEMA=DATABASE()"))->pluck('CONSTRAINT_NAME')->first();
            if ($fk) {
                DB::statement("ALTER TABLE `ticket_replies` DROP FOREIGN KEY `{$fk}`");
                DB::statement("ALTER TABLE `ticket_replies` ADD CONSTRAINT `ticket_replies_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE");
            }
        } catch (\Throwable $e) {
        }
    }

    public function down(): void
    {
        // not reverting
    }
};
