<?php

namespace App\Notifications;

use App\Models\Domain;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

/**
 * Database-channel notification: a domain is expiring soon.
 *
 * `domain_id` is stored in the payload so the listener can dedup: a notifiable
 * never receives two unread expiry warnings for the same domain.
 */
class DomainExpiringNotification extends Notification
{
    use Queueable;

    public function __construct(public Domain $domain) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        return new DatabaseMessage([
            'type' => 'domain.expiring',
            'domain_id' => $this->domain->id,
            'domain_name' => $this->domain->name,
            'expiry_date' => $this->domain->expiry_date?->toDateString(),
            'days_left' => $this->domain->daysUntilExpiry(),
            'message' => "Domain {$this->domain->name} expires soon.",
        ]);
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable)->data;
    }
}
