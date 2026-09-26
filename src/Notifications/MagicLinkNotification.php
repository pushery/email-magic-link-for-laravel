<?php

declare(strict_types=1);

namespace EmailMagicLink\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Delivers a magic link or one-time code over the mail channel.
 *
 * Queued so the request that issues it returns without waiting on the mailer,
 * which keeps the request endpoint's timing independent of whether a user was
 * found. It carries only the already-built action URL or code, never internals.
 *
 * Encrypted on the queue, because the URL or code it carries is the credential
 * itself. The token table holds only an HMAC so that a database reader cannot sign
 * in, and the default queue is a table in that same database: without encryption
 * the reader would take the plaintext from `jobs`, or from `failed_jobs` for good.
 * A configured notification has to extend this class, so it inherits the marker.
 */
class MagicLinkNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * @param  'link'|'code'  $channel
     * @param  int  $uses  How many sign-ins the link allows. A multi-use link must not be
     *                     described as single-use: whoever reads "can be used once" treats
     *                     the mail as spent after signing in, and forwards it or leaves it
     *                     in a shared inbox while it still opens the account.
     */
    public function __construct(
        public readonly string $channel,
        public readonly ?string $actionUrl,
        public readonly ?string $code,
        public readonly int $expiresInMinutes,
        public readonly int $uses = 1,
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
            ->greeting(__('email-magic-link::messages.mail_greeting'))
            ->line(__('email-magic-link::messages.mail_link_intro', ['app' => $application]))
            ->action(__('email-magic-link::messages.mail_link_action'), (string) $this->actionUrl)
            ->line($this->uses > 1
                ? trans_choice('email-magic-link::messages.mail_link_expiry_reusable', $this->expiresInMinutes, ['minutes' => $this->expiresInMinutes, 'uses' => $this->uses])
                : trans_choice('email-magic-link::messages.mail_link_expiry', $this->expiresInMinutes, ['minutes' => $this->expiresInMinutes]))
            ->line(__('email-magic-link::messages.mail_ignore'))
            ->salutation(__('email-magic-link::messages.mail_salutation', ['app' => $application]));
    }

    private function codeMessage(string $application): MailMessage
    {
        return (new MailMessage)
            ->subject(__('email-magic-link::messages.mail_code_subject', ['app' => $application]))
            ->greeting(__('email-magic-link::messages.mail_greeting'))
            ->line(__('email-magic-link::messages.mail_code_intro', ['app' => $application]))
            ->line((string) $this->code)
            ->line(trans_choice('email-magic-link::messages.mail_code_expiry', $this->expiresInMinutes, ['minutes' => $this->expiresInMinutes]))
            ->line(__('email-magic-link::messages.mail_ignore'))
            ->salutation(__('email-magic-link::messages.mail_salutation', ['app' => $application]));
    }
}
