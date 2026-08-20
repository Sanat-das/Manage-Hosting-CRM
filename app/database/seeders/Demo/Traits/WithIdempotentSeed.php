<?php

namespace Database\Seeders\Demo\Traits;

use Database\Seeders\Demo\DummyDataConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent seeding helpers.
 *
 * CONTRACT
 * --------
 * Running a seeder that uses this trait N times must leave the database in
 * exactly the same state as running it once. Every write is matched against
 * the table's natural key from `DummyDataConfig::NATURAL_KEYS`, so a second
 * run updates the existing row instead of inserting a duplicate.
 *
 * WHY QUERY BUILDER AND NOT ELOQUENT
 * ----------------------------------
 * Several tables in this schema have irregular timestamp columns: some have
 * `created_at` only (`payments`, `server_groups`, `customer_wallet`, ...),
 * one uses `changed_at` (`ip_allocation_history`), and some have none at all
 * (`invoice_items`, `product_option_values`). Eloquent would blindly write
 * `updated_at` and fail. These helpers inspect the real column list via
 * `Schema::hasColumn()` and only stamp the timestamp columns that exist.
 *
 * BEHAVIOUR OF EACH HELPER
 * ------------------------
 * - `seedRow()`      insert-or-update one row (`updateOrCreate` semantics):
 *                    match on the natural key, overwrite the other attributes.
 * - `seedRowOnce()`  insert-if-missing (`firstOrCreate` semantics): match on
 *                    the natural key, leave an existing row untouched. Use for
 *                    rows a human may have edited (settings, config).
 * - `seedRows()`     bulk version of `seedRow()`; returns the number of rows
 *                    that now exist for the given natural keys.
 * - `seedUpTo()`     tops a table up to `DummyDataConfig::ROWS[$table]` using
 *                    a generator callback, skipping rows that already exist.
 * - `attachPivot()`  idempotent pivot insert (no timestamps assumed).
 *
 * All helpers return the row's primary key value where one exists, so callers
 * can wire up foreign keys without a follow-up query.
 */
trait WithIdempotentSeed
{
    /** @var array<string, list<string>> cached column lists per table */
    private array $columnCache = [];

    /**
     * Insert or update a single row, matched on its natural key.
     *
     * @param  string  $table  target table
     * @param  array<string, mixed>  $attributes  full row payload (natural key columns included)
     * @return int|string|null primary key of the row, null when the table has no `id`
     */
    protected function seedRow(string $table, array $attributes): int|string|null
    {
        $match = $this->naturalKeyValues($table, $attributes);
        $payload = $this->withTimestamps($table, $attributes, $this->rowExists($table, $match));

        DB::table($table)->updateOrInsert($match, $payload);

        return $this->primaryKeyOf($table, $match);
    }

    /**
     * Insert the row only when no row matches its natural key.
     * Mirrors `Model::firstOrCreate()` and the style used by `InitialDataSeeder`.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function seedRowOnce(string $table, array $attributes): int|string|null
    {
        $match = $this->naturalKeyValues($table, $attributes);

        if (! $this->rowExists($table, $match)) {
            DB::table($table)->insert($this->withTimestamps($table, $attributes, false));
        }

        return $this->primaryKeyOf($table, $match);
    }

    /**
     * Seed many rows idempotently.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @return list<int|string|null> primary keys in input order
     */
    protected function seedRows(string $table, iterable $rows): array
    {
        $keys = [];

        foreach ($rows as $row) {
            $keys[] = $this->seedRow($table, $row);
        }

        return $keys;
    }

    /**
     * Top a table up to its configured minimum from `DummyDataConfig::ROWS`.
     *
     * The generator receives the zero-based index of the row being produced
     * and must return a full attribute array. Rows whose natural key already
     * exists are updated rather than duplicated, so calling this repeatedly
     * is safe.
     *
     * @param  callable(int): array<string, mixed>  $generator
     * @return int row count in the table afterwards
     */
    protected function seedUpTo(string $table, callable $generator, ?int $target = null): int
    {
        $target ??= DummyDataConfig::minRows($table);

        for ($i = 0; $i < $target; $i++) {
            $this->seedRow($table, $generator($i));
        }

        return (int) DB::table($table)->count();
    }

    /**
     * Idempotent pivot / join-table insert. Pivot tables in this schema carry
     * no timestamps, so nothing is stamped.
     *
     * @param  array<string, mixed>  $keys  the full composite key
     */
    protected function attachPivot(string $table, array $keys): void
    {
        if (! $this->rowExists($table, $keys)) {
            DB::table($table)->insert($keys);
        }
    }

    /** True when the table already holds at least its configured minimum rows. */
    protected function isSatisfied(string $table): bool
    {
        return DB::table($table)->count() >= DummyDataConfig::minRows($table);
    }

    /**
     * Extract the natural key columns out of a full attribute payload.
     * Falls back to the whole payload when the table declares no key, which
     * still guarantees "insert only if this exact row is absent".
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function naturalKeyValues(string $table, array $attributes): array
    {
        $columns = DummyDataConfig::naturalKey($table);
        $match = [];

        foreach ($columns as $column) {
            if (array_key_exists($column, $attributes)) {
                $match[$column] = $attributes[$column];
            }
        }

        return $match !== [] ? $match : $attributes;
    }

    /**
     * Add only those timestamp columns that physically exist on the table.
     * `created_at` is preserved on updates; `updated_at` is always refreshed.
     * `ip_allocation_history` uses `changed_at` and is handled here too.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function withTimestamps(string $table, array $attributes, bool $exists): array
    {
        $now = now();
        $columns = $this->columnsOf($table);

        if (in_array('created_at', $columns, true) && ! $exists && ! array_key_exists('created_at', $attributes)) {
            $attributes['created_at'] = $now;
        }

        if (in_array('updated_at', $columns, true) && ! array_key_exists('updated_at', $attributes)) {
            $attributes['updated_at'] = $now;
        }

        if (in_array('changed_at', $columns, true) && ! array_key_exists('changed_at', $attributes)) {
            $attributes['changed_at'] = $now;
        }

        return array_intersect_key($attributes, array_flip($columns));
    }

    /** @param array<string, mixed> $match */
    protected function rowExists(string $table, array $match): bool
    {
        return DB::table($table)->where($match)->exists();
    }

    /**
     * Fetch the primary key of the row matching `$match`, when the table has
     * an `id` column. Composite-key tables return null.
     *
     * @param  array<string, mixed>  $match
     */
    protected function primaryKeyOf(string $table, array $match): int|string|null
    {
        if (! in_array('id', $this->columnsOf($table), true)) {
            return null;
        }

        return DB::table($table)->where($match)->value('id');
    }

    /**
     * Column list for a table, cached for the lifetime of the seeder run.
     *
     * @return list<string>
     */
    protected function columnsOf(string $table): array
    {
        return $this->columnCache[$table] ??= Schema::getColumnListing($table);
    }
}
