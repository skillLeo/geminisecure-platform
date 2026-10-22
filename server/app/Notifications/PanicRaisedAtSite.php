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
 * A panic was raised at a site, to the person responsible for that site (M10 §1).
 *
 * THIS REPLACES `PanicReachedNoHandset`, AND THE RENAME IS THE POINT. That
 * notification was the last rung of a ladder: it went out only when no guard was
 * clocked on AND none was rostered, so every sentence in it — "NOBODY ON DUTY",
 * "reached no handset", "no guard was clocked on at this site" — was true by
 * construction.
 *
 * M10 §1 removes the ladder. A panic now reaches everyone who can help, at once,
 * on the client's own reasoning: a panic is not necessarily a security matter,
 * security are not supposed to put out fires or deal with medical services, and
 * the property office should be able to act. So this goes out on EVERY panic,
 * including the ones where officers were reached — and the old wording would
 * then have been false on most of them, telling a Head of Security nobody was on
 * duty while two officers were already walking to the lot.
 *
 * SO IT REPORTS WHAT ACTUALLY HAPPENED. `$handsets` is how many officer handsets
 * the push reached and `$officers` how many officers were on the list it went to,
 * and the mail says which of these it was — as `PanicDeliveries::pushLeg()` does:
 *
 *   handsets reached         "Pushed to 2 handsets of the 2 officers on shift or
 *                             rostered today." Never "2 officers": two phones may be
 *                             one officer's, and an officer with no phone was not told.
 *   officers, no handset     "NO HANDSET REACHED" — they are on post, and the platform
 *                             could not push them (none registered and signed in, push
 *                             off, or queueing failed): somebody has to reach them.
 *   nobody else, a duress    "NO OTHER OFFICER ON DUTY" — the raiser IS on duty.
 *   nobody at all            "NOBODY ON DUTY", the one case where it is true.
 *
 * Until M10 §1 this went out only in the last case, and it kept that case's wording
 * for all four: a Head of Security was told nobody was clocked on while officers
 * stood at the gate. A notification that cannot be wrong is worth more than one that
 * is dramatic.
 *
 * WHAT IT CARRIES: which estate, where at the estate, when, what kind, and what
 * it reached. NEVER the resident's name — the same rule as a lock screen, for
 * the same reason: a forwarded email is read by whoever it is forwarded to.
 *
 * QUEUED, on the panic queue. A slow mail server must never sit in front of a
 * panic being answered, and this must never queue behind bulk mail (M10 §1.3: "The
 * panic queue stays dedicated. Email and SMS go out on it too"). `viaQueues()` is what
 * puts it there, on every send: the caller's `CentralQueue::pin()` chooses only the
 * CONNECTION, and a queued notification with no queue of its own lands on `default`,
 * behind every estate-wide notice's push jobs, while the hand-off record said "on the
 * panic queue". The notification says where it goes, so no caller can forget it.
 *
 * TO A PROVEN ADDRESS ONLY (M10 §1.4). It goes out on `AlertMailChannel`, never on
 * `mail`: the recipient is the user's `alertEmail()` — the contact address they proved
 * by a one-time code — and a user with none is sent nothing. Never the sign-in address,
 * which nobody has shown anybody reads for alerts. `PanicFanOut` skips the unproven
 * before sending, so the count it tells the resident is the people actually mailed.
 */
class PanicRaisedAtSite extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $alertId,
        private readonly string $kind,
        private readonly string $estate,
        private readonly ?string $where,
        private readonly string $raisedAt,

        /** How many officer handsets the push actually reached. */
        private readonly int $handsets = 0,

        /**
         * A DRILL (M10 §1.3) — `panic:walk` proving the fan-out. It reaches real Heads of
         * Security exactly as a panic does, so the subject has to say DRILL before
         * anything else: this mail is read on a phone's lock screen, where the subject
         * is all there is.
         */
        private readonly bool $isDrill = false,

        /**
         * How many officers were on the list the push went to — clocked on or rostered
         * today, the raiser of a duress excluded — whether or not a handset of theirs
         * could be pushed. NULL when the caller did not say, and then the mail claims
         * nothing about who was on duty: it says only what the handset count says.
         */
        private readonly ?int $officers = null,

        /** Whether an officer raised it (a duress) — they are on duty, whoever else is. */
        private readonly bool $raisedByOfficer = false,

        /**
         * The estate the alert belongs to, so each recipient's button opens THEIR console's
         * view of it (`openFor()`). Null offers an estate recipient no button at all.
         */
        private readonly ?string $tenantId = null,
    ) {}

    /** @return list<class-string> */
    public function via(object $notifiable): array
    {
        return [AlertMailChannel::class];
    }

    /**
     * The panic queue — `push.urgent_queue`, the one `PanicSms`, `HouseholdContactsSms`,
     * `HeadsOfSecuritySms` and every urgent push already use, and the only queue the
     * dedicated panic workers read. Empty config means one queue for everything, as
     * `config/push.php` allows: slower for a panic, never lost.
     *
     * @return array<class-string, string>
     */
    public function viaQueues(): array
    {
        $queue = config('push.urgent_queue');

        return is_string($queue) && $queue !== '' ? [AlertMailChannel::class => $queue] : [];
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

        $nobody = $this->handsets === 0;
        $plural = static fn (int $count, string $one): string => $count.' '.$one.($count === 1 ? '' : 's');

        /*
         * The subject carries the one fact that changes what the reader does.
         * "Nobody on duty" is an instruction to find somebody; "no handset reached" is
         * an instruction to reach the officers who ARE there; the ordinary case is an
         * instruction to be aware.
         */
        $subject = ($this->isDrill ? DuressAlert::DRILL_PREFIX : '').match (true) {
            ! $nobody => $what.' at '.$this->estate,
            $this->officers === null => $what.' at '.$this->estate.' reached no handset',
            $this->officers > 0 => 'NO HANDSET REACHED: '.$what.' at '.$this->estate,
            $this->raisedByOfficer => 'NO OTHER OFFICER ON DUTY: '.$what.' at '.$this->estate.' reached no handset',
            default => 'NOBODY ON DUTY: '.$what.' at '.$this->estate.' reached no handset',
        };

        $reach = match (true) {
            ! $nobody && $this->officers !== null => 'Pushed to '.$plural($this->handsets, 'handset').' of the '.$plural($this->officers, 'officer').' on shift or rostered today.',
            ! $nobody => $plural($this->handsets, 'officer handset').' '.($this->handsets === 1 ? 'was' : 'were').' alerted.',
            $this->officers === null => 'No handset was alerted.',
            $this->officers > 0 => ($this->officers === 1 ? '1 officer is' : $this->officers.' officers are')
                .' on shift or rostered today, but no handset of theirs could be pushed, so the platform has alerted none of them. Reach them another way.',
            $this->raisedByOfficer => 'No other officer is on shift or rostered today, so no handset was alerted.',
            default => 'No guard was clocked on at this site and no officer was rostered for today, so no handset was alerted.',
        };

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting(($this->isDrill ? DuressAlert::DRILL_PREFIX : '').($nobody ? $what.' reached no handset' : $what));

        if ($this->isDrill) {
            $mail->line('This is a DRILL, raised to test who a panic reaches. Nobody pressed a panic button.');
        }

        $mail
            ->line($this->estate.($this->where === null ? '' : ', '.$this->where).'.')
            ->line('Raised '.$this->raisedAt.' (site time).')
            ->line($reach)
            ->line('You are the site\'s escalation contact and are told at the same time as everyone else, not afterwards.')
            ->line('The control room has the alert on its screen.');

        $open = $this->openFor($notifiable);

        if ($open !== null) {
            $mail->action($open[0], $open[1]);
        }

        return $mail->salutation('GeminiSecure');
    }

    /**
     * THE BUTTON, FOR THIS READER — or none (bug hunt B093).
     *
     * The one button in this mail linked everybody to `/dispatch/alerts/{id}`: a Gemini
     * route on the central host. Most of the people this mail goes to are the estate's
     * own — the President, the committee, the estate's Head of Security — whose sessions
     * live on the estate's host and whose console has no such page: they were sent to
     * sign in, then bounced to their estate's home, and never saw the alert. A control
     * that says "Open the alert" and cannot is the kind of claim this platform does not
     * make. So each reader is sent where THEY can see it, and a reader who can see it
     * nowhere is offered no button — the mail itself still says where and when.
     *
     *   Gemini staff with the dispatch screens, for an estate they cover
     *       → the alert's own response screen
     *   an estate console user at this estate who may see its Security screen
     *       → that screen, on the estate's own host, where live panics are listed
     *
     * Asked when the mail is BUILT, which for a queued alert is in the worker: a reader
     * whose access changed between the panic and the send is sent where they can go now.
     *
     * @return array{0: string, 1: string}|null the label and the address
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
