<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['shared_hosting', 'reseller', 'vps', 'dedicated', 'domain', 'addon', 'bundle', 'hosting', 'other'])->default('shared_hosting');
            $table->unsignedBigInteger('product_group_id')->nullable()->index();
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->enum('billing_cycle', ['monthly', 'quarterly', 'semi_annual', 'annual', 'biennial', 'one_time'])->default('monthly');
            $table->decimal('setup_fee', 12, 2)->default(0);
            $table->enum('provisioning_module', ['none', 'cpanel', 'plesk', 'directadmin', 'virtualizor', 'custom'])->default('none');
            $table->unsignedBigInteger('server_group_id')->nullable();
            $table->unsignedBigInteger('welcome_email_template_id')->nullable();
            $table->boolean('require_domain')->default(true);
            $table->boolean('show_in_order')->default(true);
            $table->boolean('show_in_affiliate')->default(true);
            $table->boolean('only_admin')->default(false);
            $table->integer('sort_order')->default(0);
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
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->boolean('gst_enabled')->default(false);
            $table->decimal('gst_rate', 5, 2)->nullable();
            $table->enum('gst_type', ['standard', 'exempt', 'reverse_charge'])->default('standard');
            $table->decimal('cgst_rate', 5, 2)->nullable();
            $table->decimal('sgst_rate', 5, 2)->nullable();
            $table->decimal('igst_rate', 5, 2)->nullable();
            $table->timestamps();
            $table->index('type');
            $table->index('status');
            $table->index('sort_order');
        });

        Schema::create('product_pricing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->enum('billing_cycle', ['free', 'one_time', 'monthly', 'quarterly', 'semi_annual', 'annual', 'biennial', 'triennial']);
            $table->decimal('setup_fee', 12, 2)->default(0);
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('promo_price', 12, 2)->nullable();
            $table->date('promo_start')->nullable();
            $table->date('promo_end')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'billing_cycle']);
        });

        Schema::create('product_option_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->enum('type', ['dropdown', 'radio', 'quantity'])->default('dropdown');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->index('product_id');
        });

        Schema::create('product_option_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('option_group_id')->constrained('product_option_groups')->cascadeOnDelete();
            $table->string('label');
            $table->integer('sort_order')->default(0);
        });

        Schema::create('product_option_pricing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('option_value_id')->constrained('product_option_values')->cascadeOnDelete();
            $table->enum('billing_cycle', ['free', 'one_time', 'monthly', 'quarterly', 'semi_annual', 'annual', 'biennial', 'triennial']);
            $table->decimal('price_modifier', 12, 2)->default(0);
            $table->unique(['option_value_id', 'billing_cycle']);
        });

        Schema::create('product_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('billing_cycle', ['one_time', 'monthly', 'quarterly', 'semi_annual', 'annual'])->default('one_time');
            $table->decimal('setup_fee', 12, 2)->default(0);
            $table->decimal('price', 12, 2)->default(0);
            $table->unsignedBigInteger('welcome_email_template_id')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        Schema::create('product_upgrades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('to_product_id')->constrained('products')->cascadeOnDelete();
            $table->enum('upgrade_type', ['upgrade', 'downgrade', 'both'])->default('both');
            $table->boolean('allowed')->default(true);
            $table->unique(['from_product_id', 'to_product_id']);
        });

        Schema::create('product_meta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'meta_key']);
        });

        Schema::create('server_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_group_id')->constrained('server_groups')->cascadeOnDelete();
            $table->unsignedBigInteger('server_id');
            $table->integer('priority')->default(0);
            $table->unique(['server_group_id', 'server_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_group_members');
        Schema::dropIfExists('product_meta');
        Schema::dropIfExists('product_upgrades');
        Schema::dropIfExists('product_addons');
        Schema::dropIfExists('product_option_pricing');
        Schema::dropIfExists('product_option_values');
        Schema::dropIfExists('product_option_groups');
        Schema::dropIfExists('product_pricing');
        Schema::dropIfExists('products');
    }
};
