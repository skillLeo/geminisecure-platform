<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The six-digit code that proves a NEW sign-in address receives mail before the account is moved to it (M34 §1,
 * `SignInEmailChange`). Sent to the new address, never to the old one.
 *
 * As `AccountPasswordCode`: on the ordinary mail queue, pinned to the central connection by the caller, ENCRYPTED there
 * so the code is never plain text in `jobs` or `failed_jobs`, and kept nowhere — no database channel, no array.
 */
class AccountEmailCode extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $console,
        public readonly int $minutes,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->code.' is your GeminiSecure code to sign in with this address')
            ->greeting('Hello '.$this->name)
            ->line('Enter this code on the '.$this->console.' to make this address the one you sign in with:')
            ->line($this->code)
            ->line('It works once, for '.$this->minutes.' minutes. If you did not ask for it, ignore this email: nothing changes without the code.')
            ->salutation('GeminiSecure');
    }
}
