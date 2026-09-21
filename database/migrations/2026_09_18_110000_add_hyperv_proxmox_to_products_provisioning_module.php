<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->enum('provisioning_module', ['manual', 'cpanel', 'plesk', 'directadmin', 'virtualizor', 'hyperv', 'proxmox', 'custom'])->default('manual')->change();
        });
    }

    public function down(): void
    {
        // Move any hyperv/proxmox rows back to custom before narrowing enum
        \Illuminate\Support\Facades\DB::table('products')->whereIn('provisioning_module', ['hyperv', 'proxmox'])->update(['provisioning_module' => 'custom']);
        Schema::table('products', function (Blueprint $table) {
            $table->enum('provisioning_module', ['manual', 'cpanel', 'plesk', 'directadmin', 'virtualizor', 'custom'])->default('manual')->change();
        });
    }
};
