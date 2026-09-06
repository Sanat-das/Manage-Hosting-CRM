<?php

namespace Tests;

use App\Services\TicketService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * TicketService::departments() caches its result in a process-static
     * property that RefreshDatabase's transaction rollback does not reset,
     * so a department created/queried in one test can leak a stale list
     * into the next. Clear it before every test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        TicketService::forgetDepartmentCache();
    }
}
