<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per weekday, seven rows forever.
 *
 * A row per day rather than a JSON blob on `chat_settings`: the admin form is
 * seven rows of three fields, validation is ordinary per-field validation, and
 * a half-written blob cannot take the whole schedule with it. `day_of_week` is
 * unique, so the seven rows cannot become eight.
 *
 * 0 = Sunday .. 6 = Saturday, matching Carbon's `dayOfWeek`, so the lookup is
 * an array index and not a translation table that can drift.
 *
 * `closes_at` earlier than or equal to `opens_at` means the window crosses
 * midnight (22:00-02:00). ChatOfficeHours handles that case explicitly; the
 * alternative is a schedule that silently evaluates to "never open" for any
 * team working nights.
 *
 * The rows are created here rather than in a seeder because the in-app updater
 * runs `migrate --force` and never `db:seed` — a settings page with no rows to
 * render is the failure mode that produces an empty form on every existing
 * install. Mon-Fri 09:00-18:00 is a starting point, not a policy: nothing reads
 * these until `chat_settings.enforce_office_hours` is switched on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_office_hours', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('day_of_week')->unique();
            $table->boolean('is_open')->default(false);
            $table->time('opens_at')->default('09:00:00');
            $table->time('closes_at')->default('18:00:00');
            $table->timestamps();
        });

        $now = now();

        \Illuminate\Support\Facades\DB::table('chat_office_hours')->insert(
            array_map(static fn (int $day): array => [
                'day_of_week' => $day,
                // Saturday (6) and Sunday (0) start closed.
                'is_open' => $day >= 1 && $day <= 5,
                'opens_at' => '09:00:00',
                'closes_at' => '18:00:00',
                'created_at' => $now,
                'updated_at' => $now,
            ], range(0, 6))
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_office_hours');
    }
};
