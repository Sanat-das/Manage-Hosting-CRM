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

    /**
     * Generate the next number scoped to the Indian financial year (Apr–Mar).
     *
     * Format: {PREFIX}-{FY}-{seq padded} — e.g. INV-2627-00001. The sequence
     * key is "{$prefix}-{FY}" (e.g. INV-2627), so each financial year starts
     * at 00001. Uses the same row-lock transaction pattern as next().
     */
    public function nextForFinancialYear(string $prefix, \DateTimeInterface $date, int $pad = 5): string
    {
        $fy = self::financialYearLabel($date);
        $key = "{$prefix}-{$fy}";

        $seq = DB::transaction(function () use ($key) {
            $row = DB::table('sequences')
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                DB::table('sequences')->insert([
                    'key' => $key,
                    'value' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return 1;
            }

            DB::table('sequences')
                ->where('key', $key)
                ->update(['value' => $row->value + 1, 'updated_at' => now()]);

            return $row->value + 1;
        });

        return "{$prefix}-{$fy}-".str_pad((string) $seq, $pad, '0', STR_PAD_LEFT);
    }

    /**
     * Indian financial-year label for the given date: month >= 4 →
     * substr(Y,2).substr(Y+1,2), else substr(Y-1,2).substr(Y,2).
     * 2026-03-31 → '2526', 2026-04-01 → '2627'.
     */
    public static function financialYearLabel(\DateTimeInterface $date): string
    {
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');

        if ($month >= 4) {
            return substr((string) $year, 2).substr((string) ($year + 1), 2);
        }

        return substr((string) ($year - 1), 2).substr((string) $year, 2);
    }
}
