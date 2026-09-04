<?php

declare(strict_types=1);

// The ISO 15897 spelling of the locale, which is the form Laravel's documentation
// prescribes for territory variants (`en_US` rather than `en-US`). The translator
// matches the directory name literally, so both spellings have to exist for the
// bundle to be found; this one delegates to the file that carries the strings.
return require __DIR__.'/../en/messages.php';
