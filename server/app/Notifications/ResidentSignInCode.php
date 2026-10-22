<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The Resident App's one-time sign-in code, by email (13 D3).
 *
 * Sent now, not queued: a code that arrives after the ten minutes it is good
 * for is a failed sign-in, and the worker may be the thing that is down.
 */
class ResidentSignInCode extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly string $estateName,
        public readonly int $minutes,
        // Asked for on the web log-in page rather than in the app (M24 §4.4): the email says where to type it.
        public readonly bool $onWeb = false,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->code.' is your '.$this->estateName.' sign-in code')
            ->line($this->onWeb
                ? 'Enter this code on the website to open your community platform for '.$this->estateName.':'
                : 'Enter this code in the Resident App to sign in to '.$this->estateName.':')
            ->line($this->code)
            ->line('It works once, for '.$this->minutes.' minutes. If you did not ask for it, ignore this email — nobody can sign in without the code.');
    }
}
