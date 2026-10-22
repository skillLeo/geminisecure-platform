<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Channels\AlertMailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * AN ATTENDANCE EXCEPTION, NOT A SILENT CORRECTION (M6 §2.1 part 4).
 *
 * A shift was still running hours after its rostered end because nobody clocked out,
 * so the platform closed it. Sent to every active Head of Security who covers the
 * estate, exactly as a supervisor override is.
 *
 * WHY IT IS TOLD AND NOT JUST DONE. A forgotten clock-out is a pay question and a
 * coverage question at once: the roster says that gate was manned until seven, and
 * the only person who knows whether it was is a supervisor. Closing the shift and
 * saying nothing would turn "we do not know when this officer left" into a record
 * that reads as though they left exactly on time, which is the one thing it must not
 * say. So the shift carries the flag, the bell carries the item, and this carries the
 * sentence — and the closing time is the ROSTERED end, never the moment it was
 * noticed, because the platform has no evidence of any later hour. When the officer
 * clocked on AFTER the rostered end, the clock-on is that last hour, the shift is closed
 * there (`ShiftAutoClose::evidencedUntil()`, bug hunt B100), and this says so rather
 * than describing a close at a time before they had started.
 *
 * NEVER A PAY FIGURE. What an officer is owed for an overrun is a decision somebody
 * makes after reading this; it is not something an automatic rule is entitled to
 * assert in an email.
 *
 * AN ALERT TO THE HEAD OF SECURITY, SO TO A PROVEN ADDRESS OR NOWHERE (M10 §1.4) —
 * "whether the post was covered after the officer left" is a security question, and
 * this goes out on `AlertMailChannel` exactly as a supervisor override does: to the
 * Head of Security's `alertEmail()`, never to the sign-in address.
 */
class ShiftAutoClosed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $guardName,
        private readonly string $employeeNumber,
        private readonly string $estate,
        private readonly string $post,
        private readonly string $startedAt,
        private readonly string $rosteredEnd,
        private readonly int $hours,
        private readonly int $shiftId,

        // Clocked on after the rostered end, so closed at the clock-on (bug hunt B100).
        private readonly bool $closedAtClockOn = false,
    ) {}

    /** @return list<class-string> */
    public function via(object $notifiable): array
    {
        return [AlertMailChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Attendance exception — no clock-out from '.$this->guardName.' · '.$this->estate)
            ->greeting('A shift was closed automatically')
            ->line($this->guardName.' ('.$this->employeeNumber.') — '.$this->estate.', '.$this->post.'.')
            ->line('Clocked on '.$this->startedAt.' (site time). Rostered to end '.$this->rosteredEnd.'. No clock-out was ever recorded.')
            ->line($this->closedAtClockOn
                ? 'It was clocked on after its rostered end and was still open '.$this->hours.' hour'.($this->hours === 1 ? '' : 's').' after the clock-on, so the platform closed it AT THE TIME IT WAS CLOCKED ON — the last hour anyone can evidence.'
                : 'It was still open '.$this->hours.' hour'.($this->hours === 1 ? '' : 's').' after its rostered end, so the platform closed it AT THE ROSTERED END — the last hour anyone can evidence.')
            ->line('This is an exception for you to settle, not a correction: confirm what hours were actually worked before the shift is paid, and whether the post was covered after the officer left.')
            ->line('The officer has been told their shift was closed automatically. Shift #'.$this->shiftId.'.')
            ->salutation('GeminiSecure');
    }
}
