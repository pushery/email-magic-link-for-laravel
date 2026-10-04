<?php

declare(strict_types=1);

// Brazilian Portuguese. It addresses the reader as você, like the base bundle, and differs
// from it in vocabulary and spelling rather than in register: "Entrar" where Portugal says
// "Iniciar sessão", "Digite" where it says "Introduza", "Enviamos" without the European
// accent, and the possessive without its article ("Seu código", not "O seu código").
return [
    // Shared.
    'heading' => 'Entrar em :app',
    'email_label' => 'Endereço de e-mail',
    'sign_in' => 'Entrar',

    // Request form.
    'request_title' => 'Entrar',
    'request_intro_link' => 'Digite seu endereço de e-mail e enviaremos um link de acesso seguro.',
    'request_intro_code' => 'Digite seu endereço de e-mail e enviaremos um código de acesso seguro.',
    'request_send_link' => 'Enviar link de acesso',
    'request_send_code' => 'Enviar código de acesso',
    'delivery_legend' => 'Forma de envio',
    'delivery_link' => 'Link mágico',
    'delivery_code' => 'Código de uso único',

    // Confirmation page.
    'confirm_title' => 'Confirmar acesso',
    'confirm_intro' => 'Para sua segurança, confirme que deseja entrar.',

    // Code entry form.
    'code_title' => 'Digite seu código',
    'code_heading' => 'Digite seu código de acesso',
    'code_intro' => 'Enviamos um código de uso único para o seu e-mail. Digite-o abaixo para concluir o acesso.',
    'code_label' => 'Código de acesso',

    // Confirmation passphrase gate.
    'passphrase_label' => 'Frase de acesso',

    // "Stay signed in" on the confirmation screen and the code form.
    'remember_label' => 'Manter conectado',

    // Invalid link page.
    'invalid_title' => 'Solicitação de acesso inválida',

    // Status and error messages.
    'status_link_sent' => 'Se houver uma conta com esse e-mail, enviamos um link de acesso.',
    'status_code_sent' => 'Se houver uma conta com esse e-mail, enviamos um código de acesso.',
    'consume_failed' => 'Esta solicitação de acesso é inválida ou expirou. Solicite uma nova.',
    'invitation_failed' => 'Este convite é inválido ou expirou. Peça um novo a quem enviou o convite.',
    'captcha_failed' => 'A verificação falhou. Tente novamente.',
    'resend_throttled' => '{1} Aguarde :seconds segundo antes de solicitar outro e-mail de acesso.|[0,*] Aguarde :seconds segundos antes de solicitar outro e-mail de acesso.',
    'resend_countdown_label' => 'Tempo de espera antes de poder solicitar outro e-mail',

    // Notification — magic link.
    'mail_link_subject' => 'Entrar em :app',
    'mail_link_intro' => 'Use o botão abaixo para entrar em :app.',
    'mail_link_action' => 'Entrar',
    'mail_link_expiry' => '{1} Este link expira em :minutes minuto e só pode ser usado uma vez.|[0,*] Este link expira em :minutes minutos e só pode ser usado uma vez.',
    'mail_link_expiry_reusable' => '{1} Este link expira em :minutes minuto e pode ser usado :uses vezes.|[0,*] Este link expira em :minutes minutos e pode ser usado :uses vezes.',

    // Notification — one-time code.
    'mail_code_subject' => 'Seu código de acesso de :app',
    'mail_code_intro' => 'Seu código de acesso para :app é:',
    'mail_code_expiry' => '{1} Este código expira em :minutes minuto.|[0,*] Este código expira em :minutes minutos.',

    // Notification — shared.
    'mail_greeting' => 'Olá!',
    'mail_salutation' => 'Atenciosamente, :app',
    'mail_ignore' => 'Se você não solicitou isto, pode ignorar este e-mail com segurança.',
];
