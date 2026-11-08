<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use EmailMagicLink\Contracts\GuardResolver;
use Illuminate\Http\Request;
use LogicException;

/**
 * The guard a request signs in to, as the bound GuardResolver decides it, held to the guards the
 * package may sign in to: the default and those in `email-magic-link.guards`.
 */
final readonly class GuardSelection
{
    public function __construct(
        private GuardResolver $resolver,
        private MagicLinkConfig $config,
    ) {}

    /**
     * The guard a credential issued or claimed on this request belongs to.
     *
     * A resolver that chooses a guard the package may not sign in to is a misconfiguration, and
     * it throws. It throws for every request it answers that way, before any address is looked
     * up, so the failure says nothing about the address that was submitted.
     *
     * @throws LogicException
     */
    public function guardFor(Request $request, ?string $requested): string
    {
        $guard = $this->resolver->guardFor($request, $requested);

        if (! in_array($guard, $this->config->allowedGuards(), true)) {
            throw new LogicException(sprintf(
                'The guard resolver [%s] chose the guard [%s], which is neither the default guard nor listed in email-magic-link.guards.',
                $this->resolver::class,
                $guard,
            ));
        }

        return $guard;
    }

    /**
     * Whether a credential signed to this guard may open a session on this request.
     *
     * Refused when the guard has left the allowlist since the credential was issued, and when
     * the resolver chooses another guard for this request. The row is spent either way, which
     * is the direction that fails closed.
     */
    public function admits(Request $request, string $guard): bool
    {
        return in_array($guard, $this->config->allowedGuards(), true)
            && $this->resolver->guardFor($request, $guard) === $guard;
    }
}
