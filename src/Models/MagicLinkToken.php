<?php

declare(strict_types=1);

namespace EmailMagicLink\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * A single issued magic link or one-time code.
 *
 * Only the keyed hash of the secret is stored. The row is the unit the atomic
 * single-use claim operates on.
 *
 * @property int $id
 * @property string $user_id
 * @property string $guard
 * @property string $token_hash
 * @property string $channel
 * @property int $attempts
 * @property int $uses_remaining
 * @property string|null $passphrase_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MagicLinkToken extends Model
{
    protected $table = 'magic_link_tokens';

    /**
     * The passphrase hash is a bcrypt of a human-chosen secret, which is offline-crackable;
     * the token hash is keyed. Neither belongs in a serialized model, and the model is
     * exposed on public value objects.
     *
     * @var list<string>
     */
    protected $hidden = ['token_hash', 'passphrase_hash'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'guard',
        'token_hash',
        'channel',
        'attempts',
        'uses_remaining',
        'passphrase_hash',
        'expires_at',
        'consumed_at',
    ];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'uses_remaining' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function isExpired(?CarbonInterface $now = null): bool
    {
        return $this->expires_at->lessThanOrEqualTo($now ?? Carbon::now());
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function requiresPassphrase(): bool
    {
        return $this->passphrase_hash !== null;
    }
}
