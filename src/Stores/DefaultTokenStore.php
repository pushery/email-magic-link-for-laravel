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

    public function issue(Authenticatable $user, string $guard, string $channel): IssuedToken
    {
        $now = Carbon::now();
        $userId = $this->identifierOf($user);

        if ($channel === 'code') {
            // Keep at most one active code per user so a claim is unambiguous.
            MagicLinkToken::query()
                ->where('user_id', $userId)
                ->where('channel', 'code')
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
        $record->expires_at = $now->copy()->addSeconds($this->config->ttl());
        $record->consumed_at = null;
        $record->save();

        return new IssuedToken($plaintext, $record);
    }

    public function claimLink(string $token): ClaimResult
    {
        $now = Carbon::now();
        $hash = $this->hasher->hash($token);

        if ($this->atomicClaim('token_hash', $hash, 'link', $now)) {
            $model = MagicLinkToken::query()
                ->where('token_hash', $hash)
                ->where('channel', 'link')
                ->first();

            return $model !== null
                ? ClaimResult::success($model)
                : ClaimResult::failed(ClaimFailure::NotFound);
        }

        return ClaimResult::failed($this->classifyLinkFailure($hash, $now));
    }

    public function claimCode(Authenticatable $user, string $code): ClaimResult
    {
        $now = Carbon::now();
        $max = $this->config->maxAttemptsPerToken();

        $token = MagicLinkToken::query()
            ->where('user_id', $this->identifierOf($user))
            ->where('channel', 'code')
            ->whereNull('consumed_at')
            ->orderByDesc('id')
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
            // Only reachable when a concurrent request consumes this same token
            // between the lookup above and this claim. The single conditional
            // UPDATE in atomicClaim() is what makes that race safe; this branch
            // just maps the losing request's outcome.
            return ClaimResult::failed(ClaimFailure::AlreadyConsumed); // @codeCoverageIgnore
        }

        $token->consumed_at = $now;

        return ClaimResult::success($token);
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
     * Atomically flip consumed_at on the single active row matching the column.
     *
     * Returns true only for the request that wins the race.
     */
    private function atomicClaim(string $column, string $value, string $channel, Carbon $now): bool
    {
        $connection = $this->connection();

        if ($connection->getDriverName() === 'pgsql') {
            $returned = $connection->select(
                "update magic_link_tokens set consumed_at = ?, updated_at = ? where {$column} = ? and channel = ? and consumed_at is null and expires_at > ? returning id",
                [$now, $now, $value, $channel, $now],
            );

            return $returned !== [];
        }

        return $connection->table('magic_link_tokens')
            ->where($column, $value)
            ->where('channel', $channel)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $now)
            ->update(['consumed_at' => $now, 'updated_at' => $now]) === 1;
    }

    private function classifyLinkFailure(string $hash, Carbon $now): ClaimFailure
    {
        $row = MagicLinkToken::query()
            ->where('token_hash', $hash)
            ->where('channel', 'link')
            ->first();

        return match (true) {
            $row === null => ClaimFailure::NotFound,
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
