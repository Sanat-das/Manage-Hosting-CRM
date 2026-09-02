<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ip_address');
            $table->enum('panel_type', ['cpanel', 'plesk', 'directadmin', 'custom'])->default('cpanel');
            $table->string('api_url')->nullable();
            $table->string('api_key')->nullable();
            $table->string('api_username')->nullable();
            $table->unsignedInteger('max_accounts')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key')->unique();
            $table->text('setting_value')->nullable();
            $table->string('group', 50)->default('general');
            $table->timestamps();
            $table->index('setting_key');
            $table->index('group');
        });

        DB::table('settings')->insert([
            ['setting_key' => 'company_name', 'setting_value' => 'Hosting Company', 'group' => 'general'],
            ['setting_key' => 'company_email', 'setting_value' => 'admin@localhost.com', 'group' => 'general'],
            ['setting_key' => 'company_phone', 'setting_value' => '', 'group' => 'general'],
            ['setting_key' => 'company_address', 'setting_value' => '', 'group' => 'general'],
            ['setting_key' => 'currency', 'setting_value' => 'INR', 'group' => 'billing'],
            ['setting_key' => 'tax_rate', 'setting_value' => '18', 'group' => 'billing'],
            ['setting_key' => 'invoice_prefix', 'setting_value' => 'INV-', 'group' => 'billing'],
            ['setting_key' => 'invoice_next_number', 'setting_value' => '1', 'group' => 'billing'],
            ['setting_key' => 'ticket_prefix', 'setting_value' => 'TKT-', 'group' => 'support'],
            ['setting_key' => 'ticket_next_number', 'setting_value' => '1', 'group' => 'support'],
            ['setting_key' => 'smtp_host', 'setting_value' => '', 'group' => 'email'],
            ['setting_key' => 'smtp_port', 'setting_value' => '587', 'group' => 'email'],
            ['setting_key' => 'smtp_username', 'setting_value' => '', 'group' => 'email'],
            ['setting_key' => 'smtp_password', 'setting_value' => '', 'group' => 'email'],
            ['setting_key' => 'smtp_encryption', 'setting_value' => 'tls', 'group' => 'email'],
            ['setting_key' => 'timezone', 'setting_value' => 'Asia/Kolkata', 'group' => 'general'],
            ['setting_key' => 'date_format', 'setting_value' => 'Y-m-d', 'group' => 'general'],
        ]);

        Schema::create('gst_settings', function (Blueprint $table) {
            $table->id();
            $table->string('gstin', 15)->nullable();
            $table->string('legal_name')->nullable();
            $table->string('state_code', 2)->default('27');
            $table->string('state_name')->default('Maharashtra');
            $table->decimal('cgst_rate', 5, 2)->default(9.00);
            $table->decimal('sgst_rate', 5, 2)->default(9.00);
            $table->decimal('igst_rate', 5, 2)->default(18.00);
            $table->string('hsn_code')->default('998314');
            $table->string('sac_code')->default('998314');
            $table->boolean('enabled')->default(false);
            $table->enum('tax_mode', ['global', 'per_product', 'mixed'])->default('global');
            $table->timestamps();
        });

        DB::table('gst_settings')->insert([
            'id' => 1,
            'enabled' => 0,
            'tax_mode' => 'global',
        ]);

        Schema::create('catalog_products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->text('description')->nullable();
            $table->enum('product_type', ['shared_hosting', 'reseller', 'vps', 'dedicated', 'domain', 'addon', 'bundle', 'license', 'other'])->default('shared_hosting');
            $table->enum('provisioning_method', ['manual', 'cpanel', 'plesk', 'directadmin', 'proxmox', 'vmware', 'hyperv', 'solusvm', 'virtualizor', 'docker', 'kubernetes', 'api', 'custom_script'])->default('manual');
            $table->json('provisioning_config')->nullable();
            $table->enum('billing_model', ['one_time', 'recurring', 'usage_based', 'tiered'])->default('recurring');
            $table->boolean('require_domain')->default(false);
            $table->boolean('show_in_order')->default(true);
            $table->boolean('only_admin')->default(false);
            $table->integer('sort_order')->default(0);
            $table->enum('status', ['active', 'inactive', 'retired'])->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_products');
        Schema::dropIfExists('gst_settings');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('servers');
    }
};
