<?php

declare(strict_types=1);

namespace App\Services\Licenses;

use App\Models\License;

/**
 * Keeps licenses.seats_available in step with the licence's active assignments.
 *
 * seats_available is derived state: it must always equal `seats` minus the
 * assignments that still hold a seat (`released_at IS NULL`). The column is
 * otherwise written only at creation, so an assignment write (or a `seats`
 * edit that shrinks the pool) can leave it stale — or, when seats drops below
 * the number of active assignments, even invert the invariant. This service
 * recomputes the counter and can sweep every licence at once.
 */
class LicenseSeatReconciler
{
    /**
     * Recompute and persist seats_available for a single licence.
     *
     * @return array{previous:int,expected:int}
     */
    public function reconcile(License $license): array
    {
        $previous = (int) $license->seats_available;
        $expected = $this->expectedSeatsAvailable($license);

        if ($previous !== $expected) {
            $license->seats_available = $expected;
            $license->save();
        }

        return ['previous' => $previous, 'expected' => $expected];
    }

    /**
     * Reconcile every licence.
     *
     * With $dryRun the drift is reported but nothing is written, and `healed`
     * stays 0. $onDrift, when given, is invoked for each drifted licence with
     * the licence and its previous/expected counts.
     *
     * @param  callable(License, array{previous:int,expected:int}):void|null  $onDrift
     * @return array{checked:int,drifted:int,healed:int}
     */
    public function reconcileAll(bool $dryRun = false, ?callable $onDrift = null): array
    {
        $checked = 0;
        $drifted = 0;
        $healed = 0;

        License::query()->chunkById(200, function ($licenses) use ($dryRun, $onDrift, &$checked, &$drifted, &$healed): void {
            foreach ($licenses as $license) {
                $checked++;

                $result = $dryRun
                    ? $this->drift($license)
                    : $this->reconcile($license);

                if ($result['previous'] === $result['expected']) {
                    continue;
                }

                $drifted++;

                if (! $dryRun) {
                    $healed++;
                }

                if ($onDrift !== null) {
                    $onDrift($license, $result);
                }
            }
        });

        return ['checked' => $checked, 'drifted' => $drifted, 'healed' => $healed];
    }

    /**
     * Where seats_available sits versus where it should be, without writing.
     *
     * @return array{previous:int,expected:int}
     */
    public function drift(License $license): array
    {
        return [
            'previous' => (int) $license->seats_available,
            'expected' => $this->expectedSeatsAvailable($license),
        ];
    }

    /**
     * seats minus the assignments that still hold a seat, floored at zero.
     */
    public function expectedSeatsAvailable(License $license): int
    {
        $activeAssignments = $license->assignments()
            ->whereNull('released_at')
            ->count();

        return max(0, (int) $license->seats - $activeAssignments);
    }
}
