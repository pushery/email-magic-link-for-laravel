<?php

declare(strict_types=1);

return [
    // Shared.
    'heading' => 'Inloggen bij :app',
    'email_label' => 'E-mailadres',
    'sign_in' => 'Inloggen',

    // Request form.
    'request_title' => 'Inloggen',
    'request_intro_link' => 'Voer je e-mailadres in en we sturen je een veilige inloglink.',
    'request_intro_code' => 'Voer je e-mailadres in en we sturen je een veilige inlogcode.',
    'request_send_link' => 'Inloglink versturen',
    'request_send_code' => 'Inlogcode versturen',
    'delivery_legend' => 'Bezorging',
    'delivery_link' => 'Magische link',
    'delivery_code' => 'Eenmalige code',

    // Confirmation page.
    'confirm_title' => 'Inloggen bevestigen',
    'confirm_intro' => 'Bevestig voor je veiligheid dat je wilt inloggen. Deze link kan maar één keer worden gebruikt.',

    // Code entry form.
    'code_title' => 'Voer je code in',
    'code_heading' => 'Voer je inlogcode in',
    'code_intro' => 'We hebben je een eenmalige code gemaild. Voer deze hieronder in om het inloggen te voltooien.',
    'code_label' => 'Inlogcode',

    // Notification — magic link.
    'mail_link_subject' => 'Inloggen bij :app',
    'mail_link_intro' => 'Gebruik de knop hieronder om in te loggen bij :app.',
    'mail_link_action' => 'Inloggen',
    'mail_link_expiry' => 'Deze link verloopt over :minutes minuten en kan maar één keer worden gebruikt.',

    // Notification — one-time code.
    'mail_code_subject' => 'Je inlogcode voor :app',
    'mail_code_intro' => 'Je inlogcode voor :app is:',
    'mail_code_expiry' => 'Deze code verloopt over :minutes minuten.',

    // Notification — shared.
    'mail_ignore' => 'Als je dit niet hebt aangevraagd, kun je deze e-mail veilig negeren.',
];
