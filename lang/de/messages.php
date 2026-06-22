<?php

declare(strict_types=1);

return [
    // Shared.
    'heading' => 'Bei :app anmelden',
    'email_label' => 'E-Mail-Adresse',
    'sign_in' => 'Anmelden',

    // Request form.
    'request_title' => 'Anmelden',
    'request_intro_link' => 'Gib deine E-Mail-Adresse ein und wir senden dir einen sicheren Anmeldelink.',
    'request_intro_code' => 'Gib deine E-Mail-Adresse ein und wir senden dir einen sicheren Anmeldecode.',
    'request_send_link' => 'Anmeldelink senden',
    'request_send_code' => 'Anmeldecode senden',
    'delivery_legend' => 'Zustellung',
    'delivery_link' => 'Magic Link',
    'delivery_code' => 'Einmalcode',

    // Confirmation page.
    'confirm_title' => 'Anmeldung bestätigen',
    'confirm_intro' => 'Bestätige zu deiner Sicherheit, dass du dich anmelden möchtest. Dieser Link kann nur einmal verwendet werden.',

    // Code entry form.
    'code_title' => 'Code eingeben',
    'code_heading' => 'Anmeldecode eingeben',
    'code_intro' => 'Wir haben dir einen Einmalcode per E-Mail geschickt. Gib ihn unten ein, um die Anmeldung abzuschließen.',
    'code_label' => 'Anmeldecode',

    // Status and error messages.
    'status_link_sent' => 'Wenn ein Konto zu dieser E-Mail-Adresse passt, haben wir einen Anmeldelink gesendet.',
    'status_code_sent' => 'Wenn ein Konto zu dieser E-Mail-Adresse passt, haben wir einen Anmeldecode gesendet.',
    'consume_failed' => 'Diese Anmeldeanfrage ist ungültig oder abgelaufen. Bitte fordere eine neue an.',
    'captcha_failed' => 'Die Sicherheitsprüfung ist fehlgeschlagen. Bitte versuche es erneut.',

    // Notification — magic link.
    'mail_link_subject' => 'Bei :app anmelden',
    'mail_link_intro' => 'Nutze die Schaltfläche unten, um dich bei :app anzumelden.',
    'mail_link_action' => 'Anmelden',
    'mail_link_expiry' => 'Dieser Link läuft in :minutes Minuten ab und kann nur einmal verwendet werden.',

    // Notification — one-time code.
    'mail_code_subject' => 'Dein Anmeldecode für :app',
    'mail_code_intro' => 'Dein Anmeldecode für :app lautet:',
    'mail_code_expiry' => 'Dieser Code läuft in :minutes Minuten ab.',

    // Notification — shared.
    'mail_ignore' => 'Falls du dies nicht angefordert hast, kannst du diese E-Mail ignorieren.',
];
