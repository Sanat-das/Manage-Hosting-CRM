<?php

declare(strict_types=1);

namespace App\Services\Billing;

/**
 * ProrationCalculator — Pro-Rata Billing Calculation Service.
 *
 * Faithful port of Includes\Billing\ProrationCalculator (v5 reference).
 * Calculates prorated charges and credits for mid-cycle plan changes using
 * daily pro-rata based on the actual days in the billing period.
 */
class ProrationCalculator
{
    /**
     * Calculate proration for a mid-cycle upgrade/downgrade.
     *
     * Daily pro-rata:
     *  - totalDays   = start → end
     *  - usedDays    = start → change
     *  - remaining   = totalDays - usedDays
     *  - credit      = round(currentAmount * remaining / totalDays, 2)
     *  - upgrade     → charge = newAmount (FULL, no proration)
     *  - downgrade   → charge = round(newAmount * remaining / totalDays, 2)
     *  - totalDays <= 0 guard → credit 0, charge = newAmount (reference behavior)
     *
     * @param  string  $startDate   Period start date (Y-m-d).
     * @param  string  $endDate     Period end date (Y-m-d).
     * @param  string  $changeDate  Date of change (Y-m-d).
     * @param  string  $changeType  'upgrade' or 'downgrade'.
     * @return array{credit:float,charge:float,proration_days:int}
     */
    public static function calculateProration(
        float $currentAmount,
        float $newAmount,
        string $startDate,
        string $endDate,
        string $changeDate,
        string $changeType,
    ): array {
        $start = new \DateTimeImmutable($startDate);
        $end = new \DateTimeImmutable($endDate);
        $change = new \DateTimeImmutable($changeDate);

        $totalDays = (int) $start->diff($end)->days;
        $usedDays = (int) $start->diff($change)->days;
        $remainingDays = $totalDays - $usedDays;

        if ($totalDays <= 0) {
            return ['credit' => 0.0, 'charge' => $newAmount, 'proration_days' => 0];
        }

        // Credit for the unused portion of the current period.
        $credit = round($currentAmount * ($remainingDays / $totalDays), 2);

        // Charge for the new period (full for upgrade, prorated for downgrade).
        $charge = $changeType === 'upgrade'
            ? $newAmount
            : round($newAmount * ($remainingDays / $totalDays), 2);

        return [
            'credit' => $credit,
            'charge' => $charge,
            'proration_days' => $remainingDays,
        ];
    }

    /**
     * Calculate proration for an annual-to-monthly conversion.
     *
     * The monthly amount is derived first (annual / 12), then treated as a
     * mid-cycle downgrade of the annual period.
     *
     * @return array{credit:float,monthly_charge:float,proration_days:int}
     */
    public static function annualToMonthly(
        float $annualAmount,
        string $startDate,
        string $endDate,
        string $changeDate,
    ): array {
        $monthlyAmount = round($annualAmount / 12, 2);

        $result = self::calculateProration(
            $annualAmount,
            $monthlyAmount,
            $startDate,
            $endDate,
            $changeDate,
            'downgrade',
        );

        return [
            'credit' => $result['credit'],
            'monthly_charge' => $result['charge'],
            'proration_days' => $result['proration_days'],
        ];
    }
}
