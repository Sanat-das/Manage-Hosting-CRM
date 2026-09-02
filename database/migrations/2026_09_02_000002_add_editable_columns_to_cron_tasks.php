<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cron_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('cron_tasks', 'expression')) {
                $table->string('expression', 100)->nullable()->after('enabled');
            }
            if (! Schema::hasColumn('cron_tasks', 'timezone')) {
                $table->string('timezone', 64)->nullable()->after('expression');
            }
            if (! Schema::hasColumn('cron_tasks', 'description')) {
                $table->string('description', 255)->nullable()->after('timezone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cron_tasks', function (Blueprint $table) {
            $columns = [];

            if (Schema::hasColumn('cron_tasks', 'expression')) {
                $columns[] = 'expression';
            }
            if (Schema::hasColumn('cron_tasks', 'timezone')) {
                $columns[] = 'timezone';
            }
            if (Schema::hasColumn('cron_tasks', 'description')) {
                $columns[] = 'description';
            }

            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
