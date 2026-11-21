<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_buckets_are_keyed_by_year_month(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $role->permissions()->sync(
            Permission::firstOrCreate(['name' => 'analytics.view'], ['label' => 'View Analytics'])
        );
        $user->roles()->sync([$role->id]);

        $bucket = now()->subMonths(2);
        $month = $bucket->format('Y-m');
        $timestamp = $bucket->format('Y-m-15 12:00:00');

        $customerId = DB::table('customers')->insertGetId([
            'user_id' => $user->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('invoices')->insert([
            'invoice_no' => 'INV-ANALYTICS-001',
            'customer_id' => $customerId,
            'status' => 'paid',
            'total' => 150.00,
            'due_date' => '2026-05-20',
            'paid_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $response = $this->actingAs($user)->get(route('admin.analytics.index'));

        $response->assertOk();

        $revenueByMonth = $response->viewData('revenueByMonth');
        $customersByMonth = $response->viewData('customersByMonth');

        $this->assertArrayHasKey($month, $revenueByMonth);
        $this->assertArrayHasKey($month, $customersByMonth);
        $this->assertGreaterThan(0, (float) $revenueByMonth[$month]);
        $this->assertGreaterThan(0, (float) $customersByMonth[$month]);
    }
}
