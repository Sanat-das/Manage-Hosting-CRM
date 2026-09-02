<?php

namespace App\Listeners;

use App\Events\DomainExpiringSoon;
use App\Models\Customer;
use App\Notifications\DomainExpiringNotification;
use App\Services\NotificationPreferenceService;

/**
 * Notify the customer that a domain is expiring soon.
 *
 * Idempotent per (notifiable, domain): a customer never receives two unread
 * expiry warnings for the same domain. The dedup key is the domain_id stored
 * in the notification payload — if an unread notification carrying that
 * domain_id already exists, the dispatch is skipped.
 */
class SendDomainExpiringNotification
{
    public function __construct(private readonly NotificationPreferenceService $prefs) {}

    public function handle(DomainExpiringSoon $event): void
    {
        $domain = $event->domain->fresh(['customer']);
        $customer = $domain->customer;

        if (! $customer instanceof Customer) {
            return;
        }

        if (! $this->prefs->isEnabled($customer, 'domain.expiring')) {
            return;
        }

        $domainId = $domain->id;

        $alreadyNotified = $customer->unreadNotifications()
            ->where('type', DomainExpiringNotification::class)
            ->get()
            ->contains(fn ($n) => ($n->data['domain_id'] ?? null) === $domainId);

        if ($alreadyNotified) {
            return;
        }

        $customer->notify(new DomainExpiringNotification($domain));
    }
}
