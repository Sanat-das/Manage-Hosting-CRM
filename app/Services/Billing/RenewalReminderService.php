<?php

namespace App\Services\Billing;

use App\Jobs\SendEmail;
use App\Models\EmailTemplate;
use App\Models\HostingAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RenewalReminderLog;
use App\Models\User;
use App\Notifications\ServiceRenewalReminderNotification;
use App\Services\Concerns\BuildsEmailVariables;
use App\Services\NotificationPreferenceService;
use App\Support\AppSettings;
use App\Support\Logging\AppLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Sends the "your service renews soon" reminders (client) and the daily
 * upcoming-renewals digest (admin).
 *
 * The sibling of InvoiceEmailService / OrderEmailService for the pre-renewal
 * window: the client half renders the admin-managed 'renewal_reminder'
 * template and raises an in-app notification, the admin half renders
 * 'renewal_reminder_admin' with every order reminded in the run so ops sees
 * the day's renewal load in one message.
 *
 * The client half is a LADDER, not a single window: `renewal_reminder_days`
 * holds comma-separated lead days (e.g. `30,15,7`), and each rung is sent
 * once per billing cycle. A run that has missed rungs (a late deploy, a
 * paused cron) catches up by sending only the most recent passed rung and
 * recording the older ones as suppressed, so a client never receives a burst
 * of stale reminders. The rung sent is resolved by RenewalsRemindCommand and
 * recorded here in `renewal_reminder_logs`; the legacy
 * `orders.renewal_reminder_for` / `renewal_reminder_sent_at` pair is still
 * stamped as a "last reminder" summary for installs and reports that read it.
 *
 * remind() returns whether the client EMAIL dispatched — not merely whether
 * anything happened. A missing/inactive template or a mail transport failure
 * therefore fails the run instead of reporting a success the customer never
 * received. The two faults differ in bookkeeping: a missing template is an
 * ops configuration fault, so the portal notification still goes out and the
 * rung is still recorded (fixing the template must not unleash a backlog of
 * reminders); a transport failure records nothing so the next run retries it.
 * The amount shown mirrors the renewal engine: unit_price × quantity over the
 * order's recurring lines only.
 *
 * Both halves are safe to invoke from anywhere, not just the CLI command: the
 * per-rung record is honoured and written here, so a caller that forgets to
 * pre-check still sends at most one reminder per rung, and the admin digest
 * carries its own once-per-day guard.
 */
final class RenewalReminderService
{
    use BuildsEmailVariables;

    /**
     * Normalise the `renewal_reminder_days` setting into a ladder.
     *
     * The setting is admin-entered free text (`30,15,7`), so this drops
     * blanks, non-digits and anything beyond a year, de-duplicates, and sorts
     * descending so the widest lead time comes first. An empty result means no
     * ladder is configured.
     *
     * @return list<int>
     */
    public static function parseOffsets(string $raw): array
    {
        $offsets = [];

        foreach (explode(',', $raw) as $token) {
            $token = trim($token);

            if ($token === '' || ! ctype_digit($token)) {
                continue;
            }

            $days = (int) $token;

            if ($days > 365) {
                continue;
            }

            $offsets[$days] = $days;
        }

        $offsets = array_values($offsets);
        rsort($offsets);

        return $offsets;
    }

