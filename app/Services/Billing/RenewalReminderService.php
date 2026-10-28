<?php

namespace App\Services\Billing;

use App\Jobs\SendEmail;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\OrderItem;
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
 * Sends the "your service renews soon" reminder (client) and the daily
 * upcoming-renewals digest (admin).
 *
 * The sibling of InvoiceEmailService / OrderEmailService for the pre-renewal
 * window: the client half renders the admin-managed 'renewal_reminder'
 * template and raises an in-app notification, the admin half renders
 * 'renewal_reminder_admin' with every order inside the run's window so ops
 * sees the day's renewal load in one message.
 *
 * remind() returns whether the client EMAIL dispatched — not merely whether
 * anything happened. A missing/inactive template or a mail transport failure
 * therefore fails the run instead of reporting a success the customer never
 * received. The two faults differ in bookkeeping: a missing template is an
 * ops configuration fault, so the portal notification still goes out and the
 * per-cycle marker is still written (fixing the template must not unleash a
 * backlog of reminders); a transport failure leaves the marker untouched so
 * the next run retries it. The amount shown mirrors the renewal engine:
 * unit_price × quantity over the order's recurring lines only.
 *
 * Both halves are safe to invoke from anywhere, not just the CLI command: the
 * per-cycle marker is honoured and written here, so a caller that forgets to
 * pre-check still sends at most one client reminder per cycle, and the admin
 * digest carries its own once-per-day guard.
 */
final class RenewalReminderService
{
    use BuildsEmailVariables;

    /**
     * Send the client renewal reminder: email plus in-app notification.
     *
     * The return value means "the client EMAIL was dispatched" — it is the
     * signal RenewalsRemindCommand turns into a non-zero exit, so a run with a
     * broken template or mail transport can never look green.
     *
     * A missing/inactive template is a configuration fault: it is logged at
     * error level and returns false, but the portal notification still goes
     * out and the per-cycle marker is still written, so the customer is not
     * reminded every day while ops fixes the template. A transport failure is
     * retried on the next run instead, so it deliberately leaves the marker
     * untouched even when the in-app channel succeeded. An order with no
     * linked user email dispatches no email and therefore returns false.
     *
     * Idempotent for the cycle: an order already reminded for its CURRENT
     * next_billing_date is a no-op that reports success, so a second caller in
     * the same cycle neither re-sends nor counts as an error.
     */
    public function remind(Order $order): bool
    {
        if ($this->alreadyRemindedForCurrentCycle($order)) {
            AppLog::billing()->info('Renewal reminder skipped: already sent for this cycle.', ['order_id' => $order->id]);

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
        // we refuse to repeat daily — stamps the cycle marker.
        //
        // Bookkeeping must never downgrade a dispatched reminder to an error:
        // the mail/notification already left, so a failed marker write is
        // logged and swallowed (the worst case is one extra reminder).
        if (! $emailTransportFailed && ($emailDispatched || $inAppSent || $templateMissing)) {
            try {
                $order->forceFill([
                    'renewal_reminder_for' => $order->next_billing_date->toDateString(),
                    'renewal_reminder_sent_at' => now(),
                ])->save();
            } catch (\Throwable $e) {
                AppLog::billing()->warning('Renewal reminder sent but the per-cycle marker could not be written.', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $emailDispatched;
    }

    /**
     * Whether the order was already reminded for the cycle it is currently in.
     *
     * The marker stores the next_billing_date the last reminder went out for,
     * so an advance (or an admin edit) re-arms the reminder.
     */
    private function alreadyRemindedForCurrentCycle(Order $order): bool
    {
        return $order->renewal_reminder_for !== null
            && $order->next_billing_date !== null
            && $order->renewal_reminder_for->toDateString() === $order->next_billing_date->toDateString();
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
     * A missing/inactive template while there are orders in the window is a
     * configuration fault, not an empty result: it throws so the caller can
     * fail the run. An empty collection and the already-sent-today guard still
     * report 0.
     *
     * @param  Collection<int, Order>  $orders  every order inside the run's window
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

        $recipients = User::query()
            ->whereIn('role', ['admin', 'staff', 'support'])
            ->where('status', 'active')
            ->pluck('email')
            ->unique();

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

        $windowDays = $latestDate !== null && $latestDate !== ''
            ? (string) max(0, (int) today()->diffInDays($latestDate, false))
            : (string) max(0, (int) AppSettings::get('renewal_reminder_days', '7'));

        return $this->brandingVariables() + [
            'renewal_count' => (string) $orders->count(),
            'window_days' => $windowDays,
            'renewals_html' => $this->renewalsHtml($orders),
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
