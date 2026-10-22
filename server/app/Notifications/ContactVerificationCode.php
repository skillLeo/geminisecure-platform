<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The six-digit code that proves a console user's alert address (M10 §1.4).
 *
 * ON THE ORDINARY MAIL QUEUE, NOT THE PANIC QUEUE. No `onQueue()` here, and that is
 * the decision: the panic queue is kept for mail that must never wait behind
 * anything (§1.3, "the panic queue stays dedicated"), and a sign-in code is exactly
 * the kind of mail it must not wait behind. The caller pins it to the CENTRAL
 * connection with `CentralQueue::pin()`, because it is sent from inside an estate
 * request, where the default connection is an estate database with no `jobs` table.
 *
 * ENCRYPTED ON THE QUEUE (`ShouldBeEncrypted`). The code is the one thing the
 * platform stores only as a hash — and a queued notification is a serialised
 * object in the `jobs` table, in plain text, until the worker sends it. Encrypting
 * the payload with APP_KEY keeps the plaintext out of the database for the minutes
 * it waits there, and out of `failed_jobs` if the mail server refuses it.
 *
 * NO `toArray()` AND NO DATABASE CHANNEL. Nothing about this notification is kept,
 * so there is nowhere a code could be read back from.
 *
 * Addressed on demand (`Notification::route('mail', …)`) to the address being
 * proven, never to the account's sign-in address: the whole point is that the
 * person proves they read THIS mailbox.
 */
class ContactVerificationCode extends Notification implements ShouldBeEncrypted, ShouldQueue
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
            ->subject($this->code.' is your GeminiSecure verification code')
            ->greeting('Hello '.$this->name)
            ->line('Enter this code on the '.$this->console.' to confirm that emergency alerts can reach you at this address:')
            ->line($this->code)
            ->line('It works once, for '.$this->minutes.' minutes. If you did not ask for it, ignore this email — the address is not confirmed without the code, and nothing changes.')
            ->salutation('GeminiSecure');
    }
}
