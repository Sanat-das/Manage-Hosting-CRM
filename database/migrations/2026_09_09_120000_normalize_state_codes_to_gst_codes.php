<?php

use App\Support\GstStateCodes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Put both state-code columns into the one vocabulary the tax engine assumes.
 *
 * GstTaxService::isIntraState() decides CGST+SGST vs IGST by comparing
 * `gst_settings.state_code` with `customers.state_code`. Those held numeric GST
 * codes ('27') and two-letter codes ('WB') respectively, so the comparison
 * could never succeed: every customer was treated as inter-state, and where the
 * IGST rate was 0 that meant silently untaxed invoices.
 *
 * Canonical form is the numeric GST code (the first two digits of a GSTIN).
 *
 * A customer whose code cannot be resolved is set to NULL rather than guessed
 * at: null and an unrecognised value already behave identically for tax (both
 * fall to inter-state), and null is the honest record that the state is unknown
 * — the full state name is still on `customers.state`, so nothing is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gst_settings')) {
            foreach (DB::table('gst_settings')->get(['id', 'state_code', 'state_name']) as $row) {
                // The name is a usable fallback when the code is unreadable.
                $code = GstStateCodes::normalize($row->state_code)
                    ?? GstStateCodes::normalize($row->state_name);

                if ($code === null) {
                    continue;
                }

                DB::table('gst_settings')->where('id', $row->id)->update([
                    'state_code' => $code,
                    // Keep the pair consistent: the name follows the code.
                    'state_name' => GstStateCodes::name($code),
                ]);
            }
        }

        if (! Schema::hasTable('customers') || ! Schema::hasColumn('customers', 'state_code')) {
            return;
        }

        $hasStateName = Schema::hasColumn('customers', 'state');

        $columns = $hasStateName ? ['id', 'state_code', 'state'] : ['id', 'state_code'];

        DB::table('customers')
            ->whereNotNull('state_code')
            ->select($columns)
            ->orderBy('id')
            ->chunk(500, function ($customers) use ($hasStateName): void {
                foreach ($customers as $customer) {
                    $code = GstStateCodes::normalize($customer->state_code);

                    if ($code === null && $hasStateName) {
                        // 'WB' truncations aside, the full state name on the
                        // address is often resolvable when the code is not.
                        $code = GstStateCodes::normalize($customer->state);
                    }

                    if ($code === (string) $customer->state_code) {
                        continue;
                    }

                    DB::table('customers')->where('id', $customer->id)->update(['state_code' => $code]);
                }
            });
    }

    /**
     * Irreversible by design: the alpha codes this replaced were themselves
     * derived from `customers.state`, so there is nothing to restore that the
     * address does not already hold.
     */
    public function down(): void
    {
        //
    }
};
