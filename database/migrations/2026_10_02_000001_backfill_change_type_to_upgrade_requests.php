<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('upgrade_requests')
            ->whereNull('change_type')
            ->update([
                'change_type' => DB::raw("CASE
                    WHEN payable > 0 THEN 'upgrade'
                    WHEN credit_amount > 0 THEN 'downgrade'
                    ELSE 'equal'
                END"),
            ]);
    }

    public function down(): void
    {
        DB::table('upgrade_requests')
            ->whereNotNull('change_type')
            ->update(['change_type' => null]);
    }
};
