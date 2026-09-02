<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_pdf_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->string('file_name');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('file_path')->nullable();
            $table->string('mime_title')->nullable();
            $table->timestamps();
            $table->index('invoice_id');
            $table->index('customer_id');
            $table->index('generated_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_pdf_log');
    }
};
