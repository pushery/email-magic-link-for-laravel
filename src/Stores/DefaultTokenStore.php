<?php

declare(strict_types=1);

namespace EmailMagicLink\Stores;

use Carbon\CarbonInterface;
use EmailMagicLink\Contracts\TokenStore;
use EmailMagicLink\Models\MagicLinkToken;
use EmailMagicLink\Support\ClaimFailure;
use EmailMagicLink\Support\ClaimResult;
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
    public function __construct(
        private MagicLinkConfig $config,
        private TokenHasher $hasher,
    ) {}

    public function issue(Authenticatable $user, string $guard, string $channel, ?int $maxUses = null, ?string $passphrase = null): IssuedToken
    {
        $now = Carbon::now();
        $userId = $this->identifierOf($user);

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
            $hash = $this->hasher->hash($token);

            // Verify the passphrase BEFORE the atomic claim, so a wrong passphrase
            // never spends a use of a multi-use link. A generic failure keeps the
            // response indistinguishable from an unknown or expired token.
            $existing = $this->findLinkByHash($hash);

            // Nothing to claim. The atomic UPDATE below could only miss, and the
            // classifier would answer NotFound after a third SELECT -- so the path
            // every scanner takes costs one statement, not three.
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
        return $this->findLinkByHash($this->hasher->hash($token))?->requiresPassphrase() ?? false;
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
            $removed = MagicLinkToken::query()
                ->where(function (Builder $query) use ($now): void {
                    $query->where('expires_at', '<=', $now)
                        ->orWhereNotNull('consumed_at');
                })
                ->limit($chunk)
                ->delete();
            $removed = is_int($removed) ? $removed : 0;
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
