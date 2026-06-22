<?php

declare(strict_types=1);

return [
    // Shared.
    'heading' => 'Accedi a :app',
    'email_label' => 'Indirizzo email',
    'sign_in' => 'Accedi',

    // Request form.
    'request_title' => 'Accedi',
    'request_intro_link' => 'Inserisci il tuo indirizzo email e ti invieremo un link di accesso sicuro.',
    'request_intro_code' => 'Inserisci il tuo indirizzo email e ti invieremo un codice di accesso sicuro.',
    'request_send_link' => 'Invia link di accesso',
    'request_send_code' => 'Invia codice di accesso',
    'delivery_legend' => 'Consegna',
    'delivery_link' => 'Link magico',
    'delivery_code' => 'Codice monouso',

    // Confirmation page.
    'confirm_title' => 'Conferma accesso',
    'confirm_intro' => 'Per la tua sicurezza, conferma di voler accedere. Questo link può essere usato una sola volta.',

    // Code entry form.
    'code_title' => 'Inserisci il tuo codice',
    'code_heading' => 'Inserisci il tuo codice di accesso',
    'code_intro' => 'Ti abbiamo inviato un codice monouso via email. Inseriscilo qui sotto per completare l’accesso.',
    'code_label' => 'Codice di accesso',

    // Notification — magic link.
    'mail_link_subject' => 'Accedi a :app',
    'mail_link_intro' => 'Usa il pulsante qui sotto per accedere a :app.',
    'mail_link_action' => 'Accedi',
    'mail_link_expiry' => 'Questo link scade tra :minutes minuti e può essere usato una sola volta.',

    // Notification — one-time code.
    'mail_code_subject' => 'Il tuo codice di accesso di :app',
    'mail_code_intro' => 'Il tuo codice di accesso per :app è:',
    'mail_code_expiry' => 'Questo codice scade tra :minutes minuti.',

    // Notification — shared.
    'mail_ignore' => 'Se non hai richiesto questo, puoi ignorare questa email in sicurezza.',
];
