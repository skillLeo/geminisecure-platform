<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The six-digit code a password change needs as well as the old password (M32 F9): a password alone, typed at an
 * unattended console, does not change it.
 *
 * As `ContactVerificationCode`: on the ordinary mail queue, pinned to the central connection by the caller, ENCRYPTED
 * there so the code is never plain text in `jobs` or `failed_jobs`, and kept nowhere — no database channel, no array.
 * Sent to the account's own address, the one it signs in with.
 */
class AccountPasswordCode extends Notification implements ShouldBeEncrypted, ShouldQueue
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
            ->subject($this->code.' is your GeminiSecure code to change your password')
            ->greeting('Hello '.$this->name)
            ->line('Enter this code on the '.$this->console.', with your current password, to change your password:')
            ->line($this->code)
            ->line('It works once, for '.$this->minutes.' minutes. If you did not ask for it, somebody may know your password: change it, and tell your administrator.')
            ->salutation('GeminiSecure');
    }
}
