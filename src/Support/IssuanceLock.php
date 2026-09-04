<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use RuntimeException;

/**
 * Serializes issuing a credential for one subject, so supersession actually supersedes.
 *
 * Both stores keep at most one live credential per subject and write it in two
 * statements. The order was chosen to be safe without gap locks -- insert first, then
 * revoke everything below this row's id -- and on InnoDB it is: the second writer waits
 * on the first writer's uncommitted index entry.
 *
 * PostgreSQL gives no such wait. Under READ COMMITTED the second writer's UPDATE takes a
 * snapshot that excludes the first writer's uncommitted INSERT, and there is no row to
 * block on, so it supersedes nothing. Both transactions commit and the address is left
 * holding two live credentials -- measured against PostgreSQL 18, two rows still live
 * where a sequential pair leaves one.
 *
 * A partial unique index would be the harder guarantee and it is PostgreSQL-only, so it
 * cannot be the package's answer for an engine set that includes MySQL. This lock is the
 * engine-neutral one, and it is the idiom the resend guard already uses.
 */
final readonly class IssuanceLock
{
    private const string PREFIX = 'eml:issue:';

    /**
     * @param  int  $blockSeconds  How long to wait for the lock. The default matches the
     *                             resend guard; a test binds its own instance with 0 so a
     *                             deliberately held lock fails fast instead of parking the
     *                             suite.
     */
    public function __construct(
        private CacheFactory $cache,
        private int $blockSeconds = 5,
    ) {}

    /**
     * The lock name for a subject.
     *
     * Public because a caller has to hash the SAME subject the row is keyed on -- the
     * normalized address, not the raw input -- and a test has to be able to name the
     * lock it holds.
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

        // A LockTimeoutException propagates rather than being swallowed, and that is the
        // deliberate half. Falling through to an unserialized write is exactly the
        // outcome this exists to prevent: it would hand one address a second live
        // credential and say nothing, which is worse than a request that fails loudly
        // and can be retried.
        return $store->lock(self::keyFor($scope, $subject, $guard), max(1, $this->blockSeconds))
            ->block($this->blockSeconds, $callback);
    }

    private function repository(): Repository
    {
        return $this->cache->store();
    }
}
