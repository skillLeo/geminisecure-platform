<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * What a push said, by email, to a resident who turned "Email" on (board 20, M50 §4.2 #4).
 *
 * The push's own title and words, the estate's name, and how to stop them — nothing more. It carries no link that signs
 * anybody in and nothing a push would not have said: the words come from the same `PushMessage`, written for a lock
 * screen, which never names a home (DESIGN INV-14).
 *
 * Queued (`ResidentEmails` pins it to the central queue): a push decision never waits on a mail server.
 */
class ResidentNewsEmailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $estateName,
        public readonly string $title,
        public readonly string $body,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title.' · '.$this->estateName)
            ->greeting($this->title)
            ->line($this->body)
            ->line('Open the GeminiSecure Resident app to see it.')
            ->line('You get these emails because Email is on in the app, under Settings → Notifications. Turn it off there to stop them.');
    }
}
