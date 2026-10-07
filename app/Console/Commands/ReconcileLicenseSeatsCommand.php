<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\License;
use App\Services\Licenses\LicenseSeatReconciler;
use Illuminate\Console\Command;

/**
 * Reconcile every licence's seats_available against its active assignments.
 *
 * seats_available is derived state (seats minus assignments with
 * released_at IS NULL) but is otherwise only written at creation, so rows can
 * drift when assignments change outside the model events or when a seats edit
 * bypassed the controller. Reports each drifted licence and heals it; with
 * --dry-run it reports without writing. Idempotent: a clean row is left alone.
 */
class ReconcileLicenseSeatsCommand extends Command
{
    protected $signature = 'licenses:reconcile-seats
                            {--dry-run : List drifted licences without correcting seats_available}';

    protected $description = 'Reconcile licenses.seats_available against their active assignments';

    public function handle(LicenseSeatReconciler $reconciler): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $rows = [];
        $counts = $reconciler->reconcileAll($dryRun, function (License $license, array $result) use (&$rows): void {
            $rows[] = [
                $license->id,
                $license->license_key ?? $license->license_type,
                sprintf('%d → %d', $result['previous'], $result['expected']),
            ];
        });

        foreach ($rows as [$id, $label, $seats]) {
            $this->line("  #{$id} {$label}: {$seats}");
        }

        $this->info(sprintf(
            '%s: checked %d licences, %d drifted, %d %s.',
            $dryRun ? 'Dry run' : 'Reconciled',
            $counts['checked'],
            $counts['drifted'],
            $counts['healed'],
            $dryRun ? 'would be healed' : 'healed',
        ));

        return self::SUCCESS;
    }
}
