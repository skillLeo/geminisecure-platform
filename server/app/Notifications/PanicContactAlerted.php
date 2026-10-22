<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\DuressAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * THE EMAIL TO A HOUSEHOLD'S OWN EMERGENCY CONTACT (M12 §2.5).
 *
 * `HouseholdContactsSms::body()`'s rules, in an email: WHO (the first name only — enough for the person the resident
 * chose to recognise), WHERE (the estate and the lot) and WHEN (on the estate's clock), and what is TRUE by
 * construction — estate security has the alert, because the control room's copy is the committed row itself. No
 * link, no code, nothing to tap or quote: an email asking a frightened relative to act on a link is what a scam
 * looks like. And the one thing they can do: try to reach the person, and call the emergency services if they think
 * somebody is in danger.
 *
 * Sent NOW from inside its job (`SendPanicContactEmail`), never queued again: the job must know whether the mail
 * server took it, because that is the only thing the resident's screen may say.
 */
class PanicContactAlerted extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $firstName,
        public readonly string $estate,
        public readonly ?string $unit,
        public readonly string $when,
        public readonly bool $isDrill,
        public readonly string $emergencyNumber,
        // The time alone, for the subject: "2:14 AM Jamaica time" (the body's `when` carries the date as well).
        public readonly ?string $at = null,
        // The date alone, for the body's first line, which mail apps show as the preview: "27 Sep 2026".
        public readonly ?string $day = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $where = $this->estate.($this->unit === null || $this->unit === '' ? '' : ', '.$this->unit);

        /*
         * THE URGENT FACTS FIRST, AND SHORT (Hassam, 12 Oct 2026): a phone cuts a subject at about forty characters, and
         * a relative abroad may read it on a lock screen without opening it. So what, where, then when, and no date:
         * "Panic alert, Lot 47, 2:14 AM Jamaica time". The estate's name stands in for a lot nobody recorded.
         *
         * A DRILL KEEPS ITS WORD AND GIVES UP THE TIME (Hassam, 12 Oct): "DRILL: Panic alert, Lot 47". A drill must never
         * be readable as a real alert, so DRILL is the one word that cannot be cut; the time does not matter for a drill,
         * so it goes to the body's first line with the date.
         */
        $subject = implode(', ', array_filter([
            'Panic alert',
            $this->unit === null || $this->unit === '' ? $this->estate : $this->unit,
            $this->isDrill ? null : $this->at,
        ]));

        // THE DATE IN THE FIRST LINE OF THE BODY (Hassam), with who: it is the preview most mail apps show under the
        // subject, so the date and the name reach the reader the subject leaves them out of. A drill's time goes there too.
        $opening = $this->isDrill ? 'this is a drill.' : ($this->firstName === 'A resident' ? 'a resident' : $this->firstName).' raised an emergency alert.';
        $stamp = implode(', ', array_filter([$this->day, $this->isDrill ? $this->at : null]));

        return (new MailMessage)
            ->subject(($this->isDrill ? DuressAlert::DRILL_PREFIX : '').$subject)
            ->greeting($stamp === '' ? ucfirst($opening) : $stamp.': '.$opening)
            ->line($this->firstName.' raised an emergency alert at '.$where.', on '.$this->when.'.')
            ->line('Estate security has the alert. You are receiving this because '.$this->firstName.' named you as an emergency contact in the GeminiSecure Resident App.')
            ->line('If you can, try to reach '.$this->firstName.'. If you think someone is in danger, call '.$this->emergencyNumber.'.')
            ->salutation('GeminiSecure');
    }
}
