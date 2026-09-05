<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes the columns the purge filters on, so its OR can be served by an index.
 *
 * Both purges are a disjunction, and PostgreSQL reaches for an index only when EVERY arm
 * of one is covered. An index on `expires_at` alone leaves the `consumed_at` arm without
 * one, so the planner falls back to a sequential scan over the whole table however
 * selective the predicate is. These are the missing arms.
 *
 * The statement the numbers below describe is not the one written in the store. Laravel
 * cannot emit `DELETE ... LIMIT` on PostgreSQL, so it rewrites the chunk into
 * `delete from t where ctid in (select ctid from t where ... limit n)`. That is not
 * cosmetic: the Tid Scan above the subquery costs roughly a buffer hit per row and puts a
 * floor of about 1 ms under a chunk of 1000, which is why the 98% row above shows the same
 * time with and without the indexes although the scan itself differs by 0.16 ms. Read the
 * table as a comparison between two plans, never as the cost of the DELETE.
 *
 * Measured on PostgreSQL 18, chunk 1000, plans read with EXPLAIN ANALYZE. The steady state
 * is the honest row -- 260 hourly purge cycles against a 7-day TTL, 134k rows surviving,
 * 0.6% qualifying -- because that is the shape a real invitation table settles into:
 *
 *   regime                       with these indexes        without
 *   98% qualifying, 200k rows    Seq Scan, ~1.0 ms         Seq Scan, ~1.0 ms
 *   1% qualifying,  200k rows    BitmapOr, 3.3 ms          Seq Scan, 9.0 ms
 *   steady state,   134k rows    BitmapOr, 0.91 ms         Seq Scan, 10.05 ms
 *
 * So: free in the regime a short-lived sign-in link produces, where almost every row
 * qualifies and a sequential scan is genuinely the right plan, and worth about ELEVEN
 * times the runtime in the regime a seven-day invitation with a daily purge produces.
 *
 * The number here said "roughly fifty times", and it was wrong by a factor of five. It
 * came from a 35.8 ms reading that could not be reproduced warm: it compared a COLD
 * sequential scan, paying dirty-buffer writeback, against a warm bitmap scan. Measured
 * symmetrically -- both sides cold, or both warm -- the ratio is 3x for a single chunk and
 * 11x over a steady-state purge. A ratio between two differently-warmed measurements is
 * not a ratio, and it flattered the decision this file already justifies on its own.
 *
 * The planner stops using them between 5% and 10% qualifying, measured. And the worst case
 * for the unindexed side is NOT the smallest slice: while fewer than `chunk` rows qualify
 * the LIMIT never engages, so the scan reads the whole table however little it finds.
 *
 * Plain indexes, not partial ones. Correct, but not for the reason written here before:
 * a partial index produces the same BitmapOr and is far SMALLER, not marginally so
 * (1368 kB against 16 kB on the 1% fixture, against 8 kB in the steady state), so "the
 * last few percent" understated it by two orders of magnitude. It also does not save the
 * write cost below, because PostgreSQL counts a predicate's columns among the attributes
 * that block a heap-only update. What actually decides it: a partial index does not exist
 * on MySQL 8.4, and one schema file for both engines is worth more than a size win on one.
 *
 * WHAT THESE INDEXES COST, which nothing here said. Before them, the claim path's UPDATE
 * touched only unindexed columns and stayed a heap-only tuple update. With an index on
 * `consumed_at` it cannot. Measured over 10,000 real claims, with a control updating only
 * never-indexed columns in the same row:
 *
 *                  control        the claim statement
 *   without index  100% HOT       100% HOT
 *   with index     100% HOT         0% HOT      60,089 WAL records vs 10,000
 *
 * About 16 microseconds per claim, so the latency is irrelevant; the real price is vacuum
 * pressure and index bloat over time. It is worth paying, and it should be written down.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->add('magic_link_tokens', 'consumed_at');
        $this->add('email_magic_link_invitations', 'accepted_at');
        $this->add('email_magic_link_invitations', 'revoked_at');
    }

    public function down(): void
    {
        $this->drop('magic_link_tokens', 'consumed_at');
        $this->drop('email_magic_link_invitations', 'accepted_at');
        $this->drop('email_magic_link_invitations', 'revoked_at');
    }

    /**
     * Guarded on the INDEX, not only on the table, and both halves are load-bearing.
     *
     * The invitations tables are optional: a host that declined them still has to be able to
     * run this migration. And a host that adopts them later runs the create-table migration
     * AFTER this one is already recorded, so this one never runs again -- which is why the
     * create-table migration carries the same two indexes and this one skips what it finds.
     *
     * The per-index guard also survives a half-applied run. MySQL has no schema transactions,
     * so a migration killed between the first and second CREATE INDEX leaves the first one
     * behind and is never recorded: without this guard the retry dies on a duplicate key name
     * and `migrate:rollback` cannot help, because there is no row to roll back.
     */
    private function add(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || Schema::hasIndex($table, [$column])) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->index($column);
        });
    }

    private function drop(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasIndex($table, [$column])) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->dropIndex([$column]);
        });
    }
};
