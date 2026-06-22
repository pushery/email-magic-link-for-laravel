<?php

declare(strict_types=1);

namespace EmailMagicLink\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Delivers a magic link or one-time code over the mail channel.
 *
 * Queued so the request that issues it returns without waiting on the mailer,
 * which keeps the request endpoint's timing independent of whether a user was
 * found. It carries only the already-built action URL or code, never internals.
 */
class MagicLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  'link'|'code'  $channel
     */
    public function __construct(
        public readonly string $channel,
        public readonly ?string $actionUrl,
        public readonly ?string $code,
        public readonly int $expiresInMinutes,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $application = config('app.name');
        $application = is_string($application) && $application !== '' ? $application : 'this application';

        return $this->channel === 'code'
            ? $this->codeMessage($application)
            : $this->linkMessage($application);
    }

    private function linkMessage(string $application): MailMessage
    {
        return (new MailMessage)
            ->subject(__('email-magic-link::messages.mail_link_subject', ['app' => $application]))
            ->line(__('email-magic-link::messages.mail_link_intro', ['app' => $application]))
            ->action(__('email-magic-link::messages.mail_link_action'), (string) $this->actionUrl)
            ->line(__('email-magic-link::messages.mail_link_expiry', ['minutes' => $this->expiresInMinutes]))
            ->line(__('email-magic-link::messages.mail_ignore'));
    }

    private function codeMessage(string $application): MailMessage
    {
        return (new MailMessage)
            ->subject(__('email-magic-link::messages.mail_code_subject', ['app' => $application]))
            ->line(__('email-magic-link::messages.mail_code_intro', ['app' => $application]))
            ->line((string) $this->code)
            ->line(__('email-magic-link::messages.mail_code_expiry', ['minutes' => $this->expiresInMinutes]))
            ->line(__('email-magic-link::messages.mail_ignore'));
    }
}
