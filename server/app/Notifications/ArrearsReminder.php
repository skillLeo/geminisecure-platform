<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One arrears reminder, by email, word for word as the log holds it (M84 §3.5).
 *
 * The subject and body are the notice's own, frozen when it was queued (`DunningNotice`): what the household reads is
 * what a dispute will find on the log. Nothing here renders a template or reads a balance. Sent now, by the delivery
 * run, which records what became of it on the notice; never queued again behind the run's back.
 */
class ArrearsReminder extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $subject,
        public readonly string $body,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject($this->subject);

        // One line per paragraph, as the estate wrote them.
        foreach (preg_split('/\R{2,}/', trim($this->body)) ?: [] as $paragraph) {
            $message->line(trim($paragraph));
        }

        return $message;
    }
}
