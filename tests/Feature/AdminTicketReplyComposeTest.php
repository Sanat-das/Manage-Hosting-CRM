<?php

namespace Tests\Feature;

use App\Jobs\SendEmail;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Outlook-style rework Task 5: the admin reply form's optional To/Cc/Bcc and
 * attachment fields, and the default-To pre-fill on the show page.
 */
class AdminTicketReplyComposeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plain_reply_with_none_of_the_new_fields_behaves_exactly_as_before(): void
    {
        Queue::fake();

        $ticket = $this->ticketFor('client@example.test');

        $this->actingAsStaffWithEditPermission()
            ->post(route('admin.tickets.reply', $ticket), [
                'message' => 'We have fixed it.',
            ])
            ->assertRedirect(route('admin.tickets.show', $ticket));

        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => $job->toEmail === 'client@example.test'
            && $job->cc === [] && $job->bcc === [] && $job->htmlBody === null && $job->attachments === []);
    }

    public function test_a_reply_with_to_cc_bcc_and_an_attachment_carries_them_through(): void
    {
        Queue::fake();
        Storage::fake('local');

        $ticket = $this->ticketFor('client@example.test');

        $this->actingAsStaffWithEditPermission()
            ->post(route('admin.tickets.reply', $ticket), [
                'message' => 'We have fixed it.',
                'to' => 'personal-inbox@example.test',
                'cc' => 'manager@example.test, sales@example.test',
                'bcc' => 'audit@example.test',
                'attachments' => [UploadedFile::fake()->create('report.pdf', 10, 'application/pdf')],
            ])
            ->assertRedirect(route('admin.tickets.show', $ticket));

        $reply = TicketReply::latest('id')->first();
        $this->assertSame(['personal-inbox@example.test'], $reply->to);
        $this->assertSame(['manager@example.test', 'sales@example.test'], $reply->cc);
        $this->assertSame(['audit@example.test'], $reply->bcc);
        $this->assertCount(1, $reply->attachments);
        $this->assertSame('report.pdf', $reply->attachments->first()->filename);

        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => $job->toEmail === 'personal-inbox@example.test'
            && $job->cc === ['manager@example.test', 'sales@example.test']
            && $job->bcc === ['audit@example.test']
            && count($job->attachments) === 1);
    }

    public function test_junk_in_the_to_cc_bcc_fields_is_dropped_not_sent_as_a_recipient(): void
    {
        Queue::fake();

        $ticket = $this->ticketFor('client@example.test');

        $this->actingAsStaffWithEditPermission()
            ->post(route('admin.tickets.reply', $ticket), [
                'message' => 'We have fixed it.',
                'to' => ' , not-an-email , ',
                'cc' => 'not-an-email-either',
            ])
            ->assertRedirect(route('admin.tickets.show', $ticket));

        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => $job->toEmail === 'client@example.test' && $job->cc === []);
    }

    public function test_the_show_page_prefills_to_with_the_actual_inbound_sender(): void
    {
        $ticket = $this->ticketFor('account@example.test');
        $ticket->replies()->where('is_staff', false)->first()->forceFill([
            'from_email' => 'personal-inbox@example.test',
        ])->save();

        $response = $this->actingAsStaffWithEditPermission()
            ->get(route('admin.tickets.show', $ticket));

        $response->assertOk();
        $this->assertSame('personal-inbox@example.test', $response->viewData('defaultReplyTo'));
    }

    public function test_the_show_page_renders_the_rich_text_editor_markup(): void
    {
        $ticket = $this->ticketFor('client@example.test');

        $response = $this->actingAsStaffWithEditPermission()
            ->get(route('admin.tickets.show', $ticket));

        $response->assertOk();
        $response->assertSee('id="reply-editor"', false);
        $response->assertSee('id="reply-editor-toolbar"', false);
        $response->assertSee('id="reply-html-body"', false);
        $response->assertSee('name="message"', false);
    }

    private function ticketFor(string $email): Ticket
    {
        $user = User::factory()->create(['email' => $email, 'role' => 'client']);
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);

        $ticket = Ticket::create([
            'ticket_no' => 'TKT-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'subject' => 'Cannot reach my site',
            'priority' => 'high',
            'status' => 'open',
            'department' => 'support',
            'last_reply_at' => now(),
        ]);

        TicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => $customer->user_id,
            'message' => 'It has been down since noon.',
            'is_staff' => false,
        ]);

        return $ticket;
    }

    private function actingAsStaffWithEditPermission(): self
    {
        $user = User::factory()->create(['role' => 'admin']);

        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin']);
        foreach (['tickets.view', 'tickets.edit'] as $permName) {
            $permission = Permission::firstOrCreate(['name' => $permName], ['label' => $permName]);
            $role->permissions()->syncWithoutDetaching($permission->id);
        }
        $user->assignRole('admin');

        return $this->actingAs($user);
    }
}
