<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Channels\AlertMailChannel;
use App\Services\Dispatch\SupervisorOverride;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Every use ... is reported to the Head of Security" (M4 §3.4).
 *
 * A guard's shift was started on a supervisor's PIN because the phone could not
 * confirm who was holding it. Sent to every active Head of Security who covers the
 * estate, the moment it happens; the same fact is on the console bell and in the
 * audit log.
 *
 * WHAT IT CARRIES: who, where, when, who vouched, why — and whether the position
 * looked wrong. NEVER THE PIN, and nothing about the guard's pay. An email is
 * forwarded and left on desks; this one is written to be safe there.
 *
 * A SECURITY ALERT, SO IT GOES TO A PROVEN ADDRESS OR NOWHERE (M10 §1.4). A clock-in
 * nobody's fingerprint confirmed is exactly the kind of thing a Head of Security is
 * alerted to, and it goes out on `AlertMailChannel` like a panic does: to their
 * `alertEmail()`, never to the sign-in address. The same Head of Security reads both,
 * at the one address they proved.
 */
class SupervisorOverrideUsed extends Notification implements ShouldQueue
{
    // QUEUED: a slow mail server must never make a guard at a gate wait for their shift to start.
    use Queueable;

    public function __construct(
        private readonly string $guardName,
        private readonly string $employeeNumber,
        private readonly string $estate,
        private readonly string $post,
        private readonly string $supervisorName,
        private readonly string $reason,
        private readonly string $startedAt,
        private readonly ?int $distanceMetres,
        private readonly bool $mockLocation,
        private readonly int $shiftId,
        private readonly ?int $accuracyMetres = null,
    ) {}

    /** @return list<class-string> */
    public function via(object $notifiable): array
    {
        return [AlertMailChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $why = match ($this->reason) {
            SupervisorOverride::REASON_NO_BIOMETRICS => 'The guard\'s phone has no fingerprint or face set up, so it could not confirm who was holding it.',
            SupervisorOverride::REASON_OUTSIDE_GEOFENCE => 'The guard\'s phone placed them OUTSIDE the post\'s geofence. The supervisor vouched that they are on post.',
            SupervisorOverride::REASON_POSITION_UNCONFIRMED => 'The guard\'s phone could not establish a position (location off, refused, or too inaccurate). The supervisor vouched that they are on post.',
            default => 'The guard\'s phone could not match their fingerprint or face after three tries.',
        };

        return (new MailMessage)
            ->subject('Supervisor override at clock-in — '.$this->guardName.' · '.$this->estate)
            ->greeting('A shift was started on a supervisor\'s PIN')
            ->line($this->guardName.' ('.$this->employeeNumber.') — '.$this->estate.', '.$this->post.'.')
            ->line('Started '.$this->startedAt.' (site time), vouched for by '.$this->supervisorName.'.')
            ->line($why)
            ->line($this->distanceMetres === null
                ? 'The phone sent no position, so the distance from the post was not checked.'
                : 'The phone was '.$this->distanceMetres.' m from the post'.($this->accuracyMetres === null ? '.' : ', sure to within '.$this->accuracyMetres.' m.'))
            ->lineIf($this->mockLocation, 'The phone reported a MOCKED location. Treat the position as unverified.')
            ->line('The shift is flagged on its record and the use is in the audit log (shift #'.$this->shiftId.').')
            ->salutation('GeminiSecure');
    }
}
