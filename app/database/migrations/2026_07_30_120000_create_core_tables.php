<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('company')->nullable();
            $table->string('tax_id')->nullable();
            $table->decimal('balance', 12, 2)->default(0.00);
            $table->decimal('credit', 12, 2)->default(0.00);
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('product_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('product_groups')->nullOnDelete();
            $table->integer('sort_order')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->index('slug');
            $table->index('status');
            $table->index('sort_order');
        });

        Schema::create('server_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('load_balancing', ['round_robin', 'least_loaded', 'failover'])->default('round_robin');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->index('status');
        });

        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('subject');
            $table->text('body');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->index('name');
        });

        Schema::create('datacenters', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 10)->unique();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('timezone')->nullable();
            $table->enum('status', ['active', 'inactive', 'maintenance'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datacenters');
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('server_groups');
        Schema::dropIfExists('product_groups');
        Schema::dropIfExists('customers');
    }
};
