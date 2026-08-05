<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment gateway registry. Each row points at a driver FQCN
 * (App\Services\Payments\Drivers\*) and holds per-gateway credentials
 * (e.g. API keys) as JSON. Online gateways stay disabled until credentials
 * are configured.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('driver');
            $table->enum('mode', ['test', 'live'])->default('test');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('credentials')->nullable();
            $table->timestamps();
            $table->index('enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateways');
    }
};
