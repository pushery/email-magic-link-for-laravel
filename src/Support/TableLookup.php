<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use Illuminate\Support\Facades\DB;

/**
 * Table lookups that resolve a name the way a query on the same connection does.
 *
 * On PostgreSQL the schema builder looks for an unqualified table in `current_schema()`, the first
 * schema of the `search_path` that exists, while a query finds the table anywhere along the
 * `search_path`. A host that keeps a schema per tenant, with `search_path` set to `tenant, public`
 * and this package's tables in `public`, gets `false` from `Schema::hasTable()` for a table every
 * query reads, and no columns or indexes for it either. Every other driver already answers the
 * way its queries do.
 *
 * @internal
 */
final class TableLookup
{
    /**
     * Whether a query on the connection finds the table.
     *
     * On PostgreSQL the name is resolved with `to_regclass()`, which walks the `search_path` as a
     * query does, quoted and prefixed the way the query builder writes it. What it resolves to
     * counts when it is a table, ordinary or partitioned, which is what `hasTable()` asks for: an
     * index, a sequence or a view of that name is no table. The lookup asks whether a row comes
     * back rather than reading a boolean, so a connection that stringifies its fetches gets the
     * same answer.
     */
    public static function exists(string $table, ?string $connection = null): bool
    {
        $database = DB::connection($connection);

        if ($database->getDriverName() !== 'pgsql') {
            return $database->getSchemaBuilder()->hasTable($table);
        }

        return $database->scalar(
            "select 1 from pg_class where oid = to_regclass(?) and relkind in ('r', 'p')",
            [$database->getQueryGrammar()->wrapTable($table)],
        ) !== null;
    }

    /**
     * The table name with the schema a query on the connection finds it in, for the schema
     * builder's column and index lookups: `public.users` where the `search_path` resolves `users`
     * to `public`. The name comes back unchanged on every other driver, when it names its schema
     * already, and for a table no query finds.
     */
    public static function qualified(string $table, ?string $connection = null): string
    {
        $database = DB::connection($connection);

        if ($database->getDriverName() !== 'pgsql' || str_contains($table, '.')) {
            return $table;
        }

        $schema = $database->scalar(
            'select n.nspname from pg_class c join pg_namespace n on n.oid = c.relnamespace where c.oid = to_regclass(?)',
            [$database->getQueryGrammar()->wrapTable($table)],
        );

        return is_string($schema) ? "{$schema}.{$table}" : $table;
    }
}
