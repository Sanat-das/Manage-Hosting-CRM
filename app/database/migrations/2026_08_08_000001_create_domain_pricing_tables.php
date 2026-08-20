<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_pricing', function (Blueprint $table) {
            $table->id();
            $table->string('tld')->unique();
            $table->decimal('register_price', 10, 2)->default(0);
            $table->decimal('renew_price', 10, 2)->default(0);
            $table->decimal('transfer_price', 10, 2)->default(0);
            $table->string('currency', 10)->default('INR');
            $table->boolean('premium')->default(false);
            $table->boolean('enabled')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('domain_pricing_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_pricing_id')->constrained('domain_pricing')->cascadeOnDelete();
            $table->unsignedSmallInteger('term_years');
            $table->decimal('register_price', 10, 2)->default(0);
            $table->decimal('renew_price', 10, 2)->default(0);
            $table->timestamps();
            $table->unique(['domain_pricing_id', 'term_years']);
        });

        Schema::create('domain_sync_log', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('operation');
            $table->string('status');
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_sync_log');
        Schema::dropIfExists('domain_pricing_terms');
        Schema::dropIfExists('domain_pricing');
    }
};
