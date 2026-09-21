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

        // 5) product_module: detach module_id (FK + indexes) so the column can
        //    be dropped. Driver split: sqlite has no ALTER ... DROP CONSTRAINT
        //    and relies on Laravel rebuilding the table from BlueprintState
        //    when a dropForeign command is present; MySQL/MariaDB InnoDB refuses
        //    to drop a column whose index is still serving a sibling FK
        //    (error 1553/1072 — the product_id FK was using the old
        //    (product_id, module_id) unique index), so every FK is discovered
        //    by its real name and dropped FIRST. The product_id FK is restored
        //    by step 7b once the replacement unique index exists.
        if (Schema::hasTable('product_module') && Schema::hasColumn('product_module', 'module_id')) {
            if ($isSqlite) {
                try {
                    Schema::table('product_module', function (Blueprint $table) {
                        $table->dropForeign(['module_id']);
                    });
                } catch (Throwable $e) {
                    // constraint may be absent (partial run)
                }

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
            } else {
                // MySQL / MariaDB — discover the real constraint names; guessed
                // names miss installations where they have drifted.
                $foreignKeys = DB::select(
                    'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE'
                    .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
                    ['product_module']
                );

                foreach ($foreignKeys as $foreignKey) {
                    try {
                        Schema::table('product_module', function (Blueprint $table) use ($foreignKey) {
                            $table->dropForeign($foreignKey->CONSTRAINT_NAME);
                        });
                    } catch (Throwable $e) {
                        // constraint may be absent (partial run)
                    }
                }

                // Indexes that still contain module_id: the old unique pairing
                // and the standalone index MariaDB leaves behind after dropping
                // the module_id FK. Both must go before the column.
                foreach (['product_module_product_id_module_id_unique', 'product_module_module_id_foreign'] as $legacyIndex) {
                    $legacyIndexExists = DB::select(
                        'SELECT 1 FROM information_schema.STATISTICS'
                        .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
                        ['product_module', $legacyIndex]
                    );

                    if ($legacyIndexExists !== []) {
                        try {
                            Schema::table('product_module', function (Blueprint $table) use ($legacyIndex) {
                                $table->dropIndex($legacyIndex);
                            });
                        } catch (Throwable $e) {
                            // index may already be gone
                        }
                    }
                }
            }

            // 6) Drop the column. On sqlite this rewrites the table; on
            //    MySQL/MariaDB every FK/index that kept it alive is detached.
            Schema::table('product_module', function (Blueprint $table) {
                $table->dropColumn('module_id');
            });
        }

        // 7) Add unique(['product_id','module_slug']) (guard against already existing)
        if (Schema::hasTable('product_module') && Schema::hasColumn('product_module', 'module_slug')) {
            // Check if index already exists — sqlite via sqlite_master, MySQL via information_schema
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
            } else {
                $slugUniqueExists = DB::select(
                    'SELECT 1 FROM information_schema.STATISTICS'
                    .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
                    ['product_module', 'product_module_product_id_module_slug_unique']
                );
                $needsIndex = $slugUniqueExists === [];
            }

            if ($needsIndex) {
                Schema::table('product_module', function (Blueprint $table) {
                    $table->unique(['product_id', 'module_slug']);
                });
            }

            // 7b) MySQL/MariaDB: step 5 detached the product_id FK because the
            //     old unique index that backed it also contained module_id.
            //     Restore it now that the replacement index can serve it.
            if (! $isSqlite) {
                $productFkExists = DB::select(
                    'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE'
                    .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1',
                    ['product_module', 'product_id']
                );

                if ($productFkExists === []) {
                    Schema::table('product_module', function (Blueprint $table) {
                        $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
                    });
                }
            }
        }

        // 8) servers: drop module_id (and its FK/index) — servers are identified
        //    by server_type now.
        if (Schema::hasTable('servers') && Schema::hasColumn('servers', 'module_id')) {
            // Drop the FK on every driver so the sqlite table rebuild removes it
            // before the column goes away.
            try {
                Schema::table('servers', function (Blueprint $table) {
                    $table->dropForeign(['module_id']);
                });
            } catch (Throwable $e) {
                // constraint may be absent (partial run)
            }

            if (! $isSqlite) {
                // MariaDB keeps the FK's backing index after DROP FOREIGN KEY;
                // drop it explicitly so the column removal is unconstrained.
                $serverIndexExists = DB::select(
                    'SELECT 1 FROM information_schema.STATISTICS'
                    .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
                    ['servers', 'servers_module_id_foreign']
                );

                if ($serverIndexExists !== []) {
                    try {
                        Schema::table('servers', function (Blueprint $table) {
                            $table->dropIndex('servers_module_id_foreign');
                        });
                    } catch (Throwable $e) {
                        // index may already be gone
                    }
                }
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
