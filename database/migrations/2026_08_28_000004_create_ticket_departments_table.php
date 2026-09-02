<?php

use App\Services\TicketService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Support departments as data instead of a PHP constant.
     *
     * Follows the WHMCS model: a department owns an address customers write to
     * AND its own mailbox credentials, because the mailbox a message lands in
     * is what identifies the department for mail that is not already a reply to
     * a known ticket. WHMCS is emphatic that each department needs its own real
     * mailbox — "an alias is not sufficient" — and that pointing two
     * departments at one mailbox imports every reply twice.
     *
     * `tickets.department` is widened from an ENUM to a string in the same
     * pass. Leaving the enum would make the new UI a lie: an admin could create
     * a "Abuse" department and every ticket filed against it would be rejected
     * by the column. Existing values are unaffected.
     */
    public function up(): void
    {
        Schema::create('ticket_departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Matches tickets.department; the stable key that existing rows,
            // TicketService::DEPARTMENTS and the ticket forms all use.
            $table->string('slug', 50)->unique();
            $table->string('email_address')->nullable()->unique();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            // Per-department mailbox. Null host = fall back to the global
            // Settings > Email > Incoming Mail configuration.
            $table->boolean('imap_enabled')->default(false);
            $table->string('imap_host')->nullable();
            $table->unsignedSmallInteger('imap_port')->default(993);
            $table->string('imap_encryption', 10)->default('ssl');
            $table->string('imap_username')->nullable();
            $table->text('imap_password')->nullable();
            $table->string('imap_folder')->default('INBOX');
            $table->boolean('imap_validate_cert')->default(true);
            $table->boolean('imap_delete_after_fetch')->default(false);

            $table->timestamps();

            $table->index('enabled');
            $table->index('sort_order');
        });

        // Seed the four that already exist so nothing that reads
        // TicketService::DEPARTMENTS changes behaviour.
        $now = now();
        $sort = 0;
        foreach (TicketService::DEPARTMENTS as $slug => $label) {
            DB::table('ticket_departments')->insert([
                'name' => $label,
                'slug' => $slug,
                'email_address' => null,
                'enabled' => true,
                'sort_order' => $sort += 10,
                'imap_enabled' => false,
                'imap_port' => 993,
                'imap_encryption' => 'ssl',
                'imap_folder' => 'INBOX',
                'imap_validate_cert' => true,
                'imap_delete_after_fetch' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('tickets', function (Blueprint $table) {
            $table->string('department', 50)->default('support')->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_departments');

        Schema::table('tickets', function (Blueprint $table) {
            $table->enum('department', ['sales', 'support', 'billing', 'technical'])->default('support')->change();
        });
    }
};
