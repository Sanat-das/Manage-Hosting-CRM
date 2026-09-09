<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Give configurable option groups a machine-readable identity.
 *
 * A group was only ever a display name ("RAM", "Storage"), which is enough to
 * print but not to act on: nothing could map a group to a provisioning
 * parameter, and a value rendered as a bare number with no unit ("Storage:
 * 200"). Two columns fix both:
 *
 * - `key`  : stable slug (`ram`, `cpu`, `disk`, `backup`, `support`) used as
 *            the provisioning handle. Backfilled from the existing name, and
 *            auto-derived for new groups by ProductOptionGroup::booted().
 * - `unit` : unit of measure for display (`GB`, `vCPU`, `TB`). Null means the
 *            value speaks for itself, as every discrete value already does.
 *
 * `key` is unique so a module can resolve one group from it; the backfill
 * de-duplicates colliding slugs with a numeric suffix. Note this table has no
 * `updated_at` (see ProductOptionGroup), so the backfill touches only the two
 * new columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_option_groups', function (Blueprint $table) {
            $table->string('key', 60)->nullable()->after('name');
            $table->string('unit', 20)->nullable()->after('key');
        });

        $taken = [];

        foreach (DB::table('product_option_groups')->select('id', 'name')->orderBy('id')->get() as $group) {
            $base = Str::slug((string) $group->name) ?: 'option';
            $candidate = $base;
            $suffix = 1;

            while (isset($taken[$candidate])) {
                $candidate = $base.'-'.++$suffix;
            }

            $taken[$candidate] = true;

            DB::table('product_option_groups')->where('id', $group->id)->update(['key' => $candidate]);
        }

        Schema::table('product_option_groups', function (Blueprint $table) {
            $table->unique('key', 'pog_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('product_option_groups', function (Blueprint $table) {
            $table->dropUnique('pog_key_unique');
            $table->dropColumn(['key', 'unit']);
        });
    }
};
