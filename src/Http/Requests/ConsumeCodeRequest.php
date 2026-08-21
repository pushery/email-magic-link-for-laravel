<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Requests;

use EmailMagicLink\Support\MagicLinkConfig;
use Illuminate\Foundation\Http\FormRequest;
use Override;

/**
 * Validates a one-time code submission (email + code).
 *
 * Open authorization for the same reason as the request endpoint: the caller is
 * an unauthenticated guest. Correctness of the code itself is verified by the
 * token store's constant-time hash comparison, not here.
 */
final class ConsumeCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string', 'max:255'],
            'guard' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    #[Override]
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        $code = $this->input('code');

        $this->merge([
            'email' => is_string($email) ? mb_strtolower(trim($email)) : $email,
            'code' => is_string($code) ? $this->normalizeCode($code) : $code,
        ]);
    }

    /**
     * Strip whitespace, then fold case ONLY in the direction the alphabet writes.
     *
     * Folding used to be an unconditional `strtoupper`. That is convenient for the
     * shipped alphabet (upper-case only, so the reader may type lower) and it is
     * destructive for any other: a mixed alphabet has `a` and `A` as two distinct
     * characters the generator both mints, and a lower-case alphabet is folded away
     * from what was minted. Either way the code can never be redeemed, the failure
     * looks exactly like an expired code, and `max_attempts_per_token` counts down
     * while nothing names the cause.
     *
     * `mb_strtoupper` rather than `strtoupper`: the alphabet is read multibyte-aware
     * everywhere else, and the byte-wise function mangles a non-ASCII alphabet
     * instead of folding it.
     */
    private function normalizeCode(string $code): string
    {
        $stripped = preg_replace('/\s+/u', '', $code) ?? $code;

        return match (app(MagicLinkConfig::class)->codeAlphabetCaseFolding()) {
            'upper' => mb_strtoupper($stripped),
            'lower' => mb_strtolower($stripped),
            default => $stripped,
        };
    }

    public function email(): string
    {
        $email = $this->validated('email');

        return is_string($email) ? $email : '';
    }

    public function code(): string
    {
        $code = $this->validated('code');

        return is_string($code) ? $code : '';
    }

    public function requestedGuard(): ?string
    {
        $guard = $this->validated('guard');

        return is_string($guard) && $guard !== '' ? $guard : null;
    }
}
