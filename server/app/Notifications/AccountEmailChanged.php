<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the OLD sign-in address once an account has moved to a new one (M34 §1, `SignInEmailChange`): a change
 * somebody else made — with the password, from a console left open — is then not silent. The new address is masked;
 * the owner of the old one recognises their own, and nobody else learns it.
 */
class AccountEmailChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $name,
        public readonly string $console,
        public readonly string $newAddress,
        public readonly string $when,
        public readonly string $support,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('The address you sign in to GeminiSecure with has changed')
            ->greeting('Hello '.$this->name)
            ->line('On '.$this->when.', your '.$this->console.' account was changed to sign in with '.$this->newAddress.'. This address no longer signs you in.')
            ->line('If you made this change, there is nothing more to do. If you did not, write to '.$this->support.' at once.')
            ->salutation('GeminiSecure');
    }
}
