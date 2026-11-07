<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_event_settings', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 100);
            $table->string('audience', 50);
            $table->string('channel', 50)->default('database');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['event_key', 'audience', 'channel'], 'notification_event_settings_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_event_settings');
    }
};
