<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_option_group_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('option_group_id')->constrained('product_option_groups')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['product_id', 'option_group_id']);
        });

        if (DB::table('product_option_groups')->whereNotNull('product_id')->exists()) {
            DB::table('product_option_group_product')->insertUsing(
                ['product_id', 'option_group_id', 'created_at', 'updated_at'],
                DB::table('product_option_groups')
                    ->select('product_id', 'id')
                    ->selectRaw('NOW()')
                    ->selectRaw('NOW()')
                    ->whereNotNull('product_id')
            );
        }

        Schema::table('product_option_groups', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropIndex(['product_id']);
            $table->dropColumn('product_id');
            $table->enum('type', ['dropdown', 'radio', 'quantity', 'text', 'number', 'slider', 'checkbox'])->default('dropdown')->change();
            $table->decimal('input_min', 12, 2)->nullable();
            $table->decimal('input_max', 12, 2)->nullable();
            $table->decimal('input_step', 12, 2)->nullable();
            $table->string('input_placeholder')->nullable();
        });
    }

    public function down(): void
    {
        // Remap any seeded rows that use values outside the original 3-value enum
        // (text/number/slider/checkbox were added in up()). Without this, MySQL
        // aborts the enum change() below with "Data truncated for column 'type'".
        DB::table('product_option_groups')
            ->whereNotIn('type', ['dropdown', 'radio', 'quantity'])
            ->update(['type' => 'dropdown']);

        Schema::table('product_option_groups', function (Blueprint $table) {
            $table->dropColumn(['input_min', 'input_max', 'input_step', 'input_placeholder']);
            $table->enum('type', ['dropdown', 'radio', 'quantity'])->default('dropdown')->change();
            $table->unsignedBigInteger('product_id')->nullable()->after('id');
            $table->index('product_id');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::dropIfExists('product_option_group_product');
    }
};
