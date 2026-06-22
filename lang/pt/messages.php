<?php

declare(strict_types=1);

return [
    // Shared.
    'heading' => 'Iniciar sessão em :app',
    'email_label' => 'Endereço de e-mail',
    'sign_in' => 'Iniciar sessão',

    // Request form.
    'request_title' => 'Iniciar sessão',
    'request_intro_link' => 'Introduza o seu endereço de e-mail e enviaremos um link de acesso seguro.',
    'request_intro_code' => 'Introduza o seu endereço de e-mail e enviaremos um código de acesso seguro.',
    'request_send_link' => 'Enviar link de acesso',
    'request_send_code' => 'Enviar código de acesso',
    'delivery_legend' => 'Entrega',
    'delivery_link' => 'Link mágico',
    'delivery_code' => 'Código de uso único',

    // Confirmation page.
    'confirm_title' => 'Confirmar início de sessão',
    'confirm_intro' => 'Para sua segurança, confirme que pretende iniciar sessão. Este link só pode ser usado uma vez.',

    // Code entry form.
    'code_title' => 'Introduza o seu código',
    'code_heading' => 'Introduza o seu código de acesso',
    'code_intro' => 'Enviámos-lhe um código de uso único por e-mail. Introduza-o abaixo para concluir o início de sessão.',
    'code_label' => 'Código de acesso',

    // Notification — magic link.
    'mail_link_subject' => 'Iniciar sessão em :app',
    'mail_link_intro' => 'Utilize o botão abaixo para iniciar sessão em :app.',
    'mail_link_action' => 'Iniciar sessão',
    'mail_link_expiry' => 'Este link expira em :minutes minutos e só pode ser usado uma vez.',

    // Notification — one-time code.
    'mail_code_subject' => 'O seu código de acesso de :app',
    'mail_code_intro' => 'O seu código de acesso para :app é:',
    'mail_code_expiry' => 'Este código expira em :minutes minutos.',

    // Notification — shared.
    'mail_ignore' => 'Se não solicitou isto, pode ignorar este e-mail em segurança.',
];
