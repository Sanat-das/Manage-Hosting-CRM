<?php

namespace App\Listeners;

use App\Events\CustomerCreated;
use App\Events\CustomerDeleted;
use App\Events\CustomerUpdated;
use App\Models\Customer;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;

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
        $this->log($event->customer, AuditEvent::CustomerCreated, 'Customer created');
    }

    public function handleUpdated(CustomerUpdated $event): void
    {
        $this->log($event->customer, AuditEvent::CustomerUpdated, 'Customer details updated');
    }

    public function handleDeleted(CustomerDeleted $event): void
    {
        $this->log($event->customer, AuditEvent::CustomerDeleted, 'Customer deleted');
    }

    private function log(Customer $customer, AuditEvent $action, string $description): void
    {
        app(AuditRecorder::class)->activity($action, $customer, ['event_dispatched' => true], $description);
    }
}
