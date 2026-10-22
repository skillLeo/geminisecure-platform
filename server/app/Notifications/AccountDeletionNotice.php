<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * THE EMAILS A DELETED ACCOUNT SENDS (M18H §2.2, §2.3, §3).
 *
 *   deleted            to the person, at once: what was deleted, when it becomes permanent, and "If this wasn't you,
 *                      sign in before 4 October to restore it." — the one message a deleted account still receives.
 *   restored           to the person, when the estate office brought it back.
 *   restored_office    to the estate office, when the person restored it themselves ("tells the estate office").
 *   deleted_office     to the estate office, when the person deleted it: what stopped, the bookings cancelled and deposits
 *                      refunded ("the estate is told", §4.3), and — a primary resident — that the household needs a new
 *                      primary (§4.1).
 *
 * Sent now, not queued: a notice about a sign-in window that arrives after the window is worse than none.
 */
class AccountDeletionNotice extends Notification
{
    use Queueable;

    public const DELETED = 'deleted';

    public const RESTORED = 'restored';

    public const RESTORED_OFFICE = 'restored_office';

    public const DELETED_OFFICE = 'deleted_office';

    /**
     * @param  array{estate: string, deleted_on: string, self_until: ?string, permanent_on: string, reference: string, who?: ?string, unit?: ?string, bookings?: int, passes?: int, was_primary?: bool}  $facts
     *         every date already written in words on the estate's calendar ("Sunday 4 October")
     */
    public function __construct(public readonly string $kind, public readonly array $facts) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $f = $this->facts;

        return match ($this->kind) {
            self::RESTORED => (new MailMessage)
                ->subject('Your '.$f['estate'].' account is back')
                ->line('The estate office has restored your Resident App account. Sign in again with this email address and everything is where it was — your home, your household, your balance and your history.')
                ->line('Visitor passes that stopped working when the account was deleted stay stopped: send your visitors new ones from the app.')
                ->line('Reference: '.$f['reference']),

            self::DELETED_OFFICE => (new MailMessage)
                ->subject(($f['who'] ?? 'A resident').' deleted their Resident App account')
                ->line(($f['who'] ?? 'A resident').($f['unit'] ? ' ('.$f['unit'].')' : '').' deleted their Resident App account on '.$f['deleted_on'].'. Their sign-in, e-pass and '.(int) ($f['passes'] ?? 0).' visitor '.((int) ($f['passes'] ?? 0) === 1 ? 'pass have' : 'passes have').' stopped, and they are off the active register.')
                ->line((int) ($f['bookings'] ?? 0) > 0
                    ? (int) $f['bookings'].' future amenity '.((int) $f['bookings'] === 1 ? 'booking was' : 'bookings were').' cancelled; any deposit held was put back for refund.'
                    : 'They had no future amenity bookings.')
                ->line(($f['was_primary'] ?? false)
                    ? 'They were the household\'s primary resident. Choose a new primary resident for the household — until then it is listed under Residents → Deleted accounts.'
                    : 'The household and its other members are unchanged.')
                ->line('Their dues, payments, gate log and incidents stay with the home. They can restore the account themselves by signing in until '.($f['self_until'] ?? $f['permanent_on']).'; the office can until '.$f['permanent_on'].'.')
                ->line('Reference: '.$f['reference']),

            self::RESTORED_OFFICE => (new MailMessage)
                ->subject(($f['who'] ?? 'A resident').' restored their Resident App account')
                ->line(($f['who'] ?? 'A resident').($f['unit'] ? ' ('.$f['unit'].')' : '').' deleted their Resident App account on '.$f['deleted_on'].' and has restored it themselves by signing in.')
                ->line('Nothing needs doing: their home, household, balance and history are as they were. Their visitor passes that stopped when the account was deleted stay stopped.')
                ->line('Reference: '.$f['reference']),

            default => (new MailMessage)
                ->subject('Your '.$f['estate'].' account was deleted')
                ->line('Your Resident App account for '.$f['estate'].' was deleted on '.$f['deleted_on'].'. You are signed out on every phone, your e-pass has stopped working, and so have the visitor passes you sent.')
                ->line($f['self_until'] !== null
                    ? 'If this wasn\'t you, sign in before '.$f['self_until'].' to restore it — nothing will be lost. After that, only the estate office can bring it back, until '.$f['permanent_on'].'.'
                    : 'Only the estate office can bring it back now, until '.$f['permanent_on'].'.')
                ->line('On '.$f['permanent_on'].' it becomes permanent: nothing of you is kept on the account after that. What you owe the estate, and the estate\'s own records of your home, stay with the home.')
                ->line('Reference: '.$f['reference']),
        };
    }
}
