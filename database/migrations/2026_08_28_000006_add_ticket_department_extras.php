<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive extras for ticket_departments: description, signature, is_default.
     *
     * Follows the pattern in 2026_08_28_000004_create_ticket_departments_table.php
     * — additive only, no renames or drops. Backfill guarantees exactly one
     * default survives (support) without a booted hook; T6 enforces the invariant
     * afterwards via TicketDepartmentService.
     */
    public function up(): void
    {
        Schema::table('ticket_departments', function (Blueprint $table) {
            $table->text('description')->nullable()->after('email_address');
            $table->text('signature')->nullable()->after('description');
            $table->boolean('is_default')->default(false)->after('signature');

            $table->index('is_default');
        });

        // Backfill: every row starts with nulls and false, then ensure exactly
        // one default. If none is marked true (fresh install after the new
        // columns), promote the support row. If departments were already
        // seeded before this migration, they all land at false and support
        // becomes the single default.
        DB::table('ticket_departments')->update([
            'description' => null,
            'signature' => null,
            'is_default' => false,
        ]);

        $hasDefault = DB::table('ticket_departments')->where('is_default', true)->exists();

        if (! $hasDefault) {
            // Prefer the canonical support row; fall back to the first ordered
            // row if support was removed (defensive).
            $supportExists = DB::table('ticket_departments')->where('slug', 'support')->exists();

            if ($supportExists) {
                DB::table('ticket_departments')->where('slug', 'support')->update(['is_default' => true]);
            } else {
                $firstId = DB::table('ticket_departments')->orderBy('sort_order')->orderBy('id')->value('id');
                if ($firstId !== null) {
                    DB::table('ticket_departments')->where('id', $firstId)->update(['is_default' => true]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('ticket_departments', function (Blueprint $table) {
            // Drop the index before the column; SQLite tolerates dropIndex via
            // Doctrine DBAL, MySQL requires explicit name — use array form.
            $table->dropIndex(['is_default']);
            $table->dropColumn(['description', 'signature', 'is_default']);
        });
    }
};
