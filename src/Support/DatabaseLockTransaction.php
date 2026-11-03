<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use Illuminate\Foundation\Application;

/**
 * Whether the database cache store's lock ends a PostgreSQL transaction it is taken in.
 *
 * The store takes a lock with an INSERT and, when the key is taken, an UPDATE on the same
 * connection. Up to laravel/framework 13.38 the INSERT is a plain one: inside a transaction,
 * PostgreSQL aborts at the INSERT that fails on the taken key, the UPDATE then fails at once with
 * SQLSTATE 25P02, and the caller's transaction cannot go on. From 13.39.0 the store inserts with
 * insertOrIgnore(), which a taken key does not fail, so the lock waits and times out as it does on
 * a connection of its own, and the transaction stays usable.
 *
 * Without a version it answers for the framework the application runs.
 */
final readonly class DatabaseLockTransaction
{
    /** The first laravel/framework release whose database lock leaves the transaction usable. */
    public const string USABLE_SINCE = '13.39.0';

    public function __construct(private ?string $frameworkVersion = null) {}

    public function abortsOnATakenKey(): bool
    {
        return version_compare($this->frameworkVersion ?? Application::VERSION, self::USABLE_SINCE, '<');
    }
}
