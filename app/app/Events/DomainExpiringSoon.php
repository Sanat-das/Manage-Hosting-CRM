<?php

namespace App\Events;

use App\Models\Domain;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched for each domain discovered within its expiry warning window.
 *
 * Hooked from DomainExpiryCheckCommand. The listener is idempotent per
 * (notifiable, domain, window): it refuses to create a second unread
 * notification for the same domain until the first is read.
 */
class DomainExpiringSoon
{
    use Dispatchable, SerializesModels;

    public function __construct(public Domain $domain) {}
}
