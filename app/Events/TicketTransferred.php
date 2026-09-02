<?php

namespace App\Events;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a support ticket is transferred to another department.
 */
class TicketTransferred
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public string $fromDepartment,
        public string $toDepartment,
        public User $actor,
    ) {}
}
