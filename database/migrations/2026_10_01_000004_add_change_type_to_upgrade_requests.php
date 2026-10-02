<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upgrade_requests', function (Blueprint $table) {
            $table->string('change_type')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('upgrade_requests', function (Blueprint $table) {
            $table->dropColumn('change_type');
        });
    }
};
