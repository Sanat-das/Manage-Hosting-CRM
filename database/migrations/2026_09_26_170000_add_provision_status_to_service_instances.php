<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add the missing `service_instances.provision_status` column.
 *
 * It was already referenced by the service-instance screen (badge, "Provision
 * Status" select, index column, and the gate on the Retry-provisioning button)
 * and by the list filter, but the column never existed and the model did not
 * declare it fillable. The visible consequences were a select that saved
 * nothing while reporting success, a badge that was always empty, and a filter
 * that errored with "Unknown column".
 *
 * Nullable on purpose: it records what the module reported, which is genuinely
 * unknown for a row nothing has provisioned yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('service_instances', 'provision_status')) {
            Schema::table('service_instances', function (Blueprint $table) {
                $table->enum('provision_status', ['pending', 'provisioning', 'provisioned', 'failed', 'suspended', 'terminated'])
                    ->nullable()
                    ->after('status');

                // The admin list filters on it.
                $table->index('provision_status');
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        if (Schema::hasColumn('service_instances', 'provision_status')) {
            Schema::table('service_instances', function (Blueprint $table) {
                $table->dropIndex(['provision_status']);
                $table->dropColumn('provision_status');
            });
        }
    }

    /**
     * Backfill from what each row already proves, so the field reads true
     * immediately instead of being empty everywhere.
     *
     * The **newest panel account** is the module's own artifact, so its state
     * decides first — a service can be active, suspended or terminated long
     * after the module did its work, and this field records the module's
     * outcome, not the billing lifecycle:
     *
     *  1. panel active     -> provisioned
     *  2. panel suspended  -> suspended
     *  3. panel terminated -> terminated
     *  4. no panel at all: fall back to the service status, else 'pending'.
     *
     * Each step is scoped to rows still NULL, so an earlier rule is never
     * overwritten by a broader one later.
     */
    private function backfill(): void
    {
        $newestPanel = static function ($query): void {
            $query->select(DB::raw('MAX(panel_accounts.id)'))
                ->from('panel_accounts')
                ->whereColumn('panel_accounts.service_instance_id', 'service_instances.id');
        };

        foreach (['active' => 'provisioned', 'suspended' => 'suspended', 'terminated' => 'terminated'] as $panelStatus => $provisionStatus) {
            DB::table('service_instances')
                ->whereNull('provision_status')
                ->whereExists(function ($query) use ($newestPanel, $panelStatus) {
                    $query->select(DB::raw(1))
                        ->from('panel_accounts')
                        ->whereColumn('panel_accounts.service_instance_id', 'service_instances.id')
                        ->where('panel_accounts.status', $panelStatus)
                        ->where('panel_accounts.id', '=', $newestPanel);
                })
                ->update(['provision_status' => $provisionStatus]);
        }

        DB::table('service_instances')
            ->whereNull('provision_status')
            ->whereIn('status', ['terminated', 'cancelled'])
            ->update(['provision_status' => 'terminated']);

        DB::table('service_instances')
            ->whereNull('provision_status')
            ->where('status', 'suspended')
            ->update(['provision_status' => 'suspended']);

        DB::table('service_instances')
            ->whereNull('provision_status')
            ->update(['provision_status' => 'pending']);
    }
};
