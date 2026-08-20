<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_consent_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('contact_type')->nullable();
            $table->enum('consent_status', ['opt_in', 'opt_out'])->default('opt_in');
            $table->string('source')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_consent_log');
    }
};
