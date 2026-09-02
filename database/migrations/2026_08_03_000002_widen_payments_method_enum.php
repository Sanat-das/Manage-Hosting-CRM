<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widens the payments.method enum to cover the registered payment gateway
 * codes plus manual bookkeeping methods. Additive only — existing rows keep
 * their values (same treatment as the domains.status enum widening).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('method', ['razorpay', 'stripe', 'paypal', 'bank_transfer', 'cash', 'cheque', 'wallet', 'manual', 'credit', 'other'])
                ->default('razorpay')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('method', ['razorpay', 'bank_transfer', 'cash', 'cheque', 'credit', 'other'])
                ->default('razorpay')
                ->change();
        });
    }
};
