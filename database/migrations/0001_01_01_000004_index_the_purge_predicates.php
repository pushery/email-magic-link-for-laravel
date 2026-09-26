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
 * one, so the planner falls back to reading the table, walking the primary key or scanning
 * all of it, however selective the predicate is. These are the missing arms.
 *
 * A chunk is two statements: `select id ... order by id limit n for update skip locked`,
 * then `delete ... where id in (...)`. The DELETE goes by primary key and costs the same
 * with and without these indexes, 0.8 ms for a chunk of 800. The SELECT is what they
 * decide, and it is what the table below times.
 *
 * Measured on PostgreSQL 18 over the token table's purge, chunk 1000, plans read with
 * EXPLAIN (ANALYZE, BUFFERS), both sides warm: the best of five runs after a warm-up. The
 * steady state is the honest row -- 260 hourly purge cycles of the store's own loop against
 * a 7-day TTL, 134k rows surviving, 0.6% qualifying -- because that is the shape a table
 * with a long lifetime and a frequent purge settles into:
 *
 *   regime                       with these indexes           without
 *   98% qualifying, 200k rows    primary key walk, 0.26 ms    primary key walk, 0.26 ms
 *   5% qualifying,  200k rows    primary key walk, 1.4 ms     primary key walk, 1.4 ms
 *   1% qualifying,  200k rows    BitmapOr, 0.77 ms            primary key walk, 6.4 ms
 *   steady state,   134k rows    BitmapOr, 0.23 ms            Seq Scan, 5.6 ms
 *
 * So: free in the regime a short-lived sign-in link produces, where almost every row
 * qualifies and walking the primary key in id order finds a chunk in the first rows it
 * reads, and worth about TWENTY-FOUR times the runtime in the steady state.
 *
 * Both sides are measured equally warm on purpose. A cold scan, paying for its reads,
 * against a warm bitmap scan would suggest a larger factor; a ratio between two differently
 * warmed measurements is not a ratio.
 *
 * The planner stops using them between 1% and 5% qualifying, measured: above that, the
 * primary key reaches a full chunk sooner. And the worst case for the unindexed side is NOT
 * the smallest slice: while fewer than `chunk` rows qualify the LIMIT never engages, so the
 * scan reads the whole table however little it finds.
 *
 * The invitation purge's predicate, three arms with conjunctions, was measured only in the
 * purge's earlier form, a single limited DELETE; its two indexes rest on the same argument.
 *
 * Plain indexes, not partial ones, and not for size: in that earlier measurement a partial
 * index produced the same BitmapOr and was far SMALLER (1368 kB against 16 kB on the 1%
 * fixture, against 8 kB in the steady state). It also does not save the
 * write cost below, because PostgreSQL counts a predicate's columns among the attributes
 * that block a heap-only update. What actually decides it: a partial index does not exist
 * on MySQL 8.4, and one schema file for both engines is worth more than a size win on one.
 *
 * WHAT THESE INDEXES COST. Without them, the claim path's UPDATE touches only unindexed
 * columns and stays a heap-only tuple update. With an index on `consumed_at` it cannot.
 * Measured over 10,000 real claims, with a control updating only
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

    /**
     * Only the index no other migration creates.
     *
     * The create-table migration carries the two invitation indexes itself, so they belong to
     * it and go when its table goes. Dropping them here as well left that migration recorded
     * as run and its table without the indexes the purge filters on, after nothing more than
     * a one-step rollback.
     */
    public function down(): void
    {
        $this->drop('magic_link_tokens', 'consumed_at');
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
