<?php

declare(strict_types=1);

namespace EmailMagicLink\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * A single issued invitation.
 *
 * Only the keyed hash of the secret is stored. The row is the unit the atomic
 * single-use claim operates on, and it is addressed by EMAIL rather than by user:
 * the recipient may have no account yet, which is the whole point.
 *
 * @property int $id
 * @property string $email
 * @property string $guard
 * @property string $token_hash
 * @property array<string, mixed>|null $context
 * @property string|null $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Invitation extends Model
{
    protected $table = 'email_magic_link_invitations';

    /**
     * @var list<string>
     */
    protected $hidden = ['token_hash'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'guard',
        'token_hash',
        'context',
        'invited_by',
        'expires_at',
        'accepted_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isExpired(?CarbonInterface $now = null): bool
    {
        return $this->expires_at->lessThanOrEqualTo($now ?? Carbon::now());
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
