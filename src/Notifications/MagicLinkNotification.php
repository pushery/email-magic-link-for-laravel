<?php

declare(strict_types=1);

namespace EmailMagicLink\Notifications;

use EmailMagicLink\Support\MagicLinkConfig;
use EmailMagicLink\Support\MarkdownText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * Delivers a magic link or one-time code over the mail channel.
 *
 * Queued so the request that issues it returns without waiting on the mailer,
 * which keeps the request endpoint's timing independent of whether a user was
 * found. It carries the already-built action URL or code and the name and
 * greeting it addresses the recipient with, never internals.
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

    /** The application's name the configured MailIdentity gave for this mail, if it was asked. */
    private ?string $identityName = null;

    /** The opening line the configured MailIdentity gave for this mail, if it gave one. */
    private Htmlable|string|null $identityGreeting = null;

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
        #[SensitiveParameter] public readonly ?string $code,
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

    /**
     * Names the application and sets the opening line of this one mail.
     *
     * The issuing request asks the configured MailIdentity with the account and hands its answers over
     * here, because the mail is built in a queue worker, where neither the account nor the request is at
     * hand. Both travel with the queued notification, encrypted like the credential, so an Htmlable
     * greeting has to survive serialization, as an HtmlString does.
     */
    public function withIdentity(string $applicationName, Htmlable|string|null $greeting): static
    {
        $this->identityName = $applicationName;
        $this->identityGreeting = $greeting;

        return $this;
    }

    /**
     * The mail is Markdown, so what the configured MailIdentity gave goes in as text: a greeting given as a
     * string and the application's name in every line. Their Markdown stays characters, and a web address
     * in them is broken, so a name typed as a link reaches no inbox as one. A greeting given as an
     * Htmlable goes in as it is. The subject is a header, not Markdown, and takes the name as it is.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $application = $this->identityName ?? app(MagicLinkConfig::class)->applicationName();
        $greeting = match (true) {
            $this->identityGreeting instanceof Htmlable => $this->identityGreeting,
            is_string($this->identityGreeting) => MarkdownText::text($this->identityGreeting),
            default => (string) __('email-magic-link::messages.mail_greeting'),
        };

        return $this->channel === 'code'
            ? $this->codeMessage($application, $greeting)
            : $this->linkMessage($application, $greeting);
    }

    private function linkMessage(string $application, Htmlable|string $greeting): MailMessage
    {
        $message = new MailMessage()->subject(__('email-magic-link::messages.mail_link_subject', ['app' => $application]));
        $message->greeting($greeting)
            ->line(MarkdownText::interpolate((string) __('email-magic-link::messages.mail_link_intro'), ['app' => $application]))
            ->action(__('email-magic-link::messages.mail_link_action'), (string) $this->actionUrl)
            ->line($this->uses > 1
                ? trans_choice('email-magic-link::messages.mail_link_expiry_reusable', $this->expiresInMinutes, ['minutes' => $this->expiresInMinutes, 'uses' => $this->uses])
                : trans_choice('email-magic-link::messages.mail_link_expiry', $this->expiresInMinutes, ['minutes' => $this->expiresInMinutes]))
            ->line(__('email-magic-link::messages.mail_ignore'));

        return $this->signedOff($message, $application);
    }

    private function codeMessage(string $application, Htmlable|string $greeting): MailMessage
    {
        $message = new MailMessage()->subject(__('email-magic-link::messages.mail_code_subject', ['app' => $application]));
        $message->greeting($greeting)
            ->line(MarkdownText::interpolate((string) __('email-magic-link::messages.mail_code_intro'), ['app' => $application]))
            ->line((string) $this->code)
            ->line(trans_choice('email-magic-link::messages.mail_code_expiry', $this->expiresInMinutes, ['minutes' => $this->expiresInMinutes]))
            ->line(__('email-magic-link::messages.mail_ignore'));

        return $this->signedOff($message, $application);
    }

    /** The message, signed off in the name of the application, set as text. */
    private function signedOff(MailMessage $message, string $application): MailMessage
    {
        return $message->salutation(MarkdownText::interpolate((string) __('email-magic-link::messages.mail_salutation'), ['app' => $application]));
    }
}
