<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\Console;
use App\Models\DuressAlert;
use App\Models\User;
use App\Notifications\Channels\AlertMailChannel;
use App\Support\ConsoleHome;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * NOBODY HAS ANSWERED — to the estate's own Head of Security, from the ring's escalation
 * (the guard ring §3.4).
 *
 * The mail beside `SendRingEscalationSms`'s text, with the same facts in the same order:
 * why the escalation rang (nobody answered in sixty seconds, nobody was on duty, no
 * officer's phone could be reached, every officer on duty said they cannot respond), and
 * how many phones are now being rung — or that none could be, and somebody has to be sent.
 *
 * A FOLLOW-UP, AND IT SAYS SO: at the raise the same person was mailed `PanicRaisedAtSite`.
 * NEVER THE RESIDENT'S NAME, for that mail's reason. QUEUED on the panic queue, and TO A
 * PROVEN ADDRESS ONLY, on `AlertMailChannel` — never the sign-in address.
 */
class PanicStillUnanswered extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $alertId,
        private readonly string $kind,
        private readonly string $estate,
        private readonly ?string $where,
        private readonly string $raisedAt,
        private readonly string $reason,
        private readonly int $phones,
        private readonly bool $isDrill = false,
        private readonly ?string $tenantId = null,
    ) {}

    /** @return list<class-string> */
    public function via(object $notifiable): array
    {
        return [AlertMailChannel::class];
    }

    /** @return array<class-string, string> */
    public function viaQueues(): array
    {
        $queue = config('push.urgent_queue');

        return is_string($queue) && $queue !== '' ? [AlertMailChannel::class => $queue] : [];
    }

    /** The subject's first words: why the escalation rang. */
    public function lead(): string
    {
        return match ($this->reason) {
            'nobody_on_duty' => 'NOBODY ON DUTY',
            'no_handset' => 'NO OFFICER\'S PHONE REACHED',
            'declined' => 'NO OFFICER ON DUTY CAN RESPOND',
            default => 'UNANSWERED '.max(1, (int) config('ring.escalate_after', 60)).' s',
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        $what = match ($this->kind) {
            'duress' => 'An officer duress alert',
            'medical' => 'A medical emergency',
            'fire' => 'A fire report',
            'intrusion' => 'An intrusion report',
            default => 'A panic alert',
        };

        $why = match ($this->reason) {
            'nobody_on_duty' => 'No officer was clocked on or rostered at the site when it was raised.',
            'no_handset' => 'Officers were on shift or rostered today, but no phone of theirs could be pushed when it was raised.',
            'declined' => 'Every officer on shift said they cannot respond.',
            default => 'Nobody has answered it in '.max(1, (int) config('ring.escalate_after', 60)).' seconds.',
        };

        $reach = $this->phones > 0
            ? 'Every active officer of the estate is being rung, off duty too: '.$this->phones.' phone'.($this->phones === 1 ? '' : 's').'.'
            : 'No officer\'s phone could be reached. Send someone.';

        $mail = (new MailMessage)
            ->subject(($this->isDrill ? DuressAlert::DRILL_PREFIX : '').$this->lead().': '.$what.' at '.$this->estate)
            ->greeting(($this->isDrill ? DuressAlert::DRILL_PREFIX : '').'Follow-up: '.lcfirst($what).' is still unanswered');

        if ($this->isDrill) {
            $mail->line('This is a DRILL, raised to test who a panic reaches. Nobody pressed a panic button.');
        }

        $mail
            ->line($this->estate.($this->where === null ? '' : ', '.$this->where).'.')
            ->line('Raised '.$this->raisedAt.' (site time).')
            ->line($why)
            ->line($reach)
            ->line('You are the estate\'s own Head of Security, and you were told when it was raised. This is the follow-up.');

        $open = $this->openFor($notifiable);

        if ($open !== null) {
            $mail->action($open[0], $open[1]);
        }

        return $mail->salutation('GeminiSecure');
    }

    /**
     * Where THIS reader can see the alert, or nowhere — `PanicRaisedAtSite::openFor()`'s rule.
     *
     * @return array{0: string, 1: string}|null
     */
    private function openFor(object $notifiable): ?array
    {
        if (! $notifiable instanceof User || $notifiable->status !== 'active') {
            return null;
        }

        if ($notifiable->console === Console::Gemini) {
            $covers = $this->tenantId === null || $notifiable->canAccessEstate($this->tenantId);

            return $covers && $notifiable->can('gemini.dispatch.view')
                ? ['Open the alert', url('/dispatch/alerts/'.$this->alertId)]
                : null;
        }

        if ($this->tenantId === null || ! $notifiable->canAccessEstate($this->tenantId) || ! $notifiable->can('estate.security.view')) {
            return null;
        }

        return ['Open the estate\'s Security screen', ConsoleHome::estateUrl($this->tenantId).'/security'];
    }
}
