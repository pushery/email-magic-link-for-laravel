<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Responses;

use EmailMagicLink\Contracts\InvalidLinkResponder;
use EmailMagicLink\Support\MagicLinkConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The bundled invalid-link responder, selected by `invalid_response.via`:
 *
 *   redirect  send the user back to the sign-in form (default) — or to a
 *             configured URL — with the generic error flashed and the email
 *             re-prefilled. Preserves the original browser behavior.
 *   view      render a Blade view (receives `message`), so the host owns the
 *             branding of the error page; answered with `abort_status`, never 200.
 *   abort     abort() with a configurable HTTP status, handing off to the
 *             application's own error page.
 *   json      return the JSON envelope ({message, error}) to every client, not
 *             only those that negotiated JSON.
 *
 * Every branch uses the same generic message and never inspects why the token
 * failed, so the response is identical for an unknown and an expired token.
 */
final readonly class DefaultInvalidLinkResponder implements InvalidLinkResponder
{
    public function __construct(private MagicLinkConfig $config) {}

    public function respond(Request $request, string $message, string $failureRoute): Response
    {
        return match ($this->config->invalidResponseMode()) {
            // The same status the abort strategy uses: a refusal rendered at 200 reads as a
            // success to a link checker, a cache and a crawler, and the invitation GET is a
            // URL that carries a token.
            'view' => response()->view($this->config->invalidResponseView(), ['message' => $message], $this->config->invalidResponseAbortStatus()),
            'abort' => abort($this->config->invalidResponseAbortStatus(), $message),
            'json' => new JsonResponse(
                ['message' => $message, 'error' => $this->config->invalidResponseErrorCode()],
                422,
            ),
            default => $this->redirect($request, $failureRoute, $message),
        };
    }

    private function redirect(Request $request, string $failureRoute, string $message): RedirectResponse
    {
        $target = $this->config->invalidResponseRedirectTo();

        $redirect = $target !== null
            ? redirect()->to($target)
            : redirect()->route($failureRoute);

        // Flash only what the forms re-prefill -- the email and the guard -- so a
        // retry keeps them without putting them in the URL. An allowlist, never a
        // denylist: the consume form posts a passphrase and the host's acceptance
        // form posts a password, and `except('code')` wrote both into the session
        // store in the clear. The next secret field cannot land there either.
        // The code form keys the generic failure on the CODE field: an unknown email and
        // a wrong code still produce a byte-identical response (both come through here),
        // but the field marked invalid is the one that was wrong.
        $field = $failureRoute === 'email-magic-link.code.form' ? 'code' : 'email';

        return $redirect
            ->withErrors([$field => $message])
            ->withInput($request->only(['email', 'guard']));
    }
}
