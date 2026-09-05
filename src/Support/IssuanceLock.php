<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use Closure;
use Illuminate\Cache\NoLock;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use RuntimeException;

/**
 * Serializes issuing a credential for one subject, so supersession actually supersedes.
 *
 * Both stores keep at most one live credential per subject and write it in two statements.
 * The order was chosen to be safe without gap locks -- insert first, then revoke everything
 * below this row's id -- and on InnoDB it is: the second writer waits on the first writer's
 * uncommitted index entry.
 *
 * PostgreSQL gives no such wait. Under READ COMMITTED the second writer's UPDATE takes a
 * snapshot that excludes the first writer's uncommitted INSERT, and there is no row to block
 * on, so it supersedes nothing. Both transactions commit and the address is left holding two
 * live credentials -- measured against PostgreSQL 18.
 *
 * A partial unique index would be the harder guarantee and it is PostgreSQL-only, so it cannot
 * be the package's answer for an engine set that includes MySQL. This lock is the
 * engine-neutral one.
 *
 *  `Repository::withoutOverlapping()` is EXACTLY this line and it is deliberately not used:
 * `Illuminate\Contracts\Cache\Factory::store()` returns the CONTRACT repository, which
 * declares twelve methods and not that one. Reaching it means typing against the concrete
 * class or the facade. It also probes nothing, so a store without locks would die with a
 * BadMethodCallException instead of the message below. Named here so the next reader does not
 * have to re-derive that it was considered.
 */
final class IssuanceLock
{
    private const string PREFIX = 'eml:issue:';

    /**
     * A wait budget that outranks the configured one, or null for the configured one.
     *
     * The only mutable state here, and the class is no longer `readonly` because of it.
     * withoutWaiting() sets it, restores it in a finally, and it therefore lives for exactly
     * one closure. Safe against the obvious objection because the binding is a singleton per
     * request: two HTTP requests are two processes, never two writers of this property.
     */
    private ?int $blockOverride = null;

    /**
     * @param  int  $blockSeconds  How long a competing writer QUEUES before giving up.
     * @param  int  $holdSeconds  How long the lock is HELD -- the work budget. See run().
     * @param  string|null  $store  The cache store to lock in, or null for the default.
     */
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly int $blockSeconds = 5,
        private readonly int $holdSeconds = 60,
        private readonly ?string $store = null,
    ) {}

    /**
     * Run $callback with the wait budget at zero: a competing writer gives up at once.
     *
     *  THIS IS AN ENUMERATION FIX, NOT A PERFORMANCE ONE. The lock is taken only in the
     * known-user branch -- an unknown address never reaches the issuer -- so the time a
     * request spends waiting for it IS the answer to "does this account exist". Measured on
     * the real endpoint with a one-second budget: 827 ms for a known, contended address
     * against 12 ms for an unknown one. At the shipped default of five seconds it is five
     * times that. And the attacker does not have to wait for contention to happen; two
     * simultaneous requests for one address produce it.
     *
     * Dropping the wait is safe rather than merely cheaper, and the reason is the same one
     * the controller's catch already gives: the request holding the lock is the one sending
     * the credential, which is as true at t=0 as at t=5s. The waiting bought the response
     * nothing it did not already have.
     *
     * The programmatic issuers keep the configured budget on purpose. Their caller asked for
     * a credential and wants one, and no response shape of theirs depends on how long it
     * took, so there is nothing to leak.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withoutWaiting(Closure $callback): mixed
    {
        $previous = $this->blockOverride;
        $this->blockOverride = 0;

        try {
            return $callback();
        } finally {
            // Restored rather than set to null: a nested call must not widen the budget of
            // the one around it.
            $this->blockOverride = $previous;
        }
    }

    /**
     * The lock name for a subject.
     *
     * Public because a caller has to hash the SAME subject the row is keyed on -- the
     * normalized address, not the raw input -- and a test has to be able to name the lock it
     * holds.
     */
    public static function keyFor(string $scope, string $subject, string $guard): string
    {
        return self::PREFIX.$scope.':'.hash('sha256', $subject.'|'.$guard);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function run(string $scope, string $subject, string $guard, Closure $callback): mixed
    {
        $store = $this->repository()->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException(
                'The [email-magic-link] issuer needs a cache store that supports atomic locks; the configured store does not.',
            );
        }

        // THE WORK BUDGET AND THE WAIT BUDGET ARE DIFFERENT NUMBERS, and deriving one from
        // the other is how this lock silently stopped locking. The critical section is a
        // database transaction; the wait is how long a competitor queues for it. With one
        // number for both, a transaction slower than the wait releases the lock UNDER ITSELF:
        // the key expires, a second writer's `SET .. EX .. NX` succeeds, and the first never
        // finds out, because release() is a compare-and-del whose 0 is discarded by
        // Lock::block()'s finally. Two live credentials, no log, no exception.
        //
        // It got worse the more sensible the tuning: shortening the wait to fail fast under
        // load also shortened the TTL, over a transaction that did not get any faster.
        $lock = $store->lock(self::keyFor($scope, $subject, $guard), max(1, $this->holdSeconds));

        // A LOCK THAT CANNOT SAY NO IS WORSE THAN NO LOCK, because it reads as protection.
        // NullStore IS a LockProvider -- the check above is exactly the condition it satisfies
        // -- and its NoLock::acquire() returns true every time. Measured: two acquires on one
        // name, both true. A host on CACHE_STORE=null therefore had no serialization at all
        // and no way to discover it, while this class's own documentation promised there were
        // never two live links for one person.
        if ($lock instanceof NoLock) {
            throw new RuntimeException(
                'The [email-magic-link] issuer needs a cache store whose locks actually exclude; '
                .'the configured store hands out a no-op lock (the `null` driver does this). '
                .'Point [email-magic-link.lock_store] at a real store.',
            );
        }

        // A LockTimeoutException propagates rather than being swallowed, and that is the
        // deliberate half. Falling through to an unserialized write is exactly the outcome this
        // exists to prevent. The CALLER decides what it means: the programmatic issuers let it
        // surface, because their caller asked for a credential and did not get one.
        //
        // SendMagicLinkController is the one caller that must NOT, and this comment used to
        // describe that as though it already did. It did not -- nothing caught this on the
        // request path, so a contended issuance answered 500. The endpoint answers identically
        // for a known and an unknown address by design, and a 500 that can only happen for an
        // address that RESOLVES is an enumeration oracle for anyone willing to send two
        // requests at once. It catches it now, and `SendMagicLinkContentionTest` pins that.
        // Zero is a real budget rather than a disabled one: `Lock::block()` tests the
        // deadline BEFORE it sleeps, so a zero budget makes exactly one attempt and throws --
        // measured at 0 ms against a held lock. If that order ever inverts upstream, a zero
        // budget starts costing one sleep interval and the timing channel reopens; the arm in
        // `SendMagicLinkContentionTest` measures the DIFFERENCE through the endpoint rather
        // than this line, so it would catch that without knowing about it.
        return $lock->block($this->blockOverride ?? $this->blockSeconds, $callback);
    }

    private function repository(): Repository
    {
        return $this->cache->store($this->store);
    }
}
