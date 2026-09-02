<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('total', 12, 2)->default(0);
            $table->enum('status', ['pending', 'active', 'suspended', 'cancelled', 'terminated'])->default('pending');
            $table->string('domain_name')->nullable();
            $table->text('notes')->nullable();
            $table->date('next_billing_date')->nullable();
            $table->date('last_billing_date')->nullable();
            $table->timestamps();
            $table->index('customer_id');
            $table->index('product_id');
            $table->index('status');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_name');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamps();
            $table->index('order_id');
            $table->index('product_id');
        });

        Schema::create('hosting_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('server_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('username');
            $table->string('domain')->nullable();
            $table->unsignedInteger('disk_quota')->default(0);
            $table->unsignedInteger('disk_used')->default(0);
            $table->unsignedInteger('bandwidth_quota')->default(0);
            $table->unsignedInteger('bandwidth_used')->default(0);
            $table->string('panel_account_id')->nullable();
            $table->string('username_prefix')->nullable();
            $table->string('password')->nullable();
            $table->enum('status', ['pending', 'active', 'suspended', 'terminated'])->default('pending');
            $table->text('suspended_reason')->nullable();
            $table->dateTime('suspended_at')->nullable();
            $table->timestamps();
            $table->index('customer_id');
            $table->index('product_id');
            $table->index('server_id');
            $table->index('status');
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('name');
            $table->enum('type', ['register', 'transfer', 'existing'])->default('register');
            $table->string('registrar_id')->nullable();
            $table->string('registrar')->nullable();
            $table->date('registration_date')->nullable();
            $table->integer('registration_period')->default(1);
            $table->date('expiry_date')->nullable();
            $table->date('next_due_date')->nullable();
            $table->date('next_invoice_date')->nullable();
            $table->decimal('recurring_amount', 10, 2)->nullable();
            $table->string('payment_method')->nullable();
            $table->string('subscription_id')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->boolean('privacy_enabled')->default(false);
            $table->text('nameservers')->nullable();
            $table->text('dns_records')->nullable();
            $table->string('auth_code')->nullable();
            $table->boolean('lock_status')->default(true);
            $table->boolean('dns_management')->default(false);
            $table->boolean('email_forwarding')->default(false);
            $table->boolean('id_protection')->default(false);
            $table->enum('status', ['pending', 'active', 'expired', 'cancelled', 'transferred', 'pending_transfer', 'redemption'])->default('pending');
            $table->timestamps();
            $table->index('customer_id');
            $table->index('name');
            $table->index('expiry_date');
            $table->index('status');
        });

        Schema::create('registrar_settings', function (Blueprint $table) {
            $table->id();
            $table->string('registrar');
            $table->string('setting_key');
            $table->text('setting_value')->nullable();
            $table->timestamps();
            $table->unique(['registrar', 'setting_key']);
        });

        Schema::create('domain_search_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('domain_name');
            $table->json('results')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->index('customer_id');
            $table->index('domain_name');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_search_logs');
        Schema::dropIfExists('registrar_settings');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('hosting_accounts');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
