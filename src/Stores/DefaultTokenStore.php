<?php

declare(strict_types=1);

namespace EmailMagicLink\Stores;

use Carbon\CarbonInterface;
use EmailMagicLink\Contracts\TokenStore;
use EmailMagicLink\Models\MagicLinkToken;
use EmailMagicLink\Support\ClaimFailure;
use EmailMagicLink\Support\ClaimResult;
use EmailMagicLink\Support\IssuanceLock;
use EmailMagicLink\Support\IssuedToken;
use EmailMagicLink\Support\MagicLinkConfig;
use EmailMagicLink\Support\TokenHasher;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Eloquent-backed token store.
 *
 * Tokens are stored only as keyed hashes. Consumption is a single conditional
 * UPDATE so two concurrent claims for the same token can never both win:
 * PostgreSQL uses RETURNING, every other driver checks the affected-row count.
 */
final readonly class DefaultTokenStore implements TokenStore
{
    /**
     * How the purge claims its chunk. `skip locked` is the load-bearing half: it makes the
     * purge the one statement in this package that never WAITS on a row lock, and a
     * statement that never waits cannot be one end of a deadlock cycle.
     *
     * Duplicated in the sibling store rather than shared, and pinned against it by
     * `PurgeNeverWaitsTest` -- which also pins the `skip locked` itself, because a shared
     * field would prevent the two from drifting apart without ever proving the value is
     * the right one. SQLite compiles this to nothing (`compileLock` returns an empty
     * string there), so the suites that run on it are unaffected.
     */
    private const string CLAIM_CHUNK_LOCK = 'for update skip locked';

    public function __construct(
        private MagicLinkConfig $config,
        private TokenHasher $hasher,
        private IssuanceLock $lock,
    ) {}

    public function issue(Authenticatable $user, string $guard, string $channel, ?int $maxUses = null, ?string $passphrase = null): IssuedToken
    {
        $userId = $this->identifierOf($user);

        // Only the code channel supersedes, so only it can lose the race: two concurrent
        // issues each invalidate what they can see and then both insert, and PostgreSQL
        // lets neither see the other. A link is deliberately allowed to coexist with
        // earlier live links, so there is nothing to serialize and nothing to pay for.
        return $channel === 'code'
            ? $this->lock->run('code', $userId, $guard, fn (): IssuedToken => $this->write($userId, $guard, $channel, $maxUses, $passphrase))
            : $this->write($userId, $guard, $channel, $maxUses, $passphrase);
    }

    /**
     * @param  'link'|'code'  $channel
     */
    private function write(string $userId, string $guard, string $channel, ?int $maxUses, ?string $passphrase): IssuedToken
    {
        $now = Carbon::now();

        // Only links may be redeemed more than once; a code is always single-use.
        // A per-call override wins over the configured default, clamped to >= 1.
        $usesRemaining = $channel === 'link'
            ? max(1, $maxUses ?? $this->config->maxUses())
            : 1;

        // A passphrase gates links only (a code is itself the secret). Put through the
        // host's configured hasher rather than the raw-token hash: it is a human-chosen
        // shared secret, so it needs a slow, salted algorithm, not a fast digest.
        $passphraseHash = $channel === 'link' && is_string($passphrase) && $passphrase !== ''
            ? Hash::make($passphrase)
            : null;

        if ($channel === 'code') {
            // Keep at most one active code per user PER GUARD so a claim is
            // unambiguous and issuing for one guard never clobbers another's code.
            MagicLinkToken::query()
                ->where('user_id', $userId)
                ->where('channel', 'code')
                ->where('guard', $guard)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => $now]);
        }

        $plaintext = $channel === 'code' ? $this->generateCode() : $this->generateLinkToken();

        $record = new MagicLinkToken;
        $record->user_id = $userId;
        $record->guard = $guard;
        $record->token_hash = $this->hasher->hash($plaintext);
        $record->channel = $channel;
        $record->attempts = 0;
        $record->uses_remaining = $usesRemaining;
        $record->passphrase_hash = $passphraseHash;
        $record->expires_at = $now->copy()->addSeconds($this->config->ttlFor($channel));
        $record->consumed_at = null;
        $record->save();

        return new IssuedToken($plaintext, $record);
    }

    public function claimLink(string $token, ?string $passphrase = null): ClaimResult
    {
        // The whole read-check-claim-read runs in a transaction, and the reason is the
        // READ side rather than the write. Connection::getReadPdo() hands back the read
        // connection unless a transaction is open, `sticky` is set after a prior write, or
        // the app forced read-on-write — and claiming a link satisfies none of those: it is
        // usually the first database work of the request. On a deployment with a read
        // connection configured, every lookup here would go to the replica, where a link
        // issued moments ago may not have arrived yet; a valid link would be reported as
        // unknown, and only under replication lag, which is exactly the failure nobody can
        // reproduce. Opening a transaction makes all four statements use the write
        // connection, and makes the sequence atomic on top — the same reasoning claimCode()
        // already documents below.
        return $this->connection()->transaction(function () use ($token, $passphrase): ClaimResult {
            $now = Carbon::now();
            $hash = $this->resolveHash($token);

            // Verify the passphrase BEFORE the atomic claim, so a wrong passphrase
            // never spends a use of a multi-use link. A generic failure keeps the
            // response indistinguishable from an unknown or expired token.
            $existing = $this->findLinkByHash($hash);

            // Nothing to claim. The atomic UPDATE below could only miss, and the
            // classifier would answer NotFound after a third SELECT -- so the path every
            // scanner takes costs one statement, not three.
            //
            // One statement WITH NO RETIRED KEYS. The sentence used to stop at "not three"
            // and that was true only until a host rotates APP_KEY: resolveHash() then has
            // to try each candidate, and the scanner path costs two. Measured over 500k
            // rows, 300 iterations: 1 statement / 0.25 ms with no previous key, 2 / 0.57 ms
            // with one. It does NOT grow past two -- ten retired keys cost the same as one,
            // because the candidates go into a single IN.
            //
            // The successful path reads this row a second time after the claim, which is
            // 26% of its database work and was measured at about 0.19 ms. It stays. On
            // PostgreSQL the claim could carry `returning *` instead, but MySQL has no
            // RETURNING, so removing it means a driver-split hydration inside the most
            // security-sensitive method here -- two engines that could disagree about
            // consumed_at and uses_remaining, to save a fifth of a millisecond on a login.
            // Written down so the next reader does not re-derive the measurement and reach
            // the opposite conclusion by looking only at the ratio.
            if (! $existing instanceof MagicLinkToken) {
                return ClaimResult::failed(ClaimFailure::NotFound);
            }

            if ($existing->passphrase_hash !== null && ! $this->passphraseMatches($passphrase, $existing->passphrase_hash)) {
                return ClaimResult::failed(ClaimFailure::InvalidPassphrase);
            }

            if ($this->atomicClaim('token_hash', $hash, 'link', $now)) {
                $model = $this->findLinkByHash($hash);

                return $model instanceof MagicLinkToken
                    ? ClaimResult::success($model)
                    : ClaimResult::failed(ClaimFailure::NotFound);
            }

            return ClaimResult::failed($this->classifyLinkFailure($hash, $now));
        });
    }

    public function requiresPassphrase(string $token): bool
    {
        return $this->findLinkByHash($this->resolveHash($token))?->requiresPassphrase() ?? false;
    }

    public function claimCode(Authenticatable $user, string $code, string $guard): ClaimResult
    {
        // The whole read-check-claim runs under a row lock so the attempt gate is
        // evaluated against fresh state: concurrent guesses cannot each pass a
        // stale attempts < max snapshot. The guard filter binds the token to the
        // guard the request is authenticating against, so a code issued for one
        // guard can never be claimed through another even if their providers
        // share a user identifier.
        return $this->connection()->transaction(function () use ($user, $code, $guard): ClaimResult {
            $now = Carbon::now();
            $max = $this->config->maxAttemptsPerToken();

            $token = MagicLinkToken::query()
                ->where('user_id', $this->identifierOf($user))
                ->where('channel', 'code')
                ->where('guard', $guard)
                ->whereNull('consumed_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($token === null) {
                return ClaimResult::failed(ClaimFailure::NotFound);
            }

            if ($token->isExpired($now)) {
                return ClaimResult::failed(ClaimFailure::Expired);
            }

            if ($token->attempts >= $max) {
                return ClaimResult::failed(ClaimFailure::LockedOut);
            }

            if (! $this->hasher->matches($code, $token->token_hash)) {
                return $this->recordFailedCodeAttempt($token, $max, $now);
            }

            if (! $this->atomicClaim('id', (string) $token->id, 'code', $now)) {
                // Unreachable once the row lock above serializes claims; kept as a
                // defensive backstop that maps a losing race to a clean outcome.
                return ClaimResult::failed(ClaimFailure::AlreadyConsumed); // @codeCoverageIgnore
            }

            $token->consumed_at = $now;

            return ClaimResult::success($token);
        });
    }

    public function purge(): int
    {
        // In chunks, the way the framework's own pruning idiom works: one unbounded
        // DELETE over months of rows holds every row lock until commit and stalls the
        // claims running beside it. The predicate is re-evaluated per chunk, so a row
        // that expires while the purge runs is picked up by the next one.
        $chunk = $this->config->pruneChunk();
        $now = Carbon::now();
        $deleted = 0;

        do {
            // Two statements rather than one, and the SECOND word is what matters: the purge
            // claims its chunk with `for update skip locked`, so it NEVER waits on a row
            // somebody else is holding. A statement that never waits cannot be one end of a
            // deadlock cycle, whatever order the other end takes its locks in.
            //
            // The obvious cheaper fix does not work, and it was measured rather than reasoned
            // about. Ordering the chunk (`order by id`) does not control the order the row
            // locks are actually acquired in: PostgreSQL compiles a limited DELETE to
            // `where ctid in (<subquery>)` and the outer node is free to re-order what the
            // subquery returns. Reproduced on PostgreSQL 18 with two connections and the two
            // rows deliberately laid out so ctid order is the reverse of id order --
            // `deadlock detected` with and without the ORDER BY, identically.
            //
            // A row skipped here is not a row kept: it was locked by a transaction that is
            // about to commit, and the next run takes it. A purge is the one caller that can
            // always afford to come back later, which is exactly why it should be the one
            // that yields.
            //
            // The lock has to be held across BOTH statements, hence the transaction. Taken in
            // autocommit the locks would drop the moment the select returned, and the delete
            // would be back to waiting on whatever moved in between.
            $removed = $this->connection()->transaction(function () use ($now, $chunk): int {
                $ids = MagicLinkToken::query()
                    ->select('id')
                    ->where(function (Builder $query) use ($now): void {
                        $query->where('expires_at', '<=', $now)
                            ->orWhereNotNull('consumed_at');
                    })
                    ->orderBy('id')
                    ->limit($chunk)
                    ->lock(self::CLAIM_CHUNK_LOCK)
                    ->pluck('id');

                if ($ids->isEmpty()) {
                    return 0;
                }

                MagicLinkToken::query()->whereIn('id', $ids)->delete();

                return $ids->count();
            });

            $deleted += $removed;
        } while ($removed === $chunk);

        return $deleted;
    }

    private function recordFailedCodeAttempt(MagicLinkToken $token, int $max, CarbonInterface $now): ClaimResult
    {
        $connection = $this->connection();

        $connection->table('magic_link_tokens')
            ->where('id', $token->id)
            ->whereNull('consumed_at')
            ->increment('attempts', 1, ['updated_at' => $now]);

        $attempts = $connection->table('magic_link_tokens')
            ->where('id', $token->id)
            ->value('attempts');
        $attempts = is_numeric($attempts) ? (int) $attempts : 0;

        if ($attempts >= $max) {
            // Burn the token: the lockout, not the rate limiter, bounds brute force.
            // updated_at was already bumped by the increment above.
            $connection->table('magic_link_tokens')
                ->where('id', $token->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => $now]);

            return ClaimResult::failed(ClaimFailure::LockedOut);
        }

        return ClaimResult::failed(ClaimFailure::InvalidCode);
    }

    /**
     * Atomically spend one use of the single active row matching the column.
     *
     * A single conditional UPDATE decrements uses_remaining and only flips
     * consumed_at once it reaches zero, so it is race-free for both single-use
     * (uses_remaining 1 -> 0, consumed) and multi-use links: two concurrent
     * claims each decrement exactly once under the row lock and can never take
     * the counter below zero (the `uses_remaining > 0` guard fails the loser).
     * Returns true only for a claim that spent a use.
     *
     * consumed_at is assigned BEFORE the decrement in the SET list on purpose:
     * MySQL evaluates SET left to right and lets a later clause see the updated
     * value, so the CASE must read uses_remaining while it still holds the
     * pre-decrement count. PostgreSQL and SQLite always read the old row values,
     * so the ordering is a no-op there — but it makes all three engines agree.
     */
    private function atomicClaim(string $column, string $value, string $channel, CarbonInterface $now): bool
    {
        $connection = $this->connection();

        // wrapTable() applies the connection's table prefix and the driver's quoting. A
        // literal table name here bypasses the prefix that Eloquent and Schema both honor,
        // so on a prefixed connection every claim ran against a table that does not exist.
        $table = $connection->getQueryGrammar()->wrapTable((new MagicLinkToken)->getTable());

        $sql = "update {$table} set "
            .'consumed_at = case when uses_remaining <= 1 then ? else consumed_at end, '
            .'uses_remaining = uses_remaining - 1, updated_at = ? '
            ."where {$column} = ? and channel = ? and consumed_at is null and expires_at > ? and uses_remaining > 0";
        $bindings = [$now, $now, $value, $channel, $now];

        if ($connection->getDriverName() === 'pgsql') {
            // Explicit `false`: select() defaults to the READ connection, and this one is
            // an UPDATE. Both callers hold a transaction, so getReadPdo() would return the
            // write PDO anyway — but that makes the safety a property of the CALLER, and a
            // future third caller would inherit a silent write-to-replica instead of a
            // visible mistake.
            return $connection->select($sql.' returning id', $bindings, false) !== [];
        }

        return $connection->update($sql, $bindings) === 1;
    }

    private function findLinkByHash(string $hash): ?MagicLinkToken
    {
        // Pinned to the write connection: the confirm page asks whether a link wants a
        // passphrase in a request of its own, after the one that issued the row, and on a
        // lagging replica the answer was "no" for a link that does. The claim callers hold
        // a transaction and would reach the write PDO anyway; stating it here keeps the
        // property on the lookup rather than on whoever calls it.
        return MagicLinkToken::query()
            ->useWritePdo()
            ->where('token_hash', $hash)
            ->where('channel', 'link')
            ->first();
    }

    private function passphraseMatches(?string $passphrase, string $hash): bool
    {
        return is_string($passphrase) && $passphrase !== '' && Hash::check($passphrase, $hash);
    }

    private function classifyLinkFailure(string $hash, CarbonInterface $now): ClaimFailure
    {
        $row = $this->findLinkByHash($hash);

        return match (true) {
            ! $row instanceof MagicLinkToken => ClaimFailure::NotFound,
            $row->isConsumed() => ClaimFailure::AlreadyConsumed,
            $row->isExpired($now) => ClaimFailure::Expired,
            default => ClaimFailure::NotFound,
        };
    }

    private function generateLinkToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function generateCode(): string
    {
        // The same canonical distinct-character set the entropy guardrail
        // certifies, so the generated distribution matches the proven keyspace.
        //
        // EFFECTIVE, not configured: minting a character that the fold then collapses
        // produces a code that can never be redeemed -- the same failure this fold
        // was corrected to remove, arriving from the other side. The generator and
        // the guardrail must read the same set or one of them is describing an
        // alphabet that does not exist at comparison time.
        $characters = $this->config->effectiveCodeAlphabetCharacters();
        $length = $this->config->codeLength();
        $bound = count($characters) - 1;

        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $characters[random_int(0, $bound)];
        }

        return $code;
    }

    /**
     * The hash a live row for this plaintext is actually stored under.
     *
     * With no retired keys this is hash() and costs nothing extra -- the common case, and
     * the one that must stay free. With retired keys present it asks once which candidate
     * exists, and everything downstream keeps working with a single hash: the atomic
     * claim, the classifier and the passphrase lookup are untouched.
     */
    private function resolveHash(string $plaintext): string
    {
        $candidates = $this->hasher->candidates($plaintext);

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        $stored = MagicLinkToken::query()->useWritePdo()->whereIn('token_hash', $candidates)->value('token_hash');

        // Falling back to the current key keeps a miss a miss: the caller goes on to its
        // ordinary not-found path rather than branching on a second kind of nothing.
        return is_string($stored) ? $stored : $candidates[0];
    }

    private function connection(): Connection
    {
        return (new MagicLinkToken)->getConnection();
    }

    private function identifierOf(Authenticatable $user): string
    {
        $identifier = $user->getAuthIdentifier();

        return is_scalar($identifier) ? (string) $identifier : '';
    }
}
