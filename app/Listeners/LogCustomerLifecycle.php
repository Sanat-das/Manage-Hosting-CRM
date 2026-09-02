<?php

namespace App\Listeners;

use App\Events\CustomerCreated;
use App\Events\CustomerDeleted;
use App\Events\CustomerUpdated;
use App\Models\ActivityLog;
use App\Models\Customer;

/**
 * Writes customer lifecycle events to the activity log.
 *
 * The reference CRM logs customer.created / customer.updated lifecycle
 * events; this listener keeps the web controller, API controller and any
 * future jobs on the same path.
 *
 * Laravel 13 auto-discovers listeners in app/Listeners by registering every
 * public handle*() / __invoke() method whose first parameter is type-hinted
 * with an event class.
 */
class LogCustomerLifecycle
{
    public function handleCreated(CustomerCreated $event): void
    {
        $this->log($event->customer, 'customer.created', 'Customer created');
    }

    public function handleUpdated(CustomerUpdated $event): void
    {
        $this->log($event->customer, 'customer.updated', 'Customer details updated');
    }

    public function handleDeleted(CustomerDeleted $event): void
    {
        $this->log($event->customer, 'customer.deleted', 'Customer deleted');
    }

    private function log(Customer $customer, string $action, string $description): void
    {
        ActivityLog::create([
            'customer_id' => $customer->id,
            'user_id' => auth()->id(),
            'action' => $action,
            'description' => $description,
            'metadata' => ['event_dispatched' => true],
            'ip_address' => request()->ip(),
        ]);
    }
}
