<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('catalog_product_id')->constrained('catalog_products')->restrictOnDelete();
            $table->unsignedInteger('order_id')->nullable();
            $table->unsignedInteger('server_id')->nullable();
            $table->string('service_tag')->unique();
            $table->string('username');
            $table->string('domain')->nullable();
            $table->string('password_hash')->nullable();
            $table->string('provisioning_method', 50)->nullable();
            $table->json('provisioning_config')->nullable();
            $table->unsignedInteger('provisioning_adapter_id')->nullable();
            $table->string('external_id')->nullable();
            $table->enum('status', ['pending','provisioning','active','suspended','terminated','cancelled'])->default('pending');
            $table->text('suspension_reason')->nullable();
            $table->dateTime('suspended_at')->nullable();
            $table->dateTime('terminated_at')->nullable();
            $table->date('next_billing_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('customer_id');
            $table->index('catalog_product_id');
            $table->index('status');
            $table->index('next_billing_date');
            $table->index('deleted_at');
        });

        Schema::create('usage_records', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('service_id')->constrained('service_instances')->restrictOnDelete();
            $table->foreignId('resource_type_id')->constrained('resource_types')->restrictOnDelete();
            $table->enum('metric', ['disk_bytes','bandwidth_bytes','cpu_seconds','memory_bytes','iops','network_packets','license_seat_hours']);
            $table->decimal('value', 20, 4)->default(0);
            $table->string('unit', 32);
            $table->dateTime('recorded_at');
            $table->enum('source', ['adapter_poll','api_webhook','manual','estimated'])->default('estimated');
            $table->date('billing_period_start')->nullable();
            $table->date('billing_period_end')->nullable();
            $table->boolean('invoiced')->default(false);
            $table->unsignedInteger('invoice_item_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['service_id', 'recorded_at']);
            $table->index(['billing_period_start', 'billing_period_end']);
            $table->index('invoiced');
        });

        Schema::create('subscription_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('service_instances')->restrictOnDelete();
            $table->enum('billing_cycle', ['free','one_time','hourly','daily','monthly','quarterly','semi_annual','annual','biennial','triennial','usage_based','custom'])->default('monthly');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('next_invoice_date')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('INR');
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->enum('status', ['active','expired','cancelled','upgraded','downgraded'])->default('active');
            $table->unsignedInteger('parent_period_id')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->index(['service_id', 'status']);
            $table->index('next_invoice_date');
        });

        Schema::create('subscription_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('service_instances')->restrictOnDelete();
            $table->unsignedInteger('from_subscription_period_id')->nullable();
            $table->unsignedInteger('to_subscription_period_id')->nullable();
            $table->enum('change_type', ['upgrade','downgrade','renewal','cancellation','addon']);
            $table->decimal('credit_amount', 12, 2)->default(0);
            $table->decimal('charge_amount', 12, 2)->default(0);
            $table->integer('proration_days')->nullable();
            $table->unsignedInteger('invoice_id')->nullable();
            $table->date('effective_date');
            $table->timestamp('created_at')->nullable();

            $table->index(['service_id', 'change_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_changes');
        Schema::dropIfExists('subscription_periods');
        Schema::dropIfExists('usage_records');
        Schema::dropIfExists('service_instances');
    }
};
