<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit orphan ticket departments and backfill missing rows.
     *
     * The client create form at resources/views/client/tickets/create.blade.php:41-46
     * historically offered hardcoded `general` and `abuse` alongside the seeded
     * four (sales/support/billing/technical). Tickets filed before DB-driven
     * departments therefore store slugs with no matching ticket_departments row.
     *
     * This migration finds every distinct `tickets.department` whose slug has no
     * match in `ticket_departments`, then creates a department per orphan:
     *   name = ucfirst(slug), slug, enabled=true, allow_new_tickets=true,
     *   sort_order = max(sort_order)+10 (appended), email null, IMAP defaults,
     *   is_default=false, description/signature NULL.
     *
     * Additive only — no tickets.department values are changed or remapped, no
     * rows are deleted. Re-running is idempotent (exists check + max sort_order).
     */
    public function up(): void
    {
        if (! Schema::hasTable('tickets') || ! Schema::hasTable('ticket_departments')) {
            return;
        }

        // Existing slugs — the NOT IN set.
        $existingSlugs = DB::table('ticket_departments')->pluck('slug')->all();

        // Distinct non-empty departments from tickets.
        $distinctDepartments = DB::table('tickets')
            ->select('department')
            ->distinct()
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->pluck('department')
            ->all();

        $orphans = array_values(array_diff($distinctDepartments, $existingSlugs));

        if ($orphans === []) {
            Log::info('[orphan-audit] No orphan ticket departments found.', [
                'existing_count' => count($existingSlugs),
                'distinct_count' => count($distinctDepartments),
            ]);

            return;
        }

        // Deterministic order so sort_order assignment is stable.
        sort($orphans);

        $created = 0;

        foreach ($orphans as $slug) {
            $slug = trim((string) $slug);

            if ($slug === '') {
                continue;
            }

            // Idempotent guard — another process or a prior run may have inserted.
            if (DB::table('ticket_departments')->where('slug', $slug)->exists()) {
                continue;
            }

            $maxSort = DB::table('ticket_departments')->max('sort_order');
            $sortOrder = is_numeric($maxSort) ? ((int) $maxSort + 10) : 10;

            $now = now();

            // Build insert payload; include columns added by T1/T5 dependencies when present.
            $payload = [
                'name' => ucfirst($slug),
                'slug' => $slug,
                'email_address' => null,
                'enabled' => true,
                'allow_new_tickets' => true,
                'sort_order' => $sortOrder,
                'imap_enabled' => false,
                'imap_port' => 993,
                'imap_encryption' => 'ssl',
                'imap_folder' => 'INBOX',
                'imap_validate_cert' => true,
                'imap_delete_after_fetch' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // Columns from 000006 (add_ticket_department_extras) — present after T1.
            if (Schema::hasColumn('ticket_departments', 'description')) {
                $payload['description'] = null;
            }
            if (Schema::hasColumn('ticket_departments', 'signature')) {
                $payload['signature'] = null;
            }
            if (Schema::hasColumn('ticket_departments', 'is_default')) {
                $payload['is_default'] = false;
            }
            // imap_* nullables already defaulted via migration defaults, but be explicit for host/user/pass.
            if (Schema::hasColumn('ticket_departments', 'imap_host')) {
                $payload['imap_host'] = null;
            }
            if (Schema::hasColumn('ticket_departments', 'imap_username')) {
                $payload['imap_username'] = null;
            }
            if (Schema::hasColumn('ticket_departments', 'imap_password')) {
                $payload['imap_password'] = null;
            }

            // Guard allow_new_tickets column existence (added in 000005) for safety on partial DBs.
            if (! Schema::hasColumn('ticket_departments', 'allow_new_tickets')) {
                unset($payload['allow_new_tickets']);
            }

            DB::table('ticket_departments')->insert($payload);
            $created++;

            Log::info('[orphan-audit] Created missing department for orphan slug.', [
                'slug' => $slug,
                'sort_order' => $sortOrder,
            ]);
        }

        Log::info('[orphan-audit] Completed.', [
            'orphans_found' => count($orphans),
            'orphans_created' => $created,
            'orphans' => $orphans,
        ]);
    }

    public function down(): void
    {
        // Additive backfill — down is intentionally a no-op to avoid re-orphaning
        // tickets that now correctly resolve to a department. Orphan departments
        // created here are left in place; an admin can disable or remove them
        // through the UI if desired.
    }
};
