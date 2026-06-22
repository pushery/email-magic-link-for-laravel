<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use EmailMagicLink\Exceptions\InsecureMagicLinkConfigurationException;

/**
 * Boot-time, fail-closed configuration guardrail.
 *
 * Magic links carry 256 bits of entropy and pass trivially. Short codes do not:
 * their brute-force resistance is keyspace / attempts-per-token, and this guard
 * refuses to boot a configuration where that ratio falls below the safety
 * factor — protecting adopters who never read the entropy math.
 */
final readonly class EntropyGuard
{
    /**
     * The lowest safety factor that may be configured. The check cannot be
     * weakened below this floor, only made stricter.
     */
    public const int MINIMUM_SAFETY_FACTOR = 1_000_000;

    public function __construct(private MagicLinkConfig $config) {}

    public function validate(): void
    {
        $ttl = $this->config->ttl();

        if ($ttl <= 0) {
            throw new InsecureMagicLinkConfigurationException(
                "email-magic-link.ttl must be greater than zero; [{$ttl}] given.",
            );
        }

        // Link tokens are 256-bit by construction; only code mode needs the check.
        if ($this->config->mode() === 'link') {
            return;
        }

        $this->validateCodeKeyspace();
    }

    private function validateCodeKeyspace(): void
    {
        $alphabetSize = count($this->config->codeAlphabetCharacters());
        $length = $this->config->codeLength();
        $maxAttempts = $this->config->maxAttemptsPerToken();
        $safetyFactor = $this->config->entropySafetyFactor();

        if ($alphabetSize < 2) {
            throw new InsecureMagicLinkConfigurationException(
                'email-magic-link.code_alphabet must contain at least 2 distinct characters in code mode.',
            );
        }

        if ($length < 1) {
            throw new InsecureMagicLinkConfigurationException(
                "email-magic-link.code_length must be at least 1 in code mode; [{$length}] given.",
            );
        }

        if ($maxAttempts < 1) {
            throw new InsecureMagicLinkConfigurationException(
                'email-magic-link.max_attempts_per_token must be set to at least 1 in code mode.',
            );
        }

        if ($safetyFactor < self::MINIMUM_SAFETY_FACTOR) {
            throw new InsecureMagicLinkConfigurationException(sprintf(
                'email-magic-link.entropy_safety_factor must be at least %s; [%s] given. The guardrail cannot be weakened below this floor.',
                number_format(self::MINIMUM_SAFETY_FACTOR),
                number_format($safetyFactor),
            ));
        }

        $keyspace = $alphabetSize ** $length;
        $required = $safetyFactor * $maxAttempts;

        if ($keyspace < $required) {
            $minLength = (int) ceil(log($required, $alphabetSize));

            $odds = $keyspace <= $maxAttempts
                ? 'an attacker can exhaust the entire keyspace within the allowed attempts (near-certain success)'
                : sprintf(
                    'permits roughly a 1-in-%s chance of success within %d attempts',
                    number_format($keyspace / $maxAttempts),
                    $maxAttempts,
                );

            throw new InsecureMagicLinkConfigurationException(sprintf(
                'The configured one-time code is brute-forceable: a %d-character code over a %d-character alphabet %s, below the required 1-in-%s (email-magic-link.entropy_safety_factor). Increase email-magic-link.code_length to at least %d, enlarge email-magic-link.code_alphabet, or lower email-magic-link.max_attempts_per_token.',
                $length,
                $alphabetSize,
                $odds,
                number_format($safetyFactor),
                $minLength,
            ));
        }
    }
}
