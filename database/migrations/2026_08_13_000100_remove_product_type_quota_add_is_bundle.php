<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn(['type']);
            $table->dropColumn([
                'quota_disk',
                'quota_bandwidth',
                'quota_email',
                'quota_database',
                'quota_cpu_cores',
                'quota_cpu_speed',
                'quota_ram',
                'quota_ips',
                'quota_ftp_accounts',
                'quota_subdomains',
            ]);
            $table->boolean('is_bundle')->default(false)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_bundle']);
            $table->enum('type', ['shared_hosting', 'reseller', 'vps', 'dedicated', 'domain', 'addon', 'bundle', 'hosting', 'other'])->default('shared_hosting');
            $table->unsignedInteger('quota_disk')->default(0);
            $table->unsignedInteger('quota_bandwidth')->default(0);
            $table->unsignedInteger('quota_email')->default(0);
            $table->unsignedInteger('quota_database')->default(0);
            $table->unsignedInteger('quota_cpu_cores')->default(0);
            $table->unsignedInteger('quota_cpu_speed')->default(0);
            $table->unsignedInteger('quota_ram')->default(0);
            $table->unsignedInteger('quota_ips')->default(0);
            $table->unsignedInteger('quota_ftp_accounts')->default(0);
            $table->unsignedInteger('quota_subdomains')->default(0);
            $table->index('type');
        });
    }
};
