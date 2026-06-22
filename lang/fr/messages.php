<?php

declare(strict_types=1);

return [
    // Shared.
    'heading' => 'Connexion à :app',
    'email_label' => 'Adresse e-mail',
    'sign_in' => 'Se connecter',

    // Request form.
    'request_title' => 'Se connecter',
    'request_intro_link' => 'Saisissez votre adresse e-mail et nous vous enverrons un lien de connexion sécurisé.',
    'request_intro_code' => 'Saisissez votre adresse e-mail et nous vous enverrons un code de connexion sécurisé.',
    'request_send_link' => 'Envoyer le lien de connexion',
    'request_send_code' => 'Envoyer le code de connexion',
    'delivery_legend' => 'Livraison',
    'delivery_link' => 'Lien magique',
    'delivery_code' => 'Code à usage unique',

    // Confirmation page.
    'confirm_title' => 'Confirmer la connexion',
    'confirm_intro' => 'Pour votre sécurité, confirmez que vous souhaitez vous connecter. Ce lien ne peut être utilisé qu’une seule fois.',

    // Code entry form.
    'code_title' => 'Saisissez votre code',
    'code_heading' => 'Saisissez votre code de connexion',
    'code_intro' => 'Nous vous avons envoyé un code à usage unique par e-mail. Saisissez-le ci-dessous pour terminer la connexion.',
    'code_label' => 'Code de connexion',

    // Notification — magic link.
    'mail_link_subject' => 'Connexion à :app',
    'mail_link_intro' => 'Utilisez le bouton ci-dessous pour vous connecter à :app.',
    'mail_link_action' => 'Se connecter',
    'mail_link_expiry' => 'Ce lien expire dans :minutes minutes et ne peut être utilisé qu’une seule fois.',

    // Notification — one-time code.
    'mail_code_subject' => 'Votre code de connexion :app',
    'mail_code_intro' => 'Votre code de connexion pour :app est :',
    'mail_code_expiry' => 'Ce code expire dans :minutes minutes.',

    // Notification — shared.
    'mail_ignore' => 'Si vous n’êtes pas à l’origine de cette demande, vous pouvez ignorer cet e-mail en toute sécurité.',
];
