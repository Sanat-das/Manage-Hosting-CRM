<?php

namespace App\Console\Commands;

use App\Jobs\SendEmail;
use App\Models\User;
use App\Services\BusinessSummaryReport;
use App\Services\NotificationPreferenceService;
use App\Support\Notifications\NotificationMatrix;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Send a periodic business-summary report to admin/staff.
 *
 * Fills the plan's `reports:send-scheduled` gap (2 of the original 6 scheduled
 * commands). Aggregates headline metrics over the trailing period and emails
 * them to staff users via the SendEmail queue job. Run manually with
 * `--to=you@example.com` to override the recipient list, or with `--dry-run`
 * to print the report instead of sending it.
 *
 * The metrics, their week-over-week comparison and the plain-text body all
 * live in BusinessSummaryReport; this command only resolves the recipients
 * and dispatches the mail.
 */
class ScheduledReportsCommand extends Command
{
    protected $signature = 'reports:send-scheduled
        {--days=7 : Reporting window: last N complete days (ends yesterday). Valid range: 1-366}
        {--to=* : Override recipient emails}
        {--dry-run : Print the report to stdout instead of sending it}';

    protected $description = 'Email a business summary report to admin/staff';

    public function handle(): int
    {
        // The window is clamped in ONE place — BusinessSummaryReport's
        // constructor (1 to 366 days) — so the command, the subject line and
        // the printed period can never disagree. A non-numeric --days
        // coerces to 0 and is clamped to 1; it never fails a scheduled job.
        $days = (int) $this->option('days');

        $report = new BusinessSummaryReport($days);
        $subject = $report->subject();
        $body = $report->renderBody();

        if ($this->option('dry-run')) {
            // Written straight to the underlying stream: the console style
            // sanitises what it prints (it collapses runs of spaces), and this
            // report is a column-aligned plain-text table that would lose its
            // alignment on the way out.
            $this->output->getOutput()->writeln($body, OutputInterface::OUTPUT_RAW);
            $this->info('Dry run: no email dispatched.');

            return self::SUCCESS;
        }

        // Prefer explicit --to overrides (manual sends bypass the matrix);
        // otherwise notify matrix-enabled staff.
        $recipients = collect($this->option('to'))
            ->flatten()
            ->filter()
            ->whenEmpty(function () {
                return NotificationMatrix::staffPool('scheduled.report', 'mail')
                    ->filter(fn (User $user): bool => app(NotificationPreferenceService::class)->isEnabled($user, 'scheduled.report', 'mail'))
                    ->pluck('email');
            });

        $dispatched = 0;
        foreach ($recipients->unique() as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                SendEmail::dispatch($email, $subject, $body, templateName: 'scheduled_report');
                $dispatched++;
            }
        }

        $this->info('Scheduled report dispatched to '.$dispatched.' recipient(s).');
        $this->line('  New customers: '.$report->newCustomers.' | Orders: '.$report->newOrders.' | Revenue: '.$report->currencySymbol.number_format($report->revenueCollected, 2).' | Open tickets: '.$report->openTickets);

        return self::SUCCESS;
    }
}
