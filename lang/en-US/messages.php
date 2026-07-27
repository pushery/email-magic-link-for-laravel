<?php

declare(strict_types=1);

// American English. The bundle EXISTS but carries no divergence from `en`, and it
// delegates rather than duplicating: a copy would have to be kept in step by hand
// on every string change, which is the drift this package cannot detect.
//
// Deleting it instead — the obvious simplification — is NOT safe, and that was
// measured rather than assumed. Laravel falls back to `app.fallback_locale`, not
// to the base language: with `fallback_locale = 'de'` and no bundle here, a
// visitor on `en-US` is served GERMAN. Keeping the file is what makes the locale
// resolve to American English whatever the host's fallback is.
//
// Give this file real content the moment a string diverges from `en`.
return require __DIR__.'/../en/messages.php';
