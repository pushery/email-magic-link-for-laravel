<?php

declare(strict_types=1);

namespace EmailMagicLink\Stores;

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

        // A passphrase gates links only (a code is itself the secret). Hashed with
        // bcrypt because it is a human-chosen shared secret, not a random token.
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
        $now = Carbon::now();
        $hash = $this->hasher->hash($token);

        // Verify the passphrase BEFORE the atomic claim, so a wrong passphrase
        // never spends a use of a multi-use link. A generic failure keeps the
        // response indistinguishable from an unknown or expired token.
        $existing = $this->findLinkByHash($hash);

        if ($existing?->passphrase_hash !== null && ! $this->passphraseMatches($passphrase, $existing->passphrase_hash)) {
            return ClaimResult::failed(ClaimFailure::InvalidPassphrase);
        }

        if ($this->atomicClaim('token_hash', $hash, 'link', $now)) {
            $model = $this->findLinkByHash($hash);

            return $model instanceof MagicLinkToken
                ? ClaimResult::success($model)
                : ClaimResult::failed(ClaimFailure::NotFound);
        }

        return ClaimResult::failed($this->classifyLinkFailure($hash, $now));
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
                // Unreachable once the row lock above serialises claims; kept as a
                // defensive backstop that maps a losing race to a clean outcome.
                return ClaimResult::failed(ClaimFailure::AlreadyConsumed); // @codeCoverageIgnore
            }

            $token->consumed_at = $now;

            return ClaimResult::success($token);
        });
    }

    public function purge(): int
    {
        $deleted = MagicLinkToken::query()
            ->where(function (Builder $query): void {
                $query->where('expires_at', '<=', Carbon::now())
                    ->orWhereNotNull('consumed_at');
            })
            ->delete();

        return is_int($deleted) ? $deleted : 0;
    }

    private function recordFailedCodeAttempt(MagicLinkToken $token, int $max, Carbon $now): ClaimResult
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
    private function atomicClaim(string $column, string $value, string $channel, Carbon $now): bool
    {
        $connection = $this->connection();

        $sql = 'update magic_link_tokens set '
            .'consumed_at = case when uses_remaining <= 1 then ? else consumed_at end, '
            .'uses_remaining = uses_remaining - 1, updated_at = ? '
            ."where {$column} = ? and channel = ? and consumed_at is null and expires_at > ? and uses_remaining > 0";
        $bindings = [$now, $now, $value, $channel, $now];

        if ($connection->getDriverName() === 'pgsql') {
            return $connection->select($sql.' returning id', $bindings) !== [];
        }

        return $connection->update($sql, $bindings) === 1;
    }

    private function findLinkByHash(string $hash): ?MagicLinkToken
    {
        return MagicLinkToken::query()
            ->where('token_hash', $hash)
            ->where('channel', 'link')
            ->first();
    }

    private function passphraseMatches(?string $passphrase, string $hash): bool
    {
        return is_string($passphrase) && $passphrase !== '' && Hash::check($passphrase, $hash);
    }

    private function classifyLinkFailure(string $hash, Carbon $now): ClaimFailure
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
        $characters = $this->config->codeAlphabetCharacters();
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
