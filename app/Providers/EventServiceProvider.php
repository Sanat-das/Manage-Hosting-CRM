<?php

namespace App\Providers;

use App\Events\CustomerCreated;
use App\Events\CustomerDeleted;
use App\Events\CustomerUpdated;
use App\Events\DomainExpiringSoon;
use App\Events\InvoiceOverdue;
use App\Events\InvoicePaid;
use App\Events\OrderCreated;
use App\Events\OrderPaid;
use App\Events\TicketCreated;
use App\Events\TicketReply;
use App\Events\TicketTransferred;
use App\Listeners\AdvanceOrderOnPayment;
use App\Listeners\LogCustomerLifecycle;
use App\Listeners\SendDomainExpiringNotification;
use App\Listeners\SendInvoiceOverdueNotification;
use App\Listeners\SendOrderCreatedNotification;
use App\Listeners\SendOrderPaidNotification;
use App\Listeners\RecordScheduledTaskRun;
use App\Listeners\SendTicketCreatedNotification;
use App\Listeners\SendTicketReplyNotification;
use App\Listeners\SendTicketTransferredNotification;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @ array<class-string, array<int, class-string>>
     */
    protected $listen = [
        CustomerCreated::class => [
            // LogCustomerLifecycle exposes one handle*() per event (designed
            // for auto-discovery), so the concrete method must be named here —
            // whole-class registration would invoke __invoke()/handle(), which
            // the class does not define.
            LogCustomerLifecycle::class.'@handleCreated',
        ],
        CustomerUpdated::class => [
            LogCustomerLifecycle::class.'@handleUpdated',
        ],
        CustomerDeleted::class => [
            LogCustomerLifecycle::class.'@handleDeleted',
        ],
        OrderPaid::class => [
            SendOrderPaidNotification::class,
        ],
        InvoicePaid::class => [
            AdvanceOrderOnPayment::class,
        ],
        OrderCreated::class => [
            SendOrderCreatedNotification::class,
        ],
        InvoiceOverdue::class => [
            SendInvoiceOverdueNotification::class,
        ],
        DomainExpiringSoon::class => [
            SendDomainExpiringNotification::class,
        ],
        TicketCreated::class => [
            SendTicketCreatedNotification::class,
        ],
        TicketReply::class => [
            SendTicketReplyNotification::class,
        ],
        TicketTransferred::class => [
            SendTicketTransferredNotification::class,
        ],

        // Cron Jobs page run history. Like LogCustomerLifecycle, the listener
        // exposes one handle*() per event, so the concrete method is named.
        ScheduledTaskStarting::class => [
            RecordScheduledTaskRun::class.'@handleStarting',
        ],
        ScheduledTaskFinished::class => [
            RecordScheduledTaskRun::class.'@handleFinished',
        ],
        ScheduledBackgroundTaskFinished::class => [
            RecordScheduledTaskRun::class.'@handleBackgroundFinished',
        ],
        ScheduledTaskFailed::class => [
            RecordScheduledTaskRun::class.'@handleFailed',
        ],
        ScheduledTaskSkipped::class => [
            RecordScheduledTaskRun::class.'@handleSkipped',
        ],
        // Scheduler heartbeat (filtered to `schedule:run` in the listener).
        CommandFinished::class => [
            RecordScheduledTaskRun::class.'@handleCommandFinished',
        ],
    ];

    /**
     * Register the application's event listeners.
     *
     * Disable framework-level auto-discovery: with explicit $listen maps we do
     * not want the base provider to also discover these listeners by their
     * handle*() method type-hints, which would register everything twice.
     */
    public function register(): void
    {
        ServiceProvider::disableEventDiscovery();

        parent::register();
    }

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
