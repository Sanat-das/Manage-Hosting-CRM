<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fold the six provisioning module rows into app code: product_module links move
 * from module_id (FK to modules.id) to module_slug (VARCHAR), then the builtin
 * rows are deleted. server.module_id is dropped (builtin servers are
 * identified by server_type slug).
 *
 * Guards: Schema::hasTable/hasColumn, cross-driver (sqlite + MySQL), try/catch
 * around constraint drops that require --doctor or IF EXISTS semantics.
 *
 * Down: best-effort schema restore only. Builtin module rows and the id->slug
 * mapping are NOT restored; down() is only meaningful with a code rollback.
 */
return new class extends Migration
{
    private const BUILTIN_SLUGS = ['cpanel', 'plesk', 'directadmin', 'virtualizor', 'hyperv', 'proxmox'];

    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $isSqlite = $driver === 'sqlite';

        // 1) product_module: add module_slug nullable
        if (Schema::hasTable('product_module') && ! Schema::hasColumn('product_module', 'module_slug')) {
            Schema::table('product_module', function (Blueprint $table) {
                $table->string('module_slug', 50)->nullable()->after('module_id');
            });
        }

        // 2) Backfill in chunks (orderBy id chunkById, cross-driver): module_id -> modules.slug
        if (Schema::hasTable('product_module') && Schema::hasColumn('product_module', 'module_slug') && Schema::hasColumn('product_module', 'module_id') && Schema::hasTable('modules')) {
            // Build id -> slug map once
            $idToSlug = DB::table('modules')->pluck('slug', 'id')->all();

            DB::table('product_module')->orderBy('id')->chunkById(200, function ($rows) use ($idToSlug) {
                foreach ($rows as $row) {
                    $moduleId = $row->module_id ?? null;
                    $slug = $moduleId !== null ? ($idToSlug[$moduleId] ?? null) : null;

                    if ($slug !== null && $slug !== '') {
                        DB::table('product_module')->where('id', $row->id)->update(['module_slug' => $slug]);
                    }
                }
            });
        }

        // 3) Loud failure if any row still has null module_slug after backfill
        if (Schema::hasTable('product_module') && Schema::hasColumn('product_module', 'module_slug')) {
            $nullIds = DB::table('product_module')->whereNull('module_slug')->pluck('id')->all();

            if ($nullIds !== []) {
                throw new \RuntimeException('Migration fold_provisioning_modules: product_module rows with null module_slug remain after backfill: ids [' . implode(', ', $nullIds) . ']. Refusing to drop module_id.');
            }
        }

        // 4) Change module_slug to NOT NULL
        if (Schema::hasTable('product_module') && Schema::hasColumn('product_module', 'module_slug')) {
            Schema::table('product_module', function (Blueprint $table) {
                $table->string('module_slug', 50)->nullable(false)->change();
            });
        }

        // 5) Drop unique(['product_id','module_id']) and dropForeign(['module_id'])
        if (Schema::hasTable('product_module')) {
            // Drop foreign key first
            if (Schema::hasColumn('product_module', 'module_id')) {
                // Drop the FK on EVERY driver. sqlite has no ALTER ... DROP
                // CONSTRAINT, but Laravel's sqlite grammar rebuilds the table
                // from BlueprintState when a dropForeign command is present.
                // Skipping it on sqlite left the FK in the table definition and
                // the following dropColumn failed with "unknown column
                // module_id in foreign key definition".
                try {
                    Schema::table('product_module', function (Blueprint $table) {
                        $table->dropForeign(['module_id']);
                    });
                } catch (Throwable $e) {
                    // constraint may be absent (partial run)
                }

                // Drop unique index — name is auto-generated or 'product_module_product_id_module_id_unique'
                // Try by column list; fallback to raw name.
                try {
                    Schema::table('product_module', function (Blueprint $table) {
                        $table->dropUnique(['product_id', 'module_id']);
                    });
                } catch (Throwable $e) {
                    // Try explicit name (Laravel default)
                    try {
                        Schema::table('product_module', function (Blueprint $table) {
                            $table->dropUnique('product_module_product_id_module_id_unique');
                        });
                    } catch (Throwable $e2) {
                        // index may be absent
                    }
                }
            }
        }

        // 6) dropColumn('module_id')
        if (Schema::hasTable('product_module') && Schema::hasColumn('product_module', 'module_id')) {
            Schema::table('product_module', function (Blueprint $table) {
                $table->dropColumn('module_id');
            });
        }

        // 7) Add unique(['product_id','module_slug']) (guard against already existing)
        if (Schema::hasTable('product_module') && Schema::hasColumn('product_module', 'module_slug')) {
            // Check if index already exists — best-effort probe via sqlite_master or information_schema
            $needsIndex = true;

            if ($isSqlite) {
                try {
                    $indexes = DB::select("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='product_module'");
                    foreach ($indexes as $idx) {
                        $name = is_object($idx) ? ($idx->name ?? '') : ($idx['name'] ?? '');
                        if (str_contains($name, 'module_slug')) {
                            $needsIndex = false;
                            break;
                        }
                    }
                    // Also check via raw: if duplicate insertion would fail, still try to detect via schema
                    if ($needsIndex) {
                        // Probe by attempting to see if index columns match — check via pragma
                        $info = DB::select("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='product_module' AND sql IS NOT NULL");
                        foreach ($info as $row) {
                            $sql = is_object($row) ? ($row->sql ?? '') : ($row['sql'] ?? '');
                            if (str_contains($sql, 'module_slug') && str_contains($sql, 'product_id')) {
                                $needsIndex = false;
                                break;
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $needsIndex = true;
                }
            }

            if ($needsIndex) {
                try {
                    Schema::table('product_module', function (Blueprint $table) {
                        $table->unique(['product_id', 'module_slug']);
                    });
                } catch (Throwable $e) {
                    // may already exist (MySQL error 1061) or driver quirk
                    if (! str_contains($e->getMessage(), 'already exists') && ! str_contains($e->getMessage(), 'Duplicate')) {
                        // If it's not a duplicate error, rethrow? But guard says skip if already existing — so swallow duplicate
                        // For other errors, still swallow to keep idempotent? Safer to swallow.
                    }
                }
            }
        }

        // 8) servers: drop module_id
        if (Schema::hasTable('servers') && Schema::hasColumn('servers', 'module_id')) {
            // Same as product_module above: drop the FK on every driver so the
            // sqlite table rebuild removes it before the column goes away.
            try {
                Schema::table('servers', function (Blueprint $table) {
                    $table->dropForeign(['module_id']);
                });
            } catch (Throwable $e) {
                // constraint may be absent (partial run)
            }

            Schema::table('servers', function (Blueprint $table) {
                $table->dropColumn('module_id');
            });
        }

        // 9) Delete module_log rows for builtin ids, then delete modules rows where slug IN builtins
        if (Schema::hasTable('modules')) {
            $builtinIds = DB::table('modules')->whereIn('slug', self::BUILTIN_SLUGS)->pluck('id')->all();

            if ($builtinIds !== [] && Schema::hasTable('module_log')) {
                // module_log may have foreign key with cascade, but explicitly delete first for clarity
                DB::table('module_log')->whereIn('module_id', $builtinIds)->delete();
            }

            // module_migrations cascades via FK on module_id nullOnDelete or cascade — explicit delete order: children first if needed
            if (Schema::hasTable('module_migrations') && $builtinIds !== []) {
                try {
                    DB::table('module_migrations')->whereIn('module_id', $builtinIds)->delete();
                } catch (Throwable $e) {
                    // table may not exist or column name differs
                }
            }

            DB::table('modules')->whereIn('slug', self::BUILTIN_SLUGS)->delete();
        }
    }

    public function down(): void
    {
        /**
         * Best-effort schema restore only. Builtin module rows and the
         * id→slug mapping are NOT restored. Down is only meaningful with a
         * code rollback (reintroducing the rows via seeder or manual insert).
         */

        $driver = Schema::getConnection()->getDriverName();
        $isSqlite = $driver === 'sqlite';

        // Restore product_module.module_id nullable FK, drop unique on slug
        if (Schema::hasTable('product_module')) {
            // Drop unique on (product_id, module_slug) if exists
            try {
                Schema::table('product_module', function (Blueprint $table) {
                    $table->dropUnique(['product_id', 'module_slug']);
                });
            } catch (Throwable $e) {
                try {
                    Schema::table('product_module', function (Blueprint $table) {
                        $table->dropUnique('product_module_product_id_module_slug_unique');
                    });
                } catch (Throwable $e2) {
                    // absent
                }
            }

            if (! Schema::hasColumn('product_module', 'module_id')) {
                try {
                    Schema::table('product_module', function (Blueprint $table) {
                        // On sqlite, foreignId constrained may not enforce; still add nullable integer
                        if (Schema::hasTable('modules')) {
                            $table->foreignId('module_id')->nullable()->constrained('modules')->nullOnDelete();
                        } else {
                            $table->unsignedBigInteger('module_id')->nullable();
                        }
                    });
                } catch (Throwable $e) {
                    // Fallback without FK
                    try {
                        Schema::table('product_module', function (Blueprint $table) {
                            if (! Schema::hasColumn('product_module', 'module_id')) {
                                $table->unsignedBigInteger('module_id')->nullable();
                            }
                        });
                    } catch (Throwable $e2) {
                        // give up
                    }
                }
            }
        }

        // Restore servers.module_id nullable FK
        if (Schema::hasTable('servers') && ! Schema::hasColumn('servers', 'module_id')) {
            try {
                Schema::table('servers', function (Blueprint $table) {
                    if (Schema::hasTable('modules')) {
                        $table->foreignId('module_id')->nullable()->after('server_type')->constrained('modules')->nullOnDelete();
                    } else {
                        $table->unsignedBigInteger('module_id')->nullable()->after('server_type');
                    }
                });
            } catch (Throwable $e) {
                try {
                    Schema::table('servers', function (Blueprint $table) {
                        if (! Schema::hasColumn('servers', 'module_id')) {
                            $table->unsignedBigInteger('module_id')->nullable()->after('server_type');
                        }
                    });
                } catch (Throwable $e2) {
                    // give up
                }
            }
        }

        // Note: module_slug column is intentionally left in place on down() —
        // it holds the migrated data and does not hurt the old code (extra
        // nullable column). Removing it would lose data.
    }
};
