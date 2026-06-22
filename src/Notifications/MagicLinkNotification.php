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
            ->subject("Sign in to {$application}")
            ->line("Use the button below to sign in to {$application}.")
            ->action('Sign in', (string) $this->actionUrl)
            ->line("This link expires in {$this->expiresInMinutes} minutes and can be used once.")
            ->line('If you did not request this, you can safely ignore this email.');
    }

    private function codeMessage(string $application): MailMessage
    {
        return (new MailMessage)
            ->subject("Your {$application} sign-in code")
            ->line("Your sign-in code for {$application} is:")
            ->line((string) $this->code)
            ->line("This code expires in {$this->expiresInMinutes} minutes.")
            ->line('If you did not request this, you can safely ignore this email.');
    }
}
