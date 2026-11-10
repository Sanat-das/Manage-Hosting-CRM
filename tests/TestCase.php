<?php

namespace Tests;

use App\Models\Role;
use App\Services\TicketService;
use Database\Seeders\AdminLteRbacSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

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

        $this->seedPanelRbac();
    }

    /**
     * Ensure the RBAC roles exist before any test builds a panel user.
     *
     * `hasPermission()` resolves a user's permissions from the pivot first and
     * then falls back to the Role named by the `users.role` column, so a test
     * doing `User::factory()->create(['role' => 'admin'])` against empty RBAC
     * tables has no permissions at all — it only passes today because
     * `hasPermission()` short-circuits on `isAdmin()`. Seeding here gives those
     * users their real permissions, so the gates are exercised for what they
     * are rather than waved through.
     *
     * The seeder runs on EVERY test, not only when the roles table is empty:
     * four migrations create a handful of roles (editor, staff, viewer) and
     * ~35 permissions of their own, so "the table is not empty" was never a
     * signal that the RBAC graph existed. Skipping the seeder then left the
     * admin, support, sales and marketing roles missing and the rest of the
     * permission inventory unseeded — every panel route answered 403 for a
     * reason that had nothing to do with the behaviour under test. The seeder
     * is idempotent (upsert for permissions, sync for role links), so running
     * it unconditionally is safe.
     *
     * Deliberately runs before any user exists: AdminLteRbacSeeder promotes
     * `User::first()` to admin, which would otherwise hand the admin role to
     * whichever user a test happened to create first.
     */
    protected function seedPanelRbac(): void
    {
        if (! Schema::hasTable('adminlte_roles')) {
            return;
        }

        (new AdminLteRbacSeeder)->run();
    }
}
