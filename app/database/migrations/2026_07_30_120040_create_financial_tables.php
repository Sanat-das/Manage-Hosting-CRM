<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->boolean('gst_enabled')->default(false);
            $table->decimal('cgst_rate', 5, 2)->nullable();
            $table->decimal('cgst_amount', 12, 2)->nullable();
            $table->decimal('sgst_rate', 5, 2)->nullable();
            $table->decimal('sgst_amount', 12, 2)->nullable();
            $table->decimal('igst_rate', 5, 2)->nullable();
            $table->decimal('igst_amount', 12, 2)->nullable();
            $table->enum('status', ['draft', 'sent', 'paid', 'overdue', 'void', 'cancelled'])->default('draft');
            $table->date('due_date');
            $table->dateTime('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('invoice_no');
            $table->index('customer_id');
            $table->index('order_id');
            $table->index('status');
            $table->index('due_date');
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->boolean('gst_enabled')->default(false);
            $table->decimal('gst_rate', 5, 2)->nullable();
            $table->enum('gst_type', ['standard', 'exempt', 'reverse_charge'])->nullable();
            $table->decimal('cgst_rate', 5, 2)->nullable();
            $table->decimal('cgst_amount', 12, 2)->nullable();
            $table->decimal('sgst_rate', 5, 2)->nullable();
            $table->decimal('sgst_amount', 12, 2)->nullable();
            $table->decimal('igst_rate', 5, 2)->nullable();
            $table->decimal('igst_amount', 12, 2)->nullable();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->decimal('amount', 12, 2)->default(0);
            $table->enum('method', ['razorpay', 'bank_transfer', 'cash', 'cheque', 'credit', 'other'])->default('razorpay');
            $table->string('gateway_id')->nullable();
            $table->string('transaction_id')->nullable();
            $table->enum('status', ['pending', 'completed', 'failed', 'refunded'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->index('invoice_id');
            $table->index('gateway_id');
            $table->index('status');
        });

        Schema::create('credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->enum('type', ['added', 'used', 'expired', 'refund']);
            $table->text('description')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->index('customer_id');
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->decimal('fee', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('INR');
            $table->string('payment_method')->nullable();
            $table->string('transaction_id')->nullable();
            $table->enum('status', ['pending', 'completed', 'failed', 'refunded', 'partially_refunded'])->default('completed');
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->index('customer_id');
            $table->index('invoice_id');
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('quote_no');
            $table->string('subject');
            $table->enum('stage', ['draft', 'delivered', 'accepted', 'rejected', 'dead'])->default('draft');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamps();
            $table->index('customer_id');
        });

        Schema::create('customer_wallet', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->enum('type', ['deposit', 'credit', 'debit', 'invoice_payment']);
            $table->decimal('amount', 12, 2)->default(0);
            $table->enum('balance_type', ['account', 'credit'])->default('account');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->index('customer_id');
            $table->index('type');
            $table->index('admin_user_id');
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_wallet');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('credits');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
