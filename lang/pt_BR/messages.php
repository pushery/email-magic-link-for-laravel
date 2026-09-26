<?php

declare(strict_types=1);

// The ISO 15897 spelling of the locale, which is the form Laravel's documentation
// prescribes for territory variants (`pt_BR` rather than `pt-BR`). The translator
// matches the directory name literally, so both spellings have to exist for the
// bundle to be found. This one delegates to its hyphenated twin, not to `pt`, so the two
// spellings cannot drift apart the day `pt-BR` gains a string of its own.
return require __DIR__.'/../pt-BR/messages.php';
