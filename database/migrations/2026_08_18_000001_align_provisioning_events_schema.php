<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Align provisioning_events with the admin event-log schema the code has
 * always expected.
 *
 * The original migration created a queue-style table (status/priority/
 * attempts/locked_by) while the controllers, views and seeders reference
 * event-log columns (service_instance_id / event_status / triggered_by /
 * result). Rows created so far only carry event_type + payload, with the
 * owning service inside payload.service_id — so backfill service_instance_id
 * from that JSON path rather than inventing links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provisioning_events', function (Blueprint $table) {
            $table->unsignedBigInteger('service_instance_id')->nullable()->after('id');
            $table->string('event_status', 20)->nullable()->after('status');
            $table->unsignedBigInteger('triggered_by')->nullable()->after('event_status');
            $table->json('result')->nullable()->after('payload');

            $table->index('service_instance_id');
        });

        // Backfill the service link from the payload JSON column, where the
        // existing seed data stored it as service_id.
        DB::table('provisioning_events')
            ->whereNull('service_instance_id')
            ->whereNotNull('payload')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $payload = json_decode((string) $row->payload, true);
                    $serviceId = is_array($payload) ? ($payload['service_id'] ?? null) : null;

                    if ($serviceId !== null) {
                        DB::table('provisioning_events')
                            ->where('id', $row->id)
                            ->update(['service_instance_id' => (int) $serviceId]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('provisioning_events', function (Blueprint $table) {
            $table->dropIndex(['service_instance_id']);
            $table->dropColumn(['service_instance_id', 'event_status', 'triggered_by', 'result']);
        });
    }
};
