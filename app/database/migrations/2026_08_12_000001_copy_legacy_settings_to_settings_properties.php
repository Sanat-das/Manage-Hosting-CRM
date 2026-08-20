<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Legacy `settings` keys that are now owned by typed settings classes.
     *
     * @var array<string, array<int, string>>
     */
    private const TYPED_KEYS_BY_GROUP = [
        'general' => ['company_name', 'company_email', 'company_phone', 'company_address', 'date_format', 'timezone'],
        'billing' => ['currency', 'invoice_next_number', 'invoice_prefix', 'tax_rate'],
        'email' => ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption'],
        'support' => ['ticket_next_number', 'ticket_prefix'],
    ];

    public function up(): void
    {
        $table = config('settings.repositories.database.table', 'settings_properties');

        if (! Schema::hasTable($table) || ! Schema::hasTable('settings')) {
            return;
        }

        $rows = DB::table('settings')->get(['setting_key', 'setting_value', 'group']);

        foreach ($rows as $row) {
            $typedKeys = self::TYPED_KEYS_BY_GROUP[$row->group] ?? null;

            if ($typedKeys === null || ! in_array($row->setting_key, $typedKeys, true)) {
                continue;
            }

            $exists = DB::table($table)
                ->where('group', $row->group)
                ->where('name', $row->setting_key)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table($table)->insert([
                'group' => $row->group,
                'name' => $row->setting_key,
                'payload' => json_encode($row->setting_value),
                'locked' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        //
    }
};