    /**
     * Send one rung of the client renewal ladder: email plus in-app
     * notification.
     *
     * `$daysBefore` is the rung — the lead time before `next_billing_date`
     * (30, 15 or 7 in a `30,15,7` ladder). The command decides which rung is
     * due; this method only guarantees it goes out once.
     *
     * The return value means "the client EMAIL was dispatched" — it is the
     * signal RenewalsRemindCommand turns into a non-zero exit, so a run with a
     * broken template or mail transport can never look green.
     *
     * A missing/inactive template is a configuration fault: it is logged at
     * error level and returns false, but the portal notification still goes
     * out and the rung is still recorded, so the customer is not reminded
     * every day while ops fixes the template. A transport failure is retried
     * on the next run instead, so it deliberately records nothing even when
     * the in-app channel succeeded. An order with no linked user email
     * dispatches no email and therefore returns false.
     *
     * Idempotent for the rung: a rung already resolved for the order's
     * CURRENT cycle — sent or suppressed — is a no-op that reports success,
     * so a second caller neither re-sends nor counts as an error. An order
     * reminded under the legacy single-slot marker (no ladder rows yet for
     * the cycle) is likewise a no-op, which stops the first ladder run from
     * repeating a reminder the old code already sent in that cycle.
     */
    public function remind(Order $order, int $daysBefore): bool
    {
        $cycle = $order->next_billing_date?->toDateString();

        if ($cycle !== null && $this->alreadyResolved($order, $cycle, $daysBefore)) {
            AppLog::billing()->info('Renewal reminder skipped: this rung is already resolved.', [
                'order_id' => $order->id,
                'cycle_date' => $cycle,
                'days_before' => $daysBefore,
            ]);

            return true;
        }

        $emailDispatched = false;
        $emailTransportFailed = false;
        $templateMissing = false;
        $email = $order->customer?->user?->email;

        if (! $email) {
            AppLog::billing()->info('Renewal reminder email skipped: customer has no linked user email.', ['order_id' => $order->id]);
        } else {
            $template = EmailTemplate::query()
                ->where('name', 'renewal_reminder')
                ->where('status', 'active')
                ->first();

            if ($template === null) {
                // Configuration fault, not a transient one: the run must go
                // red, but the cycle marker is still stamped below so fixing
                // the template does not replay a backlog of reminders.
                $templateMissing = true;

                AppLog::billing()->error('Renewal reminder email not sent: template is missing or inactive.', [
                    'order_id' => $order->id,
                    'template' => 'renewal_reminder',
                ]);
            } else {
                [$subject, $body] = $this->renderTemplate($template, $this->buildVariables($order));

                $body = $this->stripAvailableVariablesFooter($body);
                $subject = $this->stripAvailableVariablesFooter($subject);

                $htmlBody = null;
                $plainBody = $body;

                if ($this->isHtml($template->body)) {
                    $htmlBody = $body;
                    $plainBody = $this->toPlainText($body);
                }

                try {
                    SendEmail::dispatch(
                        $email,
                        $subject,
                        $plainBody,
                        null,
                        [],
                        [],
                        [],
                        $htmlBody,
                        [],
                        templateName: 'renewal_reminder',
                        customerId: $order->customer_id,
                    );

                    $emailDispatched = true;
                } catch (\Throwable $e) {
                    // Presumed transient: the next run must retry, so the
                    // per-cycle marker is deliberately left unwritten.
                    $emailTransportFailed = true;

                    AppLog::billing()->error('Renewal reminder email failed to dispatch; the next run will retry.', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $user = $order->customer?->user;
        $inAppSent = false;

        // The per-user opt-out hook: a preference row decides, and the unmapped
        // 'renewal.upcoming' type defaults to enabled.
        if ($user instanceof User && app(NotificationPreferenceService::class)->isEnabled($user, 'renewal.upcoming')) {
            $user->notify(new ServiceRenewalReminderNotification($order));

            $inAppSent = true;
        }

        if (! $emailDispatched && ! $inAppSent) {
            AppLog::billing()->warning('Renewal reminder reached no channel.', [
                'order_id' => $order->id,
                'template_missing' => $templateMissing,
                'email_transport_failed' => $emailTransportFailed,
            ]);
        }

        // A transport failure must be retried. Every other outcome that reached
        // the customer — email dispatched, in-app only, or a missing template
        // we refuse to repeat daily — resolves the rung.
        //
        // Bookkeeping must never downgrade a dispatched reminder to an error:
        // the mail/notification already left, so a failed write is logged and
        // swallowed (the worst case is one extra reminder). The ladder row is
        // written FIRST so the rung is claimed even if the legacy summary write
        // fails afterwards.
        if (! $emailTransportFailed && ($emailDispatched || $inAppSent || $templateMissing)) {
            try {
                if ($cycle !== null) {
                    RenewalReminderLog::updateOrCreate(
                        [
                            'order_id' => $order->id,
                            'cycle_date' => $cycle,
                            'days_before' => $daysBefore,
                        ],
                        ['sent_at' => now()],
                    );
                }

                $order->forceFill([
                    'renewal_reminder_for' => $cycle,
                    'renewal_reminder_sent_at' => now(),
                ])->save();
            } catch (\Throwable $e) {
                AppLog::billing()->warning('Renewal reminder sent but its bookkeeping could not be written.', [
                    'order_id' => $order->id,
                    'days_before' => $daysBefore,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $emailDispatched;
    }

    /**
     * Whether this rung is already resolved for the order's current cycle.
     *
     * Two sources count: a ladder row for (cycle, rung) — delivered or
     * suppressed — and, on installs upgraded from the single-slot marker,
     * `renewal_reminder_for` matching the cycle while no ladder rows exist for
     * it. The legacy guard switches off as soon as the first rung of the cycle
     * is logged, so later rungs of the same cycle still fire.
     */
    private function alreadyResolved(Order $order, string $cycle, int $daysBefore): bool
    {
        $logs = $this->logsForCycle($order, $cycle);

        if ($logs->contains(fn (RenewalReminderLog $log): bool => (int) $log->days_before === $daysBefore)) {
            return true;
        }

        return $logs->isEmpty()
            && $order->renewal_reminder_for !== null
            && $order->renewal_reminder_for->toDateString() === $cycle;
    }

    /**
     * The ladder rows already resolved for one cycle.
     *
     * Prefers the eager-loaded relation so a command run does not query per
     * order; falls back to a scoped query for direct callers.
     *
     * @return Collection<int, RenewalReminderLog>
     */
    private function logsForCycle(Order $order, string $cycle): Collection
    {
        if ($order->relationLoaded('reminderLogs')) {
            return $order->reminderLogs
                ->filter(fn (RenewalReminderLog $log): bool => $log->cycle_date?->toDateString() === $cycle)
                ->values();
        }

        return $order->reminderLogs()
            ->whereDate('cycle_date', $cycle)
            ->get();
    }

    /**
     * Email the daily upcoming-renewals digest to admin/staff/support.
     *
     * One dispatch per recipient and never a Cc: the recipient list is
     * internal, and no admin needs to learn who else received the digest.
     * Returns the number of recipients the digest was dispatched to.
     *
     * Once per day: the guard lives in the cache rather than in a settings key
     * or a migration, so a second invocation of the run on the same calendar
     * day is a no-op (returns 0) instead of a duplicate digest.
     *
     * A missing/inactive template while there are orders to report is a
     * configuration fault, not an empty result: it throws so the caller can
     * fail the run. An empty collection and the already-sent-today guard still
     * report 0.
     *
     * @param  Collection<int, Order>  $orders  every order reminded in this run
     *
     * @throws RuntimeException when the digest is due but its template is missing or inactive
     */
    public function sendAdminDigest(Collection $orders): int
    {
        if ($orders->isEmpty()) {
            return 0;
        }

        $sentKey = 'renewal-admin-digest:'.today()->toDateString();

        if (Cache::has($sentKey)) {
            AppLog::billing()->info('Renewal reminder admin digest skipped: already sent today.', ['cache_key' => $sentKey]);

            return 0;
        }

        $recipients = $this->staffUsers()->pluck('email')->unique();

        if ($recipients->isEmpty()) {
            return 0;
        }

        $template = EmailTemplate::query()
            ->where('name', 'renewal_reminder_admin')
            ->where('status', 'active')
            ->first();

        if ($template === null) {
            // There ARE renewals to report, so this is a broken configuration
            // rather than an empty day: the caller must see it as a failure.
            throw new RuntimeException('Renewal reminder admin digest cannot be sent: template "renewal_reminder_admin" is missing or inactive.');
        }

        [$subject, $body] = $this->renderTemplate($template, $this->buildAdminVariables($orders));

        $body = $this->stripAvailableVariablesFooter($body);
        $subject = $this->stripAvailableVariablesFooter($subject);

        $htmlBody = null;
        $plainBody = $body;

        if ($this->isHtml($template->body)) {
            $htmlBody = $body;
            $plainBody = $this->toPlainText($body);
        }

        $dispatched = 0;

        foreach ($recipients as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            SendEmail::dispatch(
                $email,
                $subject,
                $plainBody,
                null,
                [],
                [],
                [],
                $htmlBody,
                [],
                templateName: 'renewal_reminder_admin',
            );

            $dispatched++;
        }

        // Only a digest that actually reached someone blocks the rest of the
        // day; a run that dispatched nothing may still succeed later.
        if ($dispatched > 0) {
            Cache::put($sentKey, true, now()->addDay());
        }

        return $dispatched;
    }

    /**
     * Active admin/staff/support users, resolved from both role sources.
     *
     * Recipient resolution must agree with access control: a user may hold
     * the admin/staff/support role through the RBAC pivot (adminlte_role_user)
     * even when the legacy `users.role` column is not one of those values
     * (e.g. an admin granted access via the pivot only). Filtering on the
     * column alone silently drops those users.
     *
     * @return Collection<int, User>
     */
    public function staffUsers(): Collection
    {
        return User::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereIn('role', ['admin', 'staff', 'support'])
                    ->orWhereHas('roles', static function ($roleQuery): void {
                        $roleQuery->whereIn('name', ['admin', 'staff', 'support']);
                    });
            })
            ->get();
    }

    /**
     * Email the daily overdue-services digest to admin/staff/support.
     *
     * The sibling of sendAdminDigest() for accounts that passed their renewal
     * due date unpaid: one dispatch per recipient and never a Cc, and the
     * once-per-day guard lives in the cache, so a second invocation of the
     * run on the same calendar day is a no-op (returns 0) instead of a
     * duplicate digest.
     *
     * A missing/inactive template while there are accounts to report is a
     * configuration fault, not an empty result: it throws so the caller can
     * fail the run. An empty collection and the already-sent-today guard
     * still report 0.
     *
     * @param  Collection<int, HostingAccount>  $services  every overdue account found in this run
     *
     * @throws RuntimeException when the digest is due but its template is missing or inactive
     */
    public function sendAdminOverdueDigest(Collection $services): int
    {
        if ($services->isEmpty()) {
            return 0;
        }

        $sentKey = 'overdue-admin-digest:'.today()->toDateString();

        if (Cache::has($sentKey)) {
            AppLog::billing()->info('Overdue service admin digest skipped: already sent today.', ['cache_key' => $sentKey]);

            return 0;
        }

        $recipients = $this->staffUsers()->pluck('email')->unique();

        if ($recipients->isEmpty()) {
            return 0;
        }

        $template = EmailTemplate::query()
            ->where('name', 'overdue_renewal_admin')
            ->where('status', 'active')
            ->first();

        if ($template === null) {
            // There ARE overdue services to report, so this is a broken
            // configuration rather than an empty day: the caller must see it
            // as a failure.
            throw new RuntimeException('Overdue service renewals admin digest cannot be sent: template "overdue_renewal_admin" is missing or inactive.');
        }

        [$subject, $body] = $this->renderTemplate($template, $this->buildOverdueAdminVariables($services));

        $body = $this->stripAvailableVariablesFooter($body);
        $subject = $this->stripAvailableVariablesFooter($subject);

        $htmlBody = null;
        $plainBody = $body;

        if ($this->isHtml($template->body)) {
            $htmlBody = $body;
            $plainBody = $this->toPlainText($body);
        }

        $dispatched = 0;

        foreach ($recipients as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            SendEmail::dispatch(
                $email,
                $subject,
                $plainBody,
                null,
                [],
                [],
                [],
                $htmlBody,
                [],
                templateName: 'overdue_renewal_admin',
            );

            $dispatched++;
        }

        // Only a digest that actually reached someone blocks the rest of the
        // day; a run that dispatched nothing may still succeed later.
        if ($dispatched > 0) {
            Cache::put($sentKey, true, now()->addDay());
        }

        return $dispatched;
    }

    /**
     * The canonical variable map for the client renewal reminder: shared
     * branding plus the order, its recurring lines and the renewal date.
     *
     * Aliases are deliberate — the templates disagree about whether the
     * customer is {{name}} or {{customer_name}}, and both must resolve rather
     * than leaking a raw placeholder.
     *
     * @return array<string, string>
     */
    public function buildVariables(Order $order): array
    {
        $branding = $this->brandingVariables();
        $appUrl = $branding['app_url'];
        $symbol = $branding['currency_symbol'];

        $customer = $order->customer;
        $customerName = $customer?->full_name ?? 'there';

        $orderNo = (string) $order->order_number;
        $orderNoHashed = $orderNo !== '' ? '#'.ltrim($orderNo, '#') : '';

        $items = $this->renewableItems($order);

        $productName = trim((string) ($order->product?->name ?? ''));

        if ($productName === '') {
            $firstItem = $items->first();
            $productName = $firstItem !== null ? $this->itemName($firstItem) : '';
        }

        if ($productName === '') {
            $productName = 'Service';
        }

        $servicesList = $items
            ->map(fn (OrderItem $item): string => $this->itemName($item).' × '.(int) ($item->quantity ?? 1))
            ->implode(', ');

        $amount = 0.0;

        foreach ($items as $item) {
            // Quantity-aware, like BillingService: the renewal line reflects the
            // qty actually ordered, not a hardcoded unit.
            $amount += round((float) $item->unit_price * (int) ($item->quantity ?? 1), 2);
        }

        $amountFormatted = number_format($amount, 2);

        $renewalDate = $order->next_billing_date?->format('M j, Y') ?? '';

        return $branding + [
            // Customer (aliases for template flexibility)
            'name' => $customerName,
            'customer_name' => $customerName,
            'client_name' => $customerName,
            'customer_email' => $customer?->user?->email ?? '',
            'customer_company' => $customer?->company ?? '',
            'customer_id' => $customer ? $customer->display_id : '',

            // Order core
            'order_no' => $orderNo,
            'order_number' => $orderNoHashed !== '' ? $orderNoHashed : $orderNo,
            'product_name' => $productName,
            'services_list' => $servicesList,

            // Renewal
            'renewal_date' => $renewalDate,
            'renewal_date_raw' => $order->next_billing_date?->toDateString() ?? '',
            'days_until_renewal' => (string) (int) today()->diffInDays($order->next_billing_date, false),

            // Amounts
            'amount' => $amountFormatted,
            'amount_formatted' => $symbol.$amountFormatted,

            // URLs
            'order_url' => $this->safeRoute('client.orders.show', $order->id, $appUrl.'/client/orders/'.$order->id),
            'invoices_url' => $this->safeRoute('client.invoices.index', null, $appUrl.'/client/invoices'),
        ];
    }

    /**
     * The variable map for the admin digest: shared branding plus the window
     * the digest covers and the rendered list of renewals it carries.
     *
     * @param  Collection<int, Order>  $orders
     * @return array<string, string>
     */
    public function buildAdminVariables(Collection $orders): array
    {
        // The digest labels the window it ACTUALLY lists, not the configured
        // default — `renewals:remind --days=…` can override the window, and a
        // subject line must never promise a different span than the rows below.
        $latestDate = $orders->max(fn (Order $order): string => $order->next_billing_date?->toDateString() ?? '');

        $ladder = self::parseOffsets((string) AppSettings::get('renewal_reminder_days', '7'));
        $configuredWindow = $ladder === [] ? 7 : max($ladder);

        $windowDays = $latestDate !== null && $latestDate !== ''
            ? (string) max(0, (int) today()->diffInDays($latestDate, false))
            : (string) max(0, $configuredWindow);

        return $this->brandingVariables() + [
            'renewal_count' => (string) $orders->count(),
            'window_days' => $windowDays,
            'renewals_html' => $this->renewalsHtml($orders),
        ];
    }

    /**
     * The variable map for the overdue admin digest: shared branding plus the
     * count of overdue services and the rendered table it carries.
     *
     * @param  Collection<int, HostingAccount>  $services
     * @return array<string, string>
     */
    public function buildOverdueAdminVariables(Collection $services): array
    {
        return $this->brandingVariables() + [
            'overdue_count' => (string) $services->count(),
            'overdue_html' => $this->overdueServicesHtml($services),
        ];
    }

    /**
     * One table row per order: order number, customer, service, renewal date.
     *
     * @param  Collection<int, Order>  $orders
     */
    private function renewalsHtml(Collection $orders): string
    {
        $cellStyle = 'padding:10px 14px;font-size:13px;color:#334155;border-bottom:1px solid #f1f5f9;';
        $headStyle = 'padding:10px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;text-align:left;';
        $rows = [];

        foreach ($orders as $order) {
            $firstItem = $order->items->first();
            $productName = trim((string) ($order->product?->name ?? ''));

            if ($productName === '') {
                $productName = $firstItem !== null ? $this->itemName($firstItem) : 'Service';
            }

            $rows[] = '<tr>'
                .'<td style="'.$cellStyle.'">'.e($order->order_number).'</td>'
                .'<td style="'.$cellStyle.'">'.e($order->customer?->full_name ?? '').'</td>'
                .'<td style="'.$cellStyle.'">'.e($productName).'</td>'
                .'<td style="'.$cellStyle.'">'.e($order->next_billing_date?->format('M j, Y') ?? '').'</td>'
                .'</tr>';
        }

        return '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">'
            ."\n".'<tr style="background:#f8fafc;">'
            .'<th style="'.$headStyle.'">Order</th>'
            .'<th style="'.$headStyle.'">Customer</th>'
            .'<th style="'.$headStyle.'">Service</th>'
            .'<th style="'.$headStyle.'">Renews</th>'
            .'</tr>'
            ."\n".implode("\n", $rows)
            ."\n".'</table>';
    }

    /**
     * One table row per overdue account: order number, customer, service, due
     * date. The due date is the effective one (the account's own, falling
     * back to the order's) and is rendered in a red-ish inline style.
     *
     * @param  Collection<int, HostingAccount>  $services
     */
    private function overdueServicesHtml(Collection $services): string
    {
        $cellStyle = 'padding:10px 14px;font-size:13px;color:#334155;border-bottom:1px solid #f1f5f9;';
        $headStyle = 'padding:10px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;text-align:left;';
        $dueCellStyle = 'padding:10px 14px;font-size:13px;font-weight:700;color:#dc2626;border-bottom:1px solid #f1f5f9;';
        $rows = [];

        foreach ($services as $account) {
            $due = $account->next_due_date ?? $account->order?->next_billing_date;
            $productName = trim((string) ($account->product?->name ?? ''));

            if ($productName === '') {
                $productName = 'Service';
            }

            $rows[] = '<tr>'
                .'<td style="'.$cellStyle.'">'.e($account->order?->order_number ?? '').'</td>'
                .'<td style="'.$cellStyle.'">'.e($account->customer?->full_name ?? '').'</td>'
                .'<td style="'.$cellStyle.'">'.e($productName).'</td>'
                .'<td style="'.$dueCellStyle.'">'.e($due?->format('M j, Y') ?? '').'</td>'
                .'</tr>';
        }

        return '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">'
            ."\n".'<tr style="background:#f8fafc;">'
            .'<th style="'.$headStyle.'">Order</th>'
            .'<th style="'.$headStyle.'">Customer</th>'
            .'<th style="'.$headStyle.'">Service</th>'
            .'<th style="'.$headStyle.'">Due</th>'
            .'</tr>'
            ."\n".implode("\n", $rows)
            ."\n".'</table>';
    }

    /**
     * The order's recurring lines — what a renewal will actually bill.
     *
     * Mirrors the engine's cycle test: the item's own billing_cycle wins,
     * falling back to the order's, and a cycle worth zero months (one_time)
     * never renews.
     *
     * @return Collection<int, OrderItem>
     */
    private function renewableItems(Order $order): Collection
    {
        return $order->items
            ->filter(function (OrderItem $item) use ($order): bool {
                $months = Order::CYCLE_MONTHS[$item->billing_cycle ?? $order->billing_cycle] ?? 1;

                return $months > 0;
            })
            ->values();
    }

    /**
     * Display name for an order line: the product name snapshotted on the
     * item, falling back to the related product when the snapshot is blank.
     */
    private function itemName(OrderItem $item): string
    {
        $snapshot = trim((string) $item->product_name);

        if ($snapshot !== '') {
            return $snapshot;
        }

        return (string) ($item->product?->name ?? '');
    }
}
