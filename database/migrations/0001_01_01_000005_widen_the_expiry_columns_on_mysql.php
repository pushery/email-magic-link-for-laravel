<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `expires_at` as a DATETIME on MySQL and MariaDB.
 *
 * `timestamp()` is a MySQL TIMESTAMP, and a TIMESTAMP ends at 2038-01-19 03:14:07 UTC. An expiry
 * past it fails the insert under strict mode and is stored as a zero date without it, which reads
 * as expired from the moment of issue. A DATETIME reaches the year 9999, and the package binds its
 * times as `Y-m-d H:i:s` strings either way. PostgreSQL and SQLite store `timestamp()` without
 * that limit, so on them this migration does nothing.
 *
 * A table the consumer declined is left alone, as the index migration before this one does.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->each(static fn (Blueprint $table) => $table->dateTime('expires_at')->change());
    }

    /**
     * Back to TIMESTAMP. On a table that holds an expiry past 2038 this fails, which is the
     * limit the migration exists to lift.
     */
    public function down(): void
    {
        $this->each(static fn (Blueprint $table) => $table->timestamp('expires_at')->change());
    }

    /**
     * @param  Closure(Blueprint): mixed  $column
     */
    private function each(Closure $column): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (['magic_link_tokens', 'email_magic_link_invitations'] as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, $column);
            }
        }
    }
};
