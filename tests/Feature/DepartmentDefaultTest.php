<?php

namespace Tests\Feature;

use App\Models\TicketDepartment;
use App\Services\TicketDepartmentService;
use App\Services\TicketMailParser;
use App\Services\TicketService;
use App\Settings\EmailSettings;
use App\Support\InboundEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * T6 — Department is_default enforcement — service invariant.
 *
 * Only TicketDepartmentService::setDefault may establish the default;
 * no booted hook / observer auto-clears.
 */
class DepartmentDefaultTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TicketService::forgetDepartmentCache();
        Queue::fake();
    }

    public function test_set_default_clears_others(): void
    {
        // Seeded after T1 backfill: exactly one default (support).
        $this->assertSame(1, TicketDepartment::where('is_default', true)->count());

        $sales = TicketDepartment::where('slug', 'sales')->firstOrFail();
        $support = TicketDepartment::where('slug', 'support')->firstOrFail();

        // Promote sales — support must be cleared atomically.
        app(TicketDepartmentService::class)->setDefault($sales);

        $this->assertTrue($sales->fresh()->is_default, 'sales should be default after setDefault');
        $this->assertFalse($support->fresh()->is_default, 'previous default support should be cleared');
        $this->assertSame(1, TicketDepartment::where('is_default', true)->count(), 'exactly one default must remain');

        // Promote support back — sales cleared.
        app(TicketDepartmentService::class)->setDefault($support);

        $this->assertTrue($support->fresh()->is_default);
        $this->assertFalse($sales->fresh()->is_default);
        $this->assertSame(1, TicketDepartment::where('is_default', true)->count());
    }

    public function test_department_for_returns_is_default(): void
    {
        // No mailbox slug and no global imap_default_department — departmentFor
        // must prefer the explicit is_default over ordered()->first() (sales).
        $settings = app(EmailSettings::class);
        $settings->fill(['imap_default_department' => ''])->save();

        $billing = TicketDepartment::where('slug', 'billing')->firstOrFail();
        app(TicketDepartmentService::class)->setDefault($billing);

        // Verify ordering: sales (10) is still the ordered first, billing (30) is the default.
        $orderedFirst = TicketDepartment::query()->enabled()->ordered()->first();
        $this->assertSame('sales', $orderedFirst->slug, 'precondition: sales is ordered first');
        $this->assertTrue($billing->fresh()->is_default);

        TicketService::forgetDepartmentCache();

        $result = app(TicketMailParser::class)->handle(InboundEmail::fromArray([
            'messageId' => 'dept-default-1@mail.example',
            'subject' => 'Need help',
            'fromEmail' => 'newcustomer1@example.test',
            'fromName' => 'New Customer',
            'body' => 'Hello, I need help.',
        ]), false, null);

        $this->assertSame(TicketMailParser::STATUS_TICKET_OPENED, $result['status']);
        $this->assertNotNull($result['ticket']);
        $this->assertSame('billing', $result['ticket']->department, 'departmentFor should prefer is_default over ordered first');
    }

    public function test_fallback_to_ordered_first_when_no_default(): void
    {
        $settings = app(EmailSettings::class);
        $settings->fill(['imap_default_department' => ''])->save();

        // Remove every default — fallback must be the first enabled by sort_order.
        TicketDepartment::query()->update(['is_default' => false]);
        TicketService::forgetDepartmentCache();

        $this->assertSame(0, TicketDepartment::where('is_default', true)->count());

        $result = app(TicketMailParser::class)->handle(InboundEmail::fromArray([
            'messageId' => 'dept-default-2@mail.example',
            'subject' => 'Another request',
            'fromEmail' => 'newcustomer2@example.test',
            'body' => 'Hello again.',
        ]), false, null);

        $this->assertSame(TicketMailParser::STATUS_TICKET_OPENED, $result['status']);
        $this->assertNotNull($result['ticket']);
        // Seeded order: sales(10) < support(20) < billing(30) < technical(40)
        $this->assertSame('sales', $result['ticket']->department, 'when no is_default, should fall back to ordered first');
    }
}
