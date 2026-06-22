<?php

declare(strict_types=1);

return [
    // Shared.
    'heading' => 'Sign in to :app',
    'email_label' => 'Email address',
    'sign_in' => 'Sign in',

    // Request form.
    'request_title' => 'Sign in',
    'request_intro_link' => 'Enter your email address and we will send you a secure sign-in link.',
    'request_intro_code' => 'Enter your email address and we will send you a secure sign-in code.',
    'request_send_link' => 'Send sign-in link',
    'request_send_code' => 'Send sign-in code',
    'delivery_legend' => 'Delivery',
    'delivery_link' => 'Magic link',
    'delivery_code' => 'One-time code',

    // Confirmation page.
    'confirm_title' => 'Confirm sign in',
    'confirm_intro' => 'For your security, confirm that you want to sign in. This link can only be used once.',

    // Code entry form.
    'code_title' => 'Enter your code',
    'code_heading' => 'Enter your sign-in code',
    'code_intro' => 'We emailed you a one-time code. Enter it below to finish signing in.',
    'code_label' => 'Sign-in code',

    // Notification — magic link.
    'mail_link_subject' => 'Sign in to :app',
    'mail_link_intro' => 'Use the button below to sign in to :app.',
    'mail_link_action' => 'Sign in',
    'mail_link_expiry' => 'This link expires in :minutes minutes and can be used once.',

    // Notification — one-time code.
    'mail_code_subject' => 'Your :app sign-in code',
    'mail_code_intro' => 'Your sign-in code for :app is:',
    'mail_code_expiry' => 'This code expires in :minutes minutes.',

    // Notification — shared.
    'mail_ignore' => 'If you did not request this, you can safely ignore this email.',
];
