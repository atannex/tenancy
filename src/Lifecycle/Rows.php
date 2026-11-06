<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Lifecycle;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Reading and writing a tenant's rows in bounded memory, for export, import
 * and promotion.
 *
 * Rows keep their primary keys, so references between tables survive. On
 * the shared tier, other tenants' rows share the table: an auto-increment key
 * that already exists there is refused rather than overwritten or renumbered
 * (renumbering would break every foreign key pointing at it). Tables that
 * must move into the shared tier freely should use ULID or UUID keys.
 *
 * @internal
 */
final class Rows
{
    public const int CHUNK = 500;

    /**
     * Rows in chunks with keyset paging when the table has an "id" column, so
     * a big table never sits in memory. Other tables (pivots) are read with
     * one cursor: offset paging over a non-unique order could skip or repeat
     * rows.
     *
     * @return iterable<array<string, mixed>>
     */
    public static function read(Connection $connection, string $table, ?string $column, string $key): iterable
    {
        $columns = $connection->getSchemaBuilder()->getColumnListing($table);
        $query = $connection->table($table);
        $column && $query->where($column, $key);

        $rows = in_array('id', $columns, true)
            ? $query->lazyById(self::CHUNK, 'id')
            : $query->cursor();

        foreach ($rows as $row) {
            yield (array) $row;
        }
    }

    /**
     * Insert rows in batches. $shared: the table also holds other tenants'
     * rows. Returns how many rows were written.
     *
     * @param  iterable<array<string, mixed>>  $rows
     */
    public static function write(Connection $connection, string $table, iterable $rows, bool $shared): int
    {
        $increment = self::autoIncrement($connection, $table);
        $batch = [];
        $written = 0;

        foreach ($rows as $row) {
            $batch[] = $row;

            if (count($batch) >= self::CHUNK) {
                $written += self::insert($connection, $table, $batch, $shared ? $increment : null);
                $batch = [];
            }
        }

        $written += self::insert($connection, $table, $batch, $shared ? $increment : null);

        if ($written > 0 && $increment !== null) {
            self::syncSequence($connection, $table, $increment);
        }

        return $written;
    }

    /**
     * Insert one batch. $increment: the auto-increment key to check first
     * against rows other tenants already hold on the shared tier.
     *
     * @param  list<array<string, mixed>>  $batch
     */
    private static function insert(Connection $connection, string $table, array $batch, ?string $increment): int
    {
        if ($batch === []) {
            return 0;
        }

        if ($increment !== null) {
            $taken = $connection->table($table)->whereIn($increment, array_column($batch, $increment))->limit(1)->value($increment);

            if ($taken !== null) {
                throw new RuntimeException("Row [{$table}.{$increment} = {$taken}] already exists on the shared tier. Tables with auto-increment keys can only move into the shared tier when their ids do not overlap; use ULID or UUID keys.");
            }
        }

        $connection->table($table)->insert($batch);

        return count($batch);
    }

    /** The auto-increment primary key column, if the table has one. */
    public static function autoIncrement(Connection $connection, string $table): ?string
    {
        foreach ($connection->getSchemaBuilder()->getColumns($table) as $column) {
            if ($column['auto_increment']) {
                return $column['name'];
            }
        }

        return null;
    }

    /**
     * PostgreSQL sequences don't move when ids are inserted explicitly, so the
     * next ordinary insert would collide. MySQL, MariaDB and SQLite adjust.
     */
    private static function syncSequence(Connection $connection, string $table, string $column): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $qualified = $connection->getTablePrefix().$table;

        $connection->statement(
            "SELECT setval(pg_get_serial_sequence(?, ?), GREATEST(COALESCE((SELECT MAX(\"{$column}\") FROM \"{$qualified}\"), 0), 1))",
            [$qualified, $column],
        );
    }
}
