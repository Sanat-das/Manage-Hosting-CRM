<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SSL certificate registry (fresh module — the reference CRM has no SSL
 * module, so this schema is designed from scratch to mirror the Session 2
 * customer/domain conventions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssl_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('domain_name');
            $table->enum('certificate_type', ['single', 'wildcard', 'multidomain'])->default('single');
            $table->string('provider')->nullable();
            $table->enum('status', ['active', 'pending', 'expired', 'revoked', 'failed'])->default('pending');
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('customer_id');
            $table->index('domain_name');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssl_certificates');
    }
};
