<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Requests;

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
            'code' => is_string($code) ? strtoupper(preg_replace('/\s+/u', '', $code) ?? $code) : $code,
        ]);
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
