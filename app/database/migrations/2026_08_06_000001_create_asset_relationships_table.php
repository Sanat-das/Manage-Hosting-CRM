<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_relationships', function (Blueprint $table) {
            $table->id();
            $table->string('parent_kind', 100);
            $table->unsignedBigInteger('parent_id');
            $table->string('child_kind', 100);
            $table->unsignedBigInteger('child_id');
            $table->string('relationship_type', 50)->default('hosted_on');
            $table->string('label')->nullable();
            $table->integer('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['parent_kind', 'parent_id']);
            $table->index(['child_kind', 'child_id']);
            $table->unique([
                'parent_kind',
                'parent_id',
                'child_kind',
                'child_id',
                'relationship_type',
            ], 'asset_relationships_parent_child_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_relationships');
    }
};
