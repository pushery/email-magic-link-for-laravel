<?php

declare(strict_types=1);

namespace EmailMagicLink\Stores;

use Carbon\CarbonInterface;
use EmailMagicLink\Contracts\InvitationStore;
use EmailMagicLink\Models\Invitation;
use EmailMagicLink\Support\ClaimFailure;
use EmailMagicLink\Support\InvitationClaimResult;
use EmailMagicLink\Support\IssuanceLock;
use EmailMagicLink\Support\IssuedInvitationToken;
use EmailMagicLink\Support\MagicLinkConfig;
use EmailMagicLink\Support\NormalizedEmail;
use EmailMagicLink\Support\TokenHasher;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;

/**
 * Eloquent-backed invitation store.
 */
final readonly class DefaultInvitationStore implements InvitationStore
{
    public function __construct(
        private MagicLinkConfig $config,
        private TokenHasher $hasher,
        private IssuanceLock $lock,
    ) {}

    public function issue(string $email, string $guard, ?array $context = null, ?string $invitedBy = null, ?int $ttl = null): IssuedInvitationToken
    {
        $normalized = $this->normalize($email);

        // Serialized per address and guard, on the NORMALIZED address -- the same value
        // the row is keyed on, so two spellings of one mailbox cannot slip past each
        // other. See IssuanceLock for why the statement order below is not enough on its
        // own.
        return $this->lock->run('invite', $normalized, $guard, fn (): IssuedInvitationToken => $this->connection()->transaction(function () use ($normalized, $guard, $context, $invitedBy, $ttl): IssuedInvitationToken {
            $now = Carbon::now();
            $plaintext = $this->generateToken();

            $record = new Invitation;
            $record->email = $normalized;
            $record->guard = $guard;
            $record->token_hash = $this->hasher->hash($plaintext);
            $record->context = $context;
            $record->invited_by = $invitedBy;
            $record->expires_at = $now->copy()->addSeconds($ttl !== null && $ttl > 0 ? $ttl : $this->config->invitationTtl());
            $record->accepted_at = null;
            $record->revoked_at = null;
            $record->save();

            // INSERT FIRST, then revoke everything below this row's id. On InnoDB that
            // order needs no gap locks: the second writer waits on the first writer's
            // uncommitted index entry. On PostgreSQL it is NOT sufficient on its own --
            // the UPDATE's snapshot excludes an uncommitted INSERT and has nothing to
            // wait on -- which is what the lock around this transaction is for.
            //
            // Revoking first and inserting after is the obvious order and the wrong one --
            // two concurrent invites would each revoke what they saw and then both insert,
            // leaving two live links for one address -- the race this ordering exists to
            // prevent.
            //
            // Already ACCEPTED rows are left alone: they are a record of something that
            // happened, not an open door.
            Invitation::query()
                ->where('email', $normalized)
                ->where('guard', $guard)
                ->where('id', '<', $record->id)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now]);

            return new IssuedInvitationToken($plaintext, $record);
        }));
    }

    public function peek(string $token): InvitationClaimResult
    {
        $now = Carbon::now();
        $hash = $this->resolveHash($token);

        // Pinned to the write connection, like every other read of a row the previous
        // request may just have written: on a lagging replica the acceptance page
        // otherwise refuses a fresh, valid invitation.
        $live = Invitation::query()
            ->useWritePdo()
            ->where('token_hash', $hash)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', $now)
            ->first();

        return $live instanceof Invitation
            ? InvitationClaimResult::success($live)
            : InvitationClaimResult::failed($this->classify($hash, $now));
    }

    public function claim(string $token): InvitationClaimResult
    {
        return $this->connection()->transaction(function () use ($token): InvitationClaimResult {
            $now = Carbon::now();
            $hash = $this->resolveHash($token);

            if (! $this->atomicClaim($hash, $now)) {
                return InvitationClaimResult::failed($this->classify($hash, $now));
            }

            $record = Invitation::query()->where('token_hash', $hash)->first();

            return $record instanceof Invitation
                ? InvitationClaimResult::success($record)
                : InvitationClaimResult::failed(ClaimFailure::NotFound);
        });
    }

    public function revoke(string $email, string $guard): int
    {
        return Invitation::query()
            ->where('email', $this->normalize($email))
            ->where('guard', $guard)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now()]);
    }

    public function purge(): int
    {
        $now = Carbon::now();
        $retainUntil = $now->copy()->subDays($this->config->invitationRetainAcceptedDays());

        $chunk = $this->config->pruneChunk();
        $deleted = 0;

        // Chunked for the same reason as the sign-in store's purge: bounded lock time.
        do {
            $removed = Invitation::query()
                ->where(function (Builder $query) use ($now): void {
                    // Never accepted and past its lifetime: nothing to learn from it.
                    $query->where('expires_at', '<=', $now)
                        ->whereNull('accepted_at')
                        ->whereNull('revoked_at');
                })
                ->orWhere(function (Builder $query) use ($retainUntil): void {
                    // Settled rows carry the invited address in the clear, so they are kept
                    // only as long as the configured retention window -- an audit decision,
                    // which is why it is a config key rather than a constant.
                    $query->whereNotNull('accepted_at')->where('accepted_at', '<=', $retainUntil);
                })
                ->orWhere(function (Builder $query) use ($retainUntil): void {
                    $query->whereNotNull('revoked_at')->where('revoked_at', '<=', $retainUntil);
                })
                ->limit($chunk)
                ->delete();
            $removed = is_int($removed) ? $removed : 0;
            $deleted += $removed;
        } while ($removed === $chunk);

        return $deleted;
    }

    /**
     * One conditional UPDATE. Every reason an invitation cannot be claimed is a
     * predicate here, so the check and the write cannot drift apart.
     *
     * Deliberately simpler than the sign-in claim next door: there is no use counter,
     * no CASE, and therefore no SET list whose evaluation order matters. That
     * subtlety exists over there because MySQL reads left to right within SET; here
     * there is nothing to read.
     */
    private function atomicClaim(string $hash, CarbonInterface $now): bool
    {
        $connection = $this->connection();

        // Same reason as the sign-in store: the prefix lives on the connection, not in a literal.
        $table = $connection->getQueryGrammar()->wrapTable((new Invitation)->getTable());

        $sql = "update {$table} set accepted_at = ?, updated_at = ? "
            .'where token_hash = ? and accepted_at is null and revoked_at is null and expires_at > ?';
        $bindings = [$now, $now, $hash, $now];

        if ($connection->getDriverName() === 'pgsql') {
            // Explicit `false`: select() defaults to the READ connection, and this is an
            // UPDATE. The caller holds a transaction, so it would resolve to the write PDO
            // anyway -- but stating it here keeps the safety a property of this statement
            // rather than of whoever calls it.
            //
            // Three reported survivors sit on this line and NONE of them is a testable gap.
            // Recorded so the next pass spends its measurements elsewhere:
            //
            //   `false` -> `true`   equivalent for the only caller. Measured with a real
            //                       read/write split configured: inside a transaction
            //                       getReadPdo() returns the write PDO, so the argument
            //                       changes nothing. What actually pins this to the write
            //                       connection is the transaction, and InvitationClaim-
            //                       PostgresTest asserts that directly.
            //   drop ' returning id'  equivalent. Measured against real postgres: for an
            //                       UPDATE, select() answers with one result when a row
            //                       matched and none when none did -- with or without
            //                       RETURNING. Those are the only two cases `!== []`
            //                       distinguishes. The clause states the intent; it is not
            //                       what makes the check work.
            //   negate the driver test  equivalent. Postgres answers correctly through the
            //                       update() branch too, and SQLite has supported RETURNING
            //                       since 3.35, so both branches work on both drivers.
            //
            // None of that is a reason to simplify the line: the explicit `false` is the
            // safety for a caller that does NOT hold a transaction, and RETURNING is what
            // makes the row count trustworthy rather than incidental.
            return $connection->select($sql.' returning id', $bindings, false) !== [];
        }

        return $connection->update($sql, $bindings) === 1;
    }

    /**
     * Why a claim or a peek found nothing. Reuses the sign-in failure enum: an
     * invitation fails for the same three reasons a link does, plus revocation.
     */
    private function classify(string $hash, CarbonInterface $now): ClaimFailure
    {
        $record = Invitation::query()->useWritePdo()->where('token_hash', $hash)->first();

        if (! $record instanceof Invitation) {
            return ClaimFailure::NotFound;
        }

        if ($record->isAccepted()) {
            return ClaimFailure::AlreadyConsumed;
        }

        if ($record->isRevoked()) {
            return ClaimFailure::Revoked;
        }

        return $record->isExpired($now) ? ClaimFailure::Expired : ClaimFailure::NotFound;
    }

    /**
     * The same normalization the request path applies, so an invitation issued for
     * "Alice@Example.com " is found by a lookup for "alice@example.com".
     */
    private function normalize(string $email): string
    {
        return NormalizedEmail::from($email);
    }

    /**
     * 256 bits, URL-safe, no padding -- the same shape as a sign-in link token.
     */
    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
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

        $stored = Invitation::query()->useWritePdo()->whereIn('token_hash', $candidates)->value('token_hash');

        // Falling back to the current key keeps a miss a miss: the caller goes on to its
        // ordinary not-found path rather than branching on a second kind of nothing.
        return is_string($stored) ? $stored : $candidates[0];
    }

    private function connection(): Connection
    {
        return (new Invitation)->getConnection();
    }
}
