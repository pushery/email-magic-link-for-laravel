<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;

/**
 * The sign-ins a session made a moment ago, so a sign-in form sent twice reads as the sign-in it repeats.
 *
 * A double click sends a form twice. The first request spends the link, the code or the invitation and signs the
 * person in; the browser drops its answer for the second one, and the second request finds the credential spent.
 * A sign-in is noted under the session it was made from and the credential it spent, and a refused request that
 * carries the same session and the same credential within a minute is answered as the sign-in it repeats. Only the
 * browser that sent the first request holds that session, since signing in replaces it with a new one, so the note
 * opens nothing a person with the credential alone could not open with its first use.
 *
 * Kept in the cache rather than in the session: the first request replaces the session it arrived with, and the
 * second still carries the old one.
 */
final readonly class CompletedSignIns
{
    /** How long a sign-in answers for a repeat of its form: long enough for a second click, and not for a later visit. */
    private const int REPEAT_WINDOW_SECONDS = 60;

    public function __construct(private Cache $cache) {}

    /** Note that this session signed a person in with the credential, or accepted an invitation that signed nobody in. */
    public function remember(Request $request, string $credential, ?Authenticatable $user, string $guard, bool $remember): void
    {
        if ($request->hasSession()) {
            $this->cache->put($this->key($request, $credential), [
                'user' => $user?->getAuthIdentifier(),
                'guard' => $guard,
                'remember' => $remember,
            ], self::REPEAT_WINDOW_SECONDS);
        }
    }

    /**
     * The sign-in a refused request repeats, or null where it repeats none.
     *
     * @return array{user: mixed, guard: string, remember: bool}|null
     */
    public function repeated(Request $request, string $credential): ?array
    {
        $note = $request->hasSession() ? $this->cache->get($this->key($request, $credential)) : null;

        if (! is_array($note) || ! array_key_exists('user', $note) || ! is_string($note['guard'] ?? null) || ! is_bool($note['remember'] ?? null)) {
            return null;
        }

        return ['user' => $note['user'], 'guard' => $note['guard'], 'remember' => $note['remember']];
    }

    /** The cache key of the session and the credential, hashed so the cache holds neither. */
    private function key(Request $request, string $credential): string
    {
        return 'email-magic-link:sign-in:'.hash('sha256', $request->session()->getId().'|'.$credential);
    }
}
