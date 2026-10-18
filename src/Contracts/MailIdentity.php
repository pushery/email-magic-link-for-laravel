<?php

declare(strict_types=1);

namespace EmailMagicLink\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Names the application and greets the recipient in the sign-in mail.
 *
 * The bundled answer names the application after `app.name` and greets nobody by name. An installation
 * that serves several brands or tenants from one codebase names the one the account belongs to, and an
 * application that knows its users' names greets them by it. The mail that goes out stays the bundled
 * MagicLinkNotification either way, so a test that asserts on that class keeps passing.
 *
 * Asked in the request that issues the credential, with the account the mail goes to, because the mail
 * itself is built later in a queue worker, where neither is at hand. The answers travel with the queued
 * notification. The request runs this only for an address that resolves to an account, so the time an
 * answer takes is time only a known address spends: read what the account and the request already hold,
 * and leave a remote call out. An exception thrown here is reported, and the mail goes out under the
 * bundled name and greeting rather than the request failing for known addresses alone.
 */
interface MailIdentity
{
    /**
     * The name of the application in the mail's subject, its introduction and its sign-off.
     *
     * Every bundled sentence takes it as a proper noun ("Sign in to :app"), so a name reads correctly in
     * every language without declension.
     */
    public function applicationName(Authenticatable $user): string;

    /**
     * The line the mail opens with, or null for the bundled greeting.
     *
     * Sent as it is returned, so it is already in the recipient's language. The request's locale is the
     * one the mail is rendered in.
     */
    public function greeting(Authenticatable $user): ?string;
}
