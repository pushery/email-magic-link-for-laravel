<?php

declare(strict_types=1);

// European Portuguese, in the TU form — the house convention is pt-PT = tu,
// pt-BR = você, and carrying that difference is the only reason this split
// exists. Before 0.19.0 this file was a byte-identical copy of the base bundle,
// which is written in the você form: correct Portuguese, but formal and distant
// where every other locale addresses the reader informally.
//
// The verb forms follow through consistently: "Introduz" not "Introduza",
// "Aguarda" not "Aguarde", "o teu" not "o seu", and the clitic "Enviámos-te"
// rather than "Enviámos-lhe". A single stray "verifica" already sat here among
// twelve você markers; it is now the rule rather than an accident.
return [
    // Shared.
    'heading' => 'Iniciar sessão em :app',
    'email_label' => 'Endereço de e-mail',
    'sign_in' => 'Iniciar sessão',

    // Request form.
    'request_title' => 'Iniciar sessão',
    'request_intro_link' => 'Introduz o teu endereço de e-mail e enviamos-te um link de acesso seguro.',
    'request_intro_code' => 'Introduz o teu endereço de e-mail e enviamos-te um código de acesso seguro.',
    'request_send_link' => 'Enviar link de acesso',
    'request_send_code' => 'Enviar código de acesso',
    'delivery_legend' => 'Entrega',
    'delivery_link' => 'Link mágico',
    'delivery_code' => 'Código de uso único',

    // Confirmation page.
    'confirm_title' => 'Confirmar início de sessão',
    'confirm_intro' => 'Para tua segurança, confirma que pretendes iniciar sessão. Este link só pode ser usado uma vez.',

    // Code entry form.
    'code_title' => 'Introduz o teu código',
    'code_heading' => 'Introduz o teu código de acesso',
    'code_intro' => 'Enviámos-te um código de uso único por e-mail. Introdu-lo abaixo para concluíres o início de sessão.',
    'code_label' => 'Código de acesso',

    // Confirmation passphrase gate.
    'passphrase_label' => 'Frase de acesso',

    // Invalid link page.
    'invalid_title' => 'Pedido de acesso inválido',

    // Status and error messages.
    'status_link_sent' => 'Se existir uma conta com esse e-mail, enviámos um link de acesso.',
    'status_code_sent' => 'Se existir uma conta com esse e-mail, enviámos um código de acesso.',
    'consume_failed' => 'Este pedido de acesso é inválido ou expirou. Solicita um novo.',
    'captcha_failed' => 'A verificação falhou. Tenta novamente.',
    'resend_throttled' => 'Aguarda :seconds segundos antes de solicitares outro e-mail de acesso.',
    'resend_countdown_label' => 'Tempo de espera antes de poderes solicitar outro e-mail',

    // Notification — magic link.
    'mail_link_subject' => 'Iniciar sessão em :app',
    'mail_link_intro' => 'Utiliza o botão abaixo para iniciares sessão em :app.',
    'mail_link_action' => 'Iniciar sessão',
    'mail_link_expiry' => 'Este link expira em :minutes minutos e só pode ser usado uma vez.',

    // Notification — one-time code.
    'mail_code_subject' => 'O teu código de acesso de :app',
    'mail_code_intro' => 'O teu código de acesso para :app é:',
    'mail_code_expiry' => 'Este código expira em :minutes minutos.',

    // Notification — shared.
    'mail_ignore' => 'Se não solicitaste isto, podes ignorar este e-mail em segurança.',
];
