<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->morphs('preferrable');
            $table->string('type');
            $table->string('channel')->default('database');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            // Explicit short name: the auto-generated one
            // (notification_preferences_preferrable_type_preferrable_id_type_channel_unique)
            // is 74 characters — over MariaDB's 64-character identifier limit.
            $table->unique(['preferrable_type', 'preferrable_id', 'type', 'channel'], 'notification_preferences_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
