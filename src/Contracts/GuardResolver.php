<?php

declare(strict_types=1);

namespace EmailMagicLink\Contracts;

use Illuminate\Http\Request;

/**
 * Decides which guard a request signs in to.
 *
 * The default answers from the request: the guard its `guard` field names when that guard is
 * the default or listed in `email-magic-link.guards`, otherwise the default guard. An
 * application whose guard follows something the request cannot choose binds its own. A site's
 * readers sign in on the site's host and the team on the application's host, and the field
 * changes nothing on either.
 *
 * The answer is asked at both ends. Issuing a link or a code signs the credential to the guard
 * it returns, and redeeming one asks again with the credential's guard: a credential whose guard
 * is not the answer for the redeeming request is refused, so a link issued on one host opens no
 * session on another.
 *
 * The answer has to be the default guard or one listed in `email-magic-link.guards`. The list
 * stays the set of guards the package may sign in to at all, and the resolver picks one of
 * them per request; any other answer is refused as a misconfiguration.
 *
 * Invitations do not ask it. Their signed URL binds an invitation to the host it was made for,
 * and its guard is the one the code that invited chose.
 */
interface GuardResolver
{
    /**
     * The guard this request signs in to.
     *
     * @param  string|null  $requested  the guard the request names, or the guard of the
     *                                  credential it redeems; null when it names none
     */
    public function guardFor(Request $request, ?string $requested): string;
}
