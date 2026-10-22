<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Channels\AlertMailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "It is recorded as a recovery, never as a new enrolment, and the Head of Security sees it" (M46 §1.2).
 *
 * A guard's phone lost its sign-in — the app reinstalled, or its storage cleared — and set itself up again with the
 * enrolment code it was first set up with. That works only on the same handset, for the same guard
 * (`DeviceEnrolment::recover`), and every time it happens each active Head of Security who covers the estate is told,
 * beside the `device.recovered` entry in the audit log.
 *
 * WHAT IT CARRIES: who, where, when. NEVER THE CODE, and nothing about the handset's identity.
 *
 * A SECURITY ALERT, SO IT GOES TO A PROVEN ADDRESS OR NOWHERE (M10 §1.4), on `AlertMailChannel` like
 * `SupervisorOverrideUsed`.
 */
class GuardPhoneRecovered extends Notification implements ShouldQueue
{
    // QUEUED: a slow mail server must never keep a guard at a gate waiting to get back in.
    use Queueable;

    public function __construct(
        private readonly string $guardName,
        private readonly string $employeeNumber,
        private readonly string $estate,
        private readonly string $recoveredAt,
    ) {}

    /** @return list<class-string> */
    public function via(object $notifiable): array
    {
        return [AlertMailChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Guard phone set up again with its own code — '.$this->guardName.' · '.$this->estate)
            ->greeting('A guard\'s phone was recovered')
            ->line($this->guardName.' ('.$this->employeeNumber.') — '.$this->estate.'.')
            ->line('At '.$this->recoveredAt.' (site time) their phone, which had lost its sign-in, was set up again with the enrolment code it was first set up with.')
            ->line('It is the same handset: a used code works again only on the phone it first set up, and is refused on every other.')
            ->line('If '.$this->guardName.' did not do this, suspend them from their page in the console until the phone has been checked — a suspended guard\'s phone can do nothing.')
            ->salutation('GeminiSecure');
    }
}
