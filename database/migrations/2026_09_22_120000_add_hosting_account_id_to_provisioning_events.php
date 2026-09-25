<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Link provisioning_events directly to the hosting account they describe.
 *
 * Until now the owning account only lived inside the payload JSON
 * (payload.hosting_account_id, written by ManualProvisioner), so finding an
 * account's events required a JSON path query. The new nullable column is
 * written by ProvisioningEventRecorder for every new row; existing rows are
 * backfilled from the payload JSON where present.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provisioning_events', function (Blueprint $table) {
            $table->unsignedBigInteger('hosting_account_id')->nullable()->after('service_instance_id');

            $table->index('hosting_account_id');

            // The durable begin() state: `running` rows must pass the status
            // CHECK/enum on both SQLite and MySQL, otherwise every begin()
            // fails with an integrity violation and no event is ever written.
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'retrying', 'running'])
                ->default('pending')
                ->change();
        });

        // Backfill the hosting link from the payload JSON column, where the
        // manual provisioner stored it as hosting_account_id.
        DB::table('provisioning_events')
            ->whereNull('hosting_account_id')
            ->whereNotNull('payload')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $payload = json_decode((string) $row->payload, true);
                    $hostingId = is_array($payload) ? ($payload['hosting_account_id'] ?? null) : null;

                    if ($hostingId !== null) {
                        DB::table('provisioning_events')
                            ->where('id', $row->id)
                            ->update(['hosting_account_id' => (int) $hostingId]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('provisioning_events', function (Blueprint $table) {
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'retrying'])
                ->default('pending')
                ->change();
            $table->dropIndex(['hosting_account_id']);
            $table->dropColumn('hosting_account_id');
        });
    }
};
