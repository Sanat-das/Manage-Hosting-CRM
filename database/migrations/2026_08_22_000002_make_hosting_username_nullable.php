<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Username is now module-managed (e.g. Windows Server RDP). Keep column for legacy but make it nullable
        // and backfill empties so future validation can be nullable.
        Schema::table('hosting_accounts', function (Blueprint $table) {
            $table->string('username')->nullable()->change();
            $table->string('username_prefix')->nullable()->change();
        });

        // Drop unique index on username if it exists (MySQL allows multiple NULLs, but legacy unique prevented nulls)
        try {
            $indexes = DB::select("SHOW INDEX FROM hosting_accounts WHERE Key_name = 'hosting_accounts_username_unique'");
            if (!empty($indexes)) {
                Schema::table('hosting_accounts', function (Blueprint $table) {
                    $table->dropUnique(['username']);
                });
            }
        } catch (\Throwable $e) {
            // SQLite / other drivers: ignore
        }
    }

    public function down(): void
    {
        Schema::table('hosting_accounts', function (Blueprint $table) {
            $table->string('username')->nullable(false)->change();
        });
        // Re-add unique if needed — not enforced on revert to avoid collisions
    }
};
