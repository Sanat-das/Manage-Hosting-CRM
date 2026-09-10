<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the settings_properties rows left behind by the removed global inbound
 * mailbox.
 *
 * Inbound ticket mail is per-department (`ticket_departments.imap_*`, edited
 * under Support > Departments) and has been for some time. EmailSettings no
 * longer declares these nine properties — only the three imap_* POLICY keys it
 * does declare (imap_auto_create_customers, imap_default_department,
 * imap_max_new_tickets_per_hour) are real, and those are left alone.
 *
 * Spatie tolerates extra rows, so these were harmless but not free: they are
 * the first thing anyone greps when inbound mail breaks, and one of them is a
 * credential field (`imap_password`, currently empty) that nothing rotates,
 * audits or masks because no code owns it any more.
 *
 * No down(): restoring rows for properties no class declares would recreate
 * the confusion, and nothing reads them, so there is nothing to restore.
 */
return new class extends Migration
{
    /**
     * Removed global-mailbox properties. NOT the three policy keys EmailSettings
     * still declares — deleting those would break saving the whole email group.
     */
    private const ORPHANS = [
        'imap_host',
        'imap_port',
        'imap_username',
        'imap_password',
        'imap_encryption',
        'imap_folder',
        'imap_enabled',
        'imap_validate_cert',
        'imap_delete_after_fetch',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('settings_properties')) {
            return;
        }

        // Guard: only ever delete a name the EmailSettings class does not declare.
        // If someone re-adds one as a real property later, this becomes a no-op
        // rather than breaking that group's save.
        $declared = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass(\App\Settings\EmailSettings::class))->getProperties(\ReflectionProperty::IS_PUBLIC),
        );

        $deletable = array_values(array_diff(self::ORPHANS, $declared));

        if ($deletable === []) {
            return;
        }

        DB::table('settings_properties')
            ->where('group', 'email')
            ->whereIn('name', $deletable)
            ->delete();
    }

    public function down(): void
    {
        // Intentionally empty — see the class docblock.
    }
};
