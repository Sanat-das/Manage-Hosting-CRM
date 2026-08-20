<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Race-safe sequential number generator (gap-fillup T1.3).
 *
 * Produces reference-style display numbers (ORD-2026-00001) from a single
 * keyed counter row, read-and-incremented under a row lock inside one
 * transaction. Two concurrent `next()` calls always receive distinct numbers
 * (the row lock serializes them), unlike the previous count()+1 + exists
 * recheck which could double-assign under a race.
 */
class OrderNumberService
{
    /**
     * Generate the next number for the given prefix.
     *
     * Format: {PREFIX}-{YEAR}-{seq padded to 5} — e.g. ORD-2026-00001.
     */
    public function next(string $prefix = 'ORD'): string
    {
        $year = date('Y');

        $seq = DB::transaction(function () use ($prefix) {
            $row = DB::table('sequences')
                ->where('key', $prefix)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                DB::table('sequences')->insert([
                    'key' => $prefix,
                    'value' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return 1;
            }

            DB::table('sequences')
                ->where('key', $prefix)
                ->update(['value' => $row->value + 1, 'updated_at' => now()]);

            return $row->value + 1;
        });

        return "{$prefix}-{$year}-".str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
