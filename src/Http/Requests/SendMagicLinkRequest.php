<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Override;

/**
 * Validates a request to issue a magic link or code.
 *
 * Authorization is intentionally open: this is a pre-authentication endpoint
 * reachable by guests, so there is no actor or model to gate with a policy.
 */
final class SendMagicLinkRequest extends FormRequest
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
            'channel' => ['sometimes', 'nullable', 'string', Rule::in(['link', 'code'])],
        ];
    }

    #[Override]
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }

    public function email(): string
    {
        $email = $this->validated('email');

        return is_string($email) ? $email : '';
    }

    public function requestedChannel(): ?string
    {
        $channel = $this->validated('channel');

        return is_string($channel) && $channel !== '' ? $channel : null;
    }
}
