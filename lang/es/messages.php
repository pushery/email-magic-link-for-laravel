<?php

declare(strict_types=1);

return [
    // Shared.
    'heading' => 'Inicia sesión en :app',
    'email_label' => 'Correo electrónico',
    'sign_in' => 'Iniciar sesión',

    // Request form.
    'request_title' => 'Iniciar sesión',
    'request_intro_link' => 'Introduce tu correo electrónico y te enviaremos un enlace de acceso seguro.',
    'request_intro_code' => 'Introduce tu correo electrónico y te enviaremos un código de acceso seguro.',
    'request_send_link' => 'Enviar enlace de acceso',
    'request_send_code' => 'Enviar código de acceso',
    'delivery_legend' => 'Entrega',
    'delivery_link' => 'Enlace mágico',
    'delivery_code' => 'Código de un solo uso',

    // Confirmation page.
    'confirm_title' => 'Confirmar inicio de sesión',
    'confirm_intro' => 'Por tu seguridad, confirma que quieres iniciar sesión. Este enlace solo se puede usar una vez.',

    // Code entry form.
    'code_title' => 'Introduce tu código',
    'code_heading' => 'Introduce tu código de acceso',
    'code_intro' => 'Te hemos enviado un código de un solo uso por correo. Introdúcelo abajo para completar el inicio de sesión.',
    'code_label' => 'Código de acceso',

    // Notification — magic link.
    'mail_link_subject' => 'Inicia sesión en :app',
    'mail_link_intro' => 'Usa el botón de abajo para iniciar sesión en :app.',
    'mail_link_action' => 'Iniciar sesión',
    'mail_link_expiry' => 'Este enlace caduca en :minutes minutos y solo se puede usar una vez.',

    // Notification — one-time code.
    'mail_code_subject' => 'Tu código de acceso de :app',
    'mail_code_intro' => 'Tu código de acceso para :app es:',
    'mail_code_expiry' => 'Este código caduca en :minutes minutos.',

    // Notification — shared.
    'mail_ignore' => 'Si no has solicitado esto, puedes ignorar este correo de forma segura.',
];
