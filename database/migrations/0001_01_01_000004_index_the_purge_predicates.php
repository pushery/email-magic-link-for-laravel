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
 * Measured on PostgreSQL 18, 200k rows, chunk 1000, plans read with EXPLAIN ANALYZE:
 *
 *   qualifying   with these indexes           without
 *   98%          Seq Scan, 0.13 ms            Seq Scan, 0.29 ms
 *   1%           BitmapOr on both, 0.62 ms    Seq Scan, 35.8 ms
 *
 * They are free in the regime a short-lived sign-in link produces, where almost every row
 * qualifies and a sequential scan is genuinely the right plan, and worth roughly fifty
 * times the runtime in the regime a seven-day invitation with a daily purge produces,
 * where only a small slice qualifies. Which regime a table is in depends on the host's TTL
 * and purge cadence, so the choice belongs to the planner rather than to this file.
 *
 * Plain indexes, not partial ones. A partial index would be tighter on PostgreSQL and does
 * not exist on MySQL 8.4, and one schema file for both engines is worth more here than the
 * last few percent on one of them.
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
     * The invitations table is optional: a host that declined it still has to be able to
     * run this migration, and one that adopts invitations later gets the indexes from the
     * create-table migration rather than from here.
     */
    private function add(string $table, string $column): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->index($column);
        });
    }

    private function drop(string $table, string $column): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->dropIndex([$column]);
        });
    }
};
