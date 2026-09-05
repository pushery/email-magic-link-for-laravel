<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use EmailMagicLink\Contracts\ResendGuard;
use Illuminate\Cache\NoLock;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Cache-backed resend guard: an escalating cooldown plus a rolling send cap.
 *
 * Each recorded send is a timestamp in a per-key list, pruned to the rolling
 * window on every read. The whole decision derives from that list: the number
 * of sends still in the window sets both the cooldown step (base × factor per
 * step, capped) and whether the window cap is reached. The attempt path takes a
 * short cache lock so a burst of concurrent requests for one key cannot each
 * read the same window and slip past the cap.
 *
 * This guard ALWAYS guards. The `resend.enabled` switch is checked by the
 * package's own request endpoint before it calls in — never here. It used to be
 * checked in attempt() and peek(), which made it a global kill-switch: a host
 * that injects this contract for its own keys (the contract explicitly invites
 * that, and names "two-factor:{user id}" as the example) had its guard silently
 * disarmed by an env flag named for, and documented as, the magic-link request
 * flow. The failure was invisible — ResendDecision::allowed() is the same object
 * a legitimately-allowed send returns. An operator turning off magic-link
 * throttling must not be able to turn off an unrelated subsystem's flood
 * protection without ever being told.
 */
final readonly class DefaultResendGuard implements ResendGuard
{
    private const string CACHE_PREFIX = 'eml:resend:';

    private const string LOCK_PREFIX = 'eml:resend:lock:';

    /**
     * How long a second evaluation WAITS for the first, and what a caller is told to wait
     * when the guard cannot get the lock at all -- the same question from the outside:
     * come back after roughly as long as the contention should last.
     */
    private const int LOCK_BLOCK_SECONDS = 5;

    /**
     * The lock's TTL, and deliberately not the same number.
     *
     * One constant used to serve all three of these, which is the exact shape that stopped
     * the issuance lock from locking: a TTL equal to the wait budget expires while the work
     * it protects is still running, and the second caller walks in believing it holds
     * something. The evaluation below is two cache round-trips and finishes in
     * milliseconds -- but "the cache is fast" is an assumption about the host's store, and
     * a stalled store is precisely when this guard matters.
     */
    private const int LOCK_HOLD_SECONDS = 30;

    public function __construct(
        private CacheFactory $cache,
        private MagicLinkConfig $config,
    ) {}

    public function attempt(string $key): ResendDecision
    {
        $store = $this->repository()->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException(
                'The [email-magic-link] resend guard needs a cache store that supports atomic locks; the configured store does not.',
            );
        }

        $decision = ResendDecision::allowed();

        $lock = $store->lock(self::LOCK_PREFIX.$this->digest($key), self::LOCK_HOLD_SECONDS);

        // NullStore IS a LockProvider, so the check above is exactly the condition it
        // satisfies, and its NoLock::acquire() returns true every time. This guard would
        // then evaluate the window under a lock that excludes nothing -- and because the
        // whole point of it is to hold sends back, a lock that never says no makes it
        // FAIL OPEN: unlimited mail per address, with every arm of the suite green.
        //
        // Loud on the first request rather than silent forever. A host reaching this has
        // CACHE_STORE=null, which no production deployment wants for a flood guard, and
        // the message names the setting to change.
        if ($lock instanceof NoLock) {
            throw new RuntimeException(
                'The [email-magic-link] resend guard needs a cache store whose locks actually exclude; '
                .'the configured store hands out a no-op lock (the `null` driver does this). '
                .'Point [email-magic-link.resend.store] at a real store.',
            );
        }

        try {
            $lock
                ->block(self::LOCK_BLOCK_SECONDS, function () use ($key, &$decision): void {
                    $decision = $this->evaluate($key, true);
                });
        } catch (LockTimeoutException) {
            // A store that cannot hand out the lock in time is a store that cannot say
            // whether the cap is reached. Fail closed with a short hold-back rather than
            // let the exception become a 500 on the request endpoint.
            return ResendDecision::denied(ResendDenialReason::Cooldown, self::LOCK_BLOCK_SECONDS);
        }

        return $decision;
    }

    public function peek(string $key): ResendDecision
    {
        return $this->evaluate($key, false);
    }

    public function reset(string $key): void
    {
        $this->repository()->forget(self::CACHE_PREFIX.$this->digest($key));
    }

    /**
     * Decide the key's fate against the current window, recording the send when
     * asked to and the attempt is allowed.
     */
    private function evaluate(string $key, bool $record): ResendDecision
    {
        $now = Carbon::now()->getTimestamp();
        $window = $this->config->resendWindow();
        $earliest = $now - $window['seconds'];

        $sends = array_values(array_filter(
            $this->read($key),
            static fn (int $at): bool => $at > $earliest,
        ));

        if ($sends !== []) {
            $count = count($sends);
            $blockedUntil = 0;
            $reason = null;

            $cooldownUntil = max($sends) + $this->cooldownAfter($count);

            if ($cooldownUntil > $now) {
                $blockedUntil = $cooldownUntil;
                $reason = ResendDenialReason::Cooldown;
            }

            if ($count >= $window['max_sends']) {
                $windowUntil = min($sends) + $window['seconds'];

                if ($windowUntil > $blockedUntil) {
                    $blockedUntil = $windowUntil;
                    $reason = ResendDenialReason::WindowCap;
                }
            }

            if ($reason instanceof ResendDenialReason) {
                return ResendDecision::denied($reason, $blockedUntil - $now);
            }
        }

        if ($record) {
            $sends[] = $now;
            $this->write($key, $sends);
        }

        return ResendDecision::allowed();
    }

    /**
     * The cooldown, in seconds, that applies once the window holds $count sends.
     */
    private function cooldownAfter(int $count): int
    {
        $cooldown = $this->config->resendCooldown();
        $seconds = $cooldown['base'] * ($cooldown['factor'] ** ($count - 1));

        return $seconds >= $cooldown['max'] ? $cooldown['max'] : (int) $seconds;
    }

    /**
     * @return list<int>
     */
    private function read(string $key): array
    {
        $raw = $this->repository()->get(self::CACHE_PREFIX.$this->digest($key));

        if (! is_array($raw)) {
            return [];
        }

        $sends = [];

        foreach ($raw as $at) {
            if (is_int($at)) {
                $sends[] = $at;
            }
        }

        return $sends;
    }

    /**
     * @param  list<int>  $sends
     */
    private function write(string $key, array $sends): void
    {
        // The margin is the LOCK's TTL, not the wait budget, and the difference is the
        // whole reason these are two constants: the record has to outlive the window it
        // describes PLUS the longest anyone can be holding the lock over it. Padded with
        // the wait budget instead, a holder that runs long lets the record expire
        // mid-evaluation -- and a forgotten record reads as "no sends yet".
        $ttl = max($this->config->resendWindow()['seconds'], $this->config->resendCooldown()['max']) + self::LOCK_HOLD_SECONDS;

        $this->repository()->put(self::CACHE_PREFIX.$this->digest($key), $sends, $ttl);
    }

    private function repository(): Repository
    {
        return $this->cache->store($this->config->resendStore());
    }

    private function digest(string $key): string
    {
        return hash('sha256', $key);
    }
}
