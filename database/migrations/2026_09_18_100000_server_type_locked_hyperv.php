<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PANEL_TYPES = ['cpanel', 'plesk', 'directadmin', 'virtualizor', 'custom'];

    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // --- servers: panel_type -> server_type + new columns ---
        if (Schema::hasTable('servers')) {
            // 1) Migrate panel_type ENUM to server_type VARCHAR(50) NOT NULL
            $hasPanelType = Schema::hasColumn('servers', 'panel_type');
            $hasServerType = Schema::hasColumn('servers', 'server_type');

            if ($hasPanelType && ! $hasServerType) {
                // Add server_type as nullable first so we can backfill before enforcing NOT NULL
                Schema::table('servers', function (Blueprint $table) {
                    $table->string('server_type', 50)->nullable()->after('ip_address');
                });

                // Backfill server_type from panel_type
                // Known values pass through as-is
                foreach (['cpanel', 'plesk', 'directadmin', 'virtualizor'] as $type) {
                    DB::table('servers')->where('panel_type', $type)->whereNull('server_type')->update(['server_type' => $type]);
                }

                // custom -> virtualizor where api_url contains :4085 else generic
                // Use separate queries to stay portable across sqlite/mysql
                DB::table('servers')
                    ->where('panel_type', 'custom')
                    ->whereNotNull('api_url')
                    ->where('api_url', 'like', '%:4085%')
                    ->update(['server_type' => 'virtualizor']);

                DB::table('servers')
                    ->where('panel_type', 'custom')
                    ->where(function ($q) {
                        $q->whereNull('api_url')->orWhere('api_url', 'not like', '%:4085%');
                    })
                    ->whereNull('server_type')
                    ->update(['server_type' => 'generic']);

                // Any remaining null (e.g. unexpected panel_type) -> generic
                DB::table('servers')->whereNull('server_type')->update(['server_type' => 'generic']);

                // Enforce NOT NULL — driver-aware
                // On sqlite, changing nullability recreates table and drops old CHECK
                // On mysql, it alters column definition
                Schema::table('servers', function (Blueprint $table) use ($driver) {
                    $table->string('server_type', 50)->nullable(false)->change();
                });

                // Drop old column
                Schema::table('servers', function (Blueprint $table) {
                    $table->dropColumn('panel_type');
                });
            } elseif (! $hasPanelType && ! $hasServerType) {
                // Fresh table without either column (should not happen, but idempotent)
                Schema::table('servers', function (Blueprint $table) {
                    $table->string('server_type', 50)->nullable(false)->after('ip_address');
                });
            } elseif ($hasPanelType && $hasServerType) {
                // Both exist (partial migration) — backfill any null then drop panel_type
                DB::table('servers')->whereNull('server_type')->where('panel_type', 'cpanel')->update(['server_type' => 'cpanel']);
                DB::table('servers')->whereNull('server_type')->where('panel_type', 'plesk')->update(['server_type' => 'plesk']);
                DB::table('servers')->whereNull('server_type')->where('panel_type', 'directadmin')->update(['server_type' => 'directadmin']);
                DB::table('servers')->whereNull('server_type')->where('panel_type', 'virtualizor')->update(['server_type' => 'virtualizor']);
                DB::table('servers')
                    ->whereNull('server_type')
                    ->where('panel_type', 'custom')
                    ->where('api_url', 'like', '%:4085%')
                    ->update(['server_type' => 'virtualizor']);
                DB::table('servers')
                    ->whereNull('server_type')
                    ->where('panel_type', 'custom')
                    ->update(['server_type' => 'generic']);
                DB::table('servers')->whereNull('server_type')->update(['server_type' => 'generic']);

                Schema::table('servers', function (Blueprint $table) {
                    $table->dropColumn('panel_type');
                });

                Schema::table('servers', function (Blueprint $table) {
                    $table->string('server_type', 50)->nullable(false)->change();
                });
            } else {
                // server_type already exists and panel_type gone — ensure backfill for custom->generic/virtualizor
                // No-op if already migrated; idempotent custom handling for any leftover generic misclassification
                // (do not overwrite explicit values)
            }

            // 2) Add module_id FK nullable nullOnDelete
            if (! Schema::hasColumn('servers', 'module_id')) {
                Schema::table('servers', function (Blueprint $table) {
                    $table->foreignId('module_id')->nullable()->after('server_type')->constrained('modules')->nullOnDelete();
                });
            }

            // 3) Add connection_status enum default utested
            if (! Schema::hasColumn('servers', 'connection_status')) {
                Schema::table('servers', function (Blueprint $table) {
                    $table->enum('connection_status', ['untested', 'connected', 'failed'])->default('untested')->after('status');
                });
            }

            // 4) Add last_checked_at datetime nullable
            if (! Schema::hasColumn('servers', 'last_checked_at')) {
                Schema::table('servers', function (Blueprint $table) {
                    $table->dateTime('last_checked_at')->nullable()->after('connection_status');
                });
            }

            // 5) Add connection_meta JSON nullable
            if (! Schema::hasColumn('servers', 'connection_meta')) {
                Schema::table('servers', function (Blueprint $table) {
                    $table->json('connection_meta')->nullable()->after('last_checked_at');
                });
            }

            // 6) Add connection_error TEXT nullable
            if (! Schema::hasColumn('servers', 'connection_error')) {
                Schema::table('servers', function (Blueprint $table) {
                    $table->text('connection_error')->nullable()->after('connection_meta');
                });
            }

            // 7) Add api_password_encrypted TEXT nullable
            if (! Schema::hasColumn('servers', 'api_password_encrypted')) {
                Schema::table('servers', function (Blueprint $table) {
                    $table->text('api_password_encrypted')->nullable()->after('api_username');
                });
            }
        }

        // --- server_groups: allowed_server_type ---
        if (Schema::hasTable('server_groups')) {
            if (! Schema::hasColumn('server_groups', 'allowed_server_type')) {
                Schema::table('server_groups', function (Blueprint $table) {
                    $table->string('allowed_server_type', 50)->nullable()->after('load_balancing');
                });
            }

            // Backfill (idempotent)
            DB::table('server_groups')->where('name', 'Primary cPanel Servers')->where(function ($q) {
                $q->whereNull('allowed_server_type')->orWhere('allowed_server_type', '!=', 'cpanel');
            })->update(['allowed_server_type' => 'cpanel']);

            DB::table('server_groups')->where('name', 'VPS Nodes')->where(function ($q) {
                $q->whereNull('allowed_server_type')->orWhere('allowed_server_type', '!=', 'virtualizor');
            })->update(['allowed_server_type' => 'virtualizor']);
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // Reverse server_groups
        if (Schema::hasTable('server_groups') && Schema::hasColumn('server_groups', 'allowed_server_type')) {
            Schema::table('server_groups', function (Blueprint $table) {
                $table->dropColumn('allowed_server_type');
            });
        }

        if (Schema::hasTable('servers')) {
            // Drop added columns (reverse order)
            if (Schema::hasColumn('servers', 'api_password_encrypted')) {
                Schema::table('servers', function (Blueprint $table) {
                    $table->dropColumn('api_password_encrypted');
                });
            }

            if (Schema::hasColumn('servers', 'connection_error')) {
                Schema::table('servers', function (Blueprint $table) {
                    $table->dropColumn('connection_error');
                });
            }

            if (Schema::hasColumn('servers', 'connection_meta')) {
                Schema::table('servers', function (Blueprint $table) {
                    $table->dropColumn('connection_meta');
                });
            }

            if (Schema::hasColumn('servers', 'last_checked_at')) {
                Schema::table('servers', function (Blueprint $table) {
                    $table->dropColumn('last_checked_at');
                });
            }

            if (Schema::hasColumn('servers', 'connection_status')) {
                // Drop enum/check column
                Schema::table('servers', function (Blueprint $table) {
                    $table->dropColumn('connection_status');
                });
            }

            if (Schema::hasColumn('servers', 'module_id')) {
                Schema::table('servers', function (Blueprint $table) {
                    // Drop foreign key first if exists (driver aware)
                    try {
                        $table->dropForeign(['module_id']);
                    } catch (\Throwable $e) {
                        // ignore if constraint missing (sqlite)
                    }
                    $table->dropColumn('module_id');
                });
            }

            // Restore panel_type ENUM and drop server_type
            $hasServerType = Schema::hasColumn('servers', 'server_type');
            $hasPanelType = Schema::hasColumn('servers', 'panel_type');

            if ($hasServerType && ! $hasPanelType) {
                // Recreate panel_type
                Schema::table('servers', function (Blueprint $table) {
                    $table->enum('panel_type', self::PANEL_TYPES)->default('cpanel')->after('ip_address');
                });

                // Backfill panel_type from server_type
                // Known enums map directly, everything else -> custom
                foreach (['cpanel', 'plesk', 'directadmin', 'virtualizor'] as $type) {
                    DB::table('servers')->where('server_type', $type)->update(['panel_type' => $type]);
                }
                // hyperv, proxmox, generic, etc -> custom
                DB::table('servers')->whereNotIn('server_type', self::PANEL_TYPES)->update(['panel_type' => 'custom']);

                Schema::table('servers', function (Blueprint $table) {
                    $table->dropColumn('server_type');
                });
            } elseif (! $hasServerType && ! $hasPanelType) {
                Schema::table('servers', function (Blueprint $table) {
                    $table->enum('panel_type', self::PANEL_TYPES)->default('cpanel')->after('ip_address');
                });
            }
            // If both exist, just drop server_type (panel_type already there)
            if (Schema::hasColumn('servers', 'server_type') && Schema::hasColumn('servers', 'panel_type')) {
                Schema::table('servers', function (Blueprint $table) {
                    $table->dropColumn('server_type');
                });
            }
        }
    }
};
